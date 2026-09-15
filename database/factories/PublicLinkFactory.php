<?php

namespace Database\Factories;

use App\Models\PublicLink;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicLink>
 */
class PublicLinkFactory extends Factory
{
    protected $model = PublicLink::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'token' => PublicLink::generateToken(),
            'label' => 'Lien public',
            'expires_at' => null,
            'max_responses' => null,
            'responses_count' => 0,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }

    public function full(): static
    {
        return $this->state(['max_responses' => 10, 'responses_count' => 10]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
