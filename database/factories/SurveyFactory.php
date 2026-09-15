<?php

namespace Database\Factories;

use App\Enums\SurveyStatus;
use App\Models\Survey;
use App\Models\SurveyProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    protected $model = Survey::class;

    public function definition(): array
    {
        $title = 'Enquête '.fake()->unique()->words(3, true);

        return [
            'project_id' => SurveyProject::factory(),
            'created_by' => fn (array $attrs) => SurveyProject::find($attrs['project_id'])?->owner_id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'status' => SurveyStatus::Draft,
            'submissions_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => SurveyStatus::Active]);
    }

    public function closed(): static
    {
        return $this->state(['status' => SurveyStatus::Closed]);
    }
}
