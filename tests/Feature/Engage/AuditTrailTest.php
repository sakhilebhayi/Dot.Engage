<?php

namespace Tests\Feature\Engage;

use App\Models\EngagementAuditEvent;
use App\Services\Engage\AuditTrail;
use App\Support\Engage\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    public function test_creating_an_engagement_writes_the_first_event(): void
    {
        $engagement = $this->makeEngagement();

        $events = EngagementAuditEvent::where('engagement_id', $engagement->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame('engagement.created', $events[0]->event);
        $this->assertSame(AuditTrail::GENESIS, $events[0]->prev_hash);
        $this->assertSame($engagement->reference, $events[0]->data['reference']);
    }

    public function test_events_chain_and_verify(): void
    {
        $engagement = $this->makeEngagement();
        $trail = app(AuditTrail::class);

        $trail->record($engagement, 'document.uploaded', Actor::user($engagement->owner), null, ['name' => 'msa.pdf']);
        $trail->record($engagement, 'comment.added', Actor::system('test'), null, ['b' => 2, 'a' => 1]);

        $this->assertSame(['ok' => true, 'count' => 3, 'broken_at' => null], $trail->verify($engagement));

        $rows = EngagementAuditEvent::where('engagement_id', $engagement->id)->orderBy('id')->get();
        $this->assertSame($rows[0]->hash, $rows[1]->prev_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->prev_hash);
        $this->assertSame($rows[2]->hash, $trail->head($engagement));
    }

    public function test_each_engagement_has_its_own_chain(): void
    {
        $a = $this->makeEngagement();
        $b = $this->makeEngagement($this->makeOwner());

        $this->assertSame(AuditTrail::GENESIS, EngagementAuditEvent::where('engagement_id', $b->id)->first()->prev_hash);
        $this->assertTrue(app(AuditTrail::class)->verify($a)['ok']);
        $this->assertTrue(app(AuditTrail::class)->verify($b)['ok']);
    }

    public function test_the_database_refuses_to_change_an_event(): void
    {
        $engagement = $this->makeEngagement();
        $event = EngagementAuditEvent::where('engagement_id', $engagement->id)->first();

        $this->expectException(QueryException::class);
        DB::table('engagement_audit_events')->where('id', $event->id)->update(['event' => 'engagement.nothing_happened']);
    }

    public function test_the_database_refuses_to_delete_an_event(): void
    {
        $engagement = $this->makeEngagement();

        $this->expectException(QueryException::class);
        DB::table('engagement_audit_events')->where('engagement_id', $engagement->id)->delete();
    }

    public function test_a_row_forged_past_the_triggers_is_detected(): void
    {
        $engagement = $this->makeEngagement();
        $trail = app(AuditTrail::class);
        $trail->record($engagement, 'comment.added', Actor::system('test'));

        // Someone with raw database access drops the triggers and rewrites history.
        DB::unprepared('DROP TRIGGER engagement_audit_no_update');
        DB::table('engagement_audit_events')->where('engagement_id', $engagement->id)->orderBy('id')->limit(1)
            ->update(['actor_label' => 'someone else']);

        $result = $trail->verify($engagement);
        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['broken_at']);
    }

    public function test_a_removed_event_breaks_the_chain(): void
    {
        $engagement = $this->makeEngagement();
        $trail = app(AuditTrail::class);
        $trail->record($engagement, 'a', Actor::system('t'));
        $middle = $trail->record($engagement, 'b', Actor::system('t'));
        $trail->record($engagement, 'c', Actor::system('t'));

        DB::unprepared('DROP TRIGGER engagement_audit_no_delete');
        DB::table('engagement_audit_events')->where('id', $middle->id)->delete();

        $this->assertFalse($trail->verify($engagement)['ok']);
    }
}
