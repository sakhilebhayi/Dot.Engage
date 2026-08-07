<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'body' => $this->faker->sentences(rand(1, 3), true),
            'type' => 'text',
            'contract_id' => null,
            'read_at' => $this->faker->optional(0.6)->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /** Unread message. */
    public function unread(): static
    {
        return $this->state(fn () => ['read_at' => null]);
    }

    /** Message that references a contract (type = contract_share). */
    public function contractShare(int $contractId): static
    {
        return $this->state(fn () => [
            'type' => 'contract_share',
            'contract_id' => $contractId,
            'body' => 'A contract has been shared in this conversation.',
        ]);
    }
}
