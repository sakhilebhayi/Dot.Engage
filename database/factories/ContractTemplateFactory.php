<?php

namespace Database\Factories;

use App\Models\ContractTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractTemplate>
 */
class ContractTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'title' => $this->faker->words(3, true).' Template',
            'description' => $this->faker->optional(0.7)->paragraph(),
            'file_path' => 'contract-templates/'.$this->faker->uuid().'.pdf',
            'expires_in_days' => $this->faker->optional(0.6)->numberBetween(7, 90),
        ];
    }
}
