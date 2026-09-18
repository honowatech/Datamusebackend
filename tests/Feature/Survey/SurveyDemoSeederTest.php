<?php

namespace Tests\Feature\Survey;

use App\Enums\ProjectRole;
use App\Enums\SubmissionStatus;
use App\Enums\SurveyStatus;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\PublicLink;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyProject;
use App\Models\User;
use Database\Seeders\SurveyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * B-13 — `SurveyDemoSeeder` : jeu de démonstration MunaGo utilisable après `migrate:fresh --seed`.
 *
 * La file de test est `sync` : la matérialisation lancée par le seeder s'exécute dans la foulée et le
 * fichier SQLite atterrit dans le répertoire temporaire isolé par `Tests\TestCase`.
 */
class SurveyDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_the_munago_demo(): void
    {
        $this->seed(SurveyDemoSeeder::class);

        // Comptes de démonstration.
        $analyst = User::query()->where('email', 'analyste@datamuse.local')->firstOrFail();
        foreach ([1, 2, 3] as $n) {
            $enumerator = User::query()->where('email', "enq{$n}@test.local")->firstOrFail();
            $this->assertTrue(Hash::check(SurveyDemoSeeder::PASSWORD, $enumerator->password));
            $this->assertSame(
                ProjectRole::Enqueteur,
                ProjectMember::query()->where('user_id', $enumerator->id)->value('role'),
            );
        }

        // Projet et questionnaire publié.
        $project = SurveyProject::query()->where('name', SurveyDemoSeeder::PROJECT_NAME)->firstOrFail();
        $this->assertSame($analyst->id, $project->owner_id);

        $survey = Survey::query()->where('slug', SurveyDemoSeeder::SURVEY_SLUG)->firstOrFail();
        $this->assertSame($project->id, $survey->project_id);
        $this->assertSame(SurveyStatus::Active, $survey->status);
        $this->assertNotNull($survey->published_version_id);
        $this->assertCount(3, $survey->assignments);

        $definition = $survey->publishedVersion->definition;
        $this->assertSame('1.0', $definition['dfs_version']);
        $this->assertNotEmpty($definition['sections']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $survey->publishedVersion->definition_hash);

        // Terrain : 60 fiches (8 hors cible), suivis et médias.
        $this->assertSame(60, Submission::query()->where('survey_id', $survey->id)->count());
        $this->assertSame(60, (int) $survey->submissions_count);
        $this->assertSame(8, Submission::query()->where('survey_id', $survey->id)->where('status', SubmissionStatus::ScreenedOut->value)->count());
        $this->assertSame(12, FollowUpEntry::query()->where('survey_id', $survey->id)->count());
        $this->assertGreaterThan(0, $survey->submissions()->first()->id);

        // Lien public actif.
        $link = PublicLink::query()->where('token', SurveyDemoSeeder::PUBLIC_LINK_TOKEN)->firstOrFail();
        $this->assertSame($survey->id, $link->survey_id);
        $this->assertTrue((bool) $link->is_active);

        // Source de données matérialisée (60 lignes, plus `dirty`).
        $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->firstOrFail();
        $this->assertTrue($datasource->isMaterialized());
        $this->assertFalse((bool) $datasource->dirty);
        $this->assertSame(60, (int) $datasource->row_count);
        $this->assertFileExists((string) $datasource->targetDatabase->database);
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed(SurveyDemoSeeder::class);
        $this->seed(SurveyDemoSeeder::class);

        $this->assertSame(1, SurveyProject::query()->where('name', SurveyDemoSeeder::PROJECT_NAME)->count());
        $this->assertSame(1, Survey::query()->where('slug', SurveyDemoSeeder::SURVEY_SLUG)->count());
        $this->assertSame(60, Submission::query()->count());
        $this->assertSame(12, FollowUpEntry::query()->count());
        $this->assertSame(1, PublicLink::query()->count());
        $this->assertSame(4, User::query()->whereIn('email', [
            'analyste@datamuse.local', 'enq1@test.local', 'enq2@test.local', 'enq3@test.local',
        ])->count());
        // Une seule version publiée : le second passage ne republie pas.
        $this->assertSame(1, Survey::query()->where('slug', SurveyDemoSeeder::SURVEY_SLUG)->firstOrFail()->versions()->count());
    }

    public function test_the_public_link_serves_the_demo_survey(): void
    {
        $this->seed(SurveyDemoSeeder::class);

        $this->getJson('/api/public/surveys/'.SurveyDemoSeeder::PUBLIC_LINK_TOKEN)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonStructure(['data' => ['survey_id', 'title', 'definition_hash', 'definition', 'remaining']]);
    }
}
