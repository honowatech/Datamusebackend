<?php

namespace Tests\Support;

use App\Enums\FollowUpStatus;
use App\Enums\MediaState;
use App\Enums\SubmissionChannel;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\EnumeratorAssignment;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Charge la fixture MunaGo (`docs/fixtures/munago.v1.dfs.json` + `munago.submissions.json`) dans la base :
 * projet, questionnaire, version publiée, 3 enquêteurs `enq1..3@test.local` (+ appareils, affectations,
 * appartenance au projet), 60 soumissions avec médias, entrées de suivi et drapeaux de `expected_stats`.
 *
 * Réutilisable par les tests de matérialisation (B-09), de statistiques et d'export (B-10).
 *
 *   $fx = MunagoFixtureLoader::load();
 *   $fx->survey, $fx->version, $fx->owner, $fx->enumerators[1..3], $fx->submissions (Collection par uuid),
 *   $fx->expectedStats, $fx->fixture (JSON brut)
 */
final class MunagoFixtureLoader
{
    public const SUBMISSIONS_FIXTURE = '../docs/fixtures/munago.submissions.json';

    public SurveyProject $project;

    public Survey $survey;

    public SurveyVersion $version;

    public User $owner;

    /** @var array<int, User> numéro (1..3) => utilisateur */
    public array $enumerators = [];

    /** @var array<int, Device> numéro (1..3) => appareil */
    public array $devices = [];

    /** @var Collection<string, Submission> uuid => soumission */
    public Collection $submissions;

    /** @var Collection<int, FollowUpEntry> */
    public Collection $followUps;

    /** @var array<string, mixed> */
    public array $expectedStats = [];

