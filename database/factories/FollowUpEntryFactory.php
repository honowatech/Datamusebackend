<?php

namespace Database\Factories;

use App\Enums\FollowUpStatus;
use App\Models\FollowUpEntry;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<FollowUpEntry>
 */
class FollowUpEntryFactory extends Factory
{
    protected $model = FollowUpEntry::class;

    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'survey_id' => fn (array $attrs) => Submission::find($attrs['submission_id'])?->survey_id,
            'stage_key' => 'j4',
            'enumerator_id' => fn (array $attrs) => Submission::find($attrs['submission_id'])?->enumerator_id,
            'due_at' => fn (array $attrs) => self::endedAt($attrs)->addDays(4),
            'window_ends_at' => fn (array $attrs) => self::endedAt($attrs)->addDays(4 + 3),
            'status' => FollowUpStatus::Pending,
            'answers' => null,
            'completed_at' => null,
            'client_updated_at' => null,
        ];
    }

    /** Étape et échéances calculées depuis la fin de l'entretien (README § 14). */
    public function stage(string $key, int $dueOffsetDays, int $windowDays = 3): static
    {
        // Closures différées : évaluées après expansion de submission_id.
        return $this->state([
            'stage_key' => $key,
            'due_at' => fn (array $attrs) => self::endedAt($attrs)->addDays($dueOffsetDays),
            'window_ends_at' => fn (array $attrs) => self::endedAt($attrs)->addDays($dueOffsetDays + $windowDays),
        ]);
    }

    /** @param  array<string, mixed>  $answers */
    public function done(array $answers = ['j4_rappel_envoye' => 'oui']): static
    {
        return $this->state([
            'status' => FollowUpStatus::Done,
            'answers' => $answers,
            'completed_at' => fn (array $attrs) => Carbon::parse($attrs['due_at'] ?? now())->addHours(2),
            'client_updated_at' => fn (array $attrs) => Carbon::parse($attrs['due_at'] ?? now())->addHours(2),
        ]);
    }

    public function missed(): static
    {
        return $this->state(['status' => FollowUpStatus::Missed]);
    }

    public function skipped(): static
    {
        return $this->state(['status' => FollowUpStatus::Skipped]);
    }

    /** @param  array<string, mixed>  $attrs */
    private static function endedAt(array $attrs): Carbon
    {
        $submission = isset($attrs['submission_id']) ? Submission::find($attrs['submission_id']) : null;

        return $submission?->ended_at?->copy() ?? now();
    }
}
