<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\VerbatimCodebook;
use App\Models\VerbatimCoding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerbatimCoding>
 */
class VerbatimCodingFactory extends Factory
{
    protected $model = VerbatimCoding::class;

    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'survey_id' => fn (array $attrs) => Submission::find($attrs['submission_id'])?->survey_id,
            'question_key' => 'q6_freins',
            'codebook_id' => fn (array $attrs) => VerbatimCodebook::factory()->create([
                'survey_id' => $attrs['survey_id'],
                'question_key' => $attrs['question_key'] ?? 'q6_freins',
            ])->id,
            'themes' => ['confiance_donnees'],
            'sentiment' => fake()->randomElement(['positive', 'neutral', 'negative', 'mixed']),
            'confidence' => fake()->randomFloat(3, 0.5, 0.99),
            'source' => VerbatimCoding::SOURCE_AI,
        ];
    }

    public function manual(): static
    {
        return $this->state(['source' => VerbatimCoding::SOURCE_MANUAL, 'confidence' => null]);
    }
}
