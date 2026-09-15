<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\TargetDatabase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyDatasource>
 */
class SurveyDatasourceFactory extends Factory
{
    protected $model = SurveyDatasource::class;

    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'target_database_id' => null,
            'file_version' => 0,
            'dirty' => true,
            'dirty_since' => now(),
            'last_materialized_at' => null,
            'row_count' => 0,
            'last_error' => null,
        ];
    }

    /** Source déjà matérialisée dans une TargetDatabase SQLite. */
    public function materialized(int $rows = 0): static
    {
        return $this->state([
            // Closure différée : survey_id est déjà expansé quand elle s'exécute.
            'target_database_id' => function (array $attrs) {
                $survey = Survey::with('project')->find($attrs['survey_id']);
                $ownerId = $survey?->project?->owner_id ?? User::factory()->create()->id;

                return TargetDatabase::create([
                    'user_id' => $ownerId,
                    'name' => 'Enquête : '.($survey?->title ?? 'test'),
                    'driver' => 'sqlite',
                    'host' => '',
                    'port' => '',
                    'database' => sprintf('imported_databases/user_%d/survey_%d_v1.sqlite', $ownerId, $survey?->id ?? 0),
                    'username' => '',
                    'password' => '',
                ])->id;
            },
            'file_version' => 1,
            'dirty' => false,
            'dirty_since' => null,
            'last_materialized_at' => now(),
            'row_count' => $rows,
        ]);
    }
}
