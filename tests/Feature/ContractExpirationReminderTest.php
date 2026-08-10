<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractExternalSigner;
use App\Models\User;
use App\Notifications\ContractExpiringSoonNotification;
use App\Notifications\ExternalSignerExpiringSoonNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ContractExpirationReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminds_team_members_who_have_not_yet_signed(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->addDays(2),
        ]);

        $this->artisan('dotengage:send-expiration-reminders')->assertExitCode(0);

        Notification::assertSentTo([$owner, $member], ContractExpiringSoonNotification::class);
    }

    public function test_does_not_remind_a_team_member_who_already_signed(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->addDays(2),
        ]);
        $contract->signatures()->create([
            'user_id' => $owner->id,
            'signature_image_path' => 'sig.png',
            'signed_at' => now(),
        ]);

        $this->artisan('dotengage:send-expiration-reminders')->assertExitCode(0);

        Notification::assertNotSentTo($owner, ContractExpiringSoonNotification::class);
        Notification::assertSentTo($member, ContractExpiringSoonNotification::class);
    }

    public function test_does_not_remind_for_contracts_outside_the_reminder_window(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('dotengage:send-expiration-reminders --days=3')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_reminds_external_signer_whose_turn_it_is_but_not_a_later_ordered_signer(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->addDays(2),
        ]);
        // Owner already signed, so only the external signers are pending.
        $contract->signatures()->create([
            'user_id' => $owner->id,
            'signature_image_path' => 'sig.png',
            'signed_at' => now(),
        ]);

        $first = ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'First', 'email' => 'first@client.example',
            'status' => 'pending', 'sign_order' => 1, 'expires_at' => now()->addDays(2),
        ]);
        $second = ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'Second', 'email' => 'second@client.example',
            'status' => 'pending', 'sign_order' => 2, 'expires_at' => now()->addDays(2),
        ]);

        $this->artisan('dotengage:send-expiration-reminders')->assertExitCode(0);

        // Only the first (currently signable) external signer is reminded --
        // the second is still waiting its turn.
        Notification::assertSentOnDemand(
            ExternalSignerExpiringSoonNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $first->email
        );
        Notification::assertSentTimes(ExternalSignerExpiringSoonNotification::class, 1);
    }

    public function test_dry_run_sends_no_notifications_and_expires_nothing(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('dotengage:send-expiration-reminders --dry-run')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertSame('pending', $contract->fresh()->status);
    }

    public function test_marks_unsigned_contracts_past_expiry_as_expired(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $expired = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->subDay(),
        ]);
        $stillValid = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => now()->addDays(10),
        ]);

        $this->artisan('dotengage:send-expiration-reminders')->assertExitCode(0);

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('pending', $stillValid->fresh()->status);
    }

    public function test_does_not_expire_a_contract_that_has_no_expiry_date(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'expires_at' => null,
        ]);

        $this->artisan('dotengage:send-expiration-reminders')->assertExitCode(0);

        $this->assertSame('pending', $contract->fresh()->status);
    }
}
