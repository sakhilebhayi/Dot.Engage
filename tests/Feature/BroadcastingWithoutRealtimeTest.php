<?php

namespace Tests\Feature;

use App\Events\ContractShared;
use App\Events\ContractSigned;
use App\Events\MessageSent;
use App\Events\SignatureRequestedDuringCall;
use App\Events\VideoSessionEnded;
use App\Events\VideoSessionStarted;
use App\Livewire\Chat\ConversationList;
use App\Livewire\Chat\ConversationThread;
use App\Livewire\Chat\MessageComposer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Realtime;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Production has no websocket server. These tests pin what that means: the
 * broadcast events do nothing (and queue nothing) on the "null" connection,
 * and the chat screens refresh themselves by polling instead.
 */
class BroadcastingWithoutRealtimeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string}> */
    public static function broadcastEvents(): array
    {
        return [
            'ContractShared' => [ContractShared::class],
            'ContractSigned' => [ContractSigned::class],
            'MessageSent' => [MessageSent::class],
            'SignatureRequestedDuringCall' => [SignatureRequestedDuringCall::class],
            'VideoSessionEnded' => [VideoSessionEnded::class],
            'VideoSessionStarted' => [VideoSessionStarted::class],
        ];
    }

    private function realtimeOn(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
        ]);
    }

    private function conversationFor(User $user, ?User $other = null): Conversation
    {
        $conversation = Conversation::factory()->direct()->create(['team_id' => $user->currentTeam->id]);
        $conversation->participants()->attach(array_filter([$user->id, $other?->id]));

        return $conversation;
    }

    public function test_the_default_broadcast_connection_is_null_in_the_environment_example(): void
    {
        $this->assertStringContainsString("\nBROADCAST_CONNECTION=null\n", file_get_contents(base_path('.env.example')));
        $this->assertContains((string) config('broadcasting.default'), ['', 'null']);
        $this->assertSame('null', config('broadcasting.connections.null.driver'));
    }

    /** @param class-string $class */
    #[DataProvider('broadcastEvents')]
    public function test_no_broadcast_event_is_queued_on_the_null_connection(string $class): void
    {
        $event = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $this->assertFalse($event->broadcastWhen(), "{$class} must not broadcast on the null connection");

        $this->realtimeOn();
        $this->assertTrue($event->broadcastWhen(), "{$class} must broadcast when Reverb is configured");
    }

    public function test_sending_a_message_queues_no_broadcast_job_without_realtime(): void
    {
        Queue::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $conversation = $this->conversationFor($user);

        Livewire::actingAs($user)
            ->test(MessageComposer::class, ['conversationId' => $conversation->id])
            ->set('body', 'hello')
            ->call('send')
            ->assertHasNoErrors();

        $this->assertSame(1, Message::count());
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function test_sending_a_message_still_broadcasts_when_reverb_is_configured(): void
    {
        Queue::fake();
        $this->realtimeOn();

        $user = User::factory()->withPersonalTeam()->create();
        $conversation = $this->conversationFor($user);

        Livewire::actingAs($user)
            ->test(MessageComposer::class, ['conversationId' => $conversation->id])
            ->set('body', 'hello')
            ->call('send');

        Queue::assertPushed(BroadcastEvent::class);
    }

    public function test_realtime_is_reported_only_when_a_real_driver_has_a_key(): void
    {
        $this->assertFalse(Realtime::active());

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]);
        $this->assertFalse(Realtime::active(), 'reverb without a key is not realtime');

        config(['broadcasting.default' => 'log']);
        $this->assertFalse(Realtime::active(), 'the log driver reaches no browser');

        $this->realtimeOn();
        $this->assertTrue(Realtime::active());
    }

    public function test_chat_thread_polls_without_realtime(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $conversation = $this->conversationFor($user);

        Livewire::actingAs($user)
            ->test(ConversationThread::class, ['conversationId' => $conversation->id])
            ->assertSeeHtml('wire:poll.5s.visible="refreshFromPoll"');
    }

    public function test_chat_thread_does_not_poll_when_reverb_is_configured(): void
    {
        $this->realtimeOn();

        $user = User::factory()->withPersonalTeam()->create();
        $conversation = $this->conversationFor($user);

        Livewire::actingAs($user)
            ->test(ConversationThread::class, ['conversationId' => $conversation->id])
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_polling_the_thread_shows_a_new_message_and_marks_it_read(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        $user->currentTeam->users()->attach($other, ['role' => 'editor']);
        $conversation = $this->conversationFor($user, $other);

        $thread = Livewire::actingAs($user)
            ->test(ConversationThread::class, ['conversationId' => $conversation->id])
            ->assertDontSee('Are you there?');

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $other->id,
            'body' => 'Are you there?',
            'read_at' => null,
        ]);

        $thread->call('refreshFromPoll')->assertSee('Are you there?');

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_conversation_list_polls_without_realtime_and_not_with_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        Livewire::actingAs($user)->test(ConversationList::class)
            ->assertSeeHtml('wire:poll.5s.visible');

        $this->realtimeOn();

        Livewire::actingAs($user)->test(ConversationList::class)
            ->assertDontSeeHtml('wire:poll');
    }
}
