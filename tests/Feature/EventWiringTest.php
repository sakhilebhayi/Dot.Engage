<?php

namespace Tests\Feature;

use App\Events\ContractShared;
use App\Events\SignatureRequestedDuringCall;
use App\Events\VideoSessionStarted;
use App\Livewire\Contracts\ShareModal;
use App\Livewire\Video\SessionLauncher;
use App\Models\Contract;
use App\Models\User;
use App\Models\VideoSession;
use App\Notifications\ContractSharedNotification;
use App\Notifications\SignatureRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EventWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_sharing_a_contract_dispatches_contract_shared_event(): void
    {
        Event::fake([ContractShared::class]);

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(ShareModal::class, ['contractId' => $contract->id])
            ->set('selectedUsers', [$member->id])
            ->call('share');

        Event::assertDispatched(ContractShared::class, fn ($event) => $event->contract->is($contract) && $event->sharedWith->is($member)
        );
    }

    public function test_contract_shared_event_notifies_the_recipient(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        Livewire::actingAs($owner)
            ->test(ShareModal::class, ['contractId' => $contract->id])
            ->set('selectedUsers', [$member->id])
            ->call('share');

        Notification::assertSentTo($member, ContractSharedNotification::class);
    }

    public function test_starting_a_video_session_dispatches_video_session_started_event(): void
    {
        Event::fake([VideoSessionStarted::class]);

        $owner = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($owner)
            ->test(SessionLauncher::class)
            ->call('create');

        Event::assertDispatched(VideoSessionStarted::class, fn ($event) => $event->session->initiated_by === $owner->id
        );
    }

    public function test_signature_requested_during_call_notifies_other_team_members(): void
    {
        Notification::fake();

        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);
        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => Str::uuid(),
            'status' => 'active',
            'contract_id' => $contract->id,
        ]);

        SignatureRequestedDuringCall::dispatch($session, $contract, $owner);

        Notification::assertSentTo($member, SignatureRequestedNotification::class);
        Notification::assertNotSentTo($owner, SignatureRequestedNotification::class);
    }
}
