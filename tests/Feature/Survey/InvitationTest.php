<?php

namespace Tests\Feature\Survey;

use App\Enums\ProjectRole;
use App\Enums\UserRole;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * B-03 : invitations par e-mail (token + notification) et par code (join_code), acceptation, inscription avec join_code.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $supervisor;

    private User $enumerator;

    private SurveyProject $project;

    private Survey $activeSurvey;

    private Survey $draftSurvey;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = User::factory()->create(['role' => UserRole::Analyste]);
        $this->supervisor = User::factory()->create(['role' => UserRole::Analyste]);
        $this->enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->project = SurveyProject::factory()->create(['owner_id' => $this->owner->id]);
        ProjectMember::factory()->superviseur()->create(['project_id' => $this->project->id, 'user_id' => $this->supervisor->id]);
        ProjectMember::factory()->enqueteur()->create(['project_id' => $this->project->id, 'user_id' => $this->enumerator->id]);

        $this->activeSurvey = Survey::factory()->create(['project_id' => $this->project->id]);
        SurveyVersion::factory()->published()->create(['survey_id' => $this->activeSurvey->id]);
        $this->draftSurvey = Survey::factory()->create(['project_id' => $this->project->id]);
    }

    // ------------------------------------------------------------------ création

    public function test_email_invitation_creates_token_and_sends_notification(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/invitations", [
            'email' => 'Terrain@Example.com',
            'role' => 'enqueteur',
            'zone' => 'Bonamoussadi',
            'max_uses' => 25,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'terrain@example.com')
            ->assertJsonPath('data.role', 'enqueteur')
            ->assertJsonPath('data.zone', 'Bonamoussadi')
            ->assertJsonPath('data.max_uses', 1, 'mode e-mail : mono-usage')
            ->assertJsonPath('data.uses', 0)
            ->assertJsonPath('data.join_code', null)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.invited_by.id', $this->owner->id)
            ->assertJsonStructure(['data' => ['id', 'project_id', 'email', 'role', 'zone', 'join_code', 'join_url', 'max_uses', 'uses', 'expires_at', 'accepted_at', 'status', 'invited_by', 'created_at']]);

        $invitation = ProjectInvitation::findOrFail($response->json('data.id'));
        $this->assertSame(64, strlen($invitation->token));
        $this->assertSame(config('app.frontend_url').'/invitations/accept?token='.$invitation->token, $response->json('data.join_url'));
        $this->assertTrue($invitation->expires_at->between(now()->addDays(13), now()->addDays(15)), 'expiration par défaut +14 jours');

        Notification::assertSentOnDemand(
            ProjectInvitationNotification::class,
            fn (ProjectInvitationNotification $n, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'terrain@example.com'
                && $n->invitation->is($invitation)
                && str_contains($n->toMail($notifiable)->actionUrl, $invitation->token)
        );
    }

    public function test_code_invitation_generates_unambiguous_join_code(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/invitations", [
            'mode' => 'code',
            'role' => 'enqueteur',
            'max_uses' => 10,
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.max_uses', 10)
            ->assertJsonPath('data.status', 'pending');

        $code = $response->json('data.join_code');
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code, 'sans 0/O/1/I/L');
        $this->assertNotNull($response->json('data.join_url'));
        Notification::assertNothingSent();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/invitations", ['mode' => 'email', 'role' => 'enqueteur'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/invitations", ['role' => 'enqueteur', 'max_uses' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['max_uses']);
    }

    public function test_only_analysts_create_and_revoke_invitations_and_supervisors_do_not_see_codes(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/invitations", ['role' => 'enqueteur'])
            ->assertStatus(403);

        $invitation = ProjectInvitation::factory()->joinCode()->create(['project_id' => $this->project->id, 'invited_by' => $this->owner->id]);

        $this->actingAs($this->enumerator, 'sanctum')->getJson("/api/projects/{$this->project->id}/invitations")->assertStatus(403);

        $asSupervisor = $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/projects/{$this->project->id}/invitations");
        $asSupervisor->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.join_code', null)->assertJsonPath('data.0.join_url', null);

        $asOwner = $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$this->project->id}/invitations?status=pending");
        $asOwner->assertOk()->assertJsonPath('data.0.join_code', $invitation->join_code);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$this->project->id}/invitations?status=expired")->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->supervisor, 'sanctum')->deleteJson("/api/projects/{$this->project->id}/invitations/{$invitation->id}")->assertStatus(403);
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/projects/{$this->project->id}/invitations/{$invitation->id}")->assertNoContent();
        $this->assertDatabaseMissing('project_invitations', ['id' => $invitation->id]);
    }

    public function test_cross_project_invitation_is_not_found(): void
    {
        $other = SurveyProject::factory()->create();
        $foreign = ProjectInvitation::factory()->create(['project_id' => $other->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/projects/{$this->project->id}/invitations/{$foreign->id}")
            ->assertStatus(404);
        $this->assertDatabaseHas('project_invitations', ['id' => $foreign->id]);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$other->id}/invitations")->assertStatus(403);
    }

    // ------------------------------------------------------------------ acceptation

    public function test_accept_by_join_code_creates_member_and_assignments_to_active_surveys(): void
    {
        $invitation = ProjectInvitation::factory()->joinCode(3)->create([
            'project_id' => $this->project->id,
            'role' => ProjectRole::Enqueteur,
            'zone' => 'Makepe',
        ]);
        $newcomer = User::factory()->create(['role' => UserRole::Enqueteur]);

        $response = $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => strtolower($invitation->join_code)]);

        $response->assertOk()
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.project.my_role', 'enqueteur')
            ->assertJsonPath('data.member.user.id', $newcomer->id)
            ->assertJsonPath('data.member.role', 'enqueteur')
            ->assertJsonPath('data.member.zone', 'Makepe')
            ->assertJsonPath('data.member.status', 'active');

        $this->assertDatabaseHas('enumerator_assignments', ['survey_id' => $this->activeSurvey->id, 'user_id' => $newcomer->id, 'zone' => 'Makepe']);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $this->draftSurvey->id, 'user_id' => $newcomer->id]);
        $this->assertSame(1, $invitation->fresh()->uses);
        $this->assertNull($invitation->fresh()->accepted_at, 'multi-usage : accepted_at seulement à épuisement');

        // Idempotent : déjà membre -> 200 sans consommer d'usage
        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => $invitation->join_code])->assertOk();
        $this->assertSame(1, $invitation->fresh()->uses);
    }

    public function test_accept_by_token_for_supervisor_creates_no_assignment(): void
    {
        $invitation = ProjectInvitation::factory()->create(['project_id' => $this->project->id, 'role' => ProjectRole::Superviseur]);
        $newcomer = User::factory()->create();

        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['token' => $invitation->token])
            ->assertOk()
            ->assertJsonPath('data.member.role', 'superviseur');

        $this->assertDatabaseMissing('enumerator_assignments', ['user_id' => $newcomer->id]);
        $this->assertNotNull($invitation->fresh()->accepted_at, 'mono-usage : accepted_at posé');
        $this->assertSame(1, $invitation->fresh()->uses);

        // Épuisée pour un troisième
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/invitations/accept', ['token' => $invitation->token])
            ->assertStatus(410)
            ->assertJsonPath('success', false)
            ->assertJsonPath('reason', 'used_up');
    }

    public function test_accept_rejects_expired_exhausted_unknown_and_invalid_payloads(): void
    {
        $expired = ProjectInvitation::factory()->joinCode()->expired()->create(['project_id' => $this->project->id]);
        $newcomer = User::factory()->create();

        $this->postJson('/api/invitations/accept', ['join_code' => $expired->join_code])->assertStatus(401);

        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => $expired->join_code])
            ->assertStatus(410)->assertJsonPath('reason', 'expired');
        $this->assertDatabaseMissing('project_members', ['project_id' => $this->project->id, 'user_id' => $newcomer->id]);

        $exhausted = ProjectInvitation::factory()->joinCode(2)->create(['project_id' => $this->project->id, 'uses' => 2]);
        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => $exhausted->join_code])
            ->assertStatus(410)->assertJsonPath('reason', 'used_up');

        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => 'ZZZZZZZZ'])->assertStatus(404);
        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', [])->assertStatus(422);
        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => 'ABC'])->assertStatus(422)->assertJsonValidationErrors(['join_code']);
        $this->actingAs($newcomer, 'sanctum')->postJson('/api/invitations/accept', ['token' => str_repeat('a', 64), 'join_code' => 'ABCDEFGH'])->assertStatus(422);
    }

    public function test_reactivates_inactive_member_with_invitation_role(): void
    {
        $inactive = User::factory()->create();
        ProjectMember::factory()->analyste()->inactive()->create(['project_id' => $this->project->id, 'user_id' => $inactive->id]);
        $invitation = ProjectInvitation::factory()->joinCode()->create(['project_id' => $this->project->id, 'role' => ProjectRole::Enqueteur, 'zone' => 'Deido']);

        $this->actingAs($inactive, 'sanctum')->postJson('/api/invitations/accept', ['join_code' => $invitation->join_code])
            ->assertOk()->assertJsonPath('data.member.role', 'enqueteur')->assertJsonPath('data.member.status', 'active');

        $this->assertSame(1, ProjectMember::forProject($this->project->id)->where('user_id', $inactive->id)->count());
        $this->assertDatabaseHas('enumerator_assignments', ['survey_id' => $this->activeSurvey->id, 'user_id' => $inactive->id, 'zone' => 'Deido']);
    }

    // ------------------------------------------------------------------ inscription avec code

    public function test_register_with_join_code_joins_project_and_assigns_active_surveys(): void
    {
        $invitation = ProjectInvitation::factory()->joinCode(5)->create(['project_id' => $this->project->id, 'role' => ProjectRole::Enqueteur, 'zone' => 'Logpom']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Enquêteur terrain',
            'email' => 'enq@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'join_code' => $invitation->join_code,
            'phone' => '+237690000000',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.role', 'enqueteur')
            ->assertJsonPath('data.user.role', 'enqueteur')
            ->assertJsonPath('data.user.phone', '+237690000000')
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.project.my_role', 'enqueteur')
            ->assertJsonStructure(['token', 'user', 'data' => ['token', 'user', 'project']]);

        $user = User::where('email', 'enq@example.com')->firstOrFail();
        $this->assertDatabaseHas('project_members', ['project_id' => $this->project->id, 'user_id' => $user->id, 'role' => 'enqueteur', 'zone' => 'Logpom']);
        $this->assertDatabaseHas('enumerator_assignments', ['survey_id' => $this->activeSurvey->id, 'user_id' => $user->id, 'zone' => 'Logpom']);
        $this->assertDatabaseMissing('enumerator_assignments', ['survey_id' => $this->draftSurvey->id, 'user_id' => $user->id]);
        $this->assertSame(1, $invitation->fresh()->uses);

        // Le jeton renvoyé fonctionne
        $this->withToken($response->json('data.token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_register_with_invalid_or_expired_join_code_is_rejected_without_creating_user(): void
    {
        $expired = ProjectInvitation::factory()->joinCode()->expired()->create(['project_id' => $this->project->id]);

        foreach (['ZZZZZZZZ', $expired->join_code] as $code) {
            $this->postJson('/api/auth/register', [
                'name' => 'X',
                'email' => 'x@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'join_code' => $code,
            ])->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonValidationErrors(['join_code']);
        }

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);

        // Sans code : inscription analyste classique, sans projet
        $this->postJson('/api/auth/register', [
            'name' => 'Y', 'email' => 'y@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(201)->assertJsonPath('data.user.role', 'analyste')->assertJsonMissingPath('data.project');
    }
}
