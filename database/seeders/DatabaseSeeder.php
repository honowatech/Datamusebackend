<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Utilisateur de test historique (idempotent).
        User::query()->firstOr(fn () => User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]));

        $this->call([
            SystemPromptSeeder::class,
            SurveyRolesSeeder::class,
            // B-13 — démonstration MunaGo (projet, questionnaire publié, 60 soumissions, lien public,
            // source de données matérialisée). Idempotent ; ignoré si `docs/fixtures/` est absent.
            SurveyDemoSeeder::class,
        ]);
    }
}
