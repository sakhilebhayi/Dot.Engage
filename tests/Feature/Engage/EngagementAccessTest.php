<?php

namespace Tests\Feature\Engage;

use App\Enums\ParticipantRole;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Support\Engage\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class EngagementAccessTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    public function test_the_owner_may_do_everything_an_owner_does(): void
    {
        $engagement = $this->makeEngagement();
        $access = EngagementAccess::forUser($engagement, $engagement->owner);

        foreach (['view', 'comment', 'manage_participants', 'send_signing', 'cancel', 'view_audit'] as $ability) {
            $this->assertTrue($access->can($ability), $ability);
        }
        $this->assertFalse($access->can('sign'), 'signing is a capability, not a perk of owning');
        $this->assertFalse($access->can('approve'));
    }

    public function test_a_stranger_has_no_access(): void
    {
        $engagement = $this->makeEngagement();

        $this->assertNull(EngagementAccess::forUser($engagement, $this->makeOwner()));
    }

    public function test_an_editor_manages_documents_but_cannot_cancel_or_invite(): void
    {
        $engagement = $this->makeEngagement();
        $editor = $this->addTeamMember($engagement->team, 'editor');
        $access = EngagementAccess::forUser($engagement, $editor);

        $this->assertTrue($access->can('manage_documents'));
        $this->assertTrue($access->can('comment'));
        $this->assertFalse($access->can('cancel'));
        $this->assertFalse($access->can('manage_participants'));
    }

    public function test_a_team_viewer_can_look_and_nothing_else(): void
    {
        $engagement = $this->makeEngagement();
        $viewer = $this->addTeamMember($engagement->team, 'viewer');
        $access = EngagementAccess::forUser($engagement, $viewer);

        $this->assertTrue($access->can('view'));
        $this->assertFalse($access->can('comment'));
        $this->assertFalse($access->can('propose'));
    }

    public function test_an_outside_party_gets_participant_rights_and_signing_only_with_the_capability(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement, 'a@b.example', ['sign']);
        $access = EngagementAccess::forParticipant($engagement, $this->verifyParticipant($participant));

        $this->assertTrue($access->can('view'));
        $this->assertTrue($access->can('comment'));
        $this->assertTrue($access->can('sign'));
        $this->assertFalse($access->can('approve'));
        $this->assertFalse($access->can('manage_documents'));
        $this->assertFalse($access->can('cancel'));
        $this->assertFalse($access->can('view_audit'));
    }

    public function test_an_external_reviewer_role_can_review_amendments_but_not_manage_people(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement, 'lawyer@firm.example', ['approve'], ParticipantRole::Reviewer);
        $access = EngagementAccess::forParticipant($engagement, $this->verifyParticipant($participant));

        $this->assertTrue($access->can('review_amendment'));
        $this->assertTrue($access->can('approve'));
        $this->assertFalse($access->can('manage_participants'));
    }

    public function test_a_closed_engagement_is_read_only_for_everyone(): void
    {
        $engagement = $this->makeEngagement();
        app(EngagementService::class)->cancel($engagement, 'No longer needed', Actor::user($engagement->owner));
        $access = EngagementAccess::forUser($engagement->fresh(), $engagement->owner);

        $this->assertTrue($access->can('view'));
        $this->assertFalse($access->can('comment'));
        $this->assertFalse($access->can('manage_participants'));
    }

    public function test_team_member_is_linked_not_emailed_when_added_to_the_host_party(): void
    {
        $engagement = $this->makeEngagement();
        $colleague = User::factory()->create();
        $engagement->team->users()->attach($colleague, ['role' => 'editor']);
        $host = $engagement->parties()->where('is_host', true)->first();

        Notification::fake();
        $participant = app(EngagementService::class)->addParticipant(
            $engagement, $host, $colleague->name, $colleague->email, ParticipantRole::Manager, ['approve'], true,
            Actor::user($engagement->owner), $engagement->owner,
        );

        $this->assertSame($colleague->id, $participant->user_id);
        $this->assertNotNull($participant->accepted_at);
        $this->assertNull($participant->access_token_hash);
        Notification::assertSentTo($colleague, EngagementNotice::class);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $engagement = $this->makeEngagement();
        $this->inviteExternal($engagement, 'same@x.example');

        $this->expectException(\InvalidArgumentException::class);
        $this->inviteExternal($engagement, 'same@x.example');
    }
}
