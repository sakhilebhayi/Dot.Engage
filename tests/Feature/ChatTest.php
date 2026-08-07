<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Policies\ConversationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Policy: conversation view
    // -----------------------------------------------------------------------

    public function test_participant_can_view_conversation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();
        $owner->currentTeam->users()->attach($other, ['role' => 'editor']);

        $conversation = Conversation::factory()->direct()->create([
            'team_id' => $owner->currentTeam->id,
        ]);
        $conversation->participants()->attach([$owner->id, $other->id]);

        $policy = new ConversationPolicy;

        $this->assertTrue($policy->view($owner, $conversation));
        $this->assertTrue($policy->view($other, $conversation));
    }

    public function test_non_participant_cannot_view_conversation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $conversation = Conversation::factory()->direct()->create([
            'team_id' => $owner->currentTeam->id,
        ]);
        $conversation->participants()->attach($owner->id);

        $policy = new ConversationPolicy;

        $this->assertFalse($policy->view($outsider, $conversation));
    }

    // -----------------------------------------------------------------------
    // Policy: conversation update / delete
    // -----------------------------------------------------------------------

    public function test_admin_can_update_conversation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $admin = User::factory()->create();
        $owner->currentTeam->users()->attach($admin, ['role' => 'admin']);

        $conversation = Conversation::factory()->group()->create([
            'team_id' => $owner->currentTeam->id,
        ]);
        $conversation->participants()->attach([$owner->id, $admin->id]);

        $policy = new ConversationPolicy;

        $this->assertTrue($policy->update($admin, $conversation));
    }

    public function test_regular_member_cannot_update_conversation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $member = User::factory()->create();
        $owner->currentTeam->users()->attach($member, ['role' => 'editor']);

        $conversation = Conversation::factory()->group()->create([
            'team_id' => $owner->currentTeam->id,
        ]);
        $conversation->participants()->attach([$owner->id, $member->id]);

        $policy = new ConversationPolicy;

        $this->assertFalse($policy->update($member, $conversation));
    }

    // -----------------------------------------------------------------------
    // Model: message factory states
    // -----------------------------------------------------------------------

    public function test_unread_message_has_null_read_at(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $conv = Conversation::factory()->create(['team_id' => $owner->currentTeam->id]);

        $message = Message::factory()->unread()->create([
            'conversation_id' => $conv->id,
            'user_id' => $owner->id,
        ]);

        $this->assertNull($message->read_at);
    }

    public function test_contract_share_message_has_correct_type(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $conv = Conversation::factory()->create(['team_id' => $owner->currentTeam->id]);
        $contract = Contract::factory()->pending()->create([
            'team_id' => $owner->currentTeam->id,
            'created_by' => $owner->id,
        ]);

        $message = Message::factory()->contractShare($contract->id)->create([
            'conversation_id' => $conv->id,
            'user_id' => $owner->id,
        ]);

        $this->assertSame('contract_share', $message->type);
        $this->assertSame($contract->id, $message->contract_id);
    }

    // -----------------------------------------------------------------------
    // Model relationships
    // -----------------------------------------------------------------------

    public function test_conversation_has_participants(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->create();

        $conversation = Conversation::factory()->direct()->create([
            'team_id' => $owner->currentTeam->id,
        ]);
        $conversation->participants()->attach([$owner->id, $other->id]);

        $this->assertCount(2, $conversation->participants);
    }

    public function test_message_belongs_to_conversation(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $conv = Conversation::factory()->create(['team_id' => $owner->currentTeam->id]);
        $msg = Message::factory()->create([
            'conversation_id' => $conv->id,
            'user_id' => $owner->id,
        ]);

        $this->assertTrue($msg->conversation->is($conv));
    }
}
