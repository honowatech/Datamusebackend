<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de base du module Enquêtes (idempotent) : admin et analyste, mot de passe `password`.
 * B-13 (SurveyDemoSeeder) ajoutera les enquêteurs et le projet MunaGo.
 */
class SurveyRolesSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** @var array<int, array{name: string, email: string, role: UserRole}> */
    public const ACCOUNTS = [
        ['name' => 'Administrateur Datamuse', 'email' => 'admin@datamuse.local', 'role' => UserRole::Admin],
        ['name' => 'Analyste Datamuse', 'email' => 'analyste@datamuse.local', 'role' => UserRole::Analyste],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'locale' => 'fr',
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
