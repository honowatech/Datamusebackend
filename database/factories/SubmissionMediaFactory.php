<?php

namespace Database\Factories;

use App\Enums\MediaState;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubmissionMedia>
 */
class SubmissionMediaFactory extends Factory
{
    protected $model = SubmissionMedia::class;

    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'question_key' => 'photo_recu_momo',
            'repeat_index' => 0,
            'disk' => 'local',
            'path' => null,
            'mime' => 'image/jpeg',
            'size' => fake()->numberBetween(50_000, 900_000),
            'sha256' => hash('sha256', Str::random(32)),
            'state' => MediaState::Pending,
        ];
    }

    public function uploaded(): static
    {
        return $this->state(fn (array $attrs) => [
            'state' => MediaState::Uploaded,
            'path' => sprintf('surveys/%d/submissions/%s/%s.jpg', 1, Str::uuid(), $attrs['question_key'] ?? 'media'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['state' => MediaState::Failed]);
    }

    public function audio(): static
    {
        return $this->state(['question_key' => 'audio_entretien', 'mime' => 'audio/mp4']);
    }

    public function signature(): static
    {
        return $this->state(['question_key' => 'signature', 'mime' => 'image/png']);
    }
}
