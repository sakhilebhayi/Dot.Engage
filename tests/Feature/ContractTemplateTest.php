<?php

namespace Tests\Feature;

use App\Livewire\Contracts\ContractWizard;
use App\Livewire\Contracts\SaveAsTemplate;
use App\Livewire\Contracts\TemplateList;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContractTemplateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Saving a contract as a template
    // -----------------------------------------------------------------------

    public function test_contract_creator_can_save_it_as_a_template(): void
    {
        Storage::fake('contracts');
        Storage::disk('contracts')->put('original.pdf', 'fake pdf content');

        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'file_path' => 'original.pdf',
            'description' => 'Standard NDA',
            'expires_at' => now()->addDays(30),
        ]);

        Livewire::actingAs($owner)
            ->test(SaveAsTemplate::class)
            ->call('open', $contract->id)
            ->set('title', 'NDA Template')
            ->call('save')
            ->assertHasNoErrors();

        $template = ContractTemplate::where('title', 'NDA Template')->first();
        $this->assertNotNull($template);
        $this->assertSame($owner->currentTeam->id, $template->team_id);
        $this->assertSame('Standard NDA', $template->description);
        $this->assertEqualsWithDelta(30, $template->expires_in_days, 1);
        $this->assertNotNull($template->file_path);
        Storage::disk('contracts')->assertExists($template->file_path);
    }

    public function test_non_team_member_cannot_save_a_template_from_someone_elses_contract(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        // Contract carries HasTeamScope, so an outsider's Contract::findOrFail()
        // inside SaveAsTemplate::open() never finds the row in the first
        // place -- a 404-shaped failure, not a 403, matching the same
        // scope-before-Gate pattern documented for SignatureController.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($outsider)
            ->test(SaveAsTemplate::class)
            ->call('open', $contract->id);
    }

    // -----------------------------------------------------------------------
    // Listing / deleting templates
    // -----------------------------------------------------------------------

    public function test_team_member_only_sees_their_own_teams_templates(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();

        ContractTemplate::factory()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id, 'title' => 'Mine']);
        ContractTemplate::factory()->create(['team_id' => $other->currentTeam->id, 'created_by' => $other->id, 'title' => 'Not Mine']);

        Livewire::actingAs($owner)
            ->test(TemplateList::class)
            ->assertSee('Mine')
            ->assertDontSee('Not Mine');
    }

    public function test_template_creator_can_delete_it(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $template = ContractTemplate::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(TemplateList::class)
            ->call('delete', $template->id);

        $this->assertNull(ContractTemplate::find($template->id));
    }

    public function test_non_creator_non_admin_cannot_delete_a_template(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $template = ContractTemplate::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($member)
            ->test(TemplateList::class)
            ->call('delete', $template->id)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Creating a contract from a template
    // -----------------------------------------------------------------------

    public function test_wizard_prefills_fields_from_a_template(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $template = ContractTemplate::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'title' => 'MSA Template',
            'description' => 'Master Services Agreement boilerplate',
            'expires_in_days' => 21,
        ]);

        Livewire::actingAs($owner)
            ->test(ContractWizard::class, ['templateId' => $template->id])
            ->assertSet('title', 'MSA Template')
            ->assertSet('description', 'Master Services Agreement boilerplate')
            ->assertSet('expiresAt', now()->addDays(21)->format('Y-m-d'));
    }

    public function test_creating_from_a_template_copies_its_file_when_none_is_uploaded(): void
    {
        Storage::fake('contracts');
        Storage::disk('contracts')->put('templates/original.pdf', 'template pdf content');

        $owner = User::factory()->withPersonalTeam()->create();
        $template = ContractTemplate::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
            'file_path' => 'templates/original.pdf',
        ]);

        Livewire::actingAs($owner)
            ->test(ContractWizard::class, ['templateId' => $template->id])
            ->set('title', 'New Deal')
            ->call('save');

        $contract = Contract::where('title', 'New Deal')->first();
        $this->assertNotNull($contract);
        $this->assertNotNull($contract->file_path);
        $this->assertNotSame($template->file_path, $contract->file_path);
        Storage::disk('contracts')->assertExists($contract->file_path);
    }

    public function test_outsider_cannot_create_a_contract_from_someone_elses_template(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $template = ContractTemplate::factory()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        // ContractTemplate also carries HasTeamScope -- same 404-not-403
        // shape as above.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($outsider)
            ->test(ContractWizard::class, ['templateId' => $template->id]);
    }
}
