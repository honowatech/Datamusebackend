<?php

namespace Tests\Feature\Survey;

use App\Enums\UserRole;
use App\Models\AiJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * B-02 : enveloppes d'erreur, meta.server_time, CORS, limiteurs, réponse d'authentification.
 */
class ApiPlumbingTest extends TestCase
{
    use RefreshDatabase;

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    protected function setUp(): void
    {
        parent::setUp();

        // Routes factices de test (non présentes dans routes/api.php)
        Route::middleware(['server.time', 'auth:sanctum', 'throttle:mobile'])
            ->get('/api/mobile/_plumbing', fn () => response()->json(['success' => true, 'data' => ['pong' => true]]));
        Route::middleware('server.time')->get('/api/mobile/_plumbing_empty', fn () => response()->noContent());
        Route::middleware('throttle:2,1')->get('/api/_plumbing/throttled', fn () => response()->json(['success' => true, 'data' => null]));
        Route::get('/api/_plumbing/boom', fn () => throw new RuntimeException('détail interne'));
        Route::get('/api/_plumbing/abort', fn () => abort(418, 'Théière.'));
        Route::middleware('public.cors')->get('/api/public/_plumbing', fn () => response()->json(['success' => true, 'data' => 'ok']));
    }

    public function test_validation_error_returns_422_envelope(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['success', 'message', 'errors' => ['email', 'password']]);

        $this->assertIsArray($response->json('errors.email'));
    }

    public function test_unauthenticated_returns_401_envelope(): void
    {
        $this->getJson('/api/projects')
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_forbidden_returns_403_envelope(): void
    {
        $enumerator = User::factory()->create(['role' => UserRole::Enqueteur]);

        $this->actingAs($enumerator, 'sanctum')
            ->postJson('/api/projects', ['name' => 'Interdit'])
            ->assertStatus(403)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['success', 'message']);
    }

    public function test_not_found_returns_404_envelope_for_models_and_unknown_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/projects/999999')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Ressource introuvable.']);

        $this->getJson('/api/does-not-exist')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Ressource introuvable.']);
    }

    public function test_throttled_returns_429_envelope_with_retry_after(): void
    {
        $this->getJson('/api/_plumbing/throttled')->assertOk();
        $this->getJson('/api/_plumbing/throttled')->assertOk();

        $response = $this->getJson('/api/_plumbing/throttled');

        $response->assertStatus(429)
            ->assertJson(['success' => false])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '2');

        $this->assertStringContainsString('Trop de requêtes', $response->json('message'));
    }

    public function test_http_exception_keeps_its_status_code(): void
    {
        $this->getJson('/api/_plumbing/abort')
            ->assertStatus(418)
            ->assertExactJson(['success' => false, 'message' => 'Théière.']);
    }

    public function test_unexpected_exception_returns_generic_500_with_debug_message_only_in_debug(): void
    {
        config(['app.debug' => true]);
        $this->getJson('/api/_plumbing/boom')
            ->assertStatus(500)
            ->assertJson(['success' => false, 'message' => 'Une erreur interne est survenue.', 'debug_message' => 'détail interne']);

        config(['app.debug' => false]);
        $response = $this->getJson('/api/_plumbing/boom');
        $response->assertStatus(500)->assertJson(['success' => false, 'message' => 'Une erreur interne est survenue.']);
        $this->assertArrayNotHasKey('debug_message', $response->json());
    }

    public function test_mobile_route_carries_server_time_in_meta_and_header(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/mobile/_plumbing');

        $response->assertOk()
            ->assertJson(['success' => true, 'data' => ['pong' => true]])
            ->assertHeader('X-Server-Time');

        $this->assertMatchesRegularExpression(self::ISO_8601, $response->json('meta.server_time'));
        $this->assertSame($response->headers->get('X-Server-Time'), $response->json('meta.server_time'));

        // Réponse sans corps : seul l'en-tête est présent
        $empty = $this->get('/api/mobile/_plumbing_empty');
        $empty->assertNoContent();
        $this->assertMatchesRegularExpression(self::ISO_8601, $empty->headers->get('X-Server-Time'));
    }

    public function test_error_responses_on_mobile_routes_also_carry_server_time(): void
    {
        $response = $this->getJson('/api/mobile/_plumbing');

        $response->assertStatus(401)->assertJson(['success' => false]);
        $this->assertMatchesRegularExpression(self::ISO_8601, $response->json('meta.server_time'));
    }

    public function test_cors_allows_frontend_url_with_credentials(): void
    {
        // Deux origines : avec une seule, fruitcake/php-cors la renvoie systématiquement (le navigateur compare).
        config(['cors.allowed_origins' => ['http://front.test', 'http://second.test'], 'cors.allowed_origins_patterns' => []]);

        $preflight = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://front.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $preflight->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'http://front.test')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $response = $this->withHeader('Origin', 'http://front.test')->postJson('/api/auth/login', []);
        $response->assertStatus(422)->assertHeader('Access-Control-Allow-Origin', 'http://front.test');
        $this->assertStringContainsString('X-Server-Time', $response->headers->get('Access-Control-Expose-Headers'));

        $denied = $this->withHeader('Origin', 'http://evil.test')->postJson('/api/auth/login', []);
        $this->assertNull($denied->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', (string) $denied->headers->get('Vary'));
    }

    public function test_public_routes_are_open_to_any_origin_without_credentials(): void
    {
        config(['cors.allowed_origins' => ['http://front.test'], 'cors.allowed_origins_patterns' => []]);

        $preflight = $this->call('OPTIONS', '/api/public/_plumbing', [], [], [], [
            'HTTP_ORIGIN' => 'http://anyone.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $preflight->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Credentials'));

        $this->withHeader('Origin', 'http://anyone.test')->getJson('/api/public/_plumbing')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_login_and_register_return_data_envelope_in_addition_to_root_keys(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Aïcha',
            'email' => 'aicha@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $register->assertStatus(201)
            ->assertJsonStructure(['success', 'token', 'user' => ['id', 'name', 'email'], 'data' => ['token', 'user' => ['id', 'name', 'email', 'role', 'locale']]]);
        $this->assertSame($register->json('token'), $register->json('data.token'));
        $this->assertSame('analyste', $register->json('data.user.role'));

        $login = $this->postJson('/api/auth/login', ['email' => 'aicha@example.com', 'password' => 'password123']);

        $login->assertOk()->assertJsonStructure(['success', 'token', 'user', 'data' => ['token', 'user' => ['id', 'email', 'role']]]);
        $this->assertSame($login->json('token'), $login->json('data.token'));
        $this->assertSame($login->json('user.id'), $login->json('data.user.id'));
        $this->assertArrayNotHasKey('gemini_api_key', $login->json('data.user'));
    }

    public function test_job_endpoint_is_restricted_to_owner_project_members_and_admin(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $job = AiJob::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner, 'sanctum')->getJson("/api/jobs/{$job->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $job->uuid)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonStructure(['data' => ['id', 'kind', 'status', 'progress', 'message', 'result_ref', 'result', 'error', 'survey_id', 'created_at', 'updated_at']]);

        $this->actingAs($stranger, 'sanctum')->getJson("/api/jobs/{$job->uuid}")->assertStatus(403)->assertJson(['success' => false]);
        $this->actingAs($admin, 'sanctum')->getJson("/api/jobs/{$job->uuid}")->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson('/api/jobs/00000000-0000-4000-8000-000000000000')->assertStatus(404);
        $this->actingAs($owner, 'sanctum')->getJson('/api/jobs/not-a-uuid')->assertStatus(404);
    }
}
