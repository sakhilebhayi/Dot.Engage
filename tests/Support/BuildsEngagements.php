<?php

namespace Tests\Support;

use App\Enums\EngagementKind;
use App\Enums\ParticipantRole;
use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\Engage\EngagementService;
use App\Support\Engage\Actor;
use Illuminate\Support\Facades\Notification;

trait BuildsEngagements
{
    protected function makeOwner(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function makeEngagement(?User $owner = null, string $counterparty = 'Acme Supplies (Pty) Ltd'): Engagement
    {
        $owner ??= $this->makeOwner();

        return app(EngagementService::class)->create(
            $owner->currentTeam, $owner, 'Supply agreement 2026', EngagementKind::Supplier, 'Annual supply terms', $counterparty,
        );
    }

    /** Invite an outside party and return [participant, plain token] (mail is faked by the caller). */
    protected function inviteExternal(Engagement $engagement, string $email = 'thandi@acme.example', array $capabilities = ['sign'], ParticipantRole $role = ParticipantRole::External): array
    {
        Notification::fake();
        $party = $engagement->parties()->where('is_host', false)->first();
        $actor = Actor::user($engagement->owner);

        $participant = app(EngagementService::class)->addParticipant(
            $engagement, $party, 'Thandi Mokoena', $email, $role, $capabilities, true, $actor, $engagement->owner,
        );

        $token = null;
        Notification::assertSentOnDemand(EngagementNotice::class, function ($notification) use (&$token) {
            $token = basename(parse_url($notification->url, PHP_URL_PATH));

            return true;
        });

        return [$participant->fresh(), $token];
    }

    protected function verifyParticipant(EngagementParticipant $participant): EngagementParticipant
    {
        $participant->forceFill(['verified_at' => now(), 'accepted_at' => $participant->accepted_at ?? now()])->save();

        return $participant->fresh();
    }

    protected function addTeamMember(Team $team, string $role = 'editor'): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user->fresh();
    }
}
