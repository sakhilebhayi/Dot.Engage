<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOperatorFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_platform_operator_defaults_to_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->fresh()->is_platform_operator);
    }

    public function test_is_platform_operator_cannot_be_set_via_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_platform_operator' => true,
        ]);

        $this->assertFalse($user->fresh()->is_platform_operator);
    }

    public function test_is_platform_operator_casts_to_boolean(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_operator' => true])->save();

        $this->assertIsBool($user->fresh()->is_platform_operator);
        $this->assertTrue($user->fresh()->is_platform_operator);
    }
}
