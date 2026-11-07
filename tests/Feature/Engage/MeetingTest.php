<?php

namespace Tests\Feature\Engage;

use App\Models\Engagement;
use App\Models\EngagementParticipant;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\MeetingSignal;
use App\Notifications\EngagementNotice;
use App\Services\Engage\EngagementAccess;
use App\Services\Engage\IceConfig;
use App\Services\Engage\MeetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    private Engagement $engagement;

    private EngagementParticipant $guest;

    private MeetingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->svc = app(MeetingService::class);
        $this->engagement = $this->makeEngagement();
        [$guest] = $this->inviteExternal($this->engagement, 'guest@acme.example', []);
        $this->guest = $this->verifyParticipant($guest);
    }

    private function host(): EngagementAccess
    {
        return EngagementAccess::forUser($this->engagement->fresh(), $this->engagement->owner);
    }

    private function guestAccess(): EngagementAccess
    {
        return EngagementAccess::forParticipant($this->engagement->fresh(), $this->guest->fresh());
    }

    private function newMeeting(): Meeting
    {
        return $this->svc->create($this->engagement->fresh(), $this->host(), 'Clause walk-through', null);
    }

    public function test_an_instant_meeting_starts_waiting_with_a_secret_room_key(): void
    {
        $m = $this->newMeeting();

        $this->assertSame('instant', $m->kind);
        $this->assertSame('scheduled', $m->status);
        $this->assertSame(32, strlen($m->room_key));
        $this->assertTrue($m->waiting_room);
    }

    public function test_the_host_walks_in_and_the_meeting_goes_live(): void
    {
        $m = $this->newMeeting();
        $a = $this->svc->enter($m, $this->host(), 'c1');
        $this->assertSame('admitted', $a->state);
        $this->svc->join($m, $a, $this->host());

        $this->assertSame('live', $m->fresh()->status);
        $this->assertSame('joined', $a->fresh()->state);
        $this->assertTrue($this->engagement->auditEvents()->where('event', 'meeting.started')->exists());
    }

    public function test_a_guest_waits_in_the_waiting_room_until_the_host_admits_them(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());

        $g = $this->svc->enter($m, $this->guestAccess(), 'g');
        $this->assertSame('waiting', $g->state);

        try {
            $this->svc->join($m, $g, $this->guestAccess());
            $this->fail('A waiting guest joined.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('waiting room', $e->getMessage());
        }

        $this->svc->host($m, $this->host(), 'admit', $g);
        $this->assertSame('admitted', $g->fresh()->state);
        $this->svc->join($m, $g->fresh(), $this->guestAccess());
        $this->assertSame('joined', $g->fresh()->state);
    }

    public function test_a_guest_cannot_use_host_controls(): void
    {
        $this->assertFalse($this->guestAccess()->can('host_meeting'));
        $this->assertFalse($this->guestAccess()->can('schedule_meeting'));
    }

    public function test_a_locked_meeting_refuses_new_guests_but_not_people_already_in(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->svc->host($m, $this->host(), 'lock', null);

        $this->expectExceptionMessage('locked');
        $this->svc->enter($m->fresh(), $this->guestAccess(), 'g');
    }

    public function test_a_removed_person_cannot_come_back(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $g = $this->svc->enter($m, $this->guestAccess(), 'g');
        $this->svc->host($m, $this->host(), 'admit', $g);
        $this->svc->join($m, $g->fresh(), $this->guestAccess());

        $this->svc->host($m, $this->host(), 'remove', $g->fresh());
        $this->assertSame('removed', $g->fresh()->state);
        $this->assertTrue(MeetingSignal::where('to_attendee_id', $g->id)->where('payload', 'like', '%removed%')->exists());

        $this->expectExceptionMessage('removed you');
        $this->svc->enter($m->fresh(), $this->guestAccess(), 'g2');
    }

    public function test_the_room_holds_at_most_the_configured_number_of_people(): void
    {
        config(['engage.rtc.max_participants' => 2]);
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $g = $this->svc->enter($m, $this->guestAccess(), 'g');
        $this->svc->host($m, $this->host(), 'admit', $g);
        $this->svc->join($m, $g->fresh(), $this->guestAccess());

        [$third] = $this->inviteExternal($this->engagement, 'third@acme.example', []);
        $thirdAccess = EngagementAccess::forParticipant($this->engagement->fresh(), $this->verifyParticipant($third));
        $t = $this->svc->enter($m, $thirdAccess, 't');
        $this->svc->host($m, $this->host(), 'admit', $t);

        $this->expectExceptionMessage('full');
        $this->svc->join($m, $t->fresh(), $thirdAccess);
    }

    public function test_someone_who_stops_reporting_is_not_shown_as_connected(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->assertTrue($h->fresh()->isPresent());

        $this->travel(40)->seconds();

        $this->assertFalse($h->fresh()->isPresent());
        $snap = $this->svc->snapshot($m->fresh(), $h->fresh(), 0);
        $this->assertSame('lost', $snap['roster'][0]['state']);
    }

    public function test_signals_reach_only_their_recipient_and_only_once(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $g = $this->svc->enter($m, $this->guestAccess(), 'g');
        $this->svc->host($m, $this->host(), 'admit', $g);
        $this->svc->join($m, $g->fresh(), $this->guestAccess());

        $this->svc->signal($m, $h->fresh(), $g->id, 'offer', json_encode(['sdp' => 'x']));

        $forGuest = $this->svc->snapshot($m, $g->fresh(), 0);
        $this->assertCount(1, $forGuest['signals']);
        $this->assertSame('offer', $forGuest['signals'][0]['type']);
        $this->assertCount(0, $this->svc->snapshot($m, $h->fresh(), 0)['signals']);
        $this->assertCount(0, $this->svc->snapshot($m, $g->fresh(), $forGuest['last_id'])['signals']);
    }

    public function test_a_signal_needs_a_joined_sender_and_a_sane_size(): void
    {
        $m = $this->newMeeting();
        $g = $this->svc->enter($m, $this->guestAccess(), 'g');

        try {
            $this->svc->signal($m, $g, null, 'offer', '{}');
            $this->fail('A person outside the call sent a signal.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->expectException(InvalidArgumentException::class);
        $this->svc->signal($m, $h->fresh(), null, 'offer', str_repeat('x', 30000));
    }

    public function test_the_meeting_ends_when_the_last_person_leaves_and_the_link_stops_working(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->svc->leave($m, $h->fresh(), $this->host());

        $this->assertSame('ended', $m->fresh()->status);
        $this->expectExceptionMessage('ended');
        $this->svc->enter($m->fresh(), $this->host(), 'again');
    }

    public function test_the_cleanup_ends_abandoned_meetings_and_prunes_old_signals(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->svc->signal($m, $h->fresh(), null, 'state', '{}');

        $this->travel(30)->minutes();
        $result = $this->svc->cleanUp();

        $this->assertSame(1, $result['ended']);
        $this->assertSame('empty', $m->fresh()->ended_reason);
    }

    public function test_the_host_can_end_it_for_everyone(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->svc->host($m, $this->host(), 'end', null);

        $this->assertSame('ended', $m->fresh()->status);
        $this->assertSame('host_ended', $m->fresh()->ended_reason);
        $this->assertSame('left', $h->fresh()->state);
    }

    public function test_a_scheduled_meeting_invites_everyone_and_can_be_cancelled(): void
    {
        $m = $this->svc->create($this->engagement->fresh(), $this->host(), 'Review', now()->addDay());

        $this->assertSame('scheduled', $m->kind);
        Notification::assertSentOnDemand(EngagementNotice::class);
        $this->svc->cancel($m, $this->host());
        $this->assertSame('cancelled', $m->fresh()->status);
    }

    public function test_the_past_cannot_be_scheduled(): void
    {
        $this->expectExceptionMessage('future');
        $this->svc->create($this->engagement->fresh(), $this->host(), 'Late', now()->subDay());
    }

    public function test_turn_credentials_are_minted_only_when_a_relay_is_configured(): void
    {
        $none = app(IceConfig::class)->forUser('Zoë');
        $this->assertFalse($none['relay']);
        $this->assertCount(1, $none['servers']);

        config(['engage.rtc.turn_urls' => ['turn:turn.example:3478'], 'engage.rtc.turn_secret' => 'secret']);
        $ice = app(IceConfig::class)->forUser('Zoë Nkosi');
        $this->assertTrue($ice['relay']);
        $turn = $ice['servers'][1];
        [$expires] = explode(':', $turn['username']);
        $this->assertGreaterThan(time(), (int) $expires);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'secret', true)), $turn['credential']);
    }

    public function test_attendee_records_survive_for_the_meeting_history(): void
    {
        $m = $this->newMeeting();
        $h = $this->svc->enter($m, $this->host(), 'h');
        $this->svc->join($m, $h, $this->host());
        $this->svc->leave($m, $h->fresh(), $this->host());

        $row = MeetingAttendee::where('meeting_id', $m->id)->first();
        $this->assertNotNull($row->joined_at);
        $this->assertNotNull($row->left_at);
        $this->assertSame(1, $row->join_count);
    }
}
