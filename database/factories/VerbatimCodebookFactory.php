<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\VerbatimCodebook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerbatimCodebook>
 */
class VerbatimCodebookFactory extends Factory
{
    protected $model = VerbatimCodebook::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'question_key' => 'q6_freins',
            'version' => fn (array $attrs) => (int) (VerbatimCodebook::query()
                ->where('survey_id', $attrs['survey_id'])
                ->where('question_key', $attrs['question_key'] ?? 'q6_freins')
                ->max('version') ?? 0) + 1,
            'themes' => [
                ['key' => 'confiance_donnees', 'label' => 'Confiance / données personnelles'],
                ['key' => 'prix', 'label' => 'Prix jugé élevé'],
                ['key' => 'enfant_portera_pas', 'label' => "L'enfant ne le portera pas"],
            ],
            'source' => VerbatimCodebook::SOURCE_AI,
        ];
    }

    public function manual(): static
    {
        return $this->state(['source' => VerbatimCodebook::SOURCE_MANUAL]);
    }
}
