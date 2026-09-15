<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Events\SurveyPublished;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use Database\Factories\SurveyVersionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * B-05 : questionnaires (CRUD, périmètre enquêteur, suppression), brouillon avec révision (409/422),
 * publication immuable (hash figé, archivage, datasource, assignations, événement), fork, duplication, assignations.
 */
class SurveyCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $supervisor;

    private User $enumerator;

    private User $inactiveEnumerator;

    private SurveyProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);
        $this->inactiveEnumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'MunaGo Douala']);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->project->id, 'user_id' => $this->supervisor->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id, 'zone' => 'Akwa']);
        ProjectMember::factory()->enqueteur()->inactive()->create(['project_id' => $this->project->id, 'user_id' => $this->inactiveEnumerator->id]);
    }

    // ------------------------------------------------------------------ création

    public function test_create_survey_without_definition_generates_valid_skeleton(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/surveys", [
            'title' => 'Satisfaction clients',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Satisfaction clients')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.project_id', $this->project->id)
            ->assertJsonPath('data.published_version', null)
            ->assertJsonPath('data.draft_version.version', 1)
            ->assertJsonPath('data.draft_version.revision', 0)
            ->assertJsonPath('data.draft_version.status', 'draft')
            ->assertJsonPath('data.draft_version.definition_hash', null)
            ->assertJsonPath('data.draft_version.languages', ['fr'])
            ->assertJsonPath('data.submissions_count', 0)
            ->assertJsonPath('data.created_by.id', $this->owner->id)
            ->assertJsonMissingPath('data.draft_version.definition');

        $surveyId = $response->json('data.id');
        $version = SurveyVersion::query()->where('survey_id', $surveyId)->firstOrFail();

        $this->assertSame('Satisfaction clients', $version->definition['title']['fr']);
        $this->assertSame(1, $version->definition['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $version->definition['id']);
        $this->assertSame($version->id, Survey::find($surveyId)->current_version_id);

        // Le squelette passe la validation complète.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$surveyId}/validate")
            ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.errors', []);
    }

    public function test_create_survey_with_munago_definition_and_role_checks(): void
    {
        $definition = SurveyVersionFactory::munagoDefinition();

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/surveys", [
            'title' => 'MunaGo terrain',
            'definition' => $definition,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.draft_version.languages', ['fr', 'en']);
        $this->assertGreaterThan(20, $response->json('data.draft_version.question_count'));

        $version = SurveyVersion::query()->where('survey_id', $response->json('data.id'))->firstOrFail();
        $this->assertNotSame($definition['id'], $version->definition['id'], 'definition.id est généré par le serveur');
        $this->assertSame($definition['sections'], $version->definition['sections']);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/surveys", ['title' => 'Interdit'])
            ->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/surveys", ['definition' => $definition])
            ->assertStatus(422)->assertJsonValidationErrors(['title']);
    }

    public function test_create_survey_with_invalid_definition_returns_dfs_errors_with_path(): void
    {
        $definition = SurveyVersionFactory::minimalDefinition();
        $definition['sections'][0]['items'][0]['type'] = 'inconnu';

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/surveys", [
            'title' => 'Invalide',
            'definition' => $definition,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message', 'errors' => [['path', 'code', 'message']]]);
        $this->assertStringStartsWith('/sections/0/items/0', $response->json('errors.0.path'));
        $this->assertDatabaseCount('surveys', 0);
    }

    // ------------------------------------------------------------------ brouillon

    public function test_save_draft_increments_revision_syncs_title_and_returns_warnings(): void
    {
        $survey = $this->createSurvey();
        $definition = $survey->draftVersion->definition;
        $definition['title']['fr'] = 'Titre renommé depuis le builder';
        $definition['sections'][0]['items'][] = ['key' => 'ville', 'type' => 'text', 'label' => ['fr' => 'Ville ?']];

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", [
            'definition' => $definition,
            'base_revision' => 0,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('meta.changed', true)
            ->assertJsonMissingPath('data.definition');

        $this->assertSame('Titre renommé depuis le builder', $survey->fresh()->title);

        // Idempotent à contenu identique : pas d'incrément.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", [
            'definition' => $definition,
            'base_revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('meta.changed', false);

        // Erreur sémantique (variable inconnue) : enregistrée, mais renvoyée dans warnings.
        $definition['sections'][0]['items'][0]['relevant'] = ['==' => [['var' => 'inexistante'], 'oui']];
        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", [
            'definition' => $definition,
            'base_revision' => 1,
        ]);
        $response->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertContains('unknown_var', array_column($response->json('data.warnings'), 'code'));
    }

    public function test_save_draft_rejects_stale_revision_and_structural_errors(): void
    {
        $survey = $this->createSurvey();
        $definition = $survey->draftVersion->definition;

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", [
            'definition' => $definition,
            'base_revision' => 7,
        ])->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'revision_conflict')
            ->assertJsonPath('data.current_revision', 0);

        $broken = $definition;
        unset($broken['sections'][0]['items'][0]['label']);
        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", [
            'definition' => $broken,
            'base_revision' => 0,
        ]);
        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('schema', $response->json('errors.0.code'));
        $this->assertStringStartsWith('/sections/0/items/0', $response->json('errors.0.path'));
        $this->assertSame(0, $survey->draftVersion->fresh()->revision, 'rien n\'est enregistré en cas d\'erreur structurelle');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", ['definition' => $definition])
            ->assertStatus(422)->assertJsonValidationErrors(['base_revision']);

        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", [
            'definition' => $definition,
            'base_revision' => 0,
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------------ publication

    public function test_publish_freezes_version_creates_datasource_assignments_and_event(): void
    {
        Event::fake([SurveyPublished::class]);
        $survey = $this->createSurvey(SurveyVersionFactory::munagoDefinition(), 'MunaGo');

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish");

        $response->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_by.id', $this->owner->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('data.definition_hash'));
        $this->assertNotNull($response->json('data.published_at'));

        $survey->refresh();
        $version = SurveyVersion::query()->where('survey_id', $survey->id)->where('version', 1)->firstOrFail();

        $this->assertSame('active', $survey->status->value);
        $this->assertSame($version->id, $survey->published_version_id);
        $this->assertSame($version->id, $survey->current_version_id);
        $this->assertSame('Étude de marché MunaGo — terrain', $survey->title);
        $this->assertSame(1, $version->definition['version']);
        $this->assertNotEmpty($version->question_index);

        // Le hash correspond exactement au texte canonique relu depuis la base (servi tel quel par B-07).
        $this->assertSame(hash('sha256', $version->fresh()->canonicalJson()), $version->definition_hash);

        $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->firstOrFail();
        $this->assertTrue($datasource->dirty);
        $this->assertNull($datasource->target_database_id);

        $this->assertDatabaseHas('enumerator_assignments', ['survey_id' => $survey->id, 'user_id' => $this->enumerator->id, 'zone' => 'Akwa']);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $survey->id, 'user_id' => $this->inactiveEnumerator->id]);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $survey->id, 'user_id' => $this->supervisor->id]);

        Event::assertDispatched(SurveyPublished::class, fn (SurveyPublished $e) => $e->survey->id === $survey->id && $e->version->id === $version->id && $e->previous === null);

        // Republier sans brouillon → 409 ; une seule version publiée.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")
            ->assertStatus(409)->assertJsonPath('code', 'no_draft');
        $this->assertSame(1, SurveyVersion::query()->where('survey_id', $survey->id)->published()->count());

        // GET /surveys/{id} : published_version résumé, sans definition ; draft_version null.
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/surveys/{$survey->id}")
            ->assertOk()
            ->assertJsonPath('data.published_version.version', 1)
            ->assertJsonPath('data.draft_version', null)
            ->assertJsonMissingPath('data.published_version.definition');

        // GET versions/{n} : définition incluse, avec hash.
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/surveys/{$survey->id}/versions/1")
            ->assertOk()
            ->assertJsonPath('data.definition.version', 1)
            ->assertJsonPath('data.definition_hash', $version->definition_hash)
            ->assertJsonPath('data.definition.settings.default_language', 'fr');
    }

    public function test_publish_rejects_invalid_draft_and_missing_publish_role(): void
    {
        $survey = $this->createSurvey();
        $definition = $survey->draftVersion->definition;
        $definition['sections'][0]['items'][0]['relevant'] = ['==' => [['var' => 'inexistante'], 'oui']];
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", ['definition' => $definition, 'base_revision' => 0])->assertOk();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/validate")
            ->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.errors.0.code', 'unknown_var');

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish");
        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'unknown_var');
        $this->assertSame('draft', $survey->fresh()->status->value);
        $this->assertSame('draft', $survey->draftVersion->fresh()->status->value);

        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertStatus(403);
    }

    public function test_published_version_is_immutable_and_put_draft_creates_next_version(): void
    {
        $survey = $this->createSurvey();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();
        $v1 = SurveyVersion::query()->where('survey_id', $survey->id)->where('version', 1)->firstOrFail();
        $hashV1 = $v1->definition_hash;

        $definition = $v1->definition;
        $definition['sections'][0]['items'][] = ['key' => 'quartier', 'type' => 'text', 'label' => ['fr' => 'Quartier ?']];

        // base_revision périmée par rapport à la version publiée → 409.
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", ['definition' => $definition, 'base_revision' => 5])
            ->assertStatus(409)->assertJsonPath('code', 'revision_conflict')->assertJsonPath('data.current_revision', $v1->revision);

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", ['definition' => $definition, 'base_revision' => $v1->revision]);
        $response->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('meta.created', true);

        $v1->refresh();
        $this->assertSame('published', $v1->status->value);
        $this->assertSame($hashV1, $v1->definition_hash);
        $this->assertCount(1, $v1->definition['sections'][0]['items'], 'la version publiée n\'a pas été modifiée');

        $v2 = SurveyVersion::query()->where('survey_id', $survey->id)->where('version', 2)->firstOrFail();
        $this->assertSame(2, $v2->definition['version']);
        $this->assertSame($v1->definition['id'], $v2->definition['id'], 'uuid DFS stable entre versions');
        $this->assertSame($v2->id, $survey->fresh()->current_version_id);
        $this->assertSame($v1->id, $survey->fresh()->published_version_id);

        // Publication de v2 : v1 archivée, une seule publiée, événement avec `previous`.
        Event::fake([SurveyPublished::class]);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk()->assertJsonPath('data.version', 2);
        Event::assertDispatched(SurveyPublished::class, fn (SurveyPublished $e) => $e->version->version === 2 && $e->previous?->id === $v1->id);

        $this->assertSame('archived', $v1->fresh()->status->value);
        $this->assertSame('published', $v2->fresh()->status->value);
        $this->assertSame(1, SurveyVersion::query()->where('survey_id', $survey->id)->published()->count());
        $this->assertSame($v2->id, $survey->fresh()->published_version_id);

        $list = $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/surveys/{$survey->id}/versions")->assertOk();
        $this->assertSame([2, 1], array_column($list->json('data'), 'version'));
        $this->assertSame(['published', 'archived'], array_column($list->json('data'), 'status'));
    }

    public function test_publish_refuses_type_change_of_existing_key(): void
    {
        $survey = $this->createSurvey(SurveyVersionFactory::minimalDefinition());
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();

        $definition = SurveyVersion::query()->where('survey_id', $survey->id)->published()->firstOrFail()->definition;
        $definition['sections'][0]['items'][2] = ['key' => 'age', 'type' => 'text', 'label' => ['fr' => 'Âge (texte)']];

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", ['definition' => $definition, 'base_revision' => 0])
            ->assertOk()->assertJsonPath('data.version', 2);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish");
        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'type_changed')
            ->assertJsonPath('errors.0.path', '/sections/0/items/2/type');
    }

    // ------------------------------------------------------------------ fork

    public function test_fork_creates_next_draft_and_refuses_second_fork(): void
    {
        $survey = $this->createSurvey();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/versions/9/fork")->assertStatus(404);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/versions/1/fork");
        $response->assertStatus(201)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision', 0)
            ->assertJsonPath('data.definition_hash', null)
            ->assertJsonPath('data.definition.version', 2);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/versions/1/fork")
            ->assertStatus(409)->assertJsonPath('code', 'draft_exists');

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/surveys/{$survey->id}")
            ->assertOk()->assertJsonPath('data.published_version.version', 1)->assertJsonPath('data.draft_version.version', 2);

        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/surveys/{$survey->id}/versions/1/fork")->assertStatus(403);
    }

    // ------------------------------------------------------------------ duplication

    public function test_duplicate_copies_published_version_into_new_draft_survey(): void
    {
        $survey = $this->createSurvey(SurveyVersionFactory::munagoDefinition(), 'MunaGo');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();
        EnumeratorAssignment::query()->where('survey_id', $survey->id)->firstOrFail();

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/duplicate");
        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.project_id', $this->project->id)
            ->assertJsonPath('data.published_version', null)
            ->assertJsonPath('data.draft_version.version', 1)
            ->assertJsonPath('data.draft_version.revision', 0)
            ->assertJsonPath('data.submissions_count', 0);
        $this->assertStringStartsWith('Copie de ', $response->json('data.title'));

        $copyId = $response->json('data.id');
        $copy = SurveyVersion::query()->where('survey_id', $copyId)->firstOrFail();
        $original = SurveyVersion::query()->where('survey_id', $survey->id)->published()->firstOrFail();
        $this->assertNotSame($original->definition['id'], $copy->definition['id']);
        $this->assertSame($original->definition['sections'], $copy->definition['sections']);
        $this->assertSame($response->json('data.title'), $copy->definition['title']['fr']);
        $this->assertSame(0, EnumeratorAssignment::query()->where('survey_id', $copyId)->count());

        // Projet cible où l'utilisateur est analyste (propriétaire) / n'est rien → 403.
        $mine = SurveyProject::factory()->create(['owner_id' => $this->owner->id]);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/duplicate", ['title' => 'Vers autre projet', 'project_id' => $mine->id])
            ->assertStatus(201)->assertJsonPath('data.project_id', $mine->id)->assertJsonPath('data.title', 'Vers autre projet');

        $foreign = SurveyProject::factory()->create();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/duplicate", ['project_id' => $foreign->id])->assertStatus(403);
    }

    // ------------------------------------------------------------------ assignations

    public function test_assignments_are_replaced_and_restricted_to_project_enumerators(): void
    {
        $survey = $this->createSurvey();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();
        $second = User::factory()->create(['role' => UserRole::Enqueteur]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $second->id, 'zone' => 'Deido']);

        $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/surveys/{$survey->id}/assignments")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $this->enumerator->id)
            ->assertJsonPath('data.0.user.name', $this->enumerator->name)
            ->assertJsonPath('data.0.submissions_count', 0);

        $response = $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/assignments", [
            'assignments' => [
                ['user_id' => $second->id, 'zone' => 'Deido', 'quota_target' => 15],
            ],
        ]);
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $second->id)
            ->assertJsonPath('data.0.zone', 'Deido')
            ->assertJsonPath('data.0.quota_target', 15);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $survey->id, 'user_id' => $this->enumerator->id]);

        // Non-enquêteur (superviseur), inactif, étranger → 422 sur l'index fautif.
        $stranger = User::factory()->create(['role' => UserRole::Enqueteur]);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}/assignments", [
            'assignments' => [['user_id' => $second->id], ['user_id' => $this->supervisor->id], ['user_id' => $this->inactiveEnumerator->id], ['user_id' => $stranger->id]],
        ])->assertStatus(422)->assertJsonValidationErrors(['assignments.1.user_id', 'assignments.2.user_id', 'assignments.3.user_id']);

        // Superviseur : peut modifier zone/quota, pas ajouter ni retirer.
        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/surveys/{$survey->id}/assignments", [
            'assignments' => [['user_id' => $second->id, 'zone' => 'Makepe', 'quota_target' => 20]],
        ])->assertOk()->assertJsonPath('data.0.zone', 'Makepe');
        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/surveys/{$survey->id}/assignments", [
            'assignments' => [['user_id' => $second->id], ['user_id' => $this->enumerator->id]],
        ])->assertStatus(403);

        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$survey->id}/assignments")->assertStatus(403);
    }

    // ------------------------------------------------------------------ périmètre et lecture

    public function test_enumerator_sees_only_active_assigned_surveys(): void
    {
        $assigned = $this->createSurvey(null, 'Assignée');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$assigned->id}/publish")->assertOk();

        $draft = $this->createSurvey(null, 'Brouillon');

        $notAssigned = $this->createSurvey(null, 'Non assignée');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$notAssigned->id}/publish")->assertOk();
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$notAssigned->id}/assignments", ['assignments' => []])->assertOk();

        $response = $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/projects/{$this->project->id}/surveys");
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id)
            ->assertJsonPath('data.0.my_assignment.zone', 'Akwa')
            ->assertJsonPath('meta.pagination.total', 1);

        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$assigned->id}")->assertOk();
        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$notAssigned->id}")->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$draft->id}")->assertStatus(403);

        // Enquêteur assigné : lit la version publiée, pas les autres ni la liste.
        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$assigned->id}/versions/1")->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$draft->id}/versions/1")->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/surveys/{$assigned->id}/versions")->assertStatus(403);

        // Superviseur : tout le projet, filtres et tri.
        $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/projects/{$this->project->id}/surveys?sort=title")
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.title', 'Assignée');
        $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/projects/{$this->project->id}/surveys?status=draft")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $draft->id);
        $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/projects/{$this->project->id}/surveys?q=non+assign")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cross_project_access_is_denied(): void
    {
        $survey = $this->createSurvey();
        $stranger = User::factory()->create(['role' => UserRole::Analyste]);
        $otherProject = SurveyProject::factory()->create(['owner_id' => $stranger->id]);

        $this->actingAs($stranger, 'sanctum')->getJson("/api/projects/{$this->project->id}/surveys")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/surveys/{$survey->id}")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['title' => 'X'])->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->putJson("/api/surveys/{$survey->id}/draft", ['definition' => ['dfs_version' => '1.0'], 'base_revision' => 0])->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/surveys/{$survey->id}")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/surveys/{$survey->id}/versions")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/surveys/{$survey->id}/assignments")->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/surveys/999999')->assertStatus(404);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$otherProject->id}/surveys")->assertStatus(403);
    }

    // ------------------------------------------------------------------ métadonnées et suppression

    public function test_update_title_and_status_rules(): void
    {
        $survey = $this->createSurvey();

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['status' => 'active'])
            ->assertStatus(409)->assertJsonPath('code', 'survey_not_published');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['status' => 'bizarre'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['title' => 'Nouveau titre'])
            ->assertOk()->assertJsonPath('data.title', 'Nouveau titre')->assertJsonPath('data.draft_version.revision', 1);
        $this->assertSame('Nouveau titre', $survey->draftVersion()->first()->definition['title']['fr']);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();
        $this->assertSame('active', $survey->fresh()->status->value);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['status' => 'draft'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->actingAs($this->owner, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/surveys/{$survey->id}", ['title' => 'Interdit'])->assertStatus(403);
    }

    public function test_delete_refuses_with_submissions_unless_forced(): void
    {
        $survey = $this->createSurvey();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/surveys/{$survey->id}/publish")->assertOk();
        Submission::factory()->count(2)->create(['survey_id' => $survey->id, 'enumerator_id' => $this->enumerator->id]);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/surveys/{$survey->id}")
            ->assertStatus(409)->assertJsonPath('code', 'survey_has_submissions')->assertJsonPath('data.submissions', 2);
        $this->assertNull($survey->fresh()->deleted_at);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/surveys/{$survey->id}")
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/surveys/{$survey->id}?force=1")->assertNoContent();

        $this->assertSoftDeleted('surveys', ['id' => $survey->id]);
        $this->assertDatabaseMissing('survey_datasources', ['survey_id' => $survey->id]);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/surveys/{$survey->id}")->assertStatus(404);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$this->project->id}/surveys")->assertOk()->assertJsonCount(0, 'data');

        // Sans soumission : suppression directe.
        $empty = $this->createSurvey();
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/surveys/{$empty->id}")->assertNoContent();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>|null  $definition
     */
    private function createSurvey(?array $definition = null, string $title = 'Questionnaire test'): Survey
    {
        $payload = ['title' => $title];
        if ($definition !== null) {
            $payload['definition'] = $definition;
        }

        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/surveys", $payload)
            ->assertStatus(201)
            ->json('data.id');

        return Survey::query()->with('draftVersion')->findOrFail($id);
    }
}
