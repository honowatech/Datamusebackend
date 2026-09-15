<?php

namespace Tests\Feature\Survey;

use App\Enums\ProjectRole;
use App\Enums\UserRole;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-03 : projets (CRUD, périmètre, suppression) et membres (upsert, retrait, stats, protection du propriétaire).
 */
class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $supervisor;

    private User $enumerator;

    private SurveyProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'MunaGo Douala']);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->owner->id]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->project->id, 'user_id' => $this->supervisor->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id, 'zone' => 'Akwa']);
    }

    // ------------------------------------------------------------------ projets

    public function test_analyst_creates_project_and_becomes_owner_and_member(): void
    {
        $analyst = User::factory()->create(['role' => UserRole::Analyste]);

        $response = $this->actingAs($analyst, 'sanctum')->postJson('/api/projects', [
            'name' => 'Étude MunaGo',
            'client_name' => 'MunaGo',
            'description' => 'Test d\'engagement',
            'settings' => ['zones' => ['Akwa', 'Bonamoussadi']],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Étude MunaGo')
            ->assertJsonPath('data.owner_id', $analyst->id)
            ->assertJsonPath('data.my_role', 'analyste')
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.surveys_count', 0)
            ->assertJsonPath('data.settings.zones', ['Akwa', 'Bonamoussadi'])
            ->assertJsonPath('data.settings.timezone', 'Africa/Douala')
            ->assertJsonStructure(['data' => ['id', 'owner_id', 'name', 'description', 'client_name', 'settings', 'my_role', 'members_count', 'surveys_count', 'created_at', 'updated_at']]);

        $this->assertDatabaseHas('project_members', ['project_id' => $response->json('data.id'), 'user_id' => $analyst->id, 'role' => 'analyste', 'status' => 'active']);
    }

    public function test_global_enumerator_cannot_create_project_and_validation_applies(): void
    {
        $this->actingAs($this->enumerator, 'sanctum')->postJson('/api/projects', ['name' => 'X'])->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/projects', ['client_name' => 'Sans nom'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_index_lists_only_my_projects_with_search_and_pagination(): void
    {
        $foreign = SurveyProject::factory()->create(['name' => 'Projet étranger']);
        SurveyProject::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Seconde étude', 'client_name' => 'Orange']);

        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/projects?per_page=1&sort=name');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('data.0.name', 'MunaGo Douala')
            ->assertJsonPath('data.0.members_count', 3)
            ->assertJsonMissing(['name' => 'Projet étranger']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/projects?q=orange')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Seconde étude');

        // Membre (non propriétaire) voit le projet ; admin voit tout
        $this->actingAs($this->enumerator, 'sanctum')->getJson('/api/projects')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.my_role', 'enqueteur');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/projects')->assertOk()->assertJsonPath('meta.pagination.total', 3);
        $this->assertTrue($foreign->exists);
    }

    public function test_show_and_update_respect_roles(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/projects/{$this->project->id}")->assertStatus(403);
        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/projects/{$this->project->id}")
            ->assertOk()->assertJsonPath('data.my_role', 'enqueteur')->assertJsonPath('data.members_count', 3);

        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/projects/{$this->project->id}", ['name' => 'Renommé'])->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$this->project->id}", ['client_name' => 'Nouveau client'])
            ->assertOk()
            ->assertJsonPath('data.name', 'MunaGo Douala', 'les champs absents sont conservés')
            ->assertJsonPath('data.client_name', 'Nouveau client');

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/projects/{$this->project->id}", ['name' => ''])->assertStatus(422);
    }

    public function test_delete_requires_owner_and_conflicts_on_active_surveys_unless_forced(): void
    {
        $survey = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $survey->id]);
        $this->assertTrue($survey->fresh()->isActive());

        $analystMember = User::factory()->create(['role' => UserRole::Analyste]);
        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $analystMember->id]);
        $this->actingAs($analystMember, 'sanctum')->deleteJson("/api/projects/{$this->project->id}")->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/projects/{$this->project->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'project_not_empty')
            ->assertJsonPath('data.active_surveys', 1);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/projects/{$this->project->id}?force=1")->assertNoContent();

        $this->assertDatabaseMissing('survey_projects', ['id' => $this->project->id]);
        $this->assertDatabaseMissing('surveys', ['id' => $survey->id]);
        $this->assertDatabaseMissing('project_members', ['project_id' => $this->project->id]);
    }

    public function test_delete_of_empty_project_and_admin_bypass(): void
    {
        $empty = SurveyProject::factory()->create(['owner_id' => $this->owner->id]);
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/projects/{$empty->id}")->assertNoContent();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/projects/{$this->project->id}")->assertNoContent();
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/projects/{$this->project->id}")->assertStatus(404);
    }

    // ------------------------------------------------------------------ membres

    public function test_members_list_is_visible_to_supervisors_only_with_stats(): void
    {
        $survey = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $survey->id]);
        Submission::factory()->count(2)->create(['survey_id' => $survey->id, 'enumerator_id' => $this->enumerator->id]);

        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/projects/{$this->project->id}/members")->assertStatus(403);

        $response = $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/projects/{$this->project->id}/members?role=enqueteur");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project_id', $this->project->id)
            ->assertJsonPath('data.0.user.id', $this->enumerator->id)
            ->assertJsonPath('data.0.user.role', 'enqueteur')
            ->assertJsonPath('data.0.role', 'enqueteur')
            ->assertJsonPath('data.0.zone', 'Akwa')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.stats.submissions_count', 2)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonStructure(['data' => [['project_id', 'user' => ['id', 'name', 'email', 'role'], 'role', 'zone', 'status', 'joined_at', 'stats' => ['submissions_count', 'last_activity_at']]]]);

        $this->assertNotNull($response->json('data.0.stats.last_activity_at'));

        $all = $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$this->project->id}/members");
        $all->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(0, collect($all->json('data'))->firstWhere('user.id', $this->supervisor->id)['stats']['submissions_count']);
    }

    public function test_upsert_member_creates_then_updates_and_assigns_enumerators_to_active_surveys(): void
    {
        $active = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $active->id]);
        $draft = Survey::factory()->create(['project_id' => $this->project->id]);
        $newcomer = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$newcomer->id}", ['role' => 'enqueteur'])
            ->assertStatus(403);

        $created = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$newcomer->id}", ['role' => 'enqueteur', 'zone' => 'Deido']);

        $created->assertStatus(201)
            ->assertJsonPath('data.user.id', $newcomer->id)
            ->assertJsonPath('data.role', 'enqueteur')
            ->assertJsonPath('data.zone', 'Deido')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('enumerator_assignments', ['survey_id' => $active->id, 'user_id' => $newcomer->id, 'zone' => 'Deido']);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $draft->id, 'user_id' => $newcomer->id]);

        $updated = $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$newcomer->id}", ['role' => 'superviseur', 'zone' => null, 'status' => 'inactive']);

        $updated->assertOk()->assertJsonPath('data.role', 'superviseur')->assertJsonPath('data.zone', null)->assertJsonPath('data.status', 'inactive');
        $this->assertSame(1, ProjectMember::forProject($this->project->id)->where('user_id', $newcomer->id)->count(), 'unique(project, user)');
        $this->assertNull($newcomer->fresh()->projectRole($this->project->id), 'membre inactif : plus de rôle');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$newcomer->id}", ['role' => 'chef'])
            ->assertStatus(422)->assertJsonValidationErrors(['role']);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/999999", ['role' => 'enqueteur'])
            ->assertStatus(404);
    }

    public function test_owner_cannot_be_downgraded_or_removed(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$this->owner->id}", ['role' => 'enqueteur'])
            ->assertStatus(409)->assertJsonPath('code', 'owner_protected');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$this->owner->id}", ['role' => 'analyste', 'status' => 'inactive'])
            ->assertStatus(409);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$this->project->id}/members/{$this->owner->id}", ['role' => 'analyste', 'zone' => 'Siège'])
            ->assertOk()->assertJsonPath('data.zone', 'Siège');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/members/{$this->owner->id}")
            ->assertStatus(409);
    }

    public function test_remove_member_deletes_assignments_but_keeps_submissions(): void
    {
        $survey = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $survey->id]);
        EnumeratorAssignment::factory()->create(['survey_id' => $survey->id, 'user_id' => $this->enumerator->id]);
        $submission = Submission::factory()->create(['survey_id' => $survey->id, 'enumerator_id' => $this->enumerator->id]);

        $this->actingAs($this->supervisor, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/members/{$this->enumerator->id}")
            ->assertStatus(403);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/members/{$this->enumerator->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('project_members', ['project_id' => $this->project->id, 'user_id' => $this->enumerator->id]);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $survey->id, 'user_id' => $this->enumerator->id]);
        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'enumerator_id' => $this->enumerator->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/members/{$this->enumerator->id}")
            ->assertStatus(404);
    }

    public function test_cross_project_access_is_denied(): void
    {
        $otherOwner = User::factory()->create(['role' => UserRole::Analyste]);
        $other = SurveyProject::factory()->create(['owner_id' => $otherOwner->id]);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$other->id}/members")->assertStatus(403);
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/projects/{$other->id}/members/{$this->enumerator->id}", ['role' => 'enqueteur'])
            ->assertStatus(403);
        $this->actingAs($otherOwner, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/members/{$this->enumerator->id}")
            ->assertStatus(403);
        $this->assertSame(ProjectRole::Enqueteur, $this->enumerator->fresh()->projectRole($this->project->id));
    }
}
