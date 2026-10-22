<?php

namespace Tests\Feature;

use App\Livewire\Chat\ConversationThread;
use App\Livewire\Video\InCallDocumentViewer;
use App\Livewire\Video\InCallSignaturePad;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VideoSessionSignature;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ids these components work on are public Livewire properties, so a browser could
 * rewrite them after mount. They are locked, and every action re-checks authorization.
 */
class LivewireLockedIdsTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal valid PNG (1x1), as the signature pad's canvas would send it. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    // ---------------------------------------------------------------- chat

    private function conversationFor(User $owner, User ...$participants): Conversation
    {
        $conversation = Conversation::factory()->direct()->create(['team_id' => $owner->currentTeam->id]);
        $conversation->participants()->attach(array_map(fn (User $u) => $u->id, [$owner, ...$participants]));

        return $conversation;
    }

    public function test_the_browser_cannot_swap_in_another_conversation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mine = $this->conversationFor($owner);
        $stranger = User::factory()->withPersonalTeam()->create();
        $theirs = $this->conversationFor($stranger);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($owner)
            ->test(ConversationThread::class, ['conversationId' => $mine->id])
            ->set('conversationId', $theirs->id);
    }

    public function test_the_browser_cannot_set_the_page_count(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mine = $this->conversationFor($owner);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($owner)
            ->test(ConversationThread::class, ['conversationId' => $mine->id])
            ->set('page', 100000);
    }

    public function test_switching_to_a_conversation_you_are_not_in_is_refused_and_changes_nothing(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mine = $this->conversationFor($owner);
        $stranger = User::factory()->withPersonalTeam()->create();
        $theirs = $this->conversationFor($stranger);
        $secret = Message::factory()->create(['conversation_id' => $theirs->id, 'user_id' => $stranger->id, 'body' => 'not-for-you-1234', 'read_at' => null]);

        // Another team's conversation is either invisible (team scope: 404) or refused (403).
        try {
            Livewire::actingAs($owner)
                ->test(ConversationThread::class, ['conversationId' => $mine->id])
                ->dispatch('conversation-selected', conversationId: $theirs->id)
                ->assertForbidden();
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($secret->fresh()->read_at, 'a refused switch must not mark the other conversation read');
    }

    public function test_loading_more_is_capped(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $mine = $this->conversationFor($owner);

        $component = Livewire::actingAs($owner)->test(ConversationThread::class, ['conversationId' => $mine->id]);
        for ($i = 0; $i < 60; $i++) {
            $component->call('loadMore');
        }

        $this->assertSame(50, $component->get('page'));
    }

    public function test_a_participant_who_leaves_stops_seeing_the_thread(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        $owner->currentTeam->users()->attach($other, ['role' => 'editor']);
        $conversation = $this->conversationFor($owner, $other);

        $component = Livewire::actingAs($other)->test(ConversationThread::class, ['conversationId' => $conversation->id])->assertOk();

        $conversation->participants()->detach($other->id);

        $component->call('refreshFromPoll')->assertForbidden();
    }

    // --------------------------------------------------------------- video

    /** @return array{0: User, 1: Contract, 2: VideoSession} */
    private function liveSessionWithContract(string $status = 'active'): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $contract = Contract::factory()->pending()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id]);
        $session = VideoSession::create([
            'team_id' => $owner->currentTeam->id,
            'initiated_by' => $owner->id,
            'room_id' => (string) Str::uuid(),
            'status' => $status,
            'contract_id' => $contract->id,
        ]);

        return [$owner, $contract, $session];
    }

    public function test_the_pad_refuses_a_session_opened_for_another_contract(): void
    {
        [$owner, , $session] = $this->liveSessionWithContract();
        $otherContract = Contract::factory()->pending()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $otherContract->id])
            ->assertForbidden();
    }

    public function test_the_browser_cannot_repoint_the_pad(): void
    {
        [$owner, $contract, $session] = $this->liveSessionWithContract();
        $otherContract = Contract::factory()->pending()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($owner)
            ->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $contract->id])
            ->set('contractId', $otherContract->id);
    }

    public function test_a_signature_in_the_matching_live_session_is_recorded(): void
    {
        Storage::fake('signatures');
        [$owner, $contract, $session] = $this->liveSessionWithContract();

        Livewire::actingAs($owner)
            ->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $contract->id])
            ->call('sign', self::PNG)
            ->assertDispatched('signature-captured');

        $this->assertSame(1, VideoSessionSignature::where('video_session_id', $session->id)->where('contract_id', $contract->id)->count());
    }

    public function test_signing_in_an_ended_session_is_refused(): void
    {
        Storage::fake('signatures');
        [$owner, $contract, $session] = $this->liveSessionWithContract();

        $component = Livewire::actingAs($owner)->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $contract->id]);
        $session->update(['status' => 'ended', 'ended_at' => now()]);

        $component->call('sign', self::PNG)->assertForbidden();
        $this->assertSame(0, VideoSessionSignature::count());
    }

    public function test_a_non_png_signature_is_rejected(): void
    {
        Storage::fake('signatures');
        [$owner, $contract, $session] = $this->liveSessionWithContract();

        Livewire::actingAs($owner)
            ->test(InCallSignaturePad::class, ['sessionId' => $session->id, 'contractId' => $contract->id])
            ->call('sign', 'data:image/png;base64,'.base64_encode('<?php echo 1;'))
            ->assertStatus(422);

        $this->assertSame(0, VideoSessionSignature::count());
        $this->assertSame([], Storage::disk('signatures')->allFiles());
    }

    public function test_an_outsider_cannot_open_the_pad_or_the_viewer(): void
    {
        [, $contract, $session] = $this->liveSessionWithContract();
        $outsider = User::factory()->withPersonalTeam()->create();

        foreach ([InCallSignaturePad::class, InCallDocumentViewer::class] as $component) {
            // Another team's session is either invisible (team scope: 404) or refused (403).
            try {
                Livewire::actingAs($outsider)
                    ->test($component, ['sessionId' => $session->id, 'contractId' => $contract->id])
                    ->assertForbidden();
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_viewer_refuses_a_mismatched_contract_and_locks_its_ids(): void
    {
        [$owner, $contract, $session] = $this->liveSessionWithContract();
        $otherContract = Contract::factory()->pending()->create(['team_id' => $owner->currentTeam->id, 'created_by' => $owner->id]);

        Livewire::actingAs($owner)
            ->test(InCallDocumentViewer::class, ['sessionId' => $session->id, 'contractId' => $otherContract->id])
            ->assertForbidden();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($owner)
            ->test(InCallDocumentViewer::class, ['sessionId' => $session->id, 'contractId' => $contract->id])
            ->set('contractId', $otherContract->id);
    }
}
