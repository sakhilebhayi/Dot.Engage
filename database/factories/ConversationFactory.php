<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        $isGroup = $this->faker->boolean(30);

        return [
            'team_id' => Team::factory(),
            'name' => $isGroup ? $this->faker->words(3, true) : null,
            'is_group' => $isGroup,
            'last_message_at' => $this->faker->optional(0.8)->dateTimeBetween('-30 days', 'now'),
        ];
    }

    /** Direct (1-to-1) conversation. */
    public function direct(): static
    {
        return $this->state(fn () => ['is_group' => false, 'name' => null]);
    }

    /** Named group conversation. */
    public function group(): static
    {
        return $this->state(fn () => [
            'is_group' => true,
            'name' => $this->faker->words(3, true),
        ]);
    }
}
