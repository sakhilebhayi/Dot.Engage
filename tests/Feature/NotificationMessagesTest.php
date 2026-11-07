<?php

namespace Tests\Feature;

use App\Enums\EngagementKind;
use App\Livewire\Notifications\NotificationTray;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\VideoSession;
use App\Notifications\EngagementNotice;
use App\Notifications\NewMessageNotification;
use App\Services\Engage\EngagementService;
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
    public function test_engagement_notice_shows_its_message_in_the_tray(): void
    {
        $engagement = app(EngagementService::class)->create(
            $this->owner->currentTeam, $this->owner, 'Supply terms', EngagementKind::Supplier, null, 'Acme',
        );

        $this->assertTrayShows(
            new EngagementNotice($engagement, 'engagement_message', 'Olivia posted in the discussion', route('engagements.show', $engagement)),
            'Olivia posted in the discussion',
        );
    }

    public function test_every_notification_class_is_covered(): void
    {
        $covered = [
            EngagementNotice::class,
            NewMessageNotification::class,
        ];
        $mailOnly = [
            'VerificationCodeNotification',
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
