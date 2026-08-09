<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_for_authenticated_user_with_a_current_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_dashboard_displays_team_scoped_stats_and_recent_activity(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $teamId = $user->currentTeam->id;

        Contract::factory()->signed()->create([
            'team_id' => $teamId,
            'created_by' => $user->id,
            'title' => 'Acme Retainer Agreement',
        ]);

        Conversation::factory()->group()->create([
            'team_id' => $teamId,
            'name' => 'Acme Onboarding',
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Acme Retainer Agreement');
        $response->assertSee('Acme Onboarding');
    }
}
