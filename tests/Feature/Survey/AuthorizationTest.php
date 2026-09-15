<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyReport;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * B-02 : matrice rôles x capacités des Policies (SurveyProject, Survey, Submission, SurveyReport) + Gate `create-project`.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $users;

    private SurveyProject $project;

    private Survey $survey;

    private Submission $ownSubmission;

    private Submission $otherSubmission;

    private SurveyReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->project = SurveyProject::factory()->create(['owner_id' => $owner->id]);

        $this->users = [
            'admin' => User::factory()->create(['role' => UserRole::Admin]),
            'owner' => $owner,
            'analyste' => User::factory()->create(['role' => UserRole::Analyste]),
            'superviseur' => User::factory()->create(['role' => UserRole::Analyste]),
            'enqueteur' => User::factory()->create(['role' => UserRole::Enqueteur]),
            'inactif' => User::factory()->create(['role' => UserRole::Analyste]),
            'etranger' => User::factory()->create(['role' => UserRole::Analyste]),
            'enqueteur_global' => User::factory()->create(['role' => UserRole::Enqueteur]),
        ];

        ProjectMember::factory()->analyste()->create(['project_id' => $this->project->id, 'user_id' => $this->users['analyste']->id]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->project->id, 'user_id' => $this->users['superviseur']->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->users['enqueteur']->id]);
        ProjectMember::factory()->analyste()->inactive()->create(['project_id' => $this->project->id, 'user_id' => $this->users['inactif']->id]);

        $this->survey = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $this->survey->id]);
        EnumeratorAssignment::factory()->create(['survey_id' => $this->survey->id, 'user_id' => $this->users['enqueteur']->id]);

        $this->ownSubmission = Submission::factory()->create(['survey_id' => $this->survey->id, 'enumerator_id' => $this->users['enqueteur']->id]);
        $this->otherSubmission = Submission::factory()->create(['survey_id' => $this->survey->id, 'enumerator_id' => $this->users['superviseur']->id]);
        $this->report = SurveyReport::factory()->create(['survey_id' => $this->survey->id, 'requested_by' => $owner->id]);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, bool>}>
     */
    public static function projectMatrix(): array
    {
        //                     admin  owner  analyste superviseur enqueteur inactif etranger
        return [
            'view' => ['view', ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => true, 'enqueteur' => true, 'inactif' => false, 'etranger' => false]],
            'update' => ['update', ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => false, 'enqueteur' => false, 'inactif' => false, 'etranger' => false]],
            'delete' => ['delete', ['admin' => true, 'owner' => true, 'analyste' => false, 'superviseur' => false, 'enqueteur' => false, 'inactif' => false, 'etranger' => false]],
            'manageMembers' => ['manageMembers', ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => false, 'enqueteur' => false, 'inactif' => false, 'etranger' => false]],
            'viewMembers' => ['viewMembers', ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => true, 'enqueteur' => false, 'inactif' => false, 'etranger' => false]],
            'createSurvey' => ['createSurvey', ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => false, 'enqueteur' => false, 'inactif' => false, 'etranger' => false]],
        ];
    }

    /** @param  array<string, bool>  $expected */
    #[DataProvider('projectMatrix')]
    public function test_project_policy_matrix(string $ability, array $expected): void
    {
        foreach ($expected as $who => $allowed) {
            $this->assertSame(
                $allowed,
                Gate::forUser($this->users[$who])->allows($ability, $this->project),
                "{$who} devrait ".($allowed ? 'pouvoir' : 'ne pas pouvoir')." « {$ability} » sur le projet",
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, bool>}>
     */
    public static function surveyMatrix(): array
    {
        $analystOnly = ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => false, 'enqueteur' => false, 'inactif' => false, 'etranger' => false];
        $supervisorUp = ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => true, 'enqueteur' => false, 'inactif' => false, 'etranger' => false];
        $anyMember = ['admin' => true, 'owner' => true, 'analyste' => true, 'superviseur' => true, 'enqueteur' => true, 'inactif' => false, 'etranger' => false];

        return [
            'view' => ['view', $anyMember],
            'update' => ['update', $analystOnly],
            'delete' => ['delete', $analystOnly],
            'publish' => ['publish', $analystOnly],
            'manageAssignments' => ['manageAssignments', $analystOnly],
            'viewSubmissions' => ['viewSubmissions', $supervisorUp],
            'reviewSubmissions' => ['reviewSubmissions', $supervisorUp],
            'exportSubmissions' => ['exportSubmissions', $analystOnly],
            'generateAi' => ['generateAi', $analystOnly],
            'collect' => ['collect', $anyMember],
        ];
    }

    /** @param  array<string, bool>  $expected */
    #[DataProvider('surveyMatrix')]
    public function test_survey_policy_matrix(string $ability, array $expected): void
    {
        foreach ($expected as $who => $allowed) {
            $this->assertSame(
                $allowed,
                Gate::forUser($this->users[$who])->allows($ability, $this->survey),
                "{$who} devrait ".($allowed ? 'pouvoir' : 'ne pas pouvoir')." « {$ability} » sur le questionnaire",
            );
        }
    }

    public function test_enumerator_without_assignment_cannot_collect(): void
    {
        $other = Survey::factory()->create(['project_id' => $this->project->id]);

        $this->assertFalse(Gate::forUser($this->users['enqueteur'])->allows('collect', $other));
        $this->assertTrue(Gate::forUser($this->users['superviseur'])->allows('collect', $other));
    }

    public function test_survey_create_requires_project_analyst(): void
    {
        $this->assertTrue(Gate::forUser($this->users['owner'])->allows('create', [Survey::class, $this->project]));
        $this->assertTrue(Gate::forUser($this->users['analyste'])->allows('create', [Survey::class, $this->project]));
        $this->assertFalse(Gate::forUser($this->users['superviseur'])->allows('create', [Survey::class, $this->project]));
        $this->assertFalse(Gate::forUser($this->users['etranger'])->allows('create', [Survey::class, $this->project]));
        $this->assertTrue(Gate::forUser($this->users['admin'])->allows('create', [Survey::class, $this->project]));
    }

    public function test_submission_policy(): void
    {
        $gate = fn (string $who) => Gate::forUser($this->users[$who]);

        // Lecture : superviseur et plus, ou l'enquêteur auteur
        $this->assertTrue($gate('enqueteur')->allows('view', $this->ownSubmission));
        $this->assertFalse($gate('enqueteur')->allows('view', $this->otherSubmission));
        $this->assertTrue($gate('superviseur')->allows('view', $this->ownSubmission));
        $this->assertTrue($gate('analyste')->allows('view', $this->ownSubmission));
        $this->assertTrue($gate('admin')->allows('view', $this->ownSubmission));
        $this->assertFalse($gate('etranger')->allows('view', $this->ownSubmission));

        // Revue (statut / notes) : superviseur et plus
        $this->assertFalse($gate('enqueteur')->allows('update', $this->ownSubmission));
        $this->assertTrue($gate('superviseur')->allows('update', $this->ownSubmission));
        $this->assertTrue($gate('superviseur')->allows('reviewSubmissions', $this->ownSubmission));

        // Suppression : analyste
        $this->assertFalse($gate('superviseur')->allows('delete', $this->ownSubmission));
        $this->assertTrue($gate('analyste')->allows('delete', $this->ownSubmission));
        $this->assertTrue($gate('owner')->allows('delete', $this->ownSubmission));
    }

    public function test_report_policy(): void
    {
        $gate = fn (string $who) => Gate::forUser($this->users[$who]);

        $this->assertTrue($gate('superviseur')->allows('view', $this->report));
        $this->assertFalse($gate('enqueteur')->allows('view', $this->report));
        $this->assertFalse($gate('etranger')->allows('view', $this->report));

        $this->assertTrue($gate('analyste')->allows('update', $this->report));
        $this->assertFalse($gate('superviseur')->allows('update', $this->report));
        $this->assertTrue($gate('owner')->allows('delete', $this->report));
        $this->assertTrue($gate('admin')->allows('generateAi', $this->report));

        $this->assertTrue($gate('analyste')->allows('create', [SurveyReport::class, $this->survey]));
        $this->assertFalse($gate('superviseur')->allows('create', [SurveyReport::class, $this->survey]));
    }

    public function test_create_project_gate_depends_on_global_role(): void
    {
        $this->assertTrue(Gate::forUser($this->users['admin'])->allows('create-project'));
        $this->assertTrue(Gate::forUser($this->users['etranger'])->allows('create-project'), 'analyste global');
        $this->assertFalse(Gate::forUser($this->users['enqueteur_global'])->allows('create-project'));
        $this->assertFalse(Gate::forUser($this->users['enqueteur'])->allows('create-project'));
    }

    public function test_admin_bypasses_every_policy_even_without_membership(): void
    {
        $admin = $this->users['admin'];
        $this->assertNull($admin->projectRole($this->project->id));

        foreach (['view', 'update', 'delete', 'manageMembers', 'viewMembers'] as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $this->project), $ability);
        }
        $this->assertTrue(Gate::forUser($admin)->allows('viewSubmissions', $this->survey));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $this->otherSubmission));
    }
}
