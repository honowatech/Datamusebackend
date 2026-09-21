<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Page « Profil » : informations, mot de passe et photo (`/profile`).
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Awa Diallo',
            'email' => 'awa@test.local',
            'password' => Hash::make('ancienMdp1'),
        ]);
    }

    public function test_show_returns_the_profile_and_stats(): void
    {
        $this->actingAs($this->user)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'awa@test.local')
            ->assertJsonPath('data.user.avatar_updated_at', null)
            ->assertJsonPath('data.stats.projects_count', 0)
            ->assertJsonPath('data.stats.surveys_created_count', 0);
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile/password', [])->assertUnauthorized();
    }

    public function test_update_changes_the_details_and_keeps_emails_unique(): void
    {
        User::factory()->create(['email' => 'pris@test.local']);

        $this->actingAs($this->user)->putJson('/api/profile', [
            'name' => 'Awa D.', 'email' => 'awa.d@test.local', 'phone' => '+237 600 00 00 00', 'locale' => 'en',
        ])->assertOk()->assertJsonPath('data.user.name', 'Awa D.')->assertJsonPath('data.user.locale', 'en');

        $this->assertSame('awa.d@test.local', $this->user->fresh()->email);

        $this->actingAs($this->user)->putJson('/api/profile', [
            'name' => 'Awa', 'email' => 'pris@test.local', 'locale' => 'fr',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_change_requires_the_current_one_and_revokes_other_sessions(): void
    {
        $other = $this->user->createToken('telephone')->plainTextToken;
        $mine = $this->user->createToken('navigateur')->plainTextToken;

        $this->withToken($mine)->putJson('/api/profile/password', [
            'current_password' => 'mauvais', 'password' => 'nouveauMdp2', 'password_confirmation' => 'nouveauMdp2',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->withToken($mine)->putJson('/api/profile/password', [
            'current_password' => 'ancienMdp1', 'password' => 'nouveauMdp2', 'password_confirmation' => 'autre',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->withToken($mine)->putJson('/api/profile/password', [
            'current_password' => 'ancienMdp1', 'password' => 'nouveauMdp2', 'password_confirmation' => 'nouveauMdp2',
        ])->assertOk();

        $this->assertTrue(Hash::check('nouveauMdp2', $this->user->fresh()->password));
        $this->assertSame(['navigateur'], $this->user->tokens()->pluck('name')->all());
        $this->assertNotSame($other, $mine);
    }

    public function test_avatar_upload_serve_and_remove(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)->getJson('/api/profile/avatar')->assertNotFound();

        $this->actingAs($this->user)
            ->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('moi.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk();

        $path = $this->user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->assertNotNull($this->user->fresh()->avatar_updated_at);

        $this->actingAs($this->user)->get('/api/profile/avatar')->assertOk();

        // Remplacer la photo efface l'ancienne.
        $this->actingAs($this->user)
            ->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('moi2.jpg')], ['Accept' => 'application/json'])
            ->assertOk();
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($this->user)
            ->post('/api/profile/avatar', ['avatar' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->actingAs($this->user)->deleteJson('/api/profile/avatar')->assertOk()->assertJsonPath('data.user.avatar_updated_at', null);
        $this->assertNull($this->user->fresh()->avatar_path);
    }
}