    /** @var array<string, mixed> */
    public array $fixture = [];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @param  array{owner?: User, project?: SurveyProject, survey?: Survey, title?: string, with_media?: bool, with_follow_ups?: bool, with_flags?: bool, limit?: int}  $options
     */
    public static function load(array $options = []): self
    {
        $loader = new self;
        $loader->fixture = self::fixture();
        $loader->expectedStats = $loader->fixture['expected_stats'] ?? [];

        $loader->owner = $options['owner'] ?? User::factory()->create(['role' => UserRole::Analyste, 'name' => 'Analyste MunaGo', 'email' => 'analyste.munago@test.local']);
        $loader->project = $options['project'] ?? SurveyProject::factory()->create(['owner_id' => $loader->owner->id, 'name' => 'Étude MunaGo']);
        $loader->survey = $options['survey'] ?? Survey::factory()->create([
            'project_id' => $loader->project->id,
            'created_by' => $loader->owner->id,
            'title' => $options['title'] ?? 'MunaGo — test terrain',
            'slug' => 'munago-test-terrain',
        ]);
        $loader->version = $loader->survey->publishedVersion ?? SurveyVersion::factory()->munago()->published()->create([
            'survey_id' => $loader->survey->id,
            'published_by' => $loader->owner->id,
        ]);
        $loader->survey->refresh();

        $loader->createEnumerators();
        $loader->createSubmissions($options);

        return $loader;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fixture(): array
    {
        if (self::$cache === null) {
            $path = base_path(self::SUBMISSIONS_FIXTURE);
            if (! is_file($path)) {
                throw new \RuntimeException("Fixture des soumissions MunaGo introuvable : {$path}");
            }
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::$cache = is_array($decoded) ? $decoded : [];
        }

        return self::$cache;
    }

    /** Numéro d'enquêteur (1..3) d'un identifiant d'appareil `android-enqN-xxxx`. */
    public static function enumeratorNumberOf(?string $deviceId): int
    {
        return $deviceId !== null && preg_match('/enq(\d+)/', $deviceId, $m) ? (int) $m[1] : 1;
    }

    private function createEnumerators(): void
    {
        $zones = [1 => 'Bonamoussadi', 2 => 'Akwa', 3 => 'Makepe'];
        $deviceIds = [];
        foreach ($this->fixture['submissions'] ?? [] as $s) {
            $n = self::enumeratorNumberOf($s['device_id'] ?? null);
            $deviceIds[$n] ??= $s['device_id'] ?? ('android-enq'.$n.'-0000');
        }

        for ($n = 1; $n <= 3; $n++) {
            $user = User::factory()->create([
                'name' => 'Enquêteur '.$n,
                'email' => 'enq'.$n.'@test.local',
                'role' => UserRole::Enqueteur,
            ]);
            ProjectMember::factory()->enqueteur()->create([
                'project_id' => $this->project->id,
                'user_id' => $user->id,
                'zone' => $zones[$n],
            ]);
            EnumeratorAssignment::factory()->create([
                'survey_id' => $this->survey->id,
                'user_id' => $user->id,
                'zone' => $zones[$n],
                'quota_target' => 10,
            ]);
            $this->devices[$n] = Device::factory()->create([
                'user_id' => $user->id,
                'device_id' => $deviceIds[$n] ?? ('android-enq'.$n.'-0000'),
            ]);
            $this->enumerators[$n] = $user;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function createSubmissions(array $options): void
    {
        $withMedia = $options['with_media'] ?? true;
        $withFollowUps = $options['with_follow_ups'] ?? true;
        $withFlags = $options['with_flags'] ?? true;
        $limit = $options['limit'] ?? null;

        $flagsByUuid = [];
        if ($withFlags) {
            foreach ($this->expectedStats['flags']['too_fast'] ?? [] as $uuid) {
                $flagsByUuid[$uuid][] = Submission::FLAG_TOO_FAST;
            }
            foreach ($this->expectedStats['flags']['duplicate']['uuids'] ?? [] as $uuid) {
                $flagsByUuid[$uuid][] = Submission::FLAG_DUPLICATE;
            }
        }

        $stageWindows = [];
        foreach ($this->version->definition['follow_up_stages'] ?? [] as $stage) {
            $stageWindows[$stage['key']] = (int) ($stage['window_days'] ?? 3);
        }

        $usedCodes = [];
        $this->submissions = collect();
        $rows = $this->fixture['submissions'] ?? [];
        if ($limit !== null) {
            $rows = array_slice($rows, 0, $limit);
        }

        foreach ($rows as $s) {
            $n = self::enumeratorNumberOf($s['device_id'] ?? null);
            $enumerator = $this->enumerators[$n];
            $started = Carbon::parse($s['started_at']);
            $ended = Carbon::parse($s['ended_at']);
            $geo = $s['geo']['start'] ?? $s['geo']['end'] ?? null;
            $flags = $flagsByUuid[$s['uuid']] ?? [];

            $ficheCode = $s['fiche_code'] ?? null;
            if ($ficheCode !== null) {
                $base = $ficheCode;
                $suffix = 'B';
                while (isset($usedCodes[$ficheCode])) {
                    $ficheCode = $base.'-'.$suffix;
                    $suffix++;
                }
                $usedCodes[$ficheCode] = true;
            }

            $submission = Submission::query()->create([
                'uuid' => $s['uuid'],
                'survey_id' => $this->survey->id,
                'survey_version_id' => $this->version->id,
                'project_id' => $this->project->id,
                'enumerator_id' => $enumerator->id,
                'device_id' => $this->devices[$n]->id,
                'channel' => SubmissionChannel::Mobile,
                'status' => ($s['status'] ?? 'completed') === 'screened_out' ? SubmissionStatus::ScreenedOut : SubmissionStatus::Submitted,
                'fiche_code' => $ficheCode,
                'zone' => $s['zone'] ?? null,
                'language' => $s['language'] ?? 'fr',
                'started_at' => $started,
                'ended_at' => $ended,
                'duration_seconds' => max(0, $ended->getTimestamp() - $started->getTimestamp()),
                'geo_lat' => $geo['lat'] ?? null,
                'geo_lng' => $geo['lng'] ?? null,
                'geo_accuracy' => $geo['accuracy'] ?? null,
                'geo' => $s['geo'] ?? null,
                'answers' => $s['answers'] ?? [],
                'end_reason' => $s['end_reason'] ?? null,
                'client_updated_at' => isset($s['client_updated_at']) ? Carbon::parse($s['client_updated_at']) : $ended,
                'received_at' => (isset($s['client_updated_at']) ? Carbon::parse($s['client_updated_at']) : $ended)->copy()->addMinutes(5),
                'flags' => $flags,
                'suspicion_score' => $flags === [] ? 0 : 40 * count($flags),
                'device_time_offset_ms' => $s['device_time_offset_ms'] ?? 0,
            ]);

            if ($withMedia) {
                foreach ($s['media'] ?? [] as $m) {
                    SubmissionMedia::query()->create([
                        'submission_id' => $submission->id,
                        'question_key' => $m['question_key'],
                        'repeat_index' => $m['repeat_index'] ?? 0,
                        'disk' => 'local',
                        'path' => sprintf('surveys/%d/submissions/%s/%s.jpg', $this->survey->id, $submission->uuid, $m['question_key']),
                        'mime' => $m['mime'] ?? 'image/jpeg',
                        'size' => $m['size'] ?? 0,
                        'sha256' => $m['sha256'] ?? hash('sha256', Str::random(16)),
                        'state' => MediaState::Uploaded,
                    ]);
                }
            }

            $this->submissions->put($submission->uuid, $submission);
        }

        $this->survey->forceFill([
            'submissions_count' => $this->submissions->count(),
            'last_submission_at' => $this->submissions->max(fn (Submission $s) => $s->received_at),
        ])->save();

        $this->followUps = collect();
        if (! $withFollowUps) {
            return;
        }

        foreach ($this->fixture['follow_ups'] ?? [] as $f) {
            $parent = $this->submissions->get($f['parent_submission_uuid'] ?? '');
            if ($parent === null) {
                continue;
            }
            $due = isset($f['due_at']) ? Carbon::parse($f['due_at']) : $parent->ended_at->copy()->addDays(4);
            $status = match ($f['status'] ?? 'pending') {
                'completed', 'done' => FollowUpStatus::Done,
                'skipped' => FollowUpStatus::Skipped,
                'missed' => FollowUpStatus::Missed,
                default => FollowUpStatus::Pending,
            };
            $this->followUps->push(FollowUpEntry::query()->create([
                'submission_id' => $parent->id,
                'survey_id' => $this->survey->id,
                'stage_key' => $f['stage_key'],
                'enumerator_id' => $parent->enumerator_id,
                'due_at' => $due,
                'window_ends_at' => $due->copy()->addDays($stageWindows[$f['stage_key']] ?? 3),
                'status' => $status,
                'answers' => ($f['answers'] ?? []) === [] ? null : $f['answers'],
                'completed_at' => isset($f['completed_at']) ? Carbon::parse($f['completed_at']) : null,
                'client_updated_at' => isset($f['completed_at']) ? Carbon::parse($f['completed_at']) : null,
            ]));
        }
    }

    /** Soumission par uuid (exception si absente). */
    public function submission(string $uuid): Submission
    {
        $s = $this->submissions->get($uuid);
        if ($s === null) {
            throw new \OutOfBoundsException("Soumission {$uuid} absente de la fixture chargée.");
        }

        return $s;
    }

    /** Première soumission dont le statut fixture est `completed` et qui possède un acompte (médias). */
    public function firstWithDeposit(): ?Submission
    {
        return $this->submissions->first(fn (Submission $s) => ($s->answers['acompte_verse'] ?? false) === true);
    }
}
