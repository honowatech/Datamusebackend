<?php

namespace Tests\Feature\Analysis;

use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\TargetDatabase;
use App\Models\User;
use App\Services\Survey\SurveyMaterializationService;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Socle d'analyse native : `GET /surveys/{id}/analysis/dictionary|dataset` et
 * `GET /target-dbs/{id}/analysis/dictionary`. Données entièrement synthétiques.
 */
class AnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    private User $analyst;

    private User $supervisor;

    private User $enumerator;

    private User $stranger;

    private Survey $survey;

    private SurveyVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyst = User::factory()->create(['role' => UserRole::Analyste]);
        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur, 'name' => 'Enquêtrice Test', 'email' => 'enq-analyse@test.local']);
        $this->stranger = User::factory()->create(['role' => UserRole::Analyste]);

        $project = SurveyProject::factory()->create(['owner_id' => $this->analyst->id]);
        ProjectMember::factory()->analyste()->create(['project_id' => $project->id, 'user_id' => $this->analyst->id]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $project->id, 'user_id' => $this->supervisor->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $project->id, 'user_id' => $this->enumerator->id]);

        $this->survey = Survey::factory()->create(['project_id' => $project->id, 'created_by' => $this->analyst->id]);
        $this->version = SurveyVersion::factory()->published()->create([
            'survey_id' => $this->survey->id,
            'definition' => self::definition(),
        ]);
        $this->survey->refresh();
    }

    private function url(string $suffix): string
    {
        return '/api/surveys/'.$this->survey->id.'/analysis/'.$suffix;
    }

    // ================================================================== dictionnaire

    public function test_dictionary_maps_every_dfs_type_to_a_level_and_a_real_column(): void
    {
        $this->seedAndMaterialize();

        $response = $this->actingAs($this->analyst, 'sanctum')->getJson($this->url('dictionary'))->assertOk();
        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.dataset.survey_id', $this->survey->id)
            ->assertJsonPath('data.dataset.rows', 6);
        $this->assertNotNull($response->json('data.dataset.materialized_at'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->json('data.dataset.etag'));

        $variables = collect($response->json('data.variables'))->keyBy('name');

        // nom => [niveau, type DFS, table, colonne, multiple]
        $expected = [
            'region' => ['nominal', 'select_one', 'reponses', 'region', false],
            'region_other' => ['text', 'text', 'reponses', 'region_other', false],
            'satisfaction' => ['ordinal', 'select_one', 'reponses', 'satisfaction', false],
            'equipements' => ['multiple', 'select_multiple', 'reponses', 'equipements', true],
            'priorites' => ['multiple', 'rank', 'reponses', 'priorites', true],
            'avis' => ['text', 'text', 'reponses', 'avis', false],
            'avis__codes' => ['nominal', 'select_one', 'reponses', 'avis_codes', false],
            'telephone' => ['text', 'text', 'reponses', 'telephone', false],
            'age' => ['numeric', 'integer', 'reponses', 'age', false],
            'taille' => ['numeric', 'decimal', 'reponses', 'taille', false],
            'revenu' => ['numeric', 'currency', 'reponses', 'revenu', false],
            'date_visite' => ['date', 'date', 'reponses', 'date_visite', false],
            'heure_visite' => ['date', 'time', 'reponses', 'heure_visite', false],
            'horodatage' => ['date', 'datetime', 'reponses', 'horodatage', false],
            'domicile' => ['geo', 'geopoint', 'reponses', 'domicile_lat', false],
            'age_double' => ['numeric', 'calculate', 'reponses', 'age_double', false],
            'profil' => ['nominal', 'calculate', 'reponses', 'profil', false],
            'membre_age' => ['numeric', 'integer', 'rep_membres', 'membre_age', false],
            'membre_lien' => ['nominal', 'select_one', 'rep_membres', 'membre_lien', false],
            'suivi_note' => ['numeric', 'integer', 'reponses', 'suivi_note', false],
            '_zone' => ['nominal', 'meta', 'reponses', 'zone', false],
            '_canal' => ['nominal', 'meta', 'reponses', 'canal', false],
            '_enqueteur' => ['nominal', 'meta', 'reponses', 'enqueteur_id', false],
            '_fin' => ['date', 'meta', 'reponses', 'fin', false],
            '_duree_sec' => ['numeric', 'meta', 'reponses', 'duree_sec', false],
            '_position' => ['geo', 'meta', 'reponses', 'lat', false],
        ];

        $pdo = new PDO('sqlite:'.$this->sourcePath());
        foreach ($expected as $name => [$level, $dfsType, $table, $column, $multiple]) {
            $this->assertTrue($variables->has($name), "variable « $name » absente");
            $variable = $variables[$name];
            $this->assertSame($level, $variable['level'], "niveau de « $name »");
            $this->assertSame($dfsType, $variable['dfs_type'], "type DFS de « $name »");
            $this->assertSame($table, $variable['table'], "table de « $name »");
            $this->assertSame($column, $variable['column'], "colonne de « $name »");
            $this->assertSame($multiple, $variable['multiple'], "multiple de « $name »");
            $this->assertIsArray($variable['choices']);
            $this->assertSame(['name', 'label', 'level', 'dfs_type', 'table', 'column', 'choices', 'multiple'], array_slice(array_keys($variable), 0, 8));

            // L'emplacement annoncé existe réellement dans le fichier matérialisé.
            $columns = array_column($pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $this->assertContains($column, $columns, "colonne « $table.$column » introuvable dans le SQLite");
        }
        $pdo = null;

        // Médias, notes et arrêts ne sont pas des variables d'analyse.
        foreach (['photo_facade', 'consigne', 'stop_fin'] as $absent) {
            $this->assertFalse($variables->has($absent), "« $absent » ne devrait pas être une variable");
        }

        $this->assertSame(
            [['code' => 'nord', 'label' => 'Nord'], ['code' => 'sud', 'label' => 'Sud'], ['code' => 'autre', 'label' => 'Autre']],
            $variables['region']['choices'],
        );
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($variables['satisfaction']['choices'], 'code'));
        $this->assertSame(['lat' => 'domicile_lat', 'lng' => 'domicile_lng', 'precision' => 'domicile_precision'], $variables['domicile']['columns']);
        $this->assertSame(['lat' => 'lat', 'lng' => 'lng', 'precision' => 'precision_m'], $variables['_position']['columns']);
        $this->assertEqualsCanonicalizing(['jeune', 'senior'], array_column($variables['profil']['choices'], 'code'));
        $this->assertSame(
            [['code' => (string) $this->enumerator->id, 'label' => 'Enquêtrice Test']],
            $variables['_enqueteur']['choices'],
        );
        $this->assertSame('Région', $variables['region']['label']);
    }

    public function test_dictionary_labels_follow_the_requested_language(): void
    {
        $this->seedAndMaterialize();

        $variables = collect($this->actingAs($this->analyst, 'sanctum')->getJson($this->url('dictionary').'?lang=en')->assertOk()->json('data.variables'))->keyBy('name');

        $this->assertSame('Region', $variables['region']['label']);
        $this->assertSame('North', $variables['region']['choices'][0]['label']);
        $this->assertSame('Very satisfied', $variables['satisfaction']['choices'][4]['label']);
        $this->assertSame('Âge', $variables['age']['label'], 'repli sur la langue par défaut quand la traduction manque');
        $this->assertSame('Collection channel', $variables['_canal']['label']);
    }

    public function test_dictionary_before_materialization_describes_the_planned_layout_without_etag(): void
    {
        $response = $this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('dictionary'))->assertOk();

        $response->assertJsonPath('data.dataset.rows', 0)
            ->assertJsonPath('data.dataset.materialized_at', null)
            ->assertJsonPath('data.dataset.etag', null);
        $names = array_column($response->json('data.variables'), 'name');
        $this->assertContains('region', $names);
        $this->assertContains('_zone', $names);
    }

    public function test_pii_variables_are_listed_for_analysts_only(): void
    {
        $this->seedAndMaterialize();

        $forAnalyst = array_column($this->actingAs($this->analyst, 'sanctum')->getJson($this->url('dictionary'))->json('data.variables'), 'name');
        $forSupervisor = array_column($this->actingAs($this->supervisor, 'sanctum')->getJson($this->url('dictionary'))->json('data.variables'), 'name');

        $this->assertContains('telephone', $forAnalyst);
        $this->assertNotContains('telephone', $forSupervisor);
        $this->assertContains('region', $forSupervisor);
    }

    // ================================================================== autorisations

    public function test_both_endpoints_follow_the_stats_authorization_rules(): void
    {
        $this->seedAndMaterialize();

        $this->getJson($this->url('dictionary'))->assertStatus(401);
        $this->getJson($this->url('dataset'))->assertStatus(401);

        foreach (['dictionary', 'dataset'] as $endpoint) {
            $this->actingAs($this->stranger, 'sanctum')->getJson($this->url($endpoint))->assertStatus(403);
            $this->actingAs($this->enumerator, 'sanctum')->getJson($this->url($endpoint))->assertStatus(403);
            $this->actingAs($this->supervisor, 'sanctum')->get($this->url($endpoint))->assertOk();
            $this->actingAs($this->analyst, 'sanctum')->get($this->url($endpoint))->assertOk();
        }
    }

    // ================================================================== jeu de données

    public function test_dataset_is_409_until_the_survey_is_materialized(): void
    {
        $this->actingAs($this->analyst, 'sanctum')->getJson($this->url('dataset'))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'datasource_not_ready');
        $this->assertStringContainsString("n'est pas encore prête", (string) $this->actingAs($this->analyst, 'sanctum')->getJson($this->url('dataset'))->json('message'));
    }

    public function test_dataset_serves_the_sqlite_file_with_an_etag_and_answers_304_when_unchanged(): void
    {
        $this->seedAndMaterialize();
        $this->actingAs($this->analyst, 'sanctum');

        $etag = $this->getJson($this->url('dictionary'))->json('data.dataset.etag');

        $response = $this->get($this->url('dataset'))->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.sqlite3');
        $response->assertHeader('ETag', '"'.$etag.'"');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));

        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertSame('SQLite format 3', substr((string) file_get_contents($path), 0, 15));
        $pdo = new PDO('sqlite:'.$path);
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM reponses')->fetchColumn());
        $pdo = null;

        // Revalidation : même ETag (entre guillemets, faible, nu ou suffixé par mod_deflate) → 304 sans corps.
        foreach (['"'.$etag.'"', 'W/"'.$etag.'"', $etag, '"'.$etag.'-gzip"', '"autre", "'.$etag.'"'] as $header) {
            $notModified = $this->get($this->url('dataset'), ['If-None-Match' => $header]);
            $notModified->assertStatus(304)->assertHeader('ETag', '"'.$etag.'"');
            $this->assertSame('', (string) $notModified->getContent());
        }
        $this->get($this->url('dataset'), ['If-None-Match' => '"perime"'])->assertOk();

        // Nouvelle matérialisation → nouvel ETag, l'ancien ne vaut plus 304, l'ancienne copie est nettoyée.
        Submission::factory()->create($this->submissionAttributes(['region' => 'sud', 'age' => 51]));
        app(SurveyMaterializationService::class)->materialize($this->survey->refresh());

        $newEtag = $this->getJson($this->url('dictionary'))->json('data.dataset.etag');
        $this->assertNotSame($etag, $newEtag);
        $fresh = $this->get($this->url('dataset'), ['If-None-Match' => '"'.$etag.'"'])->assertOk();
        $fresh->assertHeader('ETag', '"'.$newEtag.'"');
        $this->assertFileDoesNotExist($path);
        $this->assertCount(1, glob(dirname($path).'/*.analysis-*'));
    }

    public function test_dataset_never_exposes_more_than_the_exports(): void
    {
        $this->seedAndMaterialize();

        // Le fichier matérialisé brut contient bien ces données sensibles…
        $raw = new PDO('sqlite:'.$this->sourcePath());
        $this->assertSame('enq-analyse@test.local', $raw->query('SELECT email FROM enqueteurs')->fetchColumn());
        $this->assertGreaterThan(0, (int) $raw->query('SELECT COUNT(*) FROM medias')->fetchColumn());
        $this->assertSame(6, (int) $raw->query('SELECT COUNT(telephone) FROM reponses')->fetchColumn());
        $raw = null;

        // … l'analyste (qui peut exporter avec `include_pii`) garde les colonnes pii, mais ni les
        // coordonnées des enquêteurs ni la table des médias.
        $full = $this->actingAs($this->analyst, 'sanctum')->get($this->url('dataset'))->assertOk();
        $fullPath = $full->baseResponse->getFile()->getPathname();
        $pdo = new PDO('sqlite:'.$fullPath);
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(telephone) FROM reponses')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM medias')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(email) + COUNT(telephone) FROM enqueteurs')->fetchColumn());
        $this->assertSame('Enquêtrice Test', $pdo->query('SELECT nom FROM enqueteurs')->fetchColumn());
        $this->assertSame('full', $pdo->query("SELECT valeur FROM _meta WHERE cle = 'analysis_variant'")->fetchColumn());
        $pdo = null;

        // Le superviseur (pas d'export, fiches masquées) reçoit une variante sans aucune valeur pii.
        $masked = $this->actingAs($this->supervisor, 'sanctum')->get($this->url('dataset'))->assertOk();
        $maskedPath = $masked->baseResponse->getFile()->getPathname();
        $this->assertNotSame($fullPath, $maskedPath);
        $this->assertNotSame($full->headers->get('ETag'), $masked->headers->get('ETag'));

        $pdo = new PDO('sqlite:'.$maskedPath);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(telephone) FROM reponses')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM reponses_long WHERE question_key = 'telephone'")->fetchColumn());
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(region) FROM reponses')->fetchColumn());
        $this->assertSame('masked', $pdo->query("SELECT valeur FROM _meta WHERE cle = 'analysis_variant'")->fetchColumn());
        $pdo = null;

        // Aucune trace résiduelle dans les pages libres du fichier (VACUUM).
        $bytes = (string) file_get_contents($maskedPath);
        $this->assertStringNotContainsString('699000001', $bytes);
        $this->assertStringNotContainsString('enq-analyse@test.local', $bytes);
        $this->assertStringNotContainsString('enq-analyse@test.local', (string) file_get_contents($fullPath));

        // Le fichier matérialisé d'origine (chat SQL) n'est pas modifié.
        $raw = new PDO('sqlite:'.$this->sourcePath());
        $this->assertSame(6, (int) $raw->query('SELECT COUNT(telephone) FROM reponses')->fetchColumn());
        $raw = null;
    }

    // ================================================================== tableau croisé (format étendu)

    public function test_crosstab_keeps_its_format_and_adds_df_and_cramers_v(): void
    {
        $this->seedAndMaterialize();

        $data = $this->actingAs($this->supervisor, 'sanctum')
            ->getJson('/api/surveys/'.$this->survey->id.'/stats/crosstab?row=region&col=profil')
            ->assertOk()
            ->json('data');

        $this->assertSame(['row', 'col', 'rows', 'cols', 'cells', 'n', 'chi2', 'p_value', 'df', 'cramers_v'], array_keys($data));
        $this->assertSame(6, $data['n']);
        $this->assertSame(2, $data['df']);
        // nord : 2 jeunes / 1 senior ; sud : 0 / 2 ; autre : 1 / 0 → khi² = 3,3333, p = exp(−khi²/2).
        $this->assertSame(3.3333, $data['chi2']);
        $this->assertEqualsWithDelta(exp(-10 / 6), $data['p_value'], 1e-6);
        $this->assertEqualsWithDelta(sqrt((10 / 3) / 6), $data['cramers_v'], 1e-4);
    }

    // ================================================================== fichier importé

    public function test_imported_dataset_dictionary_infers_levels_by_profiling(): void
    {
        $dir = storage_path('app/imported_databases/user_'.$this->analyst->id.'_test_'.Str::lower(Str::random(6)));
        File::ensureDirectoryExists($dir);
        $path = $dir.DIRECTORY_SEPARATOR.'import.sqlite';

        try {
            $pdo = new PDO('sqlite:'.$path);
            $pdo->exec('CREATE TABLE "clients" ("ville" TEXT, "ca" TEXT, "inscrit_le" TEXT, "remarque" TEXT)');
            $insert = $pdo->prepare('INSERT INTO "clients" VALUES (?, ?, ?, ?)');
            for ($i = 0; $i < 40; $i++) {
                $insert->execute([['Douala', 'Yaoundé'][$i % 2], (string) (100 + $i * 3.5), sprintf('%02d/03/2026', 1 + $i % 28), 'Client fictif '.$i.' rencontré au salon']);
            }
            $pdo = null;

            $target = TargetDatabase::query()->create([
                'user_id' => $this->analyst->id, 'name' => 'Import de test', 'driver' => 'sqlite',
                'host' => 'localhost', 'port' => '0', 'username' => 'sqlite', 'database' => $path, 'password' => '',
            ]);

            $response = $this->actingAs($this->analyst, 'sanctum')->getJson('/api/target-dbs/'.$target->id.'/analysis/dictionary')->assertOk();
            $response->assertJsonPath('data.dataset.survey_id', null)
                ->assertJsonPath('data.dataset.target_database_id', $target->id)
                ->assertJsonPath('data.dataset.rows', 40);

            $variables = collect($response->json('data.variables'))->keyBy('name');
            $this->assertSame('nominal', $variables['ville']['level']);
            $this->assertEqualsCanonicalizing(['Douala', 'Yaoundé'], array_column($variables['ville']['choices'], 'code'));
            $this->assertSame('numeric', $variables['ca']['level']);
            $this->assertSame('date', $variables['inscrit_le']['level']);
            $this->assertSame('text', $variables['remarque']['level']);
            $this->assertSame(['table' => 'clients', 'column' => 'ca'], ['table' => $variables['ca']['table'], 'column' => $variables['ca']['column']]);
            $this->assertNull($variables['ca']['dfs_type']);

            // Fichier d'un autre utilisateur : introuvable.
            $this->actingAs($this->stranger, 'sanctum')->getJson('/api/target-dbs/'.$target->id.'/analysis/dictionary')->assertStatus(404);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_imported_dictionary_refuses_survey_datasources_and_foreign_paths(): void
    {
        $this->seedAndMaterialize();
        $surveyDb = TargetDatabase::query()->where('database', $this->sourcePath())->firstOrFail();

        // Une source d'enquête passerait outre le masquage pii : renvoi vers la route d'enquête.
        $this->actingAs($this->analyst, 'sanctum')->getJson('/api/target-dbs/'.$surveyDb->id.'/analysis/dictionary')
            ->assertStatus(422)
            ->assertJsonPath('code', 'survey_datasource')
            ->assertJsonPath('survey_id', $this->survey->id);

        // Une « connexion » SQLite vers un fichier hors du répertoire des imports n'est pas profilée.
        $elsewhere = TargetDatabase::query()->create([
            'user_id' => $this->analyst->id, 'name' => 'Ailleurs', 'driver' => 'sqlite',
            'host' => 'localhost', 'port' => '0', 'username' => 'sqlite', 'database' => $this->sourcePath().'.copie', 'password' => '',
        ]);
        File::copy($this->sourcePath(), $this->sourcePath().'.copie');
        $this->actingAs($this->analyst, 'sanctum')->getJson('/api/target-dbs/'.$elsewhere->id.'/analysis/dictionary')
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_an_imported_file');
    }

    // ================================================================== helpers

    private function sourcePath(): string
    {
        return (string) TargetDatabase::query()
            ->whereKey($this->survey->refresh()->datasource()->value('target_database_id'))
            ->value('database');
    }

    private function seedAndMaterialize(): void
    {
        $rows = [
            ['region' => 'nord', 'satisfaction' => '5', 'age' => 24, 'profil' => 'jeune'],
            ['region' => 'nord', 'satisfaction' => '4', 'age' => 31, 'profil' => 'jeune'],
            ['region' => 'sud', 'satisfaction' => '2', 'age' => 47, 'profil' => 'senior'],
            ['region' => 'sud', 'satisfaction' => '1', 'age' => 62, 'profil' => 'senior'],
            ['region' => 'autre', 'region_other' => 'Littoral fictif', 'satisfaction' => '3', 'age' => 38, 'profil' => 'jeune'],
            ['region' => 'nord', 'satisfaction' => '5', 'age' => 55, 'profil' => 'senior'],
        ];
        foreach ($rows as $i => $row) {
            $submission = Submission::factory()->create($this->submissionAttributes($row + [
                'equipements' => $i % 2 === 0 ? ['eau', 'electricite'] : ['eau'],
                'priorites' => ['sante', 'ecole', 'route'],
                'avis' => 'Avis fictif numéro '.$i,
                'avis__codes' => $i % 2 === 0 ? 'positif' : 'negatif',
                'telephone' => '69900000'.($i + 1),
                'taille' => 1.5 + $i / 10,
                'revenu' => 50000 + 1000 * $i,
                'date_visite' => '2026-03-0'.($i + 1),
                'heure_visite' => '10:1'.$i,
                'horodatage' => '2026-03-0'.($i + 1).'T10:15:00+01:00',
                'domicile' => ['lat' => 4.05 + $i / 1000, 'lng' => 9.7, 'accuracy' => 8],
                'age_double' => $row['age'] * 2,
                'membres' => [['membre_age' => 10 + $i, 'membre_lien' => 'enfant'], ['membre_age' => 40 + $i, 'membre_lien' => 'conjoint']],
            ]));
            SubmissionMedia::factory()->create(['submission_id' => $submission->id, 'question_key' => 'photo_facade', 'path' => 'surveys/media/fictif-'.$i.'.jpg']);
        }

        app(SurveyMaterializationService::class)->materialize($this->survey->refresh());
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function submissionAttributes(array $answers): array
    {
        return [
            'survey_id' => $this->survey->id,
            'survey_version_id' => $this->version->id,
            'project_id' => $this->survey->project_id,
            'enumerator_id' => $this->enumerator->id,
            'answers' => $answers,
        ];
    }

    /**
     * Questionnaire synthétique couvrant tous les types DFS v1.
     *
     * @return array<string, mixed>
     */
    private static function definition(): array
    {
        $definition = SurveyVersionFactory::minimalDefinition();
        $definition['settings']['languages'] = ['fr', 'en'];
        $definition['choice_lists'] = [
            'regions' => [
                ['name' => 'nord', 'label' => ['fr' => 'Nord', 'en' => 'North']],
                ['name' => 'sud', 'label' => ['fr' => 'Sud', 'en' => 'South']],
                ['name' => 'autre', 'label' => ['fr' => 'Autre', 'en' => 'Other']],
            ],
            'echelle' => [
                ['name' => '1', 'label' => ['fr' => 'Très insatisfait', 'en' => 'Very dissatisfied']],
                ['name' => '2', 'label' => ['fr' => 'Insatisfait', 'en' => 'Dissatisfied']],
                ['name' => '3', 'label' => ['fr' => 'Neutre', 'en' => 'Neutral']],
                ['name' => '4', 'label' => ['fr' => 'Satisfait', 'en' => 'Satisfied']],
                ['name' => '5', 'label' => ['fr' => 'Très satisfait', 'en' => 'Very satisfied']],
            ],
            'equipements' => [
                ['name' => 'eau', 'label' => ['fr' => 'Eau']],
                ['name' => 'electricite', 'label' => ['fr' => 'Électricité']],
            ],
            'priorites' => [
                ['name' => 'sante', 'label' => ['fr' => 'Santé']],
                ['name' => 'ecole', 'label' => ['fr' => 'École']],
                ['name' => 'route', 'label' => ['fr' => 'Route']],
            ],
            'tonalite' => [
                ['name' => 'positif', 'label' => ['fr' => 'Positif']],
                ['name' => 'negatif', 'label' => ['fr' => 'Négatif']],
            ],
            'liens' => [
                ['name' => 'conjoint', 'label' => ['fr' => 'Conjoint']],
                ['name' => 'enfant', 'label' => ['fr' => 'Enfant']],
            ],
        ];
        $definition['sections'] = [[
            'key' => 'S1',
            'label' => ['fr' => 'Section unique'],
            'items' => [
                ['key' => 'consigne', 'type' => 'note', 'label' => ['fr' => 'Consigne']],
                ['key' => 'region', 'type' => 'select_one', 'label' => ['fr' => 'Région', 'en' => 'Region'], 'choices' => 'regions', 'other' => ['choice' => 'autre', 'label' => ['fr' => 'Précisez']]],
                ['key' => 'satisfaction', 'type' => 'select_one', 'label' => ['fr' => 'Satisfaction'], 'choices' => 'echelle'],
                ['key' => 'equipements', 'type' => 'select_multiple', 'label' => ['fr' => 'Équipements'], 'choices' => 'equipements'],
                ['key' => 'priorites', 'type' => 'rank', 'label' => ['fr' => 'Priorités'], 'choices' => 'priorites'],
                ['key' => 'avis', 'type' => 'text', 'label' => ['fr' => 'Avis'], 'postcode' => ['choices' => 'tonalite', 'multiple' => false, 'label' => ['fr' => 'Tonalité']]],
                ['key' => 'telephone', 'type' => 'text', 'label' => ['fr' => 'Téléphone'], 'format' => 'phone', 'tags' => ['pii']],
                ['key' => 'age', 'type' => 'integer', 'label' => ['fr' => 'Âge']],
                ['key' => 'taille', 'type' => 'decimal', 'label' => ['fr' => 'Taille (m)']],
                ['key' => 'revenu', 'type' => 'currency', 'label' => ['fr' => 'Revenu'], 'currency' => 'XAF', 'decimals' => 0],
                ['key' => 'date_visite', 'type' => 'date', 'label' => ['fr' => 'Date de visite']],
                ['key' => 'heure_visite', 'type' => 'time', 'label' => ['fr' => 'Heure de visite']],
                ['key' => 'horodatage', 'type' => 'datetime', 'label' => ['fr' => 'Horodatage']],
                ['key' => 'domicile', 'type' => 'geopoint', 'label' => ['fr' => 'Domicile']],
                ['key' => 'photo_facade', 'type' => 'photo', 'label' => ['fr' => 'Photo de la façade']],
                ['key' => 'age_double', 'type' => 'calculate', 'label' => ['fr' => 'Double de l\'âge'], 'expression' => ['*' => [['var' => 'age'], 2]]],
                ['key' => 'profil', 'type' => 'calculate', 'label' => ['fr' => 'Profil'], 'expression' => ['if' => [['<' => [['var' => 'age'], 40]], 'jeune', 'senior']]],
                ['key' => 'membres', 'type' => 'group', 'label' => ['fr' => 'Membres du ménage'], 'repeat' => ['max' => 3], 'items' => [
                    ['key' => 'membre_age', 'type' => 'integer', 'label' => ['fr' => 'Âge du membre']],
                    ['key' => 'membre_lien', 'type' => 'select_one', 'label' => ['fr' => 'Lien'], 'choices' => 'liens'],
                ]],
                ['key' => 'stop_fin', 'type' => 'stop', 'label' => ['fr' => 'Fin'], 'message' => ['fr' => 'Merci.'], 'relevant' => ['==' => [['var' => 'region'], 'aucune']]],
            ],
        ]];
        $definition['follow_up_stages'] = [[
            'key' => 'j30',
            'label' => ['fr' => 'Suivi à 30 jours'],
            'due_offset_days' => 30,
            'items' => [
                ['key' => 'suivi_note', 'type' => 'integer', 'label' => ['fr' => 'Note de suivi']],
            ],
        ]];

        return $definition;
    }
}
