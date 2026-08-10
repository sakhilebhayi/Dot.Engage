<?php

namespace Tests\Feature;

use App\Events\ContractSigned;
use App\Jobs\GenerateSignedContractPdf;
use App\Livewire\Contracts\ContractViewer;
use App\Livewire\Contracts\InviteExternalSigner;
use App\Models\Contract;
use App\Models\ContractExternalSigner;
use App\Models\User;
use App\Notifications\ExternalSignatureRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class ExternalSigningTest extends TestCase
{
    use RefreshDatabase;

    private function pixelPngDataUri(): string
    {
        $pixelPng = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82");

        return 'data:image/png;base64,'.$pixelPng;
    }

    // -----------------------------------------------------------------------
    // Inviting an external signer
    // -----------------------------------------------------------------------

    public function test_contract_admin_can_invite_an_external_signer(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'Jane Client')
            ->set('email', 'jane@client.example')
            ->call('invite')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('contract_external_signers', [
            'contract_id' => $contract->id,
            'email' => 'jane@client.example',
            'status' => 'pending',
        ]);

        Notification::assertSentOnDemand(ExternalSignatureRequestNotification::class);
    }

    public function test_non_admin_team_member_cannot_invite_an_external_signer(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($member)
            ->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'Jane Client')
            ->set('email', 'jane@client.example')
            ->call('invite')
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Viewing the guest signing page
    // -----------------------------------------------------------------------

    public function test_external_signer_can_view_contract_via_signed_link(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Jane Client',
            'email' => 'jane@client.example',
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ]);

        $url = URL::temporarySignedRoute('external.contracts.show', $signer->expires_at, ['signer' => $signer->id]);

        $this->get($url)->assertOk()->assertSee('Jane Client');

        $this->assertSame('viewed', $signer->fresh()->status);
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Jane Client',
            'email' => 'jane@client.example',
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ]);

        $this->get("/sign/{$signer->id}")->assertForbidden();
    }

    public function test_expired_signing_link_is_rejected_even_with_a_valid_signature(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Jane Client',
            'email' => 'jane@client.example',
            'status' => 'pending',
            'expires_at' => now()->addMinute(),
        ]);

        $url = URL::temporarySignedRoute('external.contracts.show', $signer->expires_at, ['signer' => $signer->id]);

        $this->travel(2)->minutes();

        // The signature itself is now expired (temporarySignedRoute bakes
        // the expiry into the signature), so this 403s via the `signed`
        // middleware before ever reaching the controller's own isExpired() check.
        $this->get($url)->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Signing
    // -----------------------------------------------------------------------

    public function test_external_signer_can_sign_the_contract(): void
    {
        Storage::fake('signatures');
        Event::fake([ContractSigned::class]);
        Queue::fake();

        // Single-member team, so the external signer is the only one left.
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        // Invite the external signer first, so the required-signature count
        // (1 team member + 1 external signer) is set before anyone signs.
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Jane Client',
            'email' => 'jane@client.example',
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ]);

        // Owner (the sole team member) signs first -- not enough on its own
        // now that an external signer is also required.
        $this->actingAs($owner, 'sanctum')->postJson('/api/signatures', [
            'contract_id' => $contract->id,
            'signature_data' => $this->pixelPngDataUri(),
        ])->assertStatus(201);

        $this->assertSame('pending', $contract->fresh()->status);

        $signUrl = URL::temporarySignedRoute('external.contracts.sign', $signer->expires_at, ['signer' => $signer->id]);

        $this->post($signUrl, ['signature_data' => $this->pixelPngDataUri()])
            ->assertRedirect();

        $this->assertSame('signed', $signer->fresh()->status);
        $this->assertDatabaseHas('contract_signatures', [
            'contract_external_signer_id' => $signer->id,
            'signer_name' => 'Jane Client',
            'signer_email' => 'jane@client.example',
        ]);

        // Both the team member and the external signer have now signed --
        // the contract is fully signed.
        $this->assertSame('signed', $contract->fresh()->status);
        Event::assertDispatched(ContractSigned::class);
        Queue::assertPushed(GenerateSignedContractPdf::class);
    }

    public function test_external_signer_cannot_sign_twice(): void
    {
        Storage::fake('signatures');

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Jane Client',
            'email' => 'jane@client.example',
            'status' => 'signed',
            'signed_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);

        $signUrl = URL::temporarySignedRoute('external.contracts.sign', $signer->expires_at, ['signer' => $signer->id]);

        $this->post($signUrl, ['signature_data' => $this->pixelPngDataUri()])
            ->assertRedirect();

        $this->assertSame(0, $contract->signatures()->count());
    }

    // -----------------------------------------------------------------------
    // Contract viewer doesn't break on external signatures
    // -----------------------------------------------------------------------

    public function test_contract_viewer_shows_external_signer_name_without_a_user(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $signer = ContractExternalSigner::create([
            'contract_id' => $contract->id,
            'invited_by' => $owner->id,
            'name' => 'Jane Client',
            'email' => 'jane@client.example',
            'status' => 'signed',
            'signed_at' => now(),
            'expires_at' => now()->addDays(14),
        ]);
        $contract->signatures()->create([
            'contract_external_signer_id' => $signer->id,
            'signer_name' => 'Jane Client',
            'signer_email' => 'jane@client.example',
            'signature_image_path' => 'sig.png',
            'signed_at' => now(),
        ]);

        Livewire::actingAs($owner)
            ->test(ContractViewer::class, ['contractId' => $contract->id])
            ->assertSee('Jane Client')
            ->assertSee('external');
    }
}
