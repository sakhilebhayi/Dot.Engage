<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Events\ContractShared;
use App\Events\ContractSigned;
use App\Livewire\Chat\ConversationThread;
use App\Livewire\Chat\MessageComposer;
use App\Livewire\Chat\NewConversation;
use App\Livewire\Contracts\ContractList;
use App\Livewire\Contracts\ContractWizard;
use App\Livewire\Contracts\InviteExternalSigner;
use App\Livewire\Contracts\SaveAsTemplate;
use App\Livewire\Contracts\ShareModal;
use App\Livewire\Contracts\SignaturePad;
use App\Livewire\Contracts\TemplateList;
use App\Livewire\Video\InCallSignaturePad;
use App\Livewire\Video\SessionLauncher;
use App\Livewire\Video\SessionRoom;
use App\Models\Contract;
use App\Models\ContractExternalSigner;
use App\Models\ContractSignature;
use App\Models\ContractTemplate;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\DeleteTeamForm;
use Laravel\Jetstream\Http\Livewire\TeamMemberManager;
use Laravel\Jetstream\Http\Livewire\UpdateTeamNameForm;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The read-only "viewer" role (InfoDot viewer -> viewer): the role itself, the InfoDot mapping,
 * and one proof per write-path family that a viewer is refused while editors and admins are not,
 * and that a viewer can still read.
 */
class ViewerRoleTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
    }

    /** A member of the owner's team with the given Jetstream role, with that team as their current one. */
    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->team->users()->attach($user, ['role' => $role]);
        $this->team->unsetRelation('users'); // Jetstream caches the member list on the model
        $user->switchTeam($this->team);

        return $user->fresh();
    }

    private function contract(?User $creator = null, string $status = 'pending'): Contract
    {
        return Contract::factory()->create([
            'team_id' => $this->team->id,
            'created_by' => ($creator ?? $this->owner)->id,
            'status' => $status,
            'file_path' => null,
        ]);
    }

    private function conversationWith(User ...$users): Conversation
    {
        $conversation = Conversation::factory()->direct()->create(['team_id' => $this->team->id]);
        $conversation->participants()->attach(collect($users)->pluck('id')->all());

        return $conversation;
    }

    private function videoSession(string $status = 'active', ?Contract $contract = null): VideoSession
    {
        return VideoSession::create([
            'team_id' => $this->team->id,
            'initiated_by' => $this->owner->id,
            'room_id' => (string) Str::uuid(),
            'status' => $status,
            'contract_id' => $contract?->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // The role and the InfoDot mapping
    // -----------------------------------------------------------------------

    public function test_viewer_is_a_real_jetstream_role_with_read_only_permission(): void
    {
        $viewer = Jetstream::findRole('viewer');

        $this->assertNotNull($viewer);
        $this->assertSame('Viewer', $viewer->name);
        $this->assertSame(['read'], $viewer->permissions);

        $this->assertSame(['create', 'read', 'update', 'delete'], Jetstream::findRole('admin')->permissions);
        $this->assertSame(['read', 'create', 'update'], Jetstream::findRole('editor')->permissions);
    }

    public function test_a_viewer_holds_read_and_nothing_that_writes(): void
    {
        $viewer = $this->member('viewer');

        $this->assertTrue($viewer->hasTeamPermission($this->team, 'read'));
        foreach (['create', 'update', 'delete'] as $permission) {
            $this->assertFalse($viewer->hasTeamPermission($this->team, $permission), "viewer must not hold {$permission}");
        }

        foreach (['editor', 'admin'] as $role) {
            $user = $this->member($role);
            $this->assertTrue($user->hasTeamPermission($this->team, 'create'));
            $this->assertTrue($user->hasTeamPermission($this->team, 'update'));
        }
    }

    #[DataProvider('infodotRoles')]
    public function test_infodot_roles_map_to_local_roles_with_least_privilege(string $infodot, string $local): void
    {
        $this->assertSame($local, SyncTeamsFromInfoDot::localRole($infodot));
        $this->assertNotNull(Jetstream::findRole($local), 'the mapped role must exist in this app');
    }

    /** @return array<string, array{string, string}> */
    public static function infodotRoles(): array
    {
        return [
            'owner' => ['owner', 'admin'],
            'admin' => ['admin', 'admin'],
            'editor' => ['editor', 'editor'],
            'viewer' => ['viewer', 'viewer'],
            'empty' => ['', 'viewer'],
            'unknown' => ['auditor', 'viewer'],
            'case differs' => ['Admin', 'viewer'],
        ];
    }

    // -----------------------------------------------------------------------
    // Reads still work for a viewer
    // -----------------------------------------------------------------------

    public function test_a_viewer_can_read_every_page_and_the_pdf(): void
    {
        Storage::fake('contracts');
        Storage::disk('contracts')->put('a.pdf', 'pdf');
        $viewer = $this->member('viewer');
        $contract = $this->contract();
        $contract->update(['file_path' => 'a.pdf']);
        ContractTemplate::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->owner->id]);
        $conversation = $this->conversationWith($this->owner, $viewer);
        Message::create(['conversation_id' => $conversation->id, 'user_id' => $this->owner->id, 'body' => 'hello viewer', 'type' => 'text']);
        $session = $this->videoSession();

        $this->actingAs($viewer);

        foreach ([
            route('dashboard'),
            route('contracts.index'),
            route('contracts.templates'),
            route('contracts.show', $contract),
            route('chat.index'),
            route('chat.show', $conversation),
            route('video.index'),
            route('video.room', $session->room_id),
            route('notifications.index'),
            route('teams.show', $this->team),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('chat.show', $conversation))->assertSee('hello viewer')->assertSee('read-only');
        $this->getJson(route('api.contracts.pdf', $contract))->assertOk();
        $this->postJson(route('api.video.token'), ['room_id' => $session->room_id])->assertOk();
        $this->getJson(route('api.video.join-url', $session))->assertOk();
    }

    public function test_a_viewer_can_still_join_a_session_and_mark_notifications_read(): void
    {
        $viewer = $this->member('viewer');
        $session = $this->videoSession();

        Livewire::actingAs($viewer)
            ->test(SessionLauncher::class)
            ->set('joinRoomId', $session->room_id)
            ->call('join')
            ->assertRedirect(route('video.room', ['room' => $session->room_id]));
    }

    // -----------------------------------------------------------------------
    // Contracts: create / edit / delete
    // -----------------------------------------------------------------------

    public function test_creating_a_contract_page_is_forbidden_to_a_viewer_and_open_to_editors_and_admins(): void
    {
        $this->actingAs($this->member('viewer'))->get(route('contracts.create'))->assertForbidden();
        $this->actingAs($this->member('editor'))->get(route('contracts.create'))->assertOk();
        $this->actingAs($this->member('admin'))->get(route('contracts.create'))->assertOk();
    }

    public function test_a_viewer_cannot_save_a_new_contract_through_the_wizard(): void
    {
        Storage::fake('contracts');
        $viewer = $this->member('viewer');

        // A viewer never gets a wizard to drive: mounting it is refused.
        Livewire::actingAs($viewer)->test(ContractWizard::class)->assertForbidden();
        $this->assertSame(0, Contract::withoutGlobalScopes()->count());

        // Editors and admins create as before.
        foreach (['editor', 'admin'] as $role) {
            Livewire::actingAs($this->member($role))
                ->test(ContractWizard::class)
                ->set('title', 'Fine '.$role)
                ->call('save')
                ->assertHasNoErrors()
                ->assertRedirect();
        }

        $this->assertSame(2, Contract::withoutGlobalScopes()->count());
    }

    public function test_a_viewer_cannot_edit_a_contract_even_one_they_created(): void
    {
        $viewer = $this->member('viewer');
        $theirs = $this->contract($viewer, 'draft');

        $this->actingAs($viewer)->get(route('contracts.edit', $theirs))->assertForbidden();

        Livewire::actingAs($viewer)->test(ContractWizard::class, ['contractId' => $theirs->id])->assertForbidden();

        $editor = $this->member('editor');
        $mine = $this->contract($editor, 'draft');
        $this->actingAs($editor)->get(route('contracts.edit', $mine))->assertOk();

        $this->actingAs($this->member('admin'))->get(route('contracts.edit', $mine))->assertOk();
    }

    public function test_a_viewer_cannot_delete_a_contract_but_editor_and_admin_can(): void
    {
        $viewer = $this->member('viewer');
        $contract = $this->contract($viewer, 'draft');

        Livewire::actingAs($viewer)->test(ContractList::class)->call('delete', $contract->id)->assertForbidden();
        $this->assertNotSoftDeleted($contract);

        $editor = $this->member('editor');
        $own = $this->contract($editor, 'draft');
        Livewire::actingAs($editor)->test(ContractList::class)->call('delete', $own->id)->assertOk();
        $this->assertSoftDeleted($own);

        $other = $this->contract($editor, 'draft');
        Livewire::actingAs($this->member('admin'))->test(ContractList::class)->call('delete', $other->id)->assertOk();
        $this->assertSoftDeleted($other);
    }

    public function test_the_contract_list_hides_the_new_contract_button_from_a_viewer(): void
    {
        $this->actingAs($this->member('viewer'))->get(route('contracts.index'))->assertDontSee('New Contract');
        $this->actingAs($this->member('editor'))->get(route('contracts.index'))->assertSee('New Contract');
    }

    // -----------------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------------

    public function test_a_viewer_cannot_save_a_contract_as_a_template(): void
    {
        $viewer = $this->member('viewer');
        $contract = $this->contract();

        Livewire::actingAs($viewer)->test(SaveAsTemplate::class)->call('open', $contract->id)->assertForbidden();

        Livewire::actingAs($viewer)->test(SaveAsTemplate::class)
            ->set('contractId', $contract->id)
            ->set('title', 'Sneaky')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, ContractTemplate::withoutGlobalScopes()->count());

        Livewire::actingAs($this->member('editor'))->test(SaveAsTemplate::class)
            ->call('open', $contract->id)->set('title', 'Fine')->call('save')->assertHasNoErrors();
        $this->assertSame(1, ContractTemplate::withoutGlobalScopes()->count());
    }

    public function test_a_viewer_cannot_delete_a_template_but_editor_and_admin_can(): void
    {
        $viewer = $this->member('viewer');
        $template = ContractTemplate::factory()->create(['team_id' => $this->team->id, 'created_by' => $viewer->id]);

        Livewire::actingAs($viewer)->test(TemplateList::class)->call('delete', $template->id)->assertForbidden();
        $this->assertModelExists($template);

        $editor = $this->member('editor');
        $own = ContractTemplate::factory()->create(['team_id' => $this->team->id, 'created_by' => $editor->id]);
        Livewire::actingAs($editor)->test(TemplateList::class)->call('delete', $own->id)->assertOk();
        $this->assertModelMissing($own);

        Livewire::actingAs($this->member('admin'))->test(TemplateList::class)->call('delete', $template->id)->assertOk();
        $this->assertModelMissing($template);
    }

    public function test_a_viewer_cannot_start_a_contract_from_a_template(): void
    {
        $template = ContractTemplate::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->owner->id]);

        $this->actingAs($this->member('viewer'))->get(route('contracts.create', ['template' => $template->id]))->assertForbidden();
        $this->actingAs($this->member('editor'))->get(route('contracts.create', ['template' => $template->id]))->assertOk();
    }

    // -----------------------------------------------------------------------
    // Sharing and sending (internal notifications, external signing invitations)
    // -----------------------------------------------------------------------

    public function test_a_viewer_cannot_share_a_contract_with_teammates(): void
    {
        Event::fake([ContractShared::class]);
        $viewer = $this->member('viewer');
        $contract = $this->contract();

        Livewire::actingAs($viewer)->test(ShareModal::class, ['contractId' => $contract->id])
            ->set('selectedUsers', [$this->owner->id])
            ->call('share')
            ->assertForbidden();
        Event::assertNotDispatched(ContractShared::class);

        Livewire::actingAs($this->member('editor'))->test(ShareModal::class, ['contractId' => $contract->id])
            ->set('selectedUsers', [$this->owner->id])
            ->call('share')
            ->assertHasNoErrors();
        Event::assertDispatched(ContractShared::class);
    }

    public function test_a_viewer_cannot_send_an_external_signing_invitation(): void
    {
        Mail::fake();
        Notification::fake();
        $contract = $this->contract();

        Livewire::actingAs($this->member('viewer'))->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'Out Sider')->set('email', 'out@example.test')
            ->call('invite')
            ->assertForbidden();

        $this->assertSame(0, ContractExternalSigner::count());
        Notification::assertNothingSent();

        // update is the creator's or an admin's; an editor invites on a contract they created.
        $editor = $this->member('editor');
        $contract = $this->contract($editor);
        Livewire::actingAs($editor)->test(InviteExternalSigner::class, ['contractId' => $contract->id])
            ->set('name', 'Out Sider')->set('email', 'out@example.test')
            ->call('invite')
            ->assertHasNoErrors();
        $this->assertSame(1, ContractExternalSigner::count());
    }

    // -----------------------------------------------------------------------
    // Signing
    // -----------------------------------------------------------------------

    public function test_a_viewer_cannot_sign_through_the_api_but_editor_and_admin_can(): void
    {
        Storage::fake('signatures');
        Event::fake([ContractSigned::class]);
        $contract = $this->contract();

        $this->actingAs($this->member('viewer'), 'sanctum')
            ->postJson('/api/signatures', ['contract_id' => $contract->id, 'signature_data' => self::PNG])
            ->assertForbidden();
        $this->assertSame(0, ContractSignature::count());

        $this->actingAs($this->member('editor'), 'sanctum')
            ->postJson('/api/signatures', ['contract_id' => $contract->id, 'signature_data' => self::PNG])
            ->assertCreated();
        $this->actingAs($this->member('admin'), 'sanctum')
            ->postJson('/api/signatures', ['contract_id' => $contract->id, 'signature_data' => self::PNG])
            ->assertCreated();
    }

    public function test_a_viewer_cannot_sign_through_the_signature_pad(): void
    {
        Storage::fake('signatures');
        Event::fake();
        $contract = $this->contract();

        Livewire::actingAs($this->member('viewer'))->test(SignaturePad::class, ['contractId' => $contract->id])
            ->call('saveSignature', $contract->id, self::PNG)
            ->assertForbidden();
        $this->assertSame(0, ContractSignature::count());

        Livewire::actingAs($this->member('editor'))->test(SignaturePad::class, ['contractId' => $contract->id])
            ->call('saveSignature', $contract->id, self::PNG)
            ->assertOk();
        $this->assertSame(1, ContractSignature::count());
    }

    public function test_a_viewer_cannot_sign_during_a_video_call(): void
    {
        Storage::fake('signatures');
        Event::fake();
        $contract = $this->contract();
        $session = $this->videoSession('active', $contract);

        Livewire::actingAs($this->member('viewer'))
            ->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $contract->id])
            ->call('sign', self::PNG)
            ->assertForbidden();

        Livewire::actingAs($this->member('editor'))
            ->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $contract->id])
            ->call('sign', self::PNG)
            ->assertOk();
    }

    public function test_the_sign_button_is_not_offered_to_a_viewer(): void
    {
        $contract = $this->contract();

        $this->actingAs($this->member('viewer'))->get(route('contracts.show', $contract))->assertDontSee("open-signature-pad', { contractId", false);
        $this->actingAs($this->member('editor'))->get(route('contracts.show', $contract))->assertSee("open-signature-pad', { contractId", false);
    }

    public function test_a_viewer_in_the_team_does_not_stop_a_contract_from_being_fully_signed(): void
    {
        Storage::fake('signatures');
        Storage::fake('contracts');
        Event::fake();
        $viewer = $this->member('viewer');
        $editor = $this->member('editor');
        $contract = $this->contract();

        // Owner + editor are the signing members; the viewer is not waited for.
        $this->assertEqualsCanonicalizing([$this->owner->id, $editor->id], $contract->signingMembers()->pluck('id')->all());
        $this->assertSame(2, $contract->requiredSignatureCount());
        $this->assertFalse($contract->signingMembers()->contains('id', $viewer->id));

        foreach ([$this->owner, $editor] as $signer) {
            Livewire::actingAs($signer)->test(SignaturePad::class, ['contractId' => $contract->id])
                ->call('saveSignature', $contract->id, self::PNG);
        }

        $this->assertSame('signed', $contract->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Chat
    // -----------------------------------------------------------------------

    public function test_a_viewer_cannot_send_a_message_even_as_a_participant(): void
    {
        $viewer = $this->member('viewer');
        $conversation = $this->conversationWith($this->owner, $viewer);

        Livewire::actingAs($viewer)->test(MessageComposer::class, ['conversationId' => $conversation->id])
            ->set('body', 'let me in')
            ->call('send')
            ->assertForbidden();
        $this->assertSame(0, Message::count());

        $editor = $this->member('editor');
        $conversation = $this->conversationWith($this->owner, $editor);
        Livewire::actingAs($editor)->test(MessageComposer::class, ['conversationId' => $conversation->id])
            ->set('body', 'hello')
            ->call('send')
            ->assertHasNoErrors();
        $this->assertSame(1, Message::count());

        $admin = $this->member('admin');
        $conversation = $this->conversationWith($this->owner, $admin);
        Livewire::actingAs($admin)->test(MessageComposer::class, ['conversationId' => $conversation->id])
            ->set('body', 'hello too')
            ->call('send')
            ->assertHasNoErrors();
        $this->assertSame(2, Message::count());
    }

    public function test_a_viewer_can_open_and_read_a_conversation_they_are_in(): void
    {
        $viewer = $this->member('viewer');
        $conversation = $this->conversationWith($this->owner, $viewer);

        Livewire::actingAs($viewer)->test(ConversationThread::class, ['conversationId' => $conversation->id])->assertOk();
    }

    public function test_a_viewer_cannot_start_a_conversation(): void
    {
        $viewer = $this->member('viewer');

        Livewire::actingAs($viewer)->test(NewConversation::class)
            ->set('selectedUsers', [$this->owner->id])
            ->call('create')
            ->assertForbidden();
        $this->assertSame(0, Conversation::withoutGlobalScopes()->count());

        Livewire::actingAs($this->member('editor'))->test(NewConversation::class)
            ->set('selectedUsers', [$this->owner->id])
            ->call('create')
            ->assertHasNoErrors();
        $this->assertSame(1, Conversation::withoutGlobalScopes()->count());
    }

    // -----------------------------------------------------------------------
    // Video sessions
    // -----------------------------------------------------------------------

    public function test_a_viewer_cannot_start_or_end_a_video_session(): void
    {
        $viewer = $this->member('viewer');

        Livewire::actingAs($viewer)->test(SessionLauncher::class)->call('create')->assertForbidden();
        $this->assertSame(0, VideoSession::withoutGlobalScopes()->count());

        $session = $this->videoSession();
        Livewire::actingAs($viewer)->test(SessionRoom::class, ['sessionId' => $session->id])->call('endSession')->assertForbidden();
        $this->assertSame('active', $session->fresh()->status);

        Livewire::actingAs($this->member('editor'))->test(SessionLauncher::class)->call('create')->assertRedirect();
        Livewire::actingAs($this->member('admin'))->test(SessionRoom::class, ['sessionId' => $session->id])
            ->call('endSession')->assertRedirect(route('video.index'));
        $this->assertSame('ended', $session->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Team management
    // -----------------------------------------------------------------------

    public function test_a_viewer_cannot_manage_team_members_or_the_team(): void
    {
        $viewer = $this->member('viewer');
        $other = $this->member('editor');
        $this->actingAs($viewer);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('addTeamMemberForm', ['email' => 'new@example.test', 'role' => 'editor'])
            ->call('addTeamMember')
            ->assertForbidden();
        $this->assertSame(0, $this->team->teamInvitations()->count());

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('managingRoleFor', $other)
            ->set('currentRole', 'viewer')
            ->call('updateRole')
            ->assertForbidden();
        $this->assertSame('editor', $other->fresh()->teamRole($this->team)->key);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('teamMemberIdBeingRemoved', $other->id)
            ->call('removeTeamMember')
            ->assertForbidden();
        $this->assertTrue($other->fresh()->belongsToTeam($this->team));

        Livewire::test(UpdateTeamNameForm::class, ['team' => $this->team])
            ->set('state', ['name' => 'Renamed'])
            ->call('updateTeamName')
            ->assertForbidden();

        Livewire::test(DeleteTeamForm::class, ['team' => $this->team])
            ->call('deleteTeam')
            ->assertForbidden();
        $this->assertModelExists($this->team);
    }

    public function test_the_team_owner_can_offer_and_assign_the_viewer_role(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->assertSee('Viewer')
            ->set('addTeamMemberForm', ['email' => 'new@example.test', 'role' => 'viewer'])
            ->call('addTeamMember')
            ->assertHasNoErrors();
        $this->assertSame('viewer', $this->team->fresh()->teamInvitations()->first()->role);

        $editor = $this->member('editor');
        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->set('managingRoleFor', $editor)
            ->set('currentRole', 'viewer')
            ->call('updateRole')
            ->assertHasNoErrors();
        $this->assertSame('viewer', $editor->fresh()->teamRole($this->team->fresh())->key);
    }

    public function test_a_viewer_may_still_leave_a_team(): void
    {
        $viewer = $this->member('viewer');
        $this->actingAs($viewer);

        Livewire::test(TeamMemberManager::class, ['team' => $this->team])
            ->call('leaveTeam')
            ->assertHasNoErrors();

        $this->assertFalse($viewer->fresh()->belongsToTeam($this->team));
    }

    // -----------------------------------------------------------------------
    // Policies directly: no ability that writes is open to a viewer
    // -----------------------------------------------------------------------

    public function test_no_mutating_policy_ability_is_open_to_a_viewer(): void
    {
        $viewer = $this->member('viewer');
        $contract = $this->contract($viewer, 'draft');
        $template = ContractTemplate::factory()->create(['team_id' => $this->team->id, 'created_by' => $viewer->id]);
        $conversation = $this->conversationWith($viewer);
        $message = Message::create(['conversation_id' => $conversation->id, 'user_id' => $viewer->id, 'body' => 'x', 'type' => 'text']);
        $session = $this->videoSession();
        $session->forceFill(['initiated_by' => $viewer->id])->save();

        $this->actingAs($viewer);

        foreach (['create'] as $ability) {
            $this->assertFalse($viewer->can($ability, Contract::class));
            $this->assertFalse($viewer->can($ability, ContractTemplate::class));
            $this->assertFalse($viewer->can($ability, Conversation::class));
            $this->assertFalse($viewer->can($ability, Message::class));
            $this->assertFalse($viewer->can($ability, VideoSession::class));
        }

        foreach (['update', 'delete', 'share', 'sign', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse($viewer->can($ability, $contract), "contract {$ability}");
        }
        foreach (['update', 'delete'] as $ability) {
            $this->assertFalse($viewer->can($ability, $template), "template {$ability}");
            $this->assertFalse($viewer->can($ability, $conversation), "conversation {$ability}");
            $this->assertFalse($viewer->can($ability, $message), "message {$ability}");
            $this->assertFalse($viewer->can($ability, $session), "session {$ability}");
        }
        $this->assertFalse($viewer->can('sendMessage', $conversation));
        $this->assertFalse($viewer->can('sign', $session));

        // Reading is what a viewer keeps.
        $this->assertTrue($viewer->can('view', $contract));
        $this->assertTrue($viewer->can('view', $template));
        $this->assertTrue($viewer->can('view', $conversation));
        $this->assertTrue($viewer->can('view', $session));
    }

    public function test_editors_and_admins_keep_what_they_had(): void
    {
        $editor = $this->member('editor');
        $admin = $this->member('admin');
        $mine = $this->contract($editor, 'draft');

        foreach ([$editor, $admin] as $user) {
            $this->actingAs($user);
            $this->assertTrue($user->can('create', Contract::class));
            $this->assertTrue($user->can('create', ContractTemplate::class));
            $this->assertTrue($user->can('create', Conversation::class));
            $this->assertTrue($user->can('create', VideoSession::class));
            $this->assertTrue($user->can('update', $mine));
            $this->assertTrue($user->can('delete', $mine));
            $this->assertTrue($user->can('share', $mine));
            $this->assertTrue($user->can('sign', $mine));
        }

        $this->assertFalse($editor->can('restore', $mine), 'restore stays admin-only');
        $this->assertTrue($admin->can('restore', $mine));
    }
}
