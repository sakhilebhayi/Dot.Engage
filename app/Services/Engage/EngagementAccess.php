<?php

namespace App\Services\Engage;

use App\Enums\ParticipantRole;
use App\Models\Contract;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\User;
use App\Support\Engage\Actor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Who is looking at an engagement and what they may do. Every controller and Livewire component asks this class,
 * so the rules live in one place and are enforced on the server, whatever the page shows.
 *
 * Two kinds of viewer:
 *  - a signed-in team member of the business that owns the engagement (permissions come from their team role,
 *    plus their participant record when they have one), and
 *  - an invited outside party who proved their email address (permissions come from their participant record
 *    and only reach the one engagement they were invited to).
 */
final class EngagementAccess
{
    public const SESSION_KEY = 'engage.participants';

    private function __construct(
        public readonly Engagement $engagement,
        public readonly ?User $user,
        public readonly ?EngagementParticipant $participant,
        private readonly ?Request $request = null,
    ) {}

    public static function forUser(Engagement $engagement, User $user, ?Request $request = null): ?self
    {
        if (! $user->belongsToTeam($engagement->team)) {
            return null;
        }

        $participant = EngagementParticipant::query()
            ->where('engagement_id', $engagement->id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('email', $user->email))
            ->whereNull('revoked_at')
            ->first();

        return new self($engagement, $user, $participant, $request);
    }

    public static function forParticipant(Engagement $engagement, EngagementParticipant $participant, ?Request $request = null): ?self
    {
        if ($participant->engagement_id !== $engagement->id || ! $participant->isActive()) {
            return null;
        }
        if ($participant->token_expires_at === null || $participant->token_expires_at->isPast()) {
            return null;
        }

        return new self($engagement, null, $participant, $request);
    }

    /** Signed-in team member first; otherwise the verified outside participant held in this browser session. */
    public static function resolve(Request $request, Engagement $engagement): ?self
    {
        $user = $request->user();
        if ($user && ($access = self::forUser($engagement, $user, $request))) {
            return $access;
        }

        $held = $request->hasSession() ? (array) $request->session()->get(self::SESSION_KEY, []) : [];
        $participantId = $held[$engagement->id] ?? null;
        if ($participantId) {
            $participant = EngagementParticipant::query()->where('engagement_id', $engagement->id)->find($participantId);
            if ($participant && $participant->verified_at && $participant->verified_at->gt(now()->subHours((int) config('engage.session_hours', 12)))) {
                return self::forParticipant($engagement, $participant, $request);
            }
        }

        return null;
    }

    public function isInternal(): bool
    {
        return $this->user !== null;
    }

    public function isExternal(): bool
    {
        return $this->user === null;
    }

    public function can(string $ability): bool
    {
        if (! $this->engagement->isOpen() && ! in_array($ability, ['view', 'view_audit', 'message_closed'], true)) {
            return false;
        }

        if ($this->user) {
            if ($this->isViewerOnly()) {
                return $ability === 'view';
            }

            $teamAbilities = $this->teamRole()->abilities();
            $own = $this->participant?->isActive() ? $this->participant->role->abilities() : [];

            if (in_array($ability, ['approve', 'sign'], true)) {
                return (bool) $this->participant?->can($ability);
            }
            if ($ability === 'host_meeting' && $this->participant?->hasCapability('host')) {
                return true;
            }

            return in_array($ability, $teamAbilities, true) || in_array($ability, $own, true);
        }

        return (bool) $this->participant?->can($ability);
    }

    /** The role a team member holds in engagements of their business, from their Jetstream team role. */
    public function teamRole(): ParticipantRole
    {
        $team = $this->engagement->team;
        $user = $this->user;

        if (! $user) {
            return ParticipantRole::External;
        }
        if ($user->id === $this->engagement->owner_id || $user->hasTeamRole($team, 'admin')) {
            return ParticipantRole::Admin;
        }
        if ($user->hasTeamPermission($team, 'create')) {
            return ParticipantRole::Manager;
        }

        // A read-only team viewer may look but not take part (see isViewerOnly()).
        return ParticipantRole::Participant;
    }

    /** Team members see every document; outside parties only those that were shared with them. */
    public function canSeeDocument(Contract $contract): bool
    {
        return $contract->engagement_id === $this->engagement->id && ($this->isInternal() || $contract->isShared());
    }

    /** @return Collection<int, Contract> */
    public function documents(): Collection
    {
        return $this->engagement->contracts()->when($this->isExternal(), fn ($q) => $q->whereNotNull('shared_at'))->orderBy('id')->get();
    }

    public function isViewerOnly(): bool
    {
        return $this->user && ! $this->user->hasTeamPermission($this->engagement->team, 'create');
    }

    /** Who is acting, with the network address and browser of the request this access was resolved from. */
    public function actor(?Request $request = null): Actor
    {
        $request ??= $this->request;

        return $this->user
            ? Actor::user($this->user, $request)
            : Actor::participant($this->participant, $request);
    }

    public function name(): string
    {
        return $this->user?->name ?? $this->participant->name;
    }

    /** The participant row for this viewer, creating one for a team member the first time they take part. */
    public function participantRecord(): ?EngagementParticipant
    {
        if ($this->participant) {
            return $this->participant;
        }
        if (! $this->user) {
            return null;
        }

        $party = $this->engagement->parties()->where('is_host', true)->first();

        return EngagementParticipant::firstOrCreate(
            ['engagement_id' => $this->engagement->id, 'email' => $this->user->email],
            [
                'party_id' => $party?->id,
                'user_id' => $this->user->id,
                'name' => $this->user->name,
                'role' => $this->teamRole()->value,
                'required' => false,
                'accepted_at' => now(),
            ],
        );
    }
}
