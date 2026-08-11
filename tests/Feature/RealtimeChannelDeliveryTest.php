<?php

namespace Tests\Feature;

use App\Livewire\Chat\ConversationThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Attributes\On;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression coverage for two real-time delivery bugs that 152 passing
 * tests never caught, because nothing exercised either the real
 * /broadcasting/auth endpoint or the literal string Livewire's native Echo
 * integration parses from a #[On(...)] attribute -- both bugs left the
 * event-dispatch and notification-sent layers looking perfectly correct
 * while the actual WebSocket delivery silently never worked.
 */
class RealtimeChannelDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml forces BROADCAST_CONNECTION=null in the test env so
        // event-broadcasting tests never attempt a real network call -- but
        // the null driver's auth() is a no-op that authorizes everything
        // unconditionally. Channel-authorization callbacks are only
        // enforced by a real Pusher-protocol broadcaster (reverb/pusher),
        // so this test class opts back into that for its own requests.
        //
        // Switching the config alone isn't enough: Broadcast::channel()
        // registers callbacks on whichever driver instance is current at
        // call time, and routes/channels.php already ran against the
        // "null" driver during app bootstrap (before this setUp() runs).
        // Re-requiring it now, after switching the default, registers the
        // same callbacks on a fresh "reverb" driver instance instead.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        require base_path('routes/channels.php');
    }

    private function authRequest(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    /**
     * ContractShared::broadcastOn() used to return
     * PrivateChannel('user.'.$id) -- a channel routes/channels.php never
     * registers, so nobody could ever authorize a subscription to it (the
     * event dispatched and the database notification still sent, which is
     * why EventWiringTest's Notification::assertSentTo() coverage passed
     * without ever revealing this). It must now match the channel that's
     * actually registered: App.Models.User.{id}.
     */
    public function test_contract_shared_recipient_can_authorize_the_channel_it_actually_broadcasts_on(): void
    {
        $recipient = User::factory()->create();

        $this->authRequest($recipient, "private-App.Models.User.{$recipient->id}")
            ->assertOk();
    }

    public function test_someone_other_than_the_recipient_cannot_authorize_that_channel(): void
    {
        $recipient = User::factory()->create();
        $someoneElse = User::factory()->create();

        $this->authRequest($someoneElse, "private-App.Models.User.{$recipient->id}")
            ->assertForbidden();
    }

    /**
     * ConversationThread::messageReceived()'s #[On(...)] attribute used to
     * read "echo-private:conversation.{conversationId},message.sent" -- no
     * leading dot. Livewire's supportLaravelEcho.js passes that raw string
     * straight to Echo.listen(), and Echo's default EventFormatter prepends
     * the "App.Events" namespace to any name without a leading dot, turning
     * dots into backslashes -- so it would listen for
     * "App.Events.message.sent" and never match
     * MessageSent::broadcastAs()'s actual "message.sent" name. No test ever
     * caught this because it's a client-side string-matching bug: the
     * server-side broadcastOn()/broadcastAs() wiring was always correct,
     * and nothing short of reading the literal attribute string (as this
     * test does) or an actual browser connecting could reveal the mismatch.
     */
    public function test_conversation_thread_echo_listener_has_the_leading_dot_message_sent_expects(): void
    {
        $method = (new ReflectionClass(ConversationThread::class))
            ->getMethod('messageReceived');

        $onAttribute = $method->getAttributes(On::class)[0]
            ?? $this->fail('messageReceived() is missing its #[On(...)] attribute entirely.');

        [$eventName] = $onAttribute->getArguments();

        $this->assertStringContainsString(
            ',.message.sent',
            $eventName,
            'The #[On(...)] event name must contain ",.message.sent" (leading dot) to match '
            .'MessageSent::broadcastAs()\'s "message.sent" -- without the dot, Echo\'s default '
            .'EventFormatter prepends the App.Events namespace and this listener silently never fires.'
        );
    }
}
