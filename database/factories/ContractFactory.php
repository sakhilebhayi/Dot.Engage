<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'title' => $this->faker->words(4, true).' Agreement',
            'description' => $this->faker->optional(0.7)->paragraph(),
            'file_path' => 'contracts/'.$this->faker->uuid().'.pdf',
            'status' => $this->faker->randomElement(['draft', 'pending', 'signed', 'expired']),
            'expires_at' => $this->faker->optional(0.5)->dateTimeBetween('+1 month', '+1 year'),
        ];
    }

    /** Contract not yet submitted for signing. */
    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'file_path' => null]);
    }

    /** Contract uploaded and awaiting signatures. */
    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    /** Fully signed contract. */
    public function signed(): static
    {
        return $this->state(fn () => ['status' => 'signed']);
    }

    /** Contract past its expiry date. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'expires_at' => $this->faker->dateTimeBetween('-6 months', '-1 day'),
        ]);
    }
}
