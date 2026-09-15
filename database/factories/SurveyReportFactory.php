<?php

namespace Database\Factories;

use App\Enums\JobStatus;
use App\Enums\ReportOrientation;
use App\Models\Survey;
use App\Models\SurveyReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyReport>
 */
class SurveyReportFactory extends Factory
{
    protected $model = SurveyReport::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'requested_by' => User::factory(),
            'title' => 'Rapport '.fake()->words(2, true),
            'brief' => fake()->paragraph(),
            'orientation' => ReportOrientation::Commercial,
            'audience' => 'Direction commerciale',
            'language' => 'fr',
            'status' => JobStatus::Queued,
            'provider' => null,
            'model' => null,
            'content_md' => null,
            'content_json' => null,
            'generated_files' => null,
            'tokens_used' => 0,
        ];
    }

    public function done(): static
    {
        return $this->state([
            'status' => JobStatus::Done,
            'provider' => 'gemini',
            'model' => 'gemini-1.5-flash',
            'content_md' => "# Synthèse\n\nLe taux d'acompte atteint 10 %.",
            'content_json' => [
                'title' => 'Synthèse',
                'sections' => [['heading' => 'Synthèse', 'body_md' => "Le taux d'acompte atteint 10 %."]],
            ],
            'tokens_used' => 1200,
        ]);
    }

    public function failed(): static
    {
        return $this->state(['status' => JobStatus::Failed]);
    }
}
