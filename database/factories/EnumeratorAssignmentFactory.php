<?php

namespace Database\Factories;

use App\Models\EnumeratorAssignment;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnumeratorAssignment>
 */
class EnumeratorAssignmentFactory extends Factory
{
    protected $model = EnumeratorAssignment::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'user_id' => User::factory(),
            'zone' => fake()->randomElement(['Akwa', 'Bonamoussadi', 'Deido', 'Makepe', 'Logpom']),
            'quota_target' => fake()->numberBetween(5, 20),
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state([
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDay(),
        ]);
    }
}
