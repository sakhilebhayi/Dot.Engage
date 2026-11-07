<?php

namespace Tests\Feature;

use App\Models\ContractTemplate;
use App\Models\Engagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsEngagements;
use Tests\TestCase;

class ContractTemplateTest extends TestCase
{
    use BuildsEngagements, RefreshDatabase;

    public function test_a_document_can_be_saved_as_a_template_with_its_file_copied(): void
    {
        $engagement = $this->makeEngagement();
        $contract = $this->addDocument($engagement);

        $this->actingAs($engagement->owner)->post(route('contracts.templates.store'), ['document' => $contract->id, 'title' => 'Supply template'])->assertRedirect(route('contracts.templates'));

        $template = ContractTemplate::sole();
        $this->assertSame('Supply template', $template->title);
        $this->assertTrue(Storage::disk('contracts')->exists($template->file_path));
        $this->assertNotSame($contract->file_path, $template->file_path, 'the file is copied, not shared');
    }

    public function test_someone_cannot_save_another_teams_document_as_a_template(): void
    {
        $engagement = $this->makeEngagement();
        $contract = $this->addDocument($engagement);
        $outsider = $this->makeOwner();

        $this->actingAs($outsider)->post(route('contracts.templates.store'), ['document' => $contract->id, 'title' => 'Mine now'])->assertNotFound();
        $this->assertSame(0, ContractTemplate::withoutGlobalScopes()->count());
    }

    public function test_the_list_shows_only_this_teams_templates(): void
    {
        $mine = $this->makeOwner();
        $theirs = $this->makeOwner();
        ContractTemplate::create(['team_id' => $mine->currentTeam->id, 'created_by' => $mine->id, 'title' => 'Our NDA']);
        ContractTemplate::create(['team_id' => $theirs->currentTeam->id, 'created_by' => $theirs->id, 'title' => 'Their secret terms']);

        $this->actingAs($mine)->get(route('contracts.templates'))->assertOk()->assertSee('Our NDA')->assertDontSee('Their secret terms');
    }

    public function test_starting_an_engagement_from_a_template_prefills_it_and_copies_the_document(): void
    {
        $engagement = $this->makeEngagement();
        $source = $this->addDocument($engagement);
        $owner = $engagement->owner;
        $this->actingAs($owner)->post(route('contracts.templates.store'), ['document' => $source->id, 'title' => 'Supply template']);
        $template = ContractTemplate::sole();

        $this->actingAs($owner)->get(route('engagements.create', ['template' => $template->id]))->assertOk()->assertSee('Supply template');

        $this->actingAs($owner)->post(route('engagements.store'), ['title' => 'Second supply deal', 'kind' => 'supplier_agreement', 'counterparty' => 'Beta Foods', 'template' => $template->id])->assertRedirect();

        $created = Engagement::where('title', 'Second supply deal')->sole();
        $doc = $created->contracts()->sole();
        $this->assertSame(1, $doc->versions()->count());
        $this->assertNotSame($template->file_path, $doc->file_path);
    }

    public function test_an_outsider_cannot_start_from_someone_elses_template(): void
    {
        $theirs = $this->makeOwner();
        $template = ContractTemplate::create(['team_id' => $theirs->currentTeam->id, 'created_by' => $theirs->id, 'title' => 'Private']);

        $this->actingAs($this->makeOwner())->get(route('engagements.create', ['template' => $template->id]))->assertNotFound();
    }

    public function test_only_the_creator_or_an_admin_may_delete_a_template(): void
    {
        $owner = $this->makeOwner();
        $editor = $this->addTeamMember($owner->currentTeam, 'editor');
        $template = ContractTemplate::create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id, 'title' => 'Mine']);

        $this->actingAs($editor)->delete(route('contracts.templates.destroy', $template))->assertForbidden();
        $this->actingAs($owner)->delete(route('contracts.templates.destroy', $template))->assertRedirect();
        $this->assertSame(0, ContractTemplate::count());
    }

    public function test_a_viewer_cannot_save_or_use_templates(): void
    {
        $engagement = $this->makeEngagement();
        $contract = $this->addDocument($engagement);
        $viewer = $this->addTeamMember($engagement->team, 'viewer');

        $this->actingAs($viewer)->post(route('contracts.templates.store'), ['document' => $contract->id, 'title' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->get(route('engagements.create'))->assertForbidden();
    }

    public function test_old_contract_addresses_lead_to_the_engagement_and_never_to_other_teams(): void
    {
        $engagement = $this->makeEngagement();
        $contract = $this->addDocument($engagement);

        $this->actingAs($engagement->owner)->get('/contracts/'.$contract->id)->assertRedirect(route('engagements.show', ['engagement' => $engagement, 'doc' => $contract->id]));
        $this->actingAs($this->makeOwner())->get('/contracts/'.$contract->id)->assertNotFound();
        $this->actingAs($engagement->owner)->get('/contracts')->assertRedirect(route('engagements.index'));
        $this->actingAs($engagement->owner)->get('/video')->assertRedirect(route('meetings.index'));
    }

    public function test_an_old_signing_link_explains_it_was_replaced(): void
    {
        $this->get('/sign/12?expires=1&signature=abc')->assertStatus(410)->assertSee('replaced');
        $this->post('/sign/12')->assertStatus(410);
    }
}
