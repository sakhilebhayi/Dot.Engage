<?php

namespace Tests\Feature;

use App\Events\ContractSigned;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // POST /api/signatures
    // -----------------------------------------------------------------------

    public function test_authenticated_team_member_can_submit_valid_signature(): void
    {
        Storage::fake('signatures');
        Event::fake([ContractSigned::class]);

        $owner    = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'file_path'  => 'contracts/test.pdf',
        ]);

        // Minimal valid 1x1 px PNG encoded as base64 data URI.
        $pngBase64 = 'data:image/png;base64,'
            . base64_encode(file_get_contents(base_path('vendor/phpunit/phpunit/phpunit.xsd')) ?: 'fake-image-data');

        // Use a real tiny PNG instead.
        $pixelPng  = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82");
        $dataUri   = 'data:image/png;base64,' . $pixelPng;

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/signatures', [
                'contract_id'    => $contract->id,
                'signature_data' => $dataUri,
            ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['signature_id', 'signed_at']);

        Storage::disk('signatures')->assertExists(
            ContractSignature::first()->signature_image_path
        );
    }

    public function test_unauthenticated_user_cannot_submit_signature(): void
    {
        $owner    = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->postJson('/api/signatures', [
            'contract_id'    => $contract->id,
            'signature_data' => 'data:image/png;base64,abc',
        ])->assertStatus(401);
    }

    public function test_invalid_contract_id_returns_validation_error(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/signatures', [
                'contract_id'    => 99999,
                'signature_data' => 'data:image/png;base64,abc',
            ])->assertStatus(422)
              ->assertJsonValidationErrors(['contract_id']);
    }

    public function test_invalid_base64_format_returns_error(): void
    {
        Storage::fake('signatures');

        $owner    = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/signatures', [
                'contract_id'    => $contract->id,
                'signature_data' => 'not-a-valid-data-uri',
            ])->assertStatus(422);
    }

    public function test_outsider_cannot_sign_contract(): void
    {
        Storage::fake('signatures');

        $owner    = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $pixelPng = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82");

        // Contract::findOrFail() inside SignatureController now runs through
        // HasTeamScope, so an outsider's currentTeam never matches this
        // contract's team_id -- the lookup itself fails before the Gate
        // check runs, so this is a 404 (not found) rather than a 403
        // (found but forbidden).
        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/signatures', [
                'contract_id'    => $contract->id,
                'signature_data' => 'data:image/png;base64,' . $pixelPng,
            ])->assertStatus(404);
    }

    public function test_all_signatures_marks_contract_as_signed_and_dispatches_event(): void
    {
        Storage::fake('signatures');
        Event::fake([ContractSigned::class]);

        // Single-member team: owner is the only member, so one signature suffices.
        $owner    = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $pixelPng = base64_encode("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82");

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/signatures', [
                'contract_id'    => $contract->id,
                'signature_data' => 'data:image/png;base64,' . $pixelPng,
            ])->assertStatus(201);

        $this->assertSame('signed', $contract->fresh()->status);
        Event::assertDispatched(ContractSigned::class);
    }

    // -----------------------------------------------------------------------
    // GET /api/contracts/{contract}/pdf
    // -----------------------------------------------------------------------

    public function test_team_member_can_download_contract_pdf(): void
    {
        $disk = Storage::fake('contracts');

        $owner    = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'file_path'  => 'test-contract.pdf',
        ]);

        $disk->put('test-contract.pdf', '%PDF-1.4 fake pdf content');

        $this->actingAs($owner, 'sanctum')
            ->get("/api/contracts/{$contract->id}/pdf")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_outsider_cannot_download_contract_pdf(): void
    {
        Storage::fake('contracts');

        $owner    = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id'    => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'file_path'  => 'test.pdf',
        ]);

        // ContractPdfController resolves {contract} via implicit route-model
        // binding, which now runs through HasTeamScope -- an outsider's
        // currentTeam never matches, so binding itself fails before the
        // Gate::authorize('view', ...) call is reached: 404, not 403.
        $this->actingAs($outsider, 'sanctum')
            ->get("/api/contracts/{$contract->id}/pdf")
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------------
    // POST /api/video/token
    // -----------------------------------------------------------------------

    public function test_team_member_can_get_video_token_for_active_session(): void
    {
        $owner   = User::factory()->withPersonalTeam()->create();
        $session = \App\Models\VideoSession::create([
            'team_id'      => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id'      => \Illuminate\Support\Str::uuid(),
            'status'       => 'active',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/video/token', ['room_id' => $session->room_id])
            ->assertStatus(200)
            ->assertJsonStructure(['channel', 'room_id', 'reverb', 'user']);
    }

    public function test_video_token_requires_valid_room_id(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/video/token', ['room_id' => 'nonexistent-room'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['room_id']);
    }
}
