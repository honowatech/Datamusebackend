<?php

namespace Database\Factories;

use App\Enums\ProjectRole;
use App\Models\ProjectInvitation;
use App\Models\SurveyProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectInvitation>
 */
class ProjectInvitationFactory extends Factory
{
    protected $model = ProjectInvitation::class;

    public function definition(): array
    {
        return [
            'project_id' => SurveyProject::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => ProjectRole::Enqueteur,
            'zone' => null,
            'token' => ProjectInvitation::generateToken(),
            'join_code' => null,
            'max_uses' => 1,
            'uses' => 0,
            'expires_at' => now()->addDays(7),
            'invited_by' => User::factory(),
            'accepted_at' => null,
        ];
    }

    /** Invitation par code de connexion (sans email, réutilisable). */
    public function joinCode(int $maxUses = 10): static
    {
        return $this->state([
            'email' => null,
            'join_code' => ProjectInvitation::generateJoinCode(),
            'max_uses' => $maxUses,
        ]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(['uses' => 1, 'accepted_at' => now()]);
    }
}
