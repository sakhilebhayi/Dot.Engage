<?php

namespace Tests\Feature;

use App\Livewire\Contracts\InviteExternalSigner;
use App\Models\Contract;
use App\Models\ContractExternalSigner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class SequentialSigningOrderTest extends TestCase
{
    use RefreshDatabase;

    private function pixelPngDataUri(): string
    {
        $pixelPng = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82");

        return 'data:image/png;base64,'.$pixelPng;
    }

    // -----------------------------------------------------------------------
    // Inviting with ordering
    // -----------------------------------------------------------------------

    public function test_inviting_with_enforce_order_assigns_sequential_sign_orders(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'First Signer')->set('email', 'first@client.example')
            ->set('enforceOrder', true)
            ->call('invite');

        Livewire::actingAs($owner)
            ->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'Second Signer')->set('email', 'second@client.example')
            ->set('enforceOrder', true)
            ->call('invite');

        $first = ContractExternalSigner::where('email', 'first@client.example')->first();
        $second = ContractExternalSigner::where('email', 'second@client.example')->first();

        $this->assertSame(1, $first->sign_order);
        $this->assertSame(2, $second->sign_order);
    }

    public function test_inviting_without_enforce_order_leaves_sign_order_null(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'Anyone')->set('email', 'anyone@client.example')
            ->call('invite');

        $this->assertNull(ContractExternalSigner::where('email', 'anyone@client.example')->first()->sign_order);
    }

    // -----------------------------------------------------------------------
    // isSignable()
    // -----------------------------------------------------------------------

    public function test_unordered_signer_is_always_signable(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Anyone',
            'email' => 'anyone@client.example',
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertTrue($signer->isSignable());
    }

    public function test_second_ordered_signer_cannot_sign_before_first(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $first = ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'First', 'email' => 'first@client.example',
            'status' => 'pending', 'sign_order' => 1, 'expires_at' => now()->addDays(14),
        ]);
        $second = ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'Second', 'email' => 'second@client.example',
            'status' => 'pending', 'sign_order' => 2, 'expires_at' => now()->addDays(14),
        ]);

        $this->assertTrue($first->isSignable());
        $this->assertFalse($second->isSignable());

        $first->update(['status' => 'signed', 'signed_at' => now()]);

        $this->assertTrue($second->fresh()->isSignable());
    }

    public function test_second_signer_posting_out_of_turn_is_rejected_server_side(): void
    {
        Storage::fake('signatures');

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'First', 'email' => 'first@client.example',
            'status' => 'pending', 'sign_order' => 1, 'expires_at' => now()->addDays(14),
        ]);
        $second = ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'Second', 'email' => 'second@client.example',
            'status' => 'pending', 'sign_order' => 2, 'expires_at' => now()->addDays(14),
        ]);

        $signUrl = URL::temporarySignedRoute('external.contracts.sign', $second->expires_at, ['signer' => $second->id]);

        $this->post($signUrl, ['signature_data' => $this->pixelPngDataUri()])
            ->assertSessionHasErrors('signature_data');

        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(0, $contract->signatures()->count());
    }

    public function test_signing_page_shows_waiting_message_when_not_this_signers_turn(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'First', 'email' => 'first@client.example',
            'status' => 'pending', 'sign_order' => 1, 'expires_at' => now()->addDays(14),
        ]);
        $second = ContractExternalSigner::create([
            'contract_id' => $contract->id, 'invited_by' => $owner->id,
            'name' => 'Second', 'email' => 'second@client.example',
            'status' => 'pending', 'sign_order' => 2, 'expires_at' => now()->addDays(14),
        ]);

        $url = URL::temporarySignedRoute('external.contracts.show', $second->expires_at, ['signer' => $second->id]);

        $this->get($url)->assertOk()->assertSee('not your turn to sign yet');
    }
}
