<?php

namespace App\Services\Engage;

use App\Enums\EngagementKind;
use App\Enums\EngagementState;
use App\Enums\ParticipantRole;
use App\Models\Engagement;
use App\Models\EngagementMessage;
use App\Models\EngagementParticipant;
use App\Models\EngagementParty;
use App\Models\SigningEnvelope;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Support\Engage\Actor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class EngagementService
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly EngagementStateMachine $states,
        private readonly ParticipantAccess $access,
    ) {}

    public function create(Team $team, User $owner, string $title, EngagementKind $kind, ?string $description, string $counterpartyName, ?\DateTimeInterface $expiresAt = null, ?Actor $actor = null): Engagement
    {
        return DB::transaction(function () use ($team, $owner, $title, $kind, $description, $counterpartyName, $expiresAt, $actor) {
            $engagement = Engagement::create([
                'team_id' => $team->id,
                'reference' => Engagement::nextReference(),
                'title' => $title,
                'kind' => $kind,
                'description' => $description,
                'owner_id' => $owner->id,
                'state' => EngagementState::Draft,
                'state_changed_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            $host = $engagement->parties()->create(['name' => $team->name, 'is_host' => true, 'position' => 0]);
            $engagement->parties()->create(['name' => $counterpartyName, 'is_host' => false, 'position' => 1]);

            EngagementParticipant::create([
                'engagement_id' => $engagement->id,
                'party_id' => $host->id,
                'user_id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'role' => ParticipantRole::Owner,
                'capabilities' => ['host'],
                'required' => true,
                'accepted_at' => now(),
            ]);

            $this->audit->record($engagement, 'engagement.created', $actor ?? Actor::user($owner), $engagement, [
                'reference' => $engagement->reference, 'kind' => $kind->value, 'title' => $title,
            ]);

            return $engagement;
        });
    }

    /**
     * Add a person to the engagement and, for an outside party, email them an invitation.
     *
     * @param  list<string>  $capabilities  any of approve, sign, host
     */
    public function addParticipant(
        Engagement $engagement,
        EngagementParty $party,
        string $name,
        string $email,
        ParticipantRole $role,
        array $capabilities,
        bool $required,
        Actor $actor,
        ?User $invitedBy = null,
    ): EngagementParticipant {
        if ($party->engagement_id !== $engagement->id) {
            throw new InvalidArgumentException('That party does not belong to this engagement.');
        }
        if (! $engagement->isOpen()) {
            throw new InvalidArgumentException('This engagement is closed.');
        }

        $email = mb_strtolower(trim($email));
        $capabilities = array_values(array_intersect(array_unique($capabilities), ['approve', 'sign', 'host']));

        $existing = $engagement->participants()->where('email', $email)->first();
        if ($existing && ! $existing->isRevoked()) {
            throw new InvalidArgumentException($email.' is already part of this engagement.');
        }

        $teamMember = $party->is_host ? $engagement->team->allUsers()->first(fn (User $u) => mb_strtolower($u->email) === $email) : null;

        $participant = DB::transaction(function () use ($engagement, $party, $name, $email, $role, $capabilities, $required, $invitedBy, $teamMember, $existing) {
            $attributes = [
                'engagement_id' => $engagement->id,
                'party_id' => $party->id,
                'user_id' => $teamMember?->id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'capabilities' => $capabilities,
                'required' => $required,
                'invited_by' => $invitedBy?->id,
                'revoked_at' => null,
                'declined_at' => null,
                'accepted_at' => $teamMember ? now() : null,
                'verified_at' => null,
                'access_token_hash' => null,
            ];

            if ($existing) {
                $existing->forceFill($attributes)->save();

                return $existing;
            }

            return EngagementParticipant::create($attributes);
        });

        if ($teamMember) {
            $this->audit->record($engagement, 'participant.added', $actor, $participant, ['email' => $email, 'role' => $role->value, 'capabilities' => $capabilities]);
            $teamMember->notify(new EngagementNotice(
                $engagement, 'engagement_added', ($invitedBy?->name ?? 'A colleague').' added you to an engagement',
                route('engagements.show', $engagement), 'Open the engagement',
            ));
        } else {
            $this->access->sendInvitation($participant, $engagement, $invitedBy?->name ?? $actor->label, $actor);
        }

        $this->states->sync($engagement, $actor);

        return $participant;
    }

    public function revokeParticipant(Engagement $engagement, EngagementParticipant $participant, Actor $actor): void
    {
        if ($participant->role === ParticipantRole::Owner) {
            throw new InvalidArgumentException('The owner of an engagement cannot be removed.');
        }

        $this->access->revoke($participant, $engagement, $actor);
        $this->states->sync($engagement, $actor);
    }

    /** Send the invitation again with a fresh link (the old one stops working). */
    public function resendInvitation(Engagement $engagement, EngagementParticipant $participant, Actor $actor, string $invitedByName): void
    {
        $this->access->sendInvitation($participant->fresh(), $engagement, $invitedByName, $actor);
        $this->states->sync($engagement, $actor);
    }

    public function postMessage(Engagement $engagement, EngagementAccess $who, string $body): EngagementMessage
    {
        $participant = $who->participantRecord();
        $message = EngagementMessage::create([
            'engagement_id' => $engagement->id,
            'participant_id' => $participant?->id,
            'user_id' => $who->user?->id,
            'author_name' => $who->name(),
            'body' => $body,
            'mentions' => $this->mentionsIn($engagement, $body),
        ]);

        $this->audit->record($engagement, 'message.posted', $who->actor(), $message);
        $this->states->sync($engagement, $who->actor());
        $this->notifyOthers($engagement, $participant, 'engagement_message', $who->name().' posted in the discussion', route('engagements.show', $engagement).'#discussion');

        return $message;
    }

    /** @return list<int> participant ids @mentioned in the text (by first name or the part of the email before @) */
    public function mentionsIn(Engagement $engagement, string $body): array
    {
        preg_match_all('/@([\p{L}0-9._-]+)/u', $body, $m);
        if (empty($m[1])) {
            return [];
        }

        $handles = array_map('mb_strtolower', $m[1]);

        return $engagement->participants()->whereNull('revoked_at')->get()
            ->filter(function (EngagementParticipant $p) use ($handles) {
                $first = mb_strtolower(explode(' ', trim($p->name))[0]);
                $local = mb_strtolower(explode('@', $p->email)[0]);

                return in_array($first, $handles, true) || in_array($local, $handles, true);
            })
            ->pluck('id')->values()->all();
    }

    /** Tell everyone else on the engagement. Internal people get an in-app notice and email; outside parties get an email link. */
    /** Notice types that can fire many times in a row: one email per person per ten minutes is enough. */
    private const CHATTY = ['engagement_message', 'document_note'];

    /** @param list<int> $alsoExcept participant ids to skip as well (already told another way) */
    public function notifyOthers(Engagement $engagement, ?EngagementParticipant $except, string $type, string $message, string $url, ?string $label = null, array $alsoExcept = []): void
    {
        $people = $engagement->participants()->whereNull('revoked_at')->whereNull('declined_at')->whereNotNull('accepted_at')->get();

        foreach ($people as $person) {
            if (($except && $person->id === $except->id) || in_array($person->id, $alsoExcept, true)) {
                continue;
            }
            if (in_array($type, self::CHATTY, true) && ! Cache::add('engage.notice.'.$engagement->id.'.'.$person->id.'.'.$type, 1, 600)) {
                continue;
            }
            $this->notifyParticipant($engagement, $person, $type, $message, $url, $label);
        }
    }

    public function notifyParticipant(Engagement $engagement, EngagementParticipant $person, string $type, string $message, string $url, ?string $label = null): void
    {
        $notice = new EngagementNotice($engagement, $type, $message, $url, $label, emailOnly: $person->user_id === null);

        if ($person->user_id && ($user = $person->user)) {
            $user->notify($notice);

            return;
        }

        if ($person->verified_at === null && $person->invitation_sent_at === null) {
            return;
        }
        // Outside parties are always sent to the join page, which asks for a fresh code, never straight to content.
        Notification::route('mail', $person->email)->notify($notice);
    }

    public function cancel(Engagement $engagement, string $reason, Actor $actor): void
    {
        DB::transaction(function () use ($engagement, $reason, $actor) {
            $engagement->forceFill(['cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 500)])->save();
            SigningEnvelope::where('engagement_id', $engagement->id)->whereIn('status', ['draft', 'sent'])
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Engagement cancelled']);
            $this->audit->record($engagement, 'engagement.cancelled', $actor, $engagement, ['reason' => $reason]);
        });

        $this->states->sync($engagement, $actor);
        $this->notifyOthers($engagement, null, 'engagement_cancelled', 'An engagement was cancelled', route('engagements.show', $engagement));
    }

    /** Close the engagement as "no agreement" because a party declined. */
    public function decline(Engagement $engagement, EngagementParticipant $by, string $reason, Actor $actor): void
    {
        DB::transaction(function () use ($engagement, $by, $reason, $actor) {
            $engagement->forceFill(['declined_at' => now(), 'decline_reason' => mb_substr($reason, 0, 500)])->save();
            SigningEnvelope::where('engagement_id', $engagement->id)->whereIn('status', ['draft', 'sent'])
                ->update(['status' => 'declined', 'declined_at' => now()]);
            $this->audit->record($engagement, 'engagement.declined', $actor, $engagement, ['by' => $by->email, 'reason' => $reason]);
        });

        $this->states->sync($engagement, $actor);
        $this->notifyOthers($engagement, $by, 'engagement_declined', $by->name.' declined the engagement', route('engagements.show', $engagement));
    }

    /** Called by the scheduler for engagements past their end date that were never completed. */
    public function expire(Engagement $engagement): void
    {
        if (! $engagement->isOpen()) {
            return;
        }

        DB::transaction(function () use ($engagement) {
            $engagement->forceFill(['expired_at' => now()])->save();
            SigningEnvelope::where('engagement_id', $engagement->id)->whereIn('status', ['draft', 'sent'])
                ->update(['status' => 'expired']);
            $this->audit->record($engagement, 'engagement.expired', Actor::system('scheduler'), $engagement);
        });

        $this->states->sync($engagement, Actor::system('scheduler'));
        $this->notifyOthers($engagement, null, 'engagement_expired', 'An engagement expired before it was signed', route('engagements.show', $engagement));
    }
}
