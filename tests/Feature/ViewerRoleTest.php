<?php

namespace Tests\Feature;

use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Livewire\Chat\ConversationThread;
use App\Livewire\Chat\MessageComposer;
use App\Livewire\Chat\NewConversation;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Models\VideoSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
