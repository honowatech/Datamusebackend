<?php

namespace Tests\Feature\Survey;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\TargetDatabase;
use App\Models\User;
use App\Models\VerbatimCodebook;
use App\Models\VerbatimCoding;
use App\Services\Survey\MaterializationResult;
use App\Services\Survey\ReponsesLayout;
use App\Services\Survey\SqliteSchemaDescriber;
use App\Services\Survey\SurveyMaterializationService;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\Support\MunagoFixtureLoader;
use Tests\TestCase;

/**
 * Matérialisation MunaGo + 60 soumissions en SQLite (plan § 5.2, tâche B-09a).
 */
class MaterializationTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    private SurveyMaterializationService $service;

    /** @var list<PDO> handles ouverts à fermer avant nettoyage (Windows verrouille les fichiers) */
    private array $handles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'datamuse_mat_'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($this->tmpDir);
        $this->service = new SurveyMaterializationService($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->handles = [];
        gc_collect_cycles();
        File::deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    // ------------------------------------------------------------------ MunaGo

    public function test_materializes_munago_fixture_into_sqlite(): void
    {
        $fx = MunagoFixtureLoader::load();
        $survey = $fx->survey;

        // Un codage de verbatim (dernier codebook) pour vérifier `_themes` / `_sentiment`.
        $codebook = VerbatimCodebook::factory()->create(['survey_id' => $survey->id, 'question_key' => 'q6_freins']);
        $first = $fx->submission('d9af4903-1980-462d-babb-9fa0aa36e3f3');
        VerbatimCoding::factory()->create([
            'submission_id' => $first->id,
            'survey_id' => $survey->id,
            'question_key' => 'q6_freins',
            'codebook_id' => $codebook->id,
            'themes' => ['confiance_donnees', 'prix'],
            'sentiment' => 'negative',
        ]);

        $result = $this->service->materialize($survey);

        $this->assertInstanceOf(MaterializationResult::class, $result);
        $expectedPath = $this->tmpDir.DIRECTORY_SEPARATOR.'user_'.$fx->owner->id.DIRECTORY_SEPARATOR.'survey_'.$survey->id.'_v1.sqlite';
        $this->assertSame($expectedPath, $result->filePath);
        $this->assertFileExists($expectedPath);
        $this->assertFileDoesNotExist($expectedPath.'.tmp');
        $this->assertSame(60, $result->rowCount, 'aucune soumission rejetée : les 60 sont incluses (8 hors cible comprises)');
        $this->assertSame(1, $result->fileVersion);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
        $this->assertSame([], $result->warnings);
        $this->assertEqualsCanonicalizing(['reponses', 'reponses_long', 'medias', 'choix', 'questions', 'enqueteurs', 'suivi', '_meta'], $result->tables);

        // TargetDatabase + survey_datasources
        $targetDb = TargetDatabase::findOrFail($result->targetDatabaseId);
        $this->assertSame('Enquête : '.$survey->title, $targetDb->name);
        $this->assertSame('sqlite', $targetDb->driver);
        $this->assertSame('localhost', $targetDb->host);
        $this->assertSame('0', (string) $targetDb->port);
        $this->assertSame('sqlite', $targetDb->username);
        $this->assertSame($expectedPath, $targetDb->database);
        $this->assertSame($fx->owner->id, $targetDb->user_id, 'propriétaire = owner du projet');

        $datasource = SurveyDatasource::where('survey_id', $survey->id)->firstOrFail();
        $this->assertSame($targetDb->id, $datasource->target_database_id);
        $this->assertSame(1, $datasource->file_version);
        $this->assertSame(60, $datasource->row_count);
        $this->assertFalse($datasource->dirty);
        $this->assertNull($datasource->dirty_since);
        $this->assertNotNull($datasource->last_materialized_at);
        $this->assertIsInt($datasource->last_duration_ms);
        $this->assertNull($datasource->last_error);

        // Fichier SQLite
        $pdo = $this->open($expectedPath);
        $this->assertSame(60, $this->rowCount($pdo, 'reponses'));
        $this->assertSame(8, (int) $pdo->query("SELECT COUNT(*) FROM reponses WHERE statut = 'screened_out'")->fetchColumn());
        $this->assertSame(52, (int) $pdo->query("SELECT COUNT(*) FROM reponses WHERE statut = 'submitted'")->fetchColumn());

        $columns = $this->columns($pdo, 'reponses');
        $names = array_keys($columns);

        // Métadonnées en tête, dans l'ordre
        $this->assertSame(array_keys(ReponsesLayout::META_COLUMNS), array_slice($names, 0, 20));
        $this->assertSame('date_entretien', $names[20], 'première question du document (les notes ne produisent pas de colonne)');

        // Colonnes attendues et leurs types
        foreach ([
            'q13_decision' => 'TEXT', 'q13_decision_lib' => 'TEXT',
            'f2_capacite' => 'TEXT', 'f2_capacite_lib' => 'TEXT',
            'f2_capacite__nounou' => 'INTEGER', 'f2_capacite__aucune' => 'INTEGER', 'f2_capacite__vehicule' => 'INTEGER',
            'q15_observation__ouvert_momo' => 'INTEGER',
            'lieu_enrolement_other' => 'TEXT', 'q10_moyen_paiement_other' => 'TEXT',
            'q5_reaction_codes' => 'TEXT', 'q6_freins_codes' => 'TEXT',
            'q6_freins_themes' => 'TEXT', 'q6_freins_sentiment' => 'TEXT',
            'age_approx' => 'INTEGER', 'nb_enfants_scolarises' => 'INTEGER', 'temps_trajet_min' => 'INTEGER',
            'f2_prix_rupture' => 'INTEGER', 'montant_recu' => 'INTEGER',
            'nb_signaux' => 'INTEGER', 'acompte_verse' => 'INTEGER', 'numero_fiche' => 'INTEGER', 'enqueteur' => 'INTEGER',
            'resultat_filtre' => 'TEXT', 'date_entretien' => 'TEXT', 'heure_debut' => 'TEXT',
            'date_limite_retrait' => 'TEXT', 'photo_recu_momo' => 'TEXT',
            'j4_statut' => 'TEXT', 'j4_date' => 'TEXT', 'j4_rappel_envoye' => 'TEXT', 'j4_rappel_envoye_lib' => 'TEXT',
            'j7_statut' => 'TEXT', 'j7_date' => 'TEXT', 'j7_retire' => 'TEXT', 'j7_motif' => 'TEXT',
            'j14_statut' => 'TEXT', 'j14_active' => 'TEXT',
            'lat' => 'REAL', 'lng' => 'REAL', 'precision_m' => 'REAL', 'duree_sec' => 'INTEGER',
        ] as $name => $type) {
            $this->assertArrayHasKey($name, $columns, "colonne {$name} absente");
            $this->assertSame($type, $columns[$name], "type de {$name}");
        }
        $this->assertArrayNotHasKey('q5_reaction_themes', $columns, 'pas de codebook pour q5_reaction → pas de colonnes de codage');
        $this->assertArrayNotHasKey('a_consigne_regles', $columns, 'les notes ne produisent pas de colonne');
        $this->assertArrayNotHasKey('stop_consentement', $columns, 'les stops ne produisent pas de colonne');
        $this->assertArrayNotHasKey('p1_composition', $columns, 'un groupe non répété ne produit pas de colonne');
        $this->assertArrayHasKey('p1_ecole_privee', $columns, 'les enfants d\'un groupe non répété sont à plat');
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(60, strlen($name));
        }
        $this->assertCount(count(array_unique($names)), $names);

        // Ligne d'une soumission complète
        $row = $pdo->query("SELECT * FROM reponses WHERE uuid = 'd9af4903-1980-462d-babb-9fa0aa36e3f3'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($first->id, (int) $row['submission_id']);
        $this->assertSame('DLA-LGP-01', $row['fiche_code']);
        $this->assertSame($fx->enumerators[3]->id, (int) $row['enqueteur_id']);
        $this->assertSame('Enquêteur 3', $row['enqueteur_nom']);
        $this->assertSame('Logpom', $row['zone']);
        $this->assertSame('mobile', $row['canal']);
        $this->assertSame('submitted', $row['statut']);
        $this->assertSame(1, (int) $row['version_formulaire']);
        $this->assertSame('fr', $row['langue']);
        $this->assertStringStartsWith('2026-09-01T', $row['debut']);
        $this->assertSame(828, (int) $row['duree_sec']);
        $this->assertEqualsWithDelta(4.06701, (float) $row['lat'], 0.00001);
        $this->assertEqualsWithDelta(9.74595, (float) $row['lng'], 0.00001);
        $this->assertEqualsWithDelta(5.7, (float) $row['precision_m'], 0.01);
        $this->assertNull($row['flags']);
        $this->assertNull($row['motif_fin']);
        $this->assertSame('oui_verbal', $row['q13_decision']);
        $this->assertStringStartsWith('OUI verbal seulement', $row['q13_decision_lib']);
        $this->assertSame('nounou;ecole_privee', $row['f2_capacite']);
        $this->assertStringContainsString(';', $row['f2_capacite_lib']);
        $this->assertSame(1, (int) $row['f2_capacite__nounou']);
        $this->assertSame(1, (int) $row['f2_capacite__ecole_privee']);
        $this->assertSame(0, (int) $row['f2_capacite__vehicule']);
        $this->assertSame(0, (int) $row['f2_capacite__aucune']);
        $this->assertSame('1 500', $row['f3_abonnement_refus_other']);
        $this->assertSame('interet_spontane', $row['q5_reaction_codes']);
        $this->assertSame('confiance_donnees', $row['q6_freins_codes']);
        $this->assertSame('confiance_donnees;prix', $row['q6_freins_themes']);
        $this->assertSame('negative', $row['q6_freins_sentiment']);
        $this->assertSame(43, $row['age_approx']);
        $this->assertSame(40000, $row['f2_prix_rupture']);
        $this->assertSame(2, $row['nb_signaux'], 'calculate typé INTEGER');
        $this->assertSame(0, $row['acompte_verse'], 'calculate booléen → 0/1');
        $this->assertSame('valide', $row['resultat_filtre']);
        $this->assertSame('2026-09-01', $row['date_entretien']);
        $this->assertNull($row['montant_recu']);
        $this->assertNull($row['j4_statut'], 'pas de suivi pour une fiche sans acompte');

        // Hors cible : statut + motif_fin, sections suivantes à NULL
        $screened = $pdo->query("SELECT * FROM reponses WHERE uuid = '6724d767-57be-48dd-974e-feb0d2d90569'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('screened_out', $screened['statut']);
        $this->assertSame('stop_consentement', $screened['motif_fin']);
        $this->assertNull($screened['q13_decision']);
        $this->assertNull($screened['f2_capacite__nounou'], 'question non répondue → NULL (pas 0)');

        // Drapeaux
        $tooFast = $pdo->query("SELECT flags, score_suspicion FROM reponses WHERE uuid = 'fac23252-9a9a-4b90-8d9e-3fb80a1a09c5'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('too_fast', $tooFast['flags']);
        $this->assertSame(40, (int) $tooFast['score_suspicion']);

        // Étapes de suivi dans la ligne + table suivi
        $withJ7 = $pdo->query("SELECT * FROM reponses WHERE uuid = '5237bc92-dd65-482f-ba2b-a385a448239a'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('done', $withJ7['j4_statut']);
        $this->assertStringStartsWith('2026-09-13T', $withJ7['j4_date']);
        $this->assertSame('oui', $withJ7['j4_rappel_envoye']);
        $this->assertSame('Oui', $withJ7['j4_rappel_envoye_lib']);
        $this->assertSame('done', $withJ7['j7_statut']);
        $this->assertSame('oui', $withJ7['j7_retire']);
        $this->assertSame('2026-09-14', $withJ7['j7_jour_retrait']);

        $this->assertSame(12, $this->rowCount($pdo, 'suivi'));
        $suiviCols = $this->columns($pdo, 'suivi');
        foreach (['submission_id', 'fiche_code', 'stage_key', 'statut', 'echeance', 'fin_fenetre', 'complete_le', 'enqueteur_id', 'j4_rappel_envoye', 'j7_retire', 'j7_retire_lib', 'j14_active'] as $c) {
            $this->assertArrayHasKey($c, $suiviCols, "suivi.{$c}");
        }
        $j7 = $pdo->query("SELECT * FROM suivi WHERE stage_key = 'j7' AND submission_id = ".(int) $withJ7['submission_id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('done', $j7['statut']);
        $this->assertSame('oui', $j7['j7_retire']);
        $this->assertNull($j7['j4_rappel_envoye'], 'colonnes des autres étapes à NULL');
        $this->assertSame(['done' => 8, 'pending' => 3, 'skipped' => 1], $this->countBy($pdo, 'suivi', 'statut'));

        // reponses_long : une ligne par valeur scalaire (listes éclatées, objets média = 1)
        $expectedLong = 0;
        foreach ($first->answers as $v) {
            $expectedLong += is_array($v) ? (array_is_list($v) ? count($v) : 1) : ($v === null ? 0 : 1);
        }
        $this->assertSame(63, $expectedLong, 'garde-fou sur la fixture');
        $this->assertSame($expectedLong, (int) $pdo->query('SELECT COUNT(*) FROM reponses_long WHERE submission_id = '.$first->id)->fetchColumn());
        $long = $pdo->query("SELECT * FROM reponses_long WHERE submission_id = {$first->id} AND question_key = 'f2_capacite' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $long);
        $this->assertSame(['nounou', 'ecole_privee'], array_column($long, 'valeur_code'));
        $this->assertStringStartsWith('Aide à domicile', $long[0]['valeur_texte']);
        $this->assertNull($long[0]['repeat_index']);
        $this->assertSame('fr', $long[0]['langue']);
        $num = $pdo->query("SELECT valeur_num, valeur_texte FROM reponses_long WHERE submission_id = {$first->id} AND question_key = 'f2_prix_rupture'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(40000, $num['valeur_num']);
        $this->assertSame('40000', $num['valeur_texte']);
        $codes = $pdo->query("SELECT valeur_code FROM reponses_long WHERE submission_id = {$first->id} AND question_key = 'q6_freins__codes'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['confiance_donnees'], $codes);
        $stageLong = (int) $pdo->query("SELECT COUNT(*) FROM reponses_long WHERE submission_id = {$withJ7['submission_id']} AND question_key = 'j7_retire'")->fetchColumn();
        $this->assertSame(1, $stageLong, 'les réponses des étapes sont aussi dans reponses_long');
        $this->assertGreaterThan(2000, $this->rowCount($pdo, 'reponses_long'));

        // medias
        $this->assertSame(2, $this->rowCount($pdo, 'medias'));
        $media = $pdo->query("SELECT * FROM medias WHERE question_key = 'photo_recu_momo' ORDER BY id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('image/jpeg', $media['mime']);
        $this->assertSame('uploaded', $media['etat']);
        $this->assertSame(1, (int) $media['repeat_index'], 'repeat_index 1-based dans le SQLite');
        $this->assertStringContainsString('photo_recu_momo', $media['chemin']);
        $withMedia = $pdo->query("SELECT photo_recu_momo FROM reponses WHERE uuid = '5a83d50f-802a-4cc3-b983-aa4dd26994fa'")->fetchColumn();
        $this->assertSame($media['chemin'], $withMedia, 'colonne média = chemin du fichier');

        // choix / questions / enqueteurs / _meta
        $choix = $pdo->query("SELECT code, libelle, ordre FROM choix WHERE question_key = 'q13_decision' ORDER BY ordre")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['oui_paiement', 'oui_verbal', 'non_clair', 'hesite_puis_non', 'hesite_puis_oui'], array_column($choix, 'code'));
        $this->assertSame([1, 2, 3, 4, 5], array_map('intval', array_column($choix, 'ordre')));
        $this->assertSame(9, (int) $pdo->query("SELECT COUNT(*) FROM choix WHERE question_key = 'q6_freins__codes'")->fetchColumn(), 'choix du post-codage sous la clé compagnon');

        $question = $pdo->query("SELECT * FROM questions WHERE question_key = 'q13_decision'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('T', $question['section']);
        $this->assertSame('select_one', $question['type']);
        $this->assertSame('q13_decision', $question['colonne']);
        $this->assertNull($question['etape']);
        $stageQuestion = $pdo->query("SELECT * FROM questions WHERE question_key = 'j7_retire'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('j7', $stageQuestion['etape']);
        $grouped = $pdo->query("SELECT groupe FROM questions WHERE question_key = 'p1_ecole_privee'")->fetchColumn();
        $this->assertSame('p1_composition', $grouped);
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE type IN ('note', 'stop')")->fetchColumn());
        $orders = $pdo->query('SELECT ordre FROM questions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(range(1, count($orders)), array_map('intval', $orders));

        $enq = $pdo->query('SELECT id, nom, email, zone, quota_cible, nb_soumissions FROM enqueteurs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $enq);
        $this->assertSame(['Enquêteur 1', 'Enquêteur 2', 'Enquêteur 3'], array_column($enq, 'nom'));
        $this->assertSame([30, 16, 14], array_map('intval', array_column($enq, 'nb_soumissions')), 'toutes les soumissions matérialisées (hors cible comprises)');
        $completedByEnumerator = $pdo->query("SELECT enqueteur_id, COUNT(*) AS n FROM reponses WHERE statut = 'submitted' GROUP BY enqueteur_id ORDER BY enqueteur_id")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertSame([25, 14, 13], array_map('intval', $completedByEnumerator), 'expected_stats.par_enqueteur (fiches complètes)');
        $this->assertSame(10, (int) $enq[0]['quota_cible']);
        $this->assertSame('Bonamoussadi', $enq[0]['zone']);

        $meta = $pdo->query('SELECT cle, valeur FROM _meta')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame((string) $survey->id, $meta['survey_id']);
        $this->assertSame('1', $meta['file_version']);
        $this->assertSame('60', $meta['row_count']);
        $this->assertSame('1', $meta['form_version']);
        $this->assertSame($fx->version->definition_hash, $meta['definition_hash']);
        $this->assertSame('fr', $meta['default_language']);
        $this->assertSame('0', $meta['include_rejected']);

        // API export : columnsFor / rowFor produisent exactement les colonnes de `reponses`
        $layout = $this->service->layoutFor($survey);
        $this->assertSame($names, $layout->columnNames());
        $this->assertSame($names, array_column($this->service->columnsFor($fx->version), 'name'));
        $this->assertSame($names, array_column($result->columns, 'name'));
        $exportRow = $this->service->rowFor($first->fresh(), $layout);
        $this->assertSame($names, array_keys($exportRow));
        $this->assertSame('oui_verbal', $exportRow['q13_decision']);
        $this->assertSame('confiance_donnees;prix', $exportRow['q6_freins_themes']);

        // Descripteur de schéma : même structure que l'import de fichiers
        $described = (new SqliteSchemaDescriber)->describe($expectedPath);
        $this->assertSame(['tables', 'schema_details', 'table_counts'], array_keys($described));
        $this->assertContains('reponses', $described['tables']);
        $this->assertSame(60, $described['table_counts']['reponses']);
        $this->assertSame($names, array_column($described['schema_details']['reponses'], 'name'));
        $decision = collect($described['schema_details']['reponses'])->firstWhere('name', 'q13_decision');
        $this->assertSame('text', $decision['type']);
        $this->assertTrue($decision['nullable']);
        $this->assertNotEmpty($decision['sample_values']);
        $this->assertLessThanOrEqual(8, count($decision['sample_values']));
    }

    public function test_rebuild_switches_versioned_file_and_updates_the_same_target_database(): void
    {
        $fx = MunagoFixtureLoader::load(['limit' => 5, 'with_follow_ups' => false]);
        $survey = $fx->survey;

        $first = $this->service->materialize($survey);
        $this->assertStringEndsWith('_v1.sqlite', $first->filePath);
        $this->assertFileExists($first->filePath);

        // Un fichier temporaire orphelin d'un échec précédent est nettoyé.
        $stale = dirname($first->filePath).DIRECTORY_SEPARATOR.'survey_'.$survey->id.'_v2.sqlite.tmp';
        file_put_contents($stale, 'stale');

        $fx->submission('d9af4903-1980-462d-babb-9fa0aa36e3f3')->update(['status' => SubmissionStatus::Validated]);
        $second = $this->service->materialize($survey);

        $this->assertStringEndsWith('_v2.sqlite', $second->filePath);
        $this->assertSame(2, $second->fileVersion);
        $this->assertFileExists($second->filePath);
        $this->assertFileDoesNotExist($first->filePath, 'ancien fichier supprimé');
        $this->assertFileDoesNotExist($stale);
        $this->assertSame([], $second->warnings);
        $this->assertSame($first->targetDatabaseId, $second->targetDatabaseId, 'une seule TargetDatabase par questionnaire');
        $this->assertSame(1, TargetDatabase::where('user_id', $fx->owner->id)->count());
        $this->assertSame($second->filePath, TargetDatabase::findOrFail($second->targetDatabaseId)->database);

        $datasource = SurveyDatasource::where('survey_id', $survey->id)->firstOrFail();
        $this->assertSame(2, $datasource->file_version);
        $this->assertSame(5, $datasource->row_count);
        $this->assertFalse($datasource->dirty);

        $pdo = $this->open($second->filePath);
        $this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM reponses WHERE statut = 'validated'")->fetchColumn());
        $this->assertSame('2', $pdo->query("SELECT valeur FROM _meta WHERE cle = 'file_version'")->fetchColumn());
    }

    public function test_rejected_submissions_are_excluded_unless_requested(): void
    {
        $fx = MunagoFixtureLoader::load(['limit' => 10, 'with_follow_ups' => false]);
        $survey = $fx->survey;
        $rejected = $fx->submissions->take(2);
        foreach ($rejected as $s) {
            $s->update(['status' => SubmissionStatus::Rejected, 'quality_notes' => 'Fiche incohérente']);
        }

        $default = $this->service->materialize($survey);
        $this->assertSame(8, $default->rowCount);
        $pdo = $this->open($default->filePath);
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM reponses WHERE statut = 'rejected'")->fetchColumn());
        $this->assertSame(8, $this->rowCount($pdo, 'reponses'));
        $this->assertSame(8, (int) $pdo->query('SELECT COUNT(DISTINCT submission_id) FROM reponses_long')->fetchColumn());
        $pdo = null;
        $this->handles = [];

        $all = $this->service->materialize($survey, ['include_rejected' => true]);
        $this->assertSame(10, $all->rowCount);
        $pdo = $this->open($all->filePath);
        $this->assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM reponses WHERE statut = 'rejected'")->fetchColumn());
        $this->assertSame('1', $pdo->query("SELECT valeur FROM _meta WHERE cle = 'include_rejected'")->fetchColumn());
    }

    public function test_submissions_from_another_version_get_null_for_missing_keys(): void
    {
        $fx = MunagoFixtureLoader::load(['limit' => 3, 'with_follow_ups' => false]);
        $survey = $fx->survey;

        // Version 2 archivée (autre formulaire) ayant une soumission : ses clés sont fusionnées.
        $v2 = SurveyVersion::factory()->archived()->create([
            'survey_id' => $survey->id,
            'definition' => SurveyVersionFactory::minimalDefinition(2),
        ]);
        $this->assertSame(2, $v2->version);
        $other = Submission::factory()->create([
            'survey_id' => $survey->id,
            'survey_version_id' => $v2->id,
            'enumerator_id' => $fx->enumerators[1]->id,
            'fiche_code' => 'V2-0001',
            'answers' => ['consent' => 'oui', 'age' => 40, 'commentaire' => 'Ancienne version'],
        ]);

        $result = $this->service->materialize($survey);
        $this->assertSame(4, $result->rowCount);

        $pdo = $this->open($result->filePath);
        $columns = $this->columns($pdo, 'reponses');
        foreach (['consent' => 'TEXT', 'consent_lib' => 'TEXT', 'age' => 'INTEGER', 'commentaire' => 'TEXT', 'q13_decision' => 'TEXT'] as $name => $type) {
            $this->assertArrayHasKey($name, $columns);
            $this->assertSame($type, $columns[$name]);
        }
        $names = array_keys($columns);
        $idx = array_flip($names);
        $this->assertGreaterThan($idx['q15_chaleur_oui'], $idx['consent'], 'clés inconnues de la version publiée ajoutées après ses questions de base');
        $this->assertLessThan($idx['j4_statut'], $idx['commentaire'], '… mais avant les colonnes des étapes');
        $this->assertSame($idx['consent'] + 2, $idx['age'], 'ordre du document de la version 2 conservé (consent, consent_lib, age, commentaire)');

        $v2Row = $pdo->query("SELECT * FROM reponses WHERE submission_id = {$other->id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $v2Row['version_formulaire']);
        $this->assertSame('oui', $v2Row['consent']);
        $this->assertSame('Oui', $v2Row['consent_lib']);
        $this->assertSame(40, $v2Row['age']);
        $this->assertNull($v2Row['q13_decision'], 'clé absente de la version 2 → NULL');
        $this->assertNull($v2Row['f2_capacite__nounou']);

        $v1Row = $pdo->query("SELECT consent, age, q13_decision FROM reponses WHERE uuid = 'd9af4903-1980-462d-babb-9fa0aa36e3f3'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($v1Row['consent'], 'clé absente de la version 1 → NULL');
        $this->assertNull($v1Row['age']);
        $this->assertSame('oui_verbal', $v1Row['q13_decision']);

        $versions = $pdo->query("SELECT question_key, version FROM questions WHERE question_key IN ('consent', 'q13_decision') ORDER BY question_key")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['consent' => 2, 'q13_decision' => 1], array_map('intval', $versions));
    }

    public function test_long_reserved_and_colliding_column_names_are_cleaned_and_deduplicated(): void
    {
        $long1 = str_repeat('a', 70);
        $long2 = str_repeat('a', 65); // même préfixe de 60 caractères → collision après troncature
        $definition = SurveyVersionFactory::minimalDefinition(1);
        $definition['choice_lists']['tres_long'] = [
            ['name' => str_repeat('b', 62), 'label' => ['fr' => 'B']],
            ['name' => 'court', 'label' => ['fr' => 'C']],
        ];
        $definition['sections'][0]['items'] = [
            ['key' => $long1, 'type' => 'select_multiple', 'label' => ['fr' => 'Long 1'], 'choices' => 'tres_long'],
            ['key' => $long2, 'type' => 'integer', 'label' => ['fr' => 'Long 2']],
            ['key' => 'order', 'type' => 'text', 'label' => ['fr' => 'Mot réservé']],
            ['key' => 'lat', 'type' => 'select_one', 'label' => ['fr' => 'Collision métadonnée'], 'choices' => 'oui_non'],
            ['key' => 'Prénom_Élève', 'type' => 'text', 'label' => ['fr' => 'Accents']],
        ];
        [$survey, $enumerator] = $this->customSurvey($definition);
        Submission::factory()->create([
            'survey_id' => $survey->id,
            'enumerator_id' => $enumerator->id,
            'answers' => [$long1 => [str_repeat('b', 62)], $long2 => 7, 'order' => 'x', 'lat' => 'oui', 'Prénom_Élève' => 'Awa'],
        ]);

        $result = $this->service->materialize($survey);
        $pdo = $this->open($result->filePath);
        $names = array_keys($this->columns($pdo, 'reponses'));

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(60, strlen($name), $name);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $name);
        }
        $this->assertCount(count(array_unique($names)), $names, 'noms dédoublonnés');

        $a60 = str_repeat('a', 60);
        $this->assertContains($a60, $names, 'clé de 70 caractères tronquée à 60');
        $this->assertContains(str_repeat('a', 58).'_2', $names, 'collision après troncature → suffixe _2 dans la limite de 60');
        $this->assertContains('order_', $names, 'mot réservé SQLite suffixé');
        $this->assertContains('lat', $names);
        $this->assertContains('lat_2', $names, 'collision avec la métadonnée lat');
        $this->assertContains('lat_lib', $names, 'le libellé dérive de la clé, sans collision');
        $this->assertContains('prenom_eleve', $names, 'translittération ASCII');
        $this->assertSame([], $result->warnings);

        $q = $pdo->query('SELECT question_key, colonne FROM questions ORDER BY ordre')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame($a60, $q[$long1]);
        $this->assertSame(str_repeat('a', 58).'_5', $q[$long2], 'value, lib, 2 choix puis la seconde clé : 5e nom sur le même préfixe');
        $this->assertSame('lat_2', $q['lat']);
        $this->assertSame('order_', $q['order']);

        $row = $pdo->query('SELECT * FROM reponses')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(str_repeat('b', 62), $row[$a60]);
        $this->assertSame(1, $row[str_repeat('a', 58).'_3'], 'colonne __choix du choix long');
        $this->assertSame(0, $row[str_repeat('a', 58).'_4']);
        $this->assertSame(7, $row[$q[$long2]]);
        $this->assertSame('x', $row['order_']);
        $this->assertSame('oui', $row['lat_2']);
        $this->assertSame('Oui', $row['lat_lib']);
        $this->assertSame('Awa', $row['prenom_eleve']);
        $this->assertNotNull($row['lat'], 'métadonnée lat (GPS de la factory) intacte');
    }

    public function test_repeated_groups_rank_and_geopoint_produce_wide_and_rep_tables(): void
    {
        $definition = SurveyVersionFactory::minimalDefinition(1);
        $definition['choice_lists']['sexe'] = [['name' => 'h', 'label' => ['fr' => 'Homme']], ['name' => 'f', 'label' => ['fr' => 'Femme']]];
        $definition['choice_lists']['criteres'] = [['name' => 'prix', 'label' => ['fr' => 'Prix']], ['name' => 'securite', 'label' => ['fr' => 'Sécurité']], ['name' => 'design', 'label' => ['fr' => 'Design']]];
        $definition['sections'][0]['items'] = [
            ['key' => 'nb', 'type' => 'integer', 'label' => ['fr' => 'Nombre d\'enfants']],
            ['key' => 'enfants', 'type' => 'group', 'label' => ['fr' => 'Enfants'], 'repeat' => ['min' => 1, 'max' => 3], 'items' => [
                ['key' => 'enfant_age', 'type' => 'integer', 'label' => ['fr' => 'Âge']],
                ['key' => 'enfant_sexe', 'type' => 'select_one', 'label' => ['fr' => 'Sexe'], 'choices' => 'sexe'],
                ['key' => 'enfant_photo', 'type' => 'photo', 'label' => ['fr' => 'Photo']],
            ]],
            ['key' => 'priorites', 'type' => 'rank', 'label' => ['fr' => 'Priorités'], 'choices' => 'criteres'],
            ['key' => 'domicile', 'type' => 'geopoint', 'label' => ['fr' => 'Domicile']],
            ['key' => 'prix', 'type' => 'currency', 'label' => ['fr' => 'Prix'], 'decimals' => 2],
            ['key' => 'taux', 'type' => 'decimal', 'label' => ['fr' => 'Taux']],
            ['key' => 'signature', 'type' => 'signature', 'label' => ['fr' => 'Signature']],
        ];
        [$survey, $enumerator] = $this->customSurvey($definition);
        $submission = Submission::factory()->create([
            'survey_id' => $survey->id,
            'enumerator_id' => $enumerator->id,
            'fiche_code' => 'REP-01',
            'answers' => [
                'nb' => 2,
                'enfants' => [
                    ['enfant_age' => 8, 'enfant_sexe' => 'f', 'enfant_photo' => ['sha256' => 'abc', 'mime' => 'image/jpeg', 'size' => 10]],
                    ['enfant_age' => 11, 'enfant_sexe' => 'h'],
                ],
                'priorites' => ['securite', 'prix', 'design'],
                'domicile' => ['lat' => 4.05, 'lng' => 9.76, 'accuracy' => 12.5],
                'prix' => 1500.5,
                'taux' => 0.25,
            ],
        ]);
        SubmissionMedia::factory()->uploaded()->create(['submission_id' => $submission->id, 'question_key' => 'enfant_photo', 'repeat_index' => 0]);
        SubmissionMedia::factory()->signature()->uploaded()->create(['submission_id' => $submission->id]);

        $result = $this->service->materialize($survey);
        $this->assertContains('rep_enfants', $result->tables);

        $pdo = $this->open($result->filePath);
        $columns = $this->columns($pdo, 'reponses');
        foreach (['enfant_age_1' => 'INTEGER', 'enfant_age_2' => 'INTEGER', 'enfant_age_3' => 'INTEGER', 'enfant_sexe_2_lib' => 'TEXT', 'enfant_photo_1' => 'TEXT',
            'priorites' => 'TEXT', 'priorites_r1' => 'TEXT', 'priorites_r3' => 'TEXT',
            'domicile_lat' => 'REAL', 'domicile_lng' => 'REAL', 'domicile_precision' => 'REAL',
            'prix' => 'REAL', 'taux' => 'REAL', 'signature' => 'TEXT'] as $name => $type) {
            $this->assertArrayHasKey($name, $columns, $name);
            $this->assertSame($type, $columns[$name], $name);
        }
        $this->assertArrayNotHasKey('enfant_age', $columns, 'les enfants d\'un groupe répété n\'existent que suffixés');
        $this->assertArrayNotHasKey('enfant_age_4', $columns, 'repeat.max = 3');

        $row = $pdo->query('SELECT * FROM reponses')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(8, $row['enfant_age_1']);
        $this->assertSame(11, $row['enfant_age_2']);
        $this->assertNull($row['enfant_age_3']);
        $this->assertSame('Femme', $row['enfant_sexe_1_lib']);
        $this->assertNotNull($row['enfant_photo_1'], 'média d\'une instance (repeat_index 0) → chemin');
        $this->assertNull($row['enfant_photo_2']);
        $this->assertSame('securite;prix;design', $row['priorites']);
        $this->assertSame('securite', $row['priorites_r1']);
        $this->assertSame('design', $row['priorites_r3']);
        $this->assertEqualsWithDelta(4.05, $row['domicile_lat'], 0.0001);
        $this->assertEqualsWithDelta(12.5, $row['domicile_precision'], 0.01);
        $this->assertEqualsWithDelta(1500.5, $row['prix'], 0.001);
        $this->assertNotNull($row['signature']);

        $rep = $pdo->query('SELECT * FROM rep_enfants ORDER BY repeat_index')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rep);
        $this->assertSame([1, 2], array_map('intval', array_column($rep, 'repeat_index')));
        $this->assertSame('REP-01', $rep[0]['fiche_code']);
        $this->assertSame(8, $rep[0]['enfant_age']);
        $this->assertSame('h', $rep[1]['enfant_sexe']);
        $this->assertSame('Homme', $rep[1]['enfant_sexe_lib']);

        $long = $pdo->query("SELECT question_key, repeat_index, valeur_num, valeur_code FROM reponses_long WHERE question_key IN ('enfant_age', 'priorites') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $ages = array_values(array_filter($long, fn ($r) => $r['question_key'] === 'enfant_age'));
        $this->assertSame([1, 2], array_map('intval', array_column($ages, 'repeat_index')));
        $ranks = array_values(array_filter($long, fn ($r) => $r['question_key'] === 'priorites'));
        $this->assertSame(['securite', 'prix', 'design'], array_column($ranks, 'valeur_code'));

        $this->assertSame(2, $this->rowCount($pdo, 'medias'));
    }

    public function test_materialization_failure_records_last_error_and_leaves_no_temporary_file(): void
    {
        $owner = User::factory()->create();
        $project = SurveyProject::factory()->create(['owner_id' => $owner->id]);
        $survey = Survey::factory()->create(['project_id' => $project->id, 'created_by' => $owner->id]);

        try {
            $this->service->materialize($survey);
            $this->fail('Une exception était attendue : aucune version.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('aucune version', $e->getMessage());
        }

        $datasource = SurveyDatasource::where('survey_id', $survey->id)->firstOrFail();
        $this->assertStringContainsString('aucune version', (string) $datasource->last_error);
        $this->assertSame(0, $datasource->file_version);
        $this->assertNull($datasource->target_database_id);
        $this->assertSame([], glob($this->tmpDir.DIRECTORY_SEPARATOR.'user_'.$owner->id.DIRECTORY_SEPARATOR.'*.tmp') ?: []);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $definition
     * @return array{0: Survey, 1: User}
     */
    private function customSurvey(array $definition): array
    {
        $owner = User::factory()->create();
        $project = SurveyProject::factory()->create(['owner_id' => $owner->id]);
        $survey = Survey::factory()->create(['project_id' => $project->id, 'created_by' => $owner->id, 'title' => 'Custom']);
        SurveyVersion::factory()->published()->create(['survey_id' => $survey->id, 'definition' => $definition]);
        $enumerator = User::factory()->create(['name' => 'Enq Custom']);

        return [$survey->fresh(), $enumerator];
    }

    private function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->handles[] = $pdo;

        return $pdo;
    }

    /**
     * @return array<string, string> nom => type déclaré
     */
    private function columns(PDO $pdo, string $table): array
    {
        $out = [];
        foreach ($pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $out[$col['name']] = $col['type'];
        }

        return $out;
    }

    private function rowCount(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
    }

    /**
     * @return array<string, int>
     */
    private function countBy(PDO $pdo, string $table, string $column): array
    {
        $out = [];
        foreach ($pdo->query("SELECT \"{$column}\", COUNT(*) AS n FROM \"{$table}\" GROUP BY \"{$column}\" ORDER BY \"{$column}\"")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r[$column]] = (int) $r['n'];
        }

        return $out;
    }
}
