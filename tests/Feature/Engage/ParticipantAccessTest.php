<?php

namespace Tests\Feature\Engage;

use App\Enums\EngagementState;
use App\Models\EngagementAuditEvent;
use App\Notifications\VerificationCodeNotification;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\EngagementService;
use App\Services\Engage\EngagementStateMachine;
use App\Services\Engage\ParticipantAccess;
use App\Support\Engage\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class ParticipantAccessTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private function request(): Request
    {
        $request = Request::create('/join', 'POST');
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    public function test_an_invited_party_gets_a_token_that_is_stored_only_as_a_hash(): void
    {
        $engagement = $this->makeEngagement();
        [$participant, $token] = $this->inviteExternal($engagement);

        $this->assertSame(64, strlen($token));
        $this->assertNotSame($token, $participant->access_token_hash);
        $this->assertSame(hash('sha256', $token), $participant->access_token_hash);
        $this->assertTrue($participant->token_expires_at->isFuture());
        $this->assertSame($participant->id, app(ParticipantAccess::class)->findByToken($token)->id);
        $this->assertNull(app(ParticipantAccess::class)->findByToken(str_repeat('a', 64)));
        $this->assertNull(app(ParticipantAccess::class)->findByToken('short'));
    }

    public function test_the_state_moves_from_draft_to_invitation_sent_on_invitation(): void
    {
        $engagement = $this->makeEngagement();
        $this->assertSame(EngagementState::Draft, $engagement->state);

        $this->inviteExternal($engagement);

        $this->assertSame(EngagementState::InvitationSent, $engagement->fresh()->state);
    }

    public function test_the_code_flow_opens_access_and_marks_the_party_as_joined(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $access = app(ParticipantAccess::class);

        Notification::fake();
        $this->assertSame(0, $access->sendCode($participant, $engagement));

        $code = null;
        Notification::assertSentOnDemand(VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $request = $this->request();
        $this->assertSame('ok', $access->verifyCode($participant->fresh(), $engagement, $code, $request));

        $fresh = $participant->fresh();
        $this->assertNotNull($fresh->verified_at);
        $this->assertNotNull($fresh->accepted_at);
        $this->assertNull($fresh->otp_hash);
        $this->assertSame([$engagement->id => $participant->id], $request->session()->get(EngagementAccess::SESSION_KEY));
        $this->assertTrue(EngagementAuditEvent::where('engagement_id', $engagement->id)->where('event', 'participant.joined')->exists());
        $this->assertSame(EngagementState::InDiscussion, app(EngagementStateMachine::class)->sync($engagement));
    }

    public function test_a_wrong_code_is_refused_and_five_wrong_codes_lock_the_attempt(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $access = app(ParticipantAccess::class);
        Notification::fake();
        $access->sendCode($participant, $engagement);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('wrong', $access->verifyCode($participant->fresh(), $engagement, '000000', $this->request()));
        }
        $this->assertSame('locked', $access->verifyCode($participant->fresh(), $engagement, '000000', $this->request()));
        $this->assertNull($participant->fresh()->verified_at);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $access = app(ParticipantAccess::class);
        Notification::fake();
        $access->sendCode($participant, $engagement);

        $this->travel(11)->minutes();

        $this->assertSame('expired', $access->verifyCode($participant->fresh(), $engagement, '123456', $this->request()));
    }

    public function test_a_code_cannot_be_requested_again_straight_away(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $access = app(ParticipantAccess::class);
        Notification::fake();

        $this->assertSame(0, $access->sendCode($participant, $engagement));
        $this->assertGreaterThan(0, $access->sendCode($participant->fresh(), $engagement));
        Notification::assertSentOnDemandTimes(VerificationCodeNotification::class, 1);
    }

    public function test_a_code_works_once(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $access = app(ParticipantAccess::class);
        Notification::fake();
        $access->sendCode($participant, $engagement);
        $code = null;
        Notification::assertSentOnDemand(VerificationCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->assertSame('ok', $access->verifyCode($participant->fresh(), $engagement, $code, $this->request()));
        $this->assertSame('none', $access->verifyCode($participant->fresh(), $engagement, $code, $this->request()));
    }

    public function test_a_revoked_participant_loses_the_link_and_the_session(): void
    {
        $engagement = $this->makeEngagement();
        [$participant, $token] = $this->inviteExternal($engagement);
        $participant = $this->verifyParticipant($participant);

        $this->assertNotNull(EngagementAccess::forParticipant($engagement, $participant));

        app(EngagementService::class)->revokeParticipant($engagement, $participant, Actor::user($engagement->owner));

        $this->assertNull(EngagementAccess::forParticipant($engagement, $participant->fresh()));
        $this->assertFalse(app(ParticipantAccess::class)->tokenUsable(app(ParticipantAccess::class)->findByToken($token)));
    }

    public function test_an_expired_invitation_gives_no_access(): void
    {
        $engagement = $this->makeEngagement();
        [$participant] = $this->inviteExternal($engagement);
        $participant = $this->verifyParticipant($participant);

        $this->travel(15)->days();

        $this->assertNull(EngagementAccess::forParticipant($engagement, $participant->fresh()));
    }

    public function test_a_participant_cannot_reach_another_engagement(): void
    {
        $one = $this->makeEngagement();
        $two = $this->makeEngagement();
        [$participant] = $this->inviteExternal($one);

        $this->assertNull(EngagementAccess::forParticipant($two, $this->verifyParticipant($participant)));
    }

    public function test_resending_an_invitation_replaces_the_old_link(): void
    {
        $engagement = $this->makeEngagement();
        [$participant, $first] = $this->inviteExternal($engagement);

        Notification::fake();
        app(EngagementService::class)->resendInvitation($engagement, $participant, Actor::user($engagement->owner), 'Owner');

        $this->assertNull(app(ParticipantAccess::class)->findByToken($first));
    }
}
