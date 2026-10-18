<?php

namespace Tests\Feature;

use App\Livewire\Contracts\ShareModal;
use App\Livewire\Notifications\NotificationTray;
use App\Models\Contract;
use App\Models\User;
use App\Notifications\ContractSharedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContractSharedNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: User, 2: Contract}
     */
    private function ownerMemberAndContract(): array
    {
        $owner = User::factory()->withPersonalTeam()->create(['name' => 'Olivia Owner']);
        $member = User::factory()->create(['name' => 'Mark Member']);
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'title' => 'Master Services Agreement',
        ]);

        return [$owner, $member, $contract];
    }

    public function test_sharing_a_contract_stores_a_notification_with_the_sharer_id_and_name(): void
    {
        [$owner, $member, $contract] = $this->ownerMemberAndContract();

        Livewire::actingAs($owner)
            ->test(ShareModal::class, ['contractId' => $contract->id])
            ->set('selectedUsers', [$member->id])
            ->call('share');

        $notification = $member->fresh()->notifications()->sole();

        $this->assertSame(ContractSharedNotification::class, $notification->type);
        $this->assertSame('contract_shared', $notification->data['type']);
        $this->assertSame($contract->id, $notification->data['contract_id']);
        $this->assertSame('Master Services Agreement', $notification->data['contract_title']);
        $this->assertSame($owner->id, $notification->data['shared_by_id']);
        $this->assertSame('Olivia Owner', $notification->data['shared_by_name']);
    }

    public function test_the_notification_renders_for_the_recipient_with_the_sharer_name(): void
    {
        [$owner, $member, $contract] = $this->ownerMemberAndContract();

        Livewire::actingAs($owner)
            ->test(ShareModal::class, ['contractId' => $contract->id])
            ->set('selectedUsers', [$member->id])
            ->call('share');

        Livewire::actingAs($member)
            ->test(NotificationTray::class)
            ->assertSee('Olivia Owner shared Master Services Agreement with you')
            ->assertDontSee('New notification');
    }

    public function test_the_mail_names_the_sharer(): void
    {
        [$owner, $member, $contract] = $this->ownerMemberAndContract();

        $mail = (new ContractSharedNotification($contract))->toMail($member);

        $this->assertSame('Olivia Owner shared a contract with you', $mail->subject);
        $this->assertStringContainsString('Olivia Owner has shared the contract **Master Services Agreement**', implode("\n", $mail->introLines));
    }

    public function test_a_missing_sharer_is_handled_without_error(): void
    {
        [$owner, $member, $contract] = $this->ownerMemberAndContract();

        // Simulates a creator that can no longer be resolved.
        $contract->setRelation('creator', null);
        $notification = new ContractSharedNotification($contract);

        $payload = $notification->toArray($member);

        $this->assertSame($owner->id, $payload['shared_by_id']);
        $this->assertNull($payload['shared_by_name']);
        $this->assertSame('Someone shared Master Services Agreement with you', $payload['message']);
        $this->assertSame('Someone shared a contract with you', $notification->toMail($member)->subject);
    }
}
