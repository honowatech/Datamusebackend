<?php

namespace Database\Factories;

use App\Enums\JobKind;
use App\Enums\JobStatus;
use App\Models\AiJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiJob>
 */
class AiJobFactory extends Factory
{
    protected $model = AiJob::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'survey_id' => null,
            'kind' => JobKind::FormGeneration,
            'status' => JobStatus::Queued,
            'progress' => 0,
            'message' => null,
            'input' => ['language' => 'fr'],
            'result_ref' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function kind(JobKind $kind): static
    {
        return $this->state(['kind' => $kind]);
    }

    public function running(int $progress = 50): static
    {
        return $this->state([
            'status' => JobStatus::Running,
            'progress' => $progress,
            'started_at' => now()->subMinute(),
        ]);
    }

    public function done(?string $resultRef = 'survey_version:1'): static
    {
        return $this->state([
            'status' => JobStatus::Done,
            'progress' => 100,
            'result_ref' => $resultRef,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $error = 'Réponse IA invalide'): static
    {
        return $this->state([
            'status' => JobStatus::Failed,
            'error' => $error,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
    }
}
