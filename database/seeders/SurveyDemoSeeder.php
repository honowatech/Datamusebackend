<?php

namespace Database\Seeders;

use App\Enums\FollowUpStatus;
use App\Enums\MediaState;
use App\Enums\ProjectRole;
use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\MaterializeSurveyDatasourceJob;
use App\Models\Device;
use App\Models\EnumeratorAssignment;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\PublicLink;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\User;
use App\Services\Survey\SurveyVersionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * B-13 — jeu de démonstration du module Enquêtes : projet « MunaGo Douala », questionnaire MunaGo publié
 * depuis `docs/fixtures/munago.v1.dfs.json`, 60 soumissions et leurs suivis
 * (`docs/fixtures/munago.submissions.json`), un lien public actif et la source de données matérialisée.
 *
 * Comptes créés (mot de passe `password`) :
 *   - `analyste@datamuse.local`  analyste du projet (créé par `SurveyRolesSeeder`) ;
 *   - `enq1@test.local` … `enq3@test.local`  enquêteurs, zones Bonamoussadi / Akwa / Makepe.
 *
 * **Idempotent** : relancer le seeder ne duplique rien (comptes `updateOrCreate`, questionnaire retrouvé
 * par `slug`, soumissions par `uuid`). Si le projet existe déjà avec sa version publiée, seule la
 * matérialisation est rejouée.
 *
 * Volontairement indépendant de `tests/Support/MunagoFixtureLoader.php` : `tests/` n'est chargé que par
 * l'autoloader de développement (`autoload-dev`), un seeder doit fonctionner en production.
 */
class SurveyDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const PROJECT_NAME = 'MunaGo Douala';

    public const SURVEY_SLUG = 'munago-douala-terrain';

    public const PUBLIC_LINK_TOKEN = 'munago-demo';

    /** Zones d'affectation des trois enquêteurs de démonstration. */
    public const ZONES = [1 => 'Bonamoussadi', 2 => 'Akwa', 3 => 'Makepe'];

    private const DEFINITION_FIXTURE = '../docs/fixtures/munago.v1.dfs.json';

    private const SUBMISSIONS_FIXTURE = '../docs/fixtures/munago.submissions.json';

    /** JPEG 1×1 (placeholder réellement écrit sur le disque privé pour les médias de démonstration). */
    private const PLACEHOLDER_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        ."\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C\x20\x24\x2E\x27\x20\x22\x2C\x23\x1C\x1C\x28\x37\x29\x2C\x30\x31\x34\x34\x34\x1F\x27\x39\x3D\x38\x32\x3C\x2E\x33\x34\x32"
        ."\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00"
        ."\xFF\xC4\x00\x14\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x03"
        ."\xFF\xC4\x00\x14\x10\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
        ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\x37\xFF\xD9";

    public function run(): void
    {
        $definition = $this->json(self::DEFINITION_FIXTURE);
        $fixture = $this->json(self::SUBMISSIONS_FIXTURE);

        if ($definition === null || $fixture === null) {
            $this->command?->warn('SurveyDemoSeeder : fixtures MunaGo introuvables dans docs/fixtures — démonstration ignorée.');

            return;
        }

        $analyst = $this->analyst();
        $enumerators = $this->enumerators();
        $project = $this->project($analyst, $enumerators);
        $survey = $this->survey($project, $analyst, $definition);

        $devices = $this->devices($enumerators, $fixture);
        $this->submissions($survey, $devices, $enumerators, $fixture);
        $this->followUps($survey, $fixture);
        $this->publicLink($survey, $analyst);

        // Matérialisation synchrone : la démonstration est utilisable immédiatement (chat SQL, stats,
        // rapports) sans attendre un worker de file.
        MaterializeSurveyDatasourceJob::dispatchSync($survey->id);

        $this->command?->info(sprintf(
            'SurveyDemoSeeder : projet « %s », questionnaire #%d, %d soumission(s), lien public /s/%s.',
            $project->name,
            $survey->id,
            Submission::query()->where('survey_id', $survey->id)->count(),
            self::PUBLIC_LINK_TOKEN,
        ));
    }

    // ------------------------------------------------------------------ comptes

    private function analyst(): User
    {
        return User::query()->updateOrCreate(
            ['email' => 'analyste@datamuse.local'],
            [
                'name' => 'Analyste Datamuse',
                'role' => UserRole::Analyste,
                'locale' => 'fr',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ],
        );
    }

    /**
     * @return array<int, User> numéro (1..3) => enquêteur
     */
    private function enumerators(): array
    {
        $out = [];
        foreach (self::ZONES as $n => $zone) {
            $out[$n] = User::query()->updateOrCreate(
                ['email' => "enq{$n}@test.local"],
                [
                    'name' => 'Enquêteur '.$n,
                    'role' => UserRole::Enqueteur,
                    'locale' => 'fr',
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                ],
            );
        }

        return $out;
    }

    /**
     * @param  array<int, User>  $enumerators
     */
    private function project(User $analyst, array $enumerators): SurveyProject
    {
        $project = SurveyProject::query()->firstOrCreate(
            ['owner_id' => $analyst->id, 'name' => self::PROJECT_NAME],
            [
                'description' => 'Étude de marché terrain du traceur MunaGo à Douala (démonstration).',
                'client_name' => 'Honowa Technologies',
            ],
        );

        ProjectMember::query()->updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $analyst->id],
            ['role' => ProjectRole::Analyste, 'status' => 'active'],
        );

        foreach ($enumerators as $n => $user) {
            ProjectMember::query()->updateOrCreate(
                ['project_id' => $project->id, 'user_id' => $user->id],
                ['role' => ProjectRole::Enqueteur, 'zone' => self::ZONES[$n], 'status' => 'active'],
            );
        }

        return $project;
    }

    // ------------------------------------------------------------------ questionnaire

    /**
     * @param  array<string, mixed>  $definition
     */
    private function survey(SurveyProject $project, User $analyst, array $definition): Survey
    {
        $versions = app(SurveyVersionService::class);

        $survey = Survey::query()->where('project_id', $project->id)->where('slug', self::SURVEY_SLUG)->first();
        if ($survey !== null && $survey->published_version_id !== null) {
            return $survey;
        }

        if ($survey === null) {
            $survey = $versions->createSurvey($project, $analyst, 'MunaGo — étude de marché terrain', $definition);
            $survey->forceFill(['slug' => self::SURVEY_SLUG])->save();
        }

        $versions->publish($survey, $analyst);

        foreach ($this->enumerators() as $n => $user) {
            EnumeratorAssignment::query()->updateOrCreate(
                ['survey_id' => $survey->id, 'user_id' => $user->id],
                ['zone' => self::ZONES[$n], 'quota_target' => 10],
            );
        }

        return $survey->refresh();
    }

    // ------------------------------------------------------------------ terrain

    /**
     * @param  array<int, User>  $enumerators
     * @param  array<string, mixed>  $fixture
     * @return array<int, Device>
     */
    private function devices(array $enumerators, array $fixture): array
    {
        $deviceIds = [];
        foreach ($fixture['submissions'] ?? [] as $submission) {
            $n = self::enumeratorNumberOf($submission['device_id'] ?? null);
            $deviceIds[$n] ??= (string) ($submission['device_id'] ?? "android-enq{$n}-0000");
        }

        $out = [];
        foreach ($enumerators as $n => $user) {
            $out[$n] = Device::query()->updateOrCreate(
                ['user_id' => $user->id, 'device_id' => $deviceIds[$n] ?? "android-enq{$n}-0000"],
                ['platform' => 'android', 'app_version' => '1.0.0', 'model' => 'Demo', 'last_seen_at' => now()],
            );
        }

        return $out;
    }

    /**
     * @param  array<int, Device>  $devices
     * @param  array<int, User>  $enumerators
     * @param  array<string, mixed>  $fixture
     */
    private function submissions(Survey $survey, array $devices, array $enumerators, array $fixture): void
    {
        $version = $survey->publishedVersion;
        if ($version === null) {
            return;
        }

        $flags = $this->flagsByUuid($fixture);
        $usedCodes = Submission::query()->where('survey_id', $survey->id)->pluck('fiche_code')->filter()->flip()->all();
        $count = 0;

        foreach ($fixture['submissions'] ?? [] as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '' || Submission::query()->where('uuid', $uuid)->exists()) {
                continue;
            }

            $n = self::enumeratorNumberOf($row['device_id'] ?? null);
            $started = Carbon::parse($row['started_at']);
            $ended = Carbon::parse($row['ended_at']);
            $geo = $row['geo']['start'] ?? $row['geo']['end'] ?? null;
            $updatedAt = isset($row['client_updated_at']) ? Carbon::parse($row['client_updated_at']) : $ended;

            $ficheCode = $row['fiche_code'] ?? null;
            if ($ficheCode !== null) {
                $base = $ficheCode;
                $suffix = 'B';
                while (isset($usedCodes[$ficheCode])) {
                    $ficheCode = $base.'-'.$suffix;
                    $suffix++;
                }
                $usedCodes[$ficheCode] = true;
            }

            $rowFlags = $flags[$uuid] ?? [];

            $submission = Submission::query()->create([
                'uuid' => $uuid,
                'survey_id' => $survey->id,
                'survey_version_id' => $version->id,
                'project_id' => $survey->project_id,
                'enumerator_id' => $enumerators[$n]->id,
                'device_id' => $devices[$n]->id,
                'channel' => SubmissionChannel::Mobile,
                'status' => ($row['status'] ?? 'completed') === 'screened_out' ? SubmissionStatus::ScreenedOut : SubmissionStatus::Submitted,
                'fiche_code' => $ficheCode,
                'zone' => $row['zone'] ?? null,
                'language' => $row['language'] ?? 'fr',
                'started_at' => $started,
                'ended_at' => $ended,
                'duration_seconds' => max(0, $ended->getTimestamp() - $started->getTimestamp()),
                'geo_lat' => $geo['lat'] ?? null,
                'geo_lng' => $geo['lng'] ?? null,
                'geo_accuracy' => $geo['accuracy'] ?? null,
                'geo' => $row['geo'] ?? null,
                'answers' => $row['answers'] ?? [],
                'end_reason' => $row['end_reason'] ?? null,
                'client_updated_at' => $updatedAt,
                'received_at' => $updatedAt->copy()->addMinutes(5),
                'flags' => $rowFlags,
                'suspicion_score' => $rowFlags === [] ? 0 : 40 * count($rowFlags),
                'device_time_offset_ms' => $row['device_time_offset_ms'] ?? 0,
            ]);

            $this->media($survey, $submission, $row['media'] ?? []);
            $count++;
        }

        if ($count > 0) {
            $survey->forceFill([
                'submissions_count' => Submission::query()->where('survey_id', $survey->id)->count(),
                'last_submission_at' => Submission::query()->where('survey_id', $survey->id)->max('received_at'),
            ])->save();
        }
    }

    /**
     * Médias de démonstration : un JPEG 1×1 réellement écrit sur le disque privé, pour que les URL
     * signées du détail d'une fiche renvoient bien un fichier.
     *
     * @param  array<int, array<string, mixed>>  $media
     */
    private function media(Survey $survey, Submission $submission, array $media): void
    {
        $disk = (string) config('filesystems.survey_media_disk', 'local');

        foreach ($media as $entry) {
            if (! is_array($entry) || ! is_string($entry['question_key'] ?? null)) {
                continue;
            }
            $path = sprintf(
                'surveys/%d/submissions/%s/%s.jpg',
                $survey->id,
                $submission->uuid,
                $entry['question_key'],
            );
            $bytes = self::PLACEHOLDER_JPEG;
            Storage::disk($disk)->put($path, $bytes);

            SubmissionMedia::query()->updateOrCreate(
                [
                    'submission_id' => $submission->id,
                    'question_key' => $entry['question_key'],
                    'repeat_index' => $entry['repeat_index'] ?? 0,
                ],
                [
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $entry['mime'] ?? 'image/jpeg',
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'state' => MediaState::Uploaded,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function followUps(Survey $survey, array $fixture): void
    {
        $windows = [];
        foreach ($survey->publishedVersion?->definition['follow_up_stages'] ?? [] as $stage) {
            if (is_array($stage) && is_string($stage['key'] ?? null)) {
                $windows[$stage['key']] = (int) ($stage['window_days'] ?? 3);
            }
        }

        foreach ($fixture['follow_ups'] ?? [] as $entry) {
            $parent = Submission::query()->where('uuid', (string) ($entry['parent_submission_uuid'] ?? ''))->first();
            if ($parent === null || ! is_string($entry['stage_key'] ?? null)) {
                continue;
            }

            $due = isset($entry['due_at']) ? Carbon::parse($entry['due_at']) : $parent->ended_at->copy()->addDays(4);
            $completedAt = isset($entry['completed_at']) ? Carbon::parse($entry['completed_at']) : null;

            FollowUpEntry::query()->updateOrCreate(
                ['submission_id' => $parent->id, 'stage_key' => $entry['stage_key']],
                [
                    'survey_id' => $survey->id,
                    'enumerator_id' => $parent->enumerator_id,
                    'due_at' => $due,
                    'window_ends_at' => $due->copy()->addDays($windows[$entry['stage_key']] ?? 3),
                    'status' => match ($entry['status'] ?? 'pending') {
                        'completed', 'done' => FollowUpStatus::Done,
                        'skipped' => FollowUpStatus::Skipped,
                        'missed' => FollowUpStatus::Missed,
                        default => FollowUpStatus::Pending,
                    },
                    'answers' => ($entry['answers'] ?? []) === [] ? null : $entry['answers'],
                    'completed_at' => $completedAt,
                    'client_updated_at' => $completedAt,
                ],
            );
        }
    }

    private function publicLink(Survey $survey, User $analyst): void
    {
        // Le lien de démonstration est le lien web par défaut du questionnaire (créé avec lui).
        $link = PublicLink::query()->where('token', self::PUBLIC_LINK_TOKEN)->first()
            ?? $survey->publicLinks()->where('is_default', true)->first()
            ?? new PublicLink;

        $link->fill([
            'survey_id' => $survey->id,
            'token' => self::PUBLIC_LINK_TOKEN,
            'label' => 'Lien de démonstration',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $analyst->id,
        ])->save();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Drapeaux qualité attendus par la fixture (`expected_stats.flags`).
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, list<string>>
     */
    private function flagsByUuid(array $fixture): array
    {
        $out = [];
        foreach ($fixture['expected_stats']['flags']['too_fast'] ?? [] as $uuid) {
            $out[(string) $uuid][] = Submission::FLAG_TOO_FAST;
        }
        foreach ($fixture['expected_stats']['flags']['duplicate']['uuids'] ?? [] as $uuid) {
            $out[(string) $uuid][] = Submission::FLAG_DUPLICATE;
        }

        return $out;
    }

    /** Numéro d'enquêteur (1..3) d'un identifiant d'appareil `android-enqN-xxxx`. */
    public static function enumeratorNumberOf(?string $deviceId): int
    {
        return $deviceId !== null && preg_match('/enq(\d+)/', $deviceId, $m) === 1 ? max(1, min(3, (int) $m[1])) : 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $relativePath): ?array
    {
        $path = base_path($relativePath);
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
