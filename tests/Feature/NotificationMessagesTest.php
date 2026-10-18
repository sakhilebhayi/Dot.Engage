<?php

namespace Tests\Feature;

use App\Livewire\Notifications\NotificationTray;
use App\Models\Contract;
use App\Models\ContractSignature;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VideoSession;
use App\Notifications\ContractExpiringSoonNotification;
use App\Notifications\ContractSharedNotification;
use App\Notifications\ContractSignedNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\SignatureRequestedNotification;
use App\Notifications\VideoSessionInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/**
 * Every notification that lands in the tray carries a plain-words
 * data['message'], so the tray never falls back to "New notification".
 */
class NotificationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $recipient;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Olivia Owner']);
        $this->recipient = User::factory()->create(['name' => 'Rita Recipient']);
        $this->owner->currentTeam->users()->attach($this->recipient, ['role' => 'editor']);

        $this->contract = Contract::factory()->pending()->create([
            'team_id' => $this->owner->currentTeam->id,
            'created_by' => $this->owner->id,
            'title' => 'Master Services Agreement',
            'expires_at' => '2027-03-05 09:00:00',
        ]);
    }

    private function assertTrayShows(Notification $notification, string $expectedMessage): void
    {
        $this->recipient->notify($notification);

        $stored = $this->recipient->fresh()->notifications()->sole();

        $this->assertSame($expectedMessage, $stored->data['message'] ?? null);
        $this->assertNotSame('', trim($stored->data['message']));

        Livewire::actingAs($this->recipient)
            ->test(NotificationTray::class)
            ->assertSee($expectedMessage)
            ->assertDontSee('New notification');
    }

    private function videoSession(?Contract $contract = null): VideoSession
    {
        return VideoSession::create([
            'team_id' => $this->owner->currentTeam->id,
            'initiated_by' => $this->owner->id,
            'room_id' => (string) Str::uuid(),
            'status' => 'active',
            'contract_id' => $contract?->id,
        ]);
    }

    public function test_contract_shared_has_a_message(): void
    {
        $this->assertTrayShows(
            new ContractSharedNotification($this->contract, $this->owner->id),
            'Olivia Owner shared Master Services Agreement with you',
        );
    }

    public function test_contract_expiring_soon_has_a_message(): void
    {
        $this->assertTrayShows(
            new ContractExpiringSoonNotification($this->contract),
            'Master Services Agreement is waiting for your signature and expires on 5th March 2027',
        );
    }

    public function test_contract_expiring_soon_without_a_date_still_has_a_message(): void
    {
        // The reminder command only picks contracts with an expiry, but the
        // payload must not break (or go blank) if one ever has none.
        $this->contract->update(['expires_at' => null]);

        $payload = (new ContractExpiringSoonNotification($this->contract->fresh()))->toArray($this->recipient);

        $this->assertSame('Master Services Agreement is waiting for your signature', $payload['message']);
    }

    public function test_contract_signed_by_one_party_has_a_message(): void
    {
        $signature = ContractSignature::create([
            'contract_id' => $this->contract->id,
            'user_id' => $this->owner->id,
            'signature_image_path' => 'signatures/x.png',
            'signed_at' => now(),
        ]);

        $this->assertTrayShows(
            new ContractSignedNotification($this->contract, $signature),
            'Olivia Owner signed Master Services Agreement',
        );
    }

    public function test_contract_fully_signed_has_a_message(): void
    {
        $this->contract->update(['status' => 'signed']);
        $signature = ContractSignature::create([
            'contract_id' => $this->contract->id,
            'user_id' => $this->owner->id,
            'signature_image_path' => 'signatures/x.png',
            'signed_at' => now(),
        ]);

        $this->assertTrayShows(
            new ContractSignedNotification($this->contract->fresh(), $signature),
            'Master Services Agreement is fully signed',
        );
    }

    public function test_video_session_invite_has_a_message(): void
    {
        $this->assertTrayShows(
            new VideoSessionInviteNotification($this->videoSession()),
            'Olivia Owner started a video session and invited you to join',
        );
    }

    public function test_video_session_invite_with_a_contract_says_so(): void
    {
        $this->assertTrayShows(
            new VideoSessionInviteNotification($this->videoSession($this->contract)),
            'Olivia Owner started a video session and invited you to join. Master Services Agreement needs signing during the call',
        );
    }

    public function test_signature_requested_has_a_message(): void
    {
        $this->assertTrayShows(
            new SignatureRequestedNotification($this->videoSession($this->contract), $this->contract),
            'Your signature is requested on Master Services Agreement in a live video session',
        );
    }

    public function test_new_message_has_a_message(): void
    {
        $conversation = Conversation::factory()->direct()->create(['team_id' => $this->owner->currentTeam->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->owner->id,
            'body' => 'Can you look at the draft today?',
        ]);

        $this->assertTrayShows(
            new NewMessageNotification($message),
            'Olivia Owner sent you a message: Can you look at the draft today?',
        );
    }

    public function test_new_message_without_text_still_has_a_message(): void
    {
        $conversation = Conversation::factory()->direct()->create(['team_id' => $this->owner->currentTeam->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->owner->id,
            'body' => null,
            'type' => 'file',
        ]);

        $this->assertTrayShows(
            new NewMessageNotification($message),
            'Olivia Owner sent you a message',
        );
    }

    /**
     * A new notification class must either carry a message for the tray or be
     * mail-only (external signers have no account and no tray). This fails when
     * someone adds a class and forgets to cover it here.
     */
    public function test_every_notification_class_is_covered(): void
    {
        $covered = [
            ContractExpiringSoonNotification::class,
            ContractSharedNotification::class,
            ContractSignedNotification::class,
            NewMessageNotification::class,
            SignatureRequestedNotification::class,
            VideoSessionInviteNotification::class,
        ];
        $mailOnly = [
            'ExternalSignatureRequestNotification',
            'ExternalSignerExpiringSoonNotification',
        ];

        $classes = collect(glob(app_path('Notifications/*.php')))
            ->map(fn ($file) => 'App\\Notifications\\'.basename($file, '.php'));

        foreach ($classes as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            $this->assertTrue(
                in_array($class, $covered, true) || in_array($short, $mailOnly, true),
                "{$short} has no tray-message test and is not listed as mail-only.",
            );
        }

        foreach ($mailOnly as $short) {
            $instance = (new ReflectionClass('App\\Notifications\\'.$short))->newInstanceWithoutConstructor();
            $this->assertSame(['mail'], $instance->via(new \stdClass), "{$short} is listed as mail-only but has other channels.");
        }
    }
}
