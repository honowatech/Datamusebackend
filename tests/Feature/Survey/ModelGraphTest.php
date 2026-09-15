<?php

namespace Tests\Feature\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\MediaState;
use App\Enums\ProjectRole;
use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Enums\SurveyStatus;
use App\Enums\UserRole;
use App\Enums\VersionStatus;
use App\Models\Device;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Support\QuestionIndexBuilder;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Graphe projet -> membre -> enquête -> version publiée MunaGo -> soumissions -> médias -> suivis -> datasource.
 */
class ModelGraphTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $enumerator;

    private SurveyProject $project;

    private Survey $survey;

    private SurveyVersion $version;

    private Submission $complete;

    private Submission $deposit;

    private Submission $screenedOut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id]);
        ProjectMember::factory()->enqueteur()->create([
            'project_id' => $this->project->id,
            'user_id' => $this->enumerator->id,
            'zone' => 'Bonamoussadi',
        ]);

        $this->survey = Survey::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'title' => 'MunaGo terrain',
        ]);
        $this->version = SurveyVersion::factory()->munago()->published()->create([
            'survey_id' => $this->survey->id,
            'published_by' => $this->owner->id,
        ]);

        $device = Device::factory()->create(['user_id' => $this->enumerator->id]);

        $base = [
            'survey_id' => $this->survey->id,
            'enumerator_id' => $this->enumerator->id,
            'device_id' => $device->id,
        ];

        $this->complete = Submission::factory()->create($base + ['fiche_code' => 'DLA-BMP-01']);
        $this->deposit = Submission::factory()->withDeposit()->create($base + ['fiche_code' => 'DLA-BMP-02']);
        $this->screenedOut = Submission::factory()->screenedOut('stop_f1_enfant')->create($base);

        SubmissionMedia::factory()->uploaded()->create([
            'submission_id' => $this->deposit->id,
            'question_key' => 'photo_recu_momo',
        ]);
        SubmissionMedia::factory()->signature()->create(['submission_id' => $this->deposit->id]);

        foreach ([['j4', 4], ['j7', 7], ['j14', 14]] as [$stage, $offset]) {
            FollowUpEntry::factory()->stage($stage, $offset)->create(['submission_id' => $this->deposit->id]);
        }

        SurveyDatasource::factory()->materialized(rows: 3)->create(['survey_id' => $this->survey->id]);
    }

    public function test_relations_walk_the_graph_in_both_directions(): void
    {
        $survey = Survey::with(['project.owner', 'publishedVersion', 'submissions', 'datasource', 'assignments', 'reports', 'publicLinks'])
            ->findOrFail($this->survey->id);

        $this->assertTrue($survey->project->is($this->project));
        $this->assertTrue($survey->project->owner->is($this->owner));
        $this->assertTrue($survey->publishedVersion->is($this->version));
        $this->assertSame(SurveyStatus::Active, $survey->status, 'published() active le questionnaire');
        $this->assertCount(3, $survey->submissions);
        $this->assertCount(1, $survey->versions);
        $this->assertNull($survey->draftVersion);
        $this->assertTrue($survey->datasource->isMaterialized());
        $this->assertSame(3, $survey->datasource->row_count);
        $this->assertTrue($survey->datasource->targetDatabase->user->is($this->owner));
        $this->assertSame('sqlite', $survey->datasource->targetDatabase->driver);

        // Projet <-> membres <-> utilisateurs (pivot role/zone/status)
        $this->assertCount(1, $this->project->members);
        $member = $this->project->users()->first();
        $this->assertTrue($member->is($this->enumerator));
        $this->assertSame('enqueteur', $member->pivot->role);
        $this->assertSame('Bonamoussadi', $member->pivot->zone);
        $this->assertTrue($this->enumerator->projects->first()->is($this->project));
        $this->assertTrue($this->owner->projectsOwned->first()->is($this->project));
        $this->assertCount(1, $this->enumerator->memberships);

        // Soumission -> survey / version / enumerator / device / media / suivis
        $deposit = Submission::with(['survey', 'version', 'enumerator', 'device', 'media', 'followUps', 'codings'])->findOrFail($this->deposit->id);
        $this->assertTrue($deposit->survey->is($this->survey));
        $this->assertTrue($deposit->version->is($this->version));
        $this->assertTrue($deposit->enumerator->is($this->enumerator));
        $this->assertSame($this->enumerator->id, $deposit->device->user_id);
        $this->assertCount(2, $deposit->media);
        $this->assertCount(3, $deposit->followUps);
        $this->assertCount(0, $deposit->codings);
        $this->assertSame($this->project->id, $deposit->project_id, 'project_id dérivé du questionnaire');

        // Sens inverse
        $this->assertCount(3, $this->version->submissions);
        $this->assertCount(3, $this->enumerator->submissions);
        $this->assertCount(3, $this->enumerator->followUps);
        $this->assertCount(1, $this->enumerator->devices);
        $this->assertTrue($deposit->media->first()->submission->is($deposit));
        $this->assertTrue($deposit->followUps->first()->survey->is($this->survey));
        $this->assertTrue($deposit->followUps->first()->enumerator->is($this->enumerator));
    }

    public function test_scopes_and_status_enums(): void
    {
        $this->assertSame(2, Submission::forSurvey($this->survey->id)->valid()->count());
        $this->assertSame(1, Submission::forSurvey($this->survey->id)->screenedOut()->count());
        $this->assertSame(3, Submission::forSurvey($this->survey->id)->count());
        $this->assertSame(1, SurveyVersion::forSurvey($this->survey->id)->published()->count());
        $this->assertSame(0, SurveyVersion::forSurvey($this->survey->id)->draft()->count());

        $screened = $this->screenedOut->fresh();
        $this->assertSame(SubmissionStatus::ScreenedOut, $screened->status);
        $this->assertSame('stop_f1_enfant', $screened->end_reason);
        $this->assertNull($screened->fiche_code);
        $this->assertFalse($screened->isValid());
        $this->assertTrue($this->complete->fresh()->isValid());

        // Rejeter une soumission la sort du périmètre « valid »
        $this->complete->update(['status' => SubmissionStatus::Rejected]);
        $this->assertSame(1, Submission::forSurvey($this->survey->id)->valid()->count());

        $this->assertSame(VersionStatus::Published, $this->version->fresh()->status);
        $this->assertSame(SubmissionChannel::Mobile, $this->deposit->fresh()->channel);
        $this->assertSame(MediaState::Uploaded, SubmissionMedia::where('question_key', 'photo_recu_momo')->first()->state);
        $this->assertSame(FollowUpStatus::Pending, $this->deposit->followUps()->first()->status);
        $this->assertSame(3, FollowUpEntry::pending()->forEnumerator($this->enumerator->id)->count());

        $j7 = FollowUpEntry::where('stage_key', 'j7')->firstOrFail();
        $this->assertTrue($j7->due_at->equalTo($this->deposit->ended_at->copy()->addDays(7)));
        $this->assertTrue($j7->window_ends_at->equalTo($this->deposit->ended_at->copy()->addDays(10)));
    }

    public function test_casts_json_dates_and_enums(): void
    {
        $deposit = $this->deposit->fresh();

        $this->assertIsArray($deposit->answers);
        $this->assertSame('oui_paiement', $deposit->answers['q13_decision']);
        $this->assertTrue($deposit->answers['acompte_verse']);
        $this->assertSame(5000, $deposit->answers['montant_recu']);
        $this->assertIsArray($deposit->flags);
        $this->assertInstanceOf(Carbon::class, $deposit->started_at);
        $this->assertInstanceOf(Carbon::class, $deposit->ended_at);
        $this->assertInstanceOf(Carbon::class, $deposit->client_updated_at);
        $this->assertIsInt($deposit->device_time_offset_ms);
        $this->assertIsInt($deposit->duration_seconds);
        $this->assertSame($deposit->ended_at->getTimestamp() - $deposit->started_at->getTimestamp(), $deposit->duration_seconds);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $deposit->answers_hash, 'answers_hash calculé à la sauvegarde');
        $this->assertSame(Submission::hashAnswers($deposit->answers), $deposit->answers_hash);

        $tooFast = Submission::factory()->tooFast()->create(['survey_id' => $this->survey->id, 'enumerator_id' => $this->enumerator->id]);
        $this->assertTrue($tooFast->hasFlag(Submission::FLAG_TOO_FAST));
        $this->assertLessThan(600, $tooFast->duration_seconds);
        $this->assertSame(40, $tooFast->fresh()->suspicion_score);

        $version = $this->version->fresh();
        $this->assertIsArray($version->definition);
        $this->assertSame('1.0', $version->definition['dfs_version']);
        $this->assertIsArray($version->question_index);
        $this->assertSame(1, $version->revision);
        $this->assertInstanceOf(Carbon::class, $version->published_at);

        $this->assertIsArray($this->project->fresh()->settings);
        $this->assertSame(UserRole::Enqueteur, $this->enumerator->fresh()->role);
        $this->assertSame('fr', $this->enumerator->fresh()->locale);
    }

    public function test_user_project_role_is_memoized_and_admin_detection(): void
    {
        $this->assertSame(ProjectRole::Enqueteur, $this->enumerator->projectRole($this->project->id));
        $this->assertSame(ProjectRole::Analyste, $this->owner->projectRole($this->project->id), 'le propriétaire est analyste implicite');
        $this->assertNull(User::factory()->create()->projectRole($this->project->id));
        $this->assertFalse($this->enumerator->isAdmin());
        $this->assertTrue(User::factory()->create(['role' => UserRole::Admin])->isAdmin());
        $this->assertTrue($this->enumerator->hasProjectRole($this->project->id, ProjectRole::Enqueteur, ProjectRole::Superviseur));
        $this->assertFalse($this->enumerator->hasProjectRole($this->project->id, ProjectRole::Analyste));

        // Mémoïsation : changer le rôle en base n'est pas vu tant que le cache n'est pas vidé
        ProjectMember::where('user_id', $this->enumerator->id)->update(['role' => ProjectRole::Superviseur->value]);
        $this->assertSame(ProjectRole::Enqueteur, $this->enumerator->projectRole($this->project->id));
        $this->assertSame(ProjectRole::Superviseur, $this->enumerator->forgetProjectRoles()->projectRole($this->project->id));

        // Un membre inactif n'a plus de rôle
        ProjectMember::where('user_id', $this->enumerator->id)->update(['status' => ProjectMember::STATUS_INACTIVE]);
        $this->assertNull($this->enumerator->forgetProjectRoles()->projectRole($this->project->id));
    }

    public function test_fiche_code_is_unique_per_survey(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        Submission::factory()->create([
            'survey_id' => $this->survey->id,
            'enumerator_id' => $this->enumerator->id,
            'fiche_code' => 'DLA-BMP-01',
        ]);
    }

    public function test_same_fiche_code_allowed_on_another_survey_and_null_codes_do_not_collide(): void
    {
        $other = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $other->id]);

        $s = Submission::factory()->create(['survey_id' => $other->id, 'fiche_code' => 'DLA-BMP-01']);
        $this->assertSame('DLA-BMP-01', $s->fiche_code);

        Submission::factory()->screenedOut()->create(['survey_id' => $this->survey->id]);
        $this->assertSame(2, Submission::forSurvey($this->survey->id)->whereNull('fiche_code')->count());
    }

    public function test_follow_up_stage_is_unique_per_submission(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        FollowUpEntry::factory()->stage('j4', 4)->create(['submission_id' => $this->deposit->id]);
    }

    public function test_question_index_flattens_munago_definition(): void
    {
        $index = $this->version->fresh()->question_index;

        $this->assertArrayHasKey('q13_decision', $index);
        $q13 = $index['q13_decision'];
        $this->assertSame('select_one', $q13['type']);
        $this->assertSame('T', $q13['section']);
        $this->assertArrayNotHasKey('stage', $q13);
        $this->assertNotSame('', $q13['label_default']);
        $this->assertContains('oui_paiement', array_column($q13['choices'], 'name'));

        // Question de groupe (matrice P1) : section C, groupe p1_composition, non répété
        $this->assertSame('C', $index['p1_ecole_privee']['section']);
        $this->assertSame('p1_composition', $index['p1_ecole_privee']['group']);
        $this->assertFalse($index['p1_ecole_privee']['repeat']);

        // Étape de suivi
        $this->assertSame('j7', $index['j7_retire']['stage']);
        $this->assertNull($index['j7_retire']['section']);

        // « Autre : précisez » -> clé compagnon
        $this->assertSame('lieu_enrolement_other', $index['lieu_enrolement']['other_key']);
        $this->assertSame('autre', $index['lieu_enrolement']['other_choice']);

        // Types sans réponse tout de même indexés (stop / note / calculate)
        $this->assertSame('stop', $index['stop_consentement']['type']);
        $this->assertSame('calculate', $index['numero_fiche']['type']);

        // Ordre d'administration conservé
        $this->assertLessThan($index['q13_decision']['order'], $index['consentement']['order']);

        // Déterminisme du builder
        $this->assertSame($index, QuestionIndexBuilder::build(SurveyVersionFactory::munagoDefinition()));
    }

    public function test_definition_hash_is_sha256_of_exact_json_and_stable(): void
    {
        $json = '{"dfs_version":"1.0","title":{"fr":"Été"}}';

        $this->assertSame(hash('sha256', $json), SurveyVersion::computeHash($json));
        $this->assertSame(SurveyVersion::computeHash($json), SurveyVersion::computeHash($json));
        $this->assertNotSame(SurveyVersion::computeHash($json), SurveyVersion::computeHash($json.' '));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SurveyVersion::computeHash($json));

        // Le hash stocké correspond à l'encodage canonique (sans espaces, Unicode non échappé)
        $version = $this->version->fresh();
        $canonical = $version->canonicalJson();
        $this->assertStringContainsString('é', $canonical, 'Unicode non échappé');
        $this->assertStringNotContainsString(chr(92).'u00e9', $canonical, 'pas de séquence \\uXXXX');
        $this->assertStringNotContainsString(chr(92).'/', $canonical, 'slashes non échappés');
        $this->assertSame(SurveyVersion::computeHash($canonical), $version->definition_hash);

        // Recharger la même fixture donne le même hash ; la modifier le change
        $twin = SurveyVersion::factory()->munago()->create(['survey_id' => $this->survey->id]);
        $this->assertSame($version->definition_hash, $twin->definition_hash);
        $this->assertSame(2, $twin->version, 'numéro de version auto-incrémenté par questionnaire');

        $twin->definition = array_merge($twin->definition, ['version' => 2]);
        $twin->save();
        $this->assertNotSame($version->definition_hash, $twin->fresh()->definition_hash);

        // Un hash fixé explicitement (texte exact servi) n'est pas écrasé
        $frozen = str_repeat('a', 64);
        $twin->definition = array_merge($twin->definition, ['version' => 3]);
        $twin->definition_hash = $frozen;
        $twin->save();
        $this->assertSame($frozen, $twin->fresh()->definition_hash);
    }

    public function test_survey_soft_delete_keeps_rows_and_project_cascade_removes_graph(): void
    {
        $this->survey->delete();
        $this->assertSoftDeleted('surveys', ['id' => $this->survey->id]);
        $this->assertSame(3, Submission::forSurvey($this->survey->id)->count());
        $this->assertNull(Survey::find($this->survey->id));
        $this->assertNotNull(Survey::withTrashed()->find($this->survey->id));

        $this->project->delete();
        $this->assertDatabaseMissing('surveys', ['id' => $this->survey->id]);
        $this->assertDatabaseMissing('survey_versions', ['id' => $this->version->id]);
        $this->assertSame(0, Submission::count());
        $this->assertSame(0, SubmissionMedia::count());
        $this->assertSame(0, FollowUpEntry::count());
        $this->assertSame(0, SurveyDatasource::count());
        $this->assertSame(0, ProjectMember::count());
    }
}
