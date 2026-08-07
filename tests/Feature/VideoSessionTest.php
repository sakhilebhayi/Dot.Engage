<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoSession;
use App\Policies\VideoSessionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VideoSessionTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Policy: view
    // -----------------------------------------------------------------------

    public function test_team_member_can_view_video_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertTrue($policy->view($owner, $session));
    }

    public function test_outsider_cannot_view_video_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertFalse($policy->view($outsider, $session));
    }

    // -----------------------------------------------------------------------
    // Policy: join
    // -----------------------------------------------------------------------

    public function test_team_member_can_join_active_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertTrue($policy->join($member, $session));
    }

    public function test_team_member_cannot_join_ended_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'ended',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertFalse($policy->join($owner, $session));
    }

    public function test_outsider_cannot_join_active_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertFalse($policy->join($outsider, $session));
    }

    // -----------------------------------------------------------------------
    // Policy: update / delete
    // -----------------------------------------------------------------------

    public function test_initiator_can_update_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertTrue($policy->update($owner, $session));
    }

    public function test_regular_member_cannot_update_session(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
        ]);

        $policy = new VideoSessionPolicy;

        $this->assertFalse($policy->update($member, $session));
    }

    // -----------------------------------------------------------------------
    // Model
    // -----------------------------------------------------------------------

    public function test_video_session_belongs_to_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'waiting',
        ]);

        $this->assertTrue($session->team->is($owner->currentTeam));
    }

    public function test_video_session_has_initiator(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'waiting',
        ]);

        $this->assertTrue($session->initiator->is($owner));
    }
}
