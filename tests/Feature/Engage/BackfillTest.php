<?php

namespace Tests\Feature\Engage;

use App\Enums\EngagementState;
use App\Models\Contract;
use App\Models\ContractExternalSigner;
use App\Models\ContractSignature;
use App\Models\ContractVersion;
use App\Models\Engagement;
use App\Models\User;
use App\Services\Engage\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillTest extends TestCase
{
    use RefreshDatabase;

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_11_07_100600_backfill_engagements_from_contracts.php'))->up();
    }

    public function test_an_old_signed_contract_becomes_a_completed_engagement_with_its_history_kept(): void
    {
        Storage::fake('contracts');
        Storage::disk('contracts')->put('orig.pdf', '%PDF-1.4 original');
        Storage::disk('contracts')->put('signed/c_v1_signed.pdf', '%PDF-1.4 signed');
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id, 'file_path' => 'orig.pdf', 'status' => 'signed', 'title' => 'Old NDA']);
        ContractVersion::create(['contract_id' => $contract->id, 'created_by' => $owner->id, 'version_number' => 1, 'file_path' => 'signed/c_v1_signed.pdf', 'change_notes' => 'Signed by all parties — 1 Jan']);
        ContractSignature::create(['contract_id' => $contract->id, 'user_id' => $owner->id, 'signed_at' => '2026-05-01 10:00:00', 'signature_image_path' => 's.png']);
        $ext = ContractExternalSigner::create(['contract_id' => $contract->id, 'invited_by' => $owner->id, 'name' => 'Zoë', 'email' => 'zoe@x.example', 'status' => 'signed', 'expires_at' => now()->addDay(), 'signed_at' => '2026-05-02 10:00:00']);

        $this->runBackfill();

        $e = Engagement::withoutGlobalScopes()->sole();
        $this->assertSame(EngagementState::Completed, $e->state);
        $this->assertSame('Old NDA', $e->title);
        $this->assertStringStartsWith('ENG-', $e->reference);
        $this->assertSame($e->id, $contract->fresh()->engagement_id);

        $versions = ContractVersion::where('contract_id', $contract->id)->orderBy('version_number')->get();
        $this->assertSame([1, 2], $versions->pluck('version_number')->all());
        $this->assertSame(['original', 'signed'], $versions->pluck('kind')->all());
        $this->assertSame(hash('sha256', '%PDF-1.4 original'), $versions[0]->sha256);

        $emails = $e->participants()->pluck('email')->all();
        $this->assertContains('zoe@x.example', $emails);
        $this->assertContains($owner->email, $emails);
        $this->assertTrue(app(AuditTrail::class)->verify($e)['ok']);
        $this->assertSame(1, ContractSignature::where('contract_id', $contract->id)->count(), 'the old signatures are untouched');
    }

    public function test_an_unsigned_contract_becomes_a_draft_and_running_twice_changes_nothing(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        Contract::factory()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id, 'file_path' => null, 'status' => 'draft']);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame(1, Engagement::withoutGlobalScopes()->count());
        $this->assertSame(EngagementState::Draft, Engagement::withoutGlobalScopes()->first()->state);
    }
}
