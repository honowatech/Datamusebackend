<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\ApiKeyResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * B-02 : résolution requête -> utilisateur -> config, et chiffrement pour les jobs.
 */
class ApiKeyResolverTest extends TestCase
{
    private ApiKeyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ApiKeyResolver;
        config(['services.gemini.key' => null, 'services.deepseek.key' => null]);
    }

    private function user(?string $gemini = null, ?string $deepseek = null): User
    {
        $user = new User(['name' => 'U', 'email' => 'u@example.com']);
        $user->gemini_api_key = $gemini;
        $user->deepseek_api_key = $deepseek;

        return $user;
    }

    public function test_request_body_key_wins_over_user_and_config(): void
    {
        config(['services.gemini.key' => 'server-gemini']);
        $request = Request::create('/api/x', 'POST', ['apiKey' => '  body-key  ']);

        $this->assertSame('body-key', $this->resolver->resolve($request, $this->user('user-gemini'), 'gemini'));
    }

    public function test_user_key_wins_over_config_and_is_decrypted(): void
    {
        config(['services.gemini.key' => 'server-gemini', 'services.deepseek.key' => 'server-deepseek']);
        $user = $this->user('user-gemini', 'user-deepseek');

        $this->assertSame('user-gemini', $this->resolver->resolve(null, $user, 'gemini'));
        $this->assertSame('user-deepseek', $this->resolver->resolve(Request::create('/api/x', 'POST', ['apiKey' => '']), $user, 'deepseek'));
        $this->assertNotSame('user-gemini', $user->getAttributes()['gemini_api_key'], 'stockée chiffrée');
    }

    public function test_config_key_is_the_fallback_and_unknown_provider_maps_to_gemini(): void
    {
        config(['services.gemini.key' => 'server-gemini', 'services.deepseek.key' => 'server-deepseek']);

        $this->assertSame('server-gemini', $this->resolver->resolve(null, $this->user(), 'gemini'));
        $this->assertSame('server-deepseek', $this->resolver->resolve(null, $this->user(), 'DeepSeek'));
        $this->assertSame('server-gemini', $this->resolver->resolve(null, $this->user(), 'openai'), 'fournisseur inconnu -> gemini (comportement historique)');
    }

    public function test_returns_null_when_no_key_is_available(): void
    {
        config(['services.gemini.key' => '']);

        $this->assertNull($this->resolver->resolve(Request::create('/api/x', 'POST'), $this->user(), 'gemini'));
        $this->assertNull($this->resolver->resolveForJob(null, $this->user(), 'deepseek'));
        $this->assertStringContainsString('Gemini', ApiKeyResolver::missingKeyMessage('gemini'));
    }

    public function test_resolve_for_job_encrypts_the_key(): void
    {
        $user = $this->user(null, 'user-deepseek');

        $encrypted = $this->resolver->resolveForJob(null, $user, 'deepseek');

        $this->assertNotNull($encrypted);
        $this->assertNotSame('user-deepseek', $encrypted);
        $this->assertSame('user-deepseek', Crypt::decryptString($encrypted));
        $this->assertSame('user-deepseek', ApiKeyResolver::decryptForJob($encrypted));
    }
}
