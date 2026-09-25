<?php

use App\Enums\ProjectRole;
use App\Enums\UserRole;
use App\Models\ProjectMember;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Survey\SurveyVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration de données — projet « MunaGo Douala » en production : trois enquêteurs (un par zone),
 * le projet détenu par l'administrateur, et le questionnaire MunaGo v1 publié (enquêteurs affectés
 * par zone, lien web par défaut). Aucune soumission.
 *
 * Ne s'applique que si le compte propriétaire existe (`admin@honowa.com`, créé par le lot SQL
 * d'installation) : les bases de test et de développement l'ignorent, la démonstration locale
 * reste l'affaire de `SurveyDemoSeeder`. Idempotente : rien n'est dupliqué si elle est rejouée.
 *
 * Les mots de passe des enquêteurs ne figurent ici que sous forme de hash ; ils sont à changer
 * dès la première connexion.
 */
return new class extends Migration
{
    private const OWNER_EMAIL = 'admin@honowa.com';

    private const PROJECT_NAME = 'MunaGo Douala';

    private const SURVEY_SLUG = 'munago-douala-terrain';

    private const DEFINITION = 'database/data/munago.v1.dfs.json';

    /** @var array<int, array{email: string, zone: string, password: string}> */
    private const ENUMERATORS = [
        1 => ['email' => 'enq1@honowa.com', 'zone' => 'Bonamoussadi', 'password' => '$2y$12$tzh1yP49itCaJP4EzHtWDOoQyQKhHrLDjWbKWXNIZqH.53TjfoQru'],
        2 => ['email' => 'enq2@honowa.com', 'zone' => 'Akwa', 'password' => '$2y$12$mkP6Iz/EtN8JxcDOvNJLz.66M5uBRldKlqIdZCRXgErRChl18STWe'],
        3 => ['email' => 'enq3@honowa.com', 'zone' => 'Makepe', 'password' => '$2y$12$gwdnUobZEZe7t9DjJtoCdODKe17W8uAUB5Qz91.7r8Y8yWuLQ4vja'],
    ];

    public function up(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();
        if ($owner === null) {
            return;
        }

        $definition = json_decode((string) file_get_contents(base_path(self::DEFINITION)), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($owner, $definition) {
            $project = SurveyProject::query()->firstOrCreate(
                ['owner_id' => $owner->id, 'name' => self::PROJECT_NAME],
                [
                    'description' => 'Étude de marché terrain du traceur MunaGo à Douala.',
                    'client_name' => 'Honowa Technologies',
                    'settings' => ['timezone' => 'Africa/Douala', 'currency' => 'XAF'],
                ],
            );

            ProjectMember::query()->firstOrCreate(
                ['project_id' => $project->id, 'user_id' => $owner->id],
                ['role' => ProjectRole::Analyste, 'status' => ProjectMember::STATUS_ACTIVE],
            );

            foreach (self::ENUMERATORS as $n => $enumerator) {
                // Hash inséré tel quel : le cast `hashed` du modèle le rejetterait si le coût
                // bcrypt du serveur différait de celui du hash.
                if (! DB::table('users')->where('email', $enumerator['email'])->exists()) {
                    DB::table('users')->insert([
                        'name' => 'Enquêteur '.$n,
                        'email' => $enumerator['email'],
                        'password' => $enumerator['password'],
                        'role' => UserRole::Enqueteur->value,
                        'locale' => 'fr',
                        'email_verified_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                ProjectMember::query()->firstOrCreate(
                    ['project_id' => $project->id, 'user_id' => DB::table('users')->where('email', $enumerator['email'])->value('id')],
                    ['role' => ProjectRole::Enqueteur, 'zone' => $enumerator['zone'], 'status' => ProjectMember::STATUS_ACTIVE],
                );
            }

            $survey = Survey::query()->where('project_id', $project->id)->where('slug', self::SURVEY_SLUG)->first();
            if ($survey?->published_version_id !== null) {
                return;
            }

            $versions = app(SurveyVersionService::class);
            if ($survey === null) {
                $survey = $versions->createSurvey($project, $owner, 'MunaGo — étude de marché terrain', $definition);
                $survey->forceFill(['slug' => self::SURVEY_SLUG])->save();
            }

            // Affecte aussi les enquêteurs actifs du projet au questionnaire, avec leur zone.
            $versions->publish($survey, $owner);
        });
    }

    public function down(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();
        if ($owner === null) {
            return;
        }

        // Le projet emporte en cascade questionnaires, versions, membres et affectations.
        SurveyProject::query()->where('owner_id', $owner->id)->where('name', self::PROJECT_NAME)->get()->each->delete();
        DB::table('users')->whereIn('email', array_column(self::ENUMERATORS, 'email'))->delete();
    }
};
