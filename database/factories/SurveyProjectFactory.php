<?php

namespace Database\Factories;

use App\Models\SurveyProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyProject>
 */
class SurveyProjectFactory extends Factory
{
    protected $model = SurveyProject::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => 'Étude '.fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'client_name' => fake()->company(),
            'settings' => ['timezone' => 'Africa/Douala', 'default_language' => 'fr'],
        ];
    }
}
