<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\ProjectMember;
use App\Models\SurveyProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMember>
 */
class ProjectMemberFactory extends Factory
{
    protected $model = ProjectMember::class;

    public function definition(): array
    {
        return [
            'project_id' => SurveyProject::factory(),
            'user_id' => User::factory(),
            'role' => ProjectRole::Enqueteur,
            'zone' => fake()->randomElement(['Akwa', 'Bonamoussadi', 'Deido', 'Makepe', 'Logpom', null]),
            'status' => ProjectMember::STATUS_ACTIVE,
        ];
    }

    public function analyste(): static
    {
        return $this->state(['role' => ProjectRole::Analyste, 'zone' => null]);
    }

    public function superviseur(): static
    {
        return $this->state(['role' => ProjectRole::Superviseur]);
    }

    public function enqueteur(): static
    {
        return $this->state(['role' => ProjectRole::Enqueteur]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => ProjectMember::STATUS_INACTIVE]);
    }
}
