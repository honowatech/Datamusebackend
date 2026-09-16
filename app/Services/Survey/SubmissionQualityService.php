<?php

namespace App\Services\Survey;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Services\Dfs\LogicEvaluator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Drapeaux qualité et score de suspicion d'une soumission (plan § 5.3 « Drapeaux qualité »,
 * docs/openapi/survey.yaml schéma `QualityFlag`).
 *
 * | Drapeau | Règle |
 * |---|---|
 * | `too_fast`          | durée < `settings.timing.min_duration_seconds` (soumissions complètes uniquement : un STOP est légitimement court) |
 * | `duplicate`         | une autre soumission de l'enquête porte les mêmes valeurs de `settings.duplicate_keys`, ou le même `answers_hash` |
 * | `off_hours`         | heure locale de `started_at` hors [07:00, 20:00) |
 * | `gps_missing`       | `settings.geo.capture != none` et `settings.geo.required` et aucune position reçue |
 * | `gps_outside_zone`  | `settings.geo.zones` définit une bbox pour la zone de la fiche et le point est en dehors (jamais sinon) |
 * | `clock_skew`        | `|device_time_offset_ms|` > 10 min |
 * | `quota_exceeded`    | un quota `max: true` est dépassé (portée `survey`, `zone`, `enumerator` ou `answer`) |
 *
 * `evaluate()` renvoie `{flags: string[], score: int}` ; `apply()` persiste `flags` et
 * `suspicion_score` sur la soumission. Le service est appelé à la réception (B-07) et
 * réutilisable par `POST /surveys/{id}/supervision/recompute` (B-10).
 *
 * `settings.geo.zones` (extension facultative du DFS, ignorée si absente) :
 * `{"Akwa": {"min_lat": …, "max_lat": …, "min_lng": …, "max_lng": …}}` — les alias
 * `[minLat, minLng, maxLat, maxLng]` et `{"bbox": [...]}` sont acceptés.
 */
class SubmissionQualityService
{
    /** Décalage d'horloge toléré (ms) avant `clock_skew`. */
    public const CLOCK_SKEW_TOLERANCE_MS = 10 * 60 * 1000;

    /** Plage horaire « normale » de collecte (heure locale de `started_at`). */
    public const OFF_HOURS_START = 7;

    public const OFF_HOURS_END = 20;

    /** Pondération du score de suspicion (somme bornée à 100). */
    public const WEIGHTS = [
        Submission::FLAG_TOO_FAST => 30,
        Submission::FLAG_DUPLICATE => 35,
        Submission::FLAG_OFF_HOURS => 10,
        Submission::FLAG_GPS_MISSING => 10,
        Submission::FLAG_GPS_OUTSIDE_ZONE => 25,
        Submission::FLAG_CLOCK_SKEW => 10,
        Submission::FLAG_QUOTA_EXCEEDED => 15,
    ];

    public function __construct(private readonly LogicEvaluator $logic = new LogicEvaluator) {}

    /**
     * Évalue les drapeaux d'une soumission déjà persistée (ou au moins renseignée).
     *
     * @param  array<string, mixed>|null  $settings  `settings` du DFS ; lu depuis la version si null
     * @param  string|null  $localStartIso  `started_at` tel qu'envoyé (avec son décalage) : c'est lui qui
     *                                      porte l'heure *locale* du terrain pour le drapeau `off_hours`
     * @return array{flags: list<string>, score: int}
     */
    public function evaluate(Submission $submission, ?array $settings = null, ?string $localStartIso = null): array
    {
        $settings ??= $this->settingsOf($submission);
        $flags = [];

        if ($this->isTooFast($submission, $settings)) {
            $flags[] = Submission::FLAG_TOO_FAST;
        }
        if ($this->isDuplicate($submission, $settings)) {
            $flags[] = Submission::FLAG_DUPLICATE;
        }
        if ($this->isOffHours($submission, $localStartIso)) {
            $flags[] = Submission::FLAG_OFF_HOURS;
        }
        if ($this->isGpsMissing($submission, $settings)) {
            $flags[] = Submission::FLAG_GPS_MISSING;
        }
        if ($this->isGpsOutsideZone($submission, $settings)) {
            $flags[] = Submission::FLAG_GPS_OUTSIDE_ZONE;
        }
        if ($this->hasClockSkew($submission)) {
            $flags[] = Submission::FLAG_CLOCK_SKEW;
        }
        if ($this->exceedsQuota($submission, $settings)) {
            $flags[] = Submission::FLAG_QUOTA_EXCEEDED;
        }

        return ['flags' => $flags, 'score' => $this->score($flags)];
    }

    /**
     * Évalue puis persiste `flags` / `suspicion_score`.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array{flags: list<string>, score: int}
     */
    public function apply(Submission $submission, ?array $settings = null, ?string $localStartIso = null): array
    {
        $result = $this->evaluate($submission, $settings, $localStartIso);

        $submission->forceFill([
            'flags' => $result['flags'],
            'suspicion_score' => $result['score'],
        ])->save();

        return $result;
    }

    /**
     * Score 0-100 : somme pondérée des drapeaux, bornée.
     *
     * @param  list<string>  $flags
     */
    public function score(array $flags): int
    {
        $total = 0;
        foreach ($flags as $flag) {
            $total += self::WEIGHTS[$flag] ?? 10;
        }

        return max(0, min(100, $total));
    }

    // ------------------------------------------------------------------ règles

    /** @param array<string, mixed> $settings */
    private function isTooFast(Submission $submission, array $settings): bool
    {
        $min = $settings['timing']['min_duration_seconds'] ?? null;
        if (! is_numeric($min) || (int) $min <= 0) {
            return false;
        }
        // Un entretien interrompu par un `stop` est légitimement court (README § 8).
        if ($submission->status === SubmissionStatus::ScreenedOut) {
            return false;
        }
        $duration = $submission->duration_seconds;
        if ($duration === null && $submission->started_at && $submission->ended_at) {
            $duration = max(0, $submission->ended_at->getTimestamp() - $submission->started_at->getTimestamp());
        }

        return $duration !== null && $duration < (int) $min;
    }

    /** @param array<string, mixed> $settings */
    private function isDuplicate(Submission $submission, array $settings): bool
    {
        $answers = is_array($submission->answers) ? $submission->answers : [];

        // 1. même empreinte de réponses dans l'enquête.
        if ($submission->answers_hash !== null) {
            $same = Submission::query()
                ->where('survey_id', $submission->survey_id)
                ->where('answers_hash', $submission->answers_hash)
                ->when($submission->id !== null, fn ($q) => $q->where('id', '!=', $submission->id))
                ->exists();
            if ($same) {
                return true;
            }
        }

        // 2. mêmes valeurs sur toutes les `duplicate_keys` (au moins une renseignée).
        $keys = array_values(array_filter((array) ($settings['duplicate_keys'] ?? []), 'is_string'));
        if ($keys === []) {
            return false;
        }
        $values = [];
        foreach ($keys as $key) {
            $value = $answers[$key] ?? null;
            if (LogicEvaluator::isEmpty($value)) {
                return false;
            }
            $values[$key] = self::normalizeValue($value);
        }

        $candidates = Submission::query()
            ->where('survey_id', $submission->survey_id)
            ->when($submission->id !== null, fn ($q) => $q->where('id', '!=', $submission->id))
            ->whereNotIn('status', [SubmissionStatus::Rejected->value])
            ->get(['id', 'answers']);

        foreach ($candidates as $candidate) {
            $other = is_array($candidate->answers) ? $candidate->answers : [];
            $match = true;
            foreach ($values as $key => $value) {
                if (self::normalizeValue($other[$key] ?? null) !== $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    private function isOffHours(Submission $submission, ?string $localStartIso = null): bool
    {
        if ($submission->started_at === null && $localStartIso === null) {
            return false;
        }
        $local = $this->localStart($submission, $localStartIso);
        $hour = (int) $local->format('G');

        return $hour < self::OFF_HOURS_START || $hour >= self::OFF_HOURS_END;
    }

    /** @param array<string, mixed> $settings */
    private function isGpsMissing(Submission $submission, array $settings): bool
    {
        $geo = (array) ($settings['geo'] ?? []);
        $capture = (string) ($geo['capture'] ?? 'none');
        if ($capture === 'none' || ! (bool) ($geo['required'] ?? false)) {
            return false;
        }

        return $this->firstPoint($submission) === null;
    }

    /** @param array<string, mixed> $settings */
    private function isGpsOutsideZone(Submission $submission, array $settings): bool
    {
        $zones = $settings['geo']['zones'] ?? null;
        if (! is_array($zones) || $zones === []) {
            return false;
        }
        $zone = $submission->zone;
        if ($zone === null || ! array_key_exists($zone, $zones)) {
            return false;
        }
        $bbox = self::normalizeBbox($zones[$zone]);
        $point = $this->firstPoint($submission);
        if ($bbox === null || $point === null) {
            return false;
        }

        return $point['lat'] < $bbox['min_lat'] || $point['lat'] > $bbox['max_lat']
            || $point['lng'] < $bbox['min_lng'] || $point['lng'] > $bbox['max_lng'];
    }

    private function hasClockSkew(Submission $submission): bool
    {
        $offset = $submission->device_time_offset_ms;

        return $offset !== null && abs((int) $offset) > self::CLOCK_SKEW_TOLERANCE_MS;
    }

    /** @param array<string, mixed> $settings */
    private function exceedsQuota(Submission $submission, array $settings): bool
    {
        if ($submission->status === SubmissionStatus::ScreenedOut) {
            return false;
        }
        foreach ((array) ($settings['quotas'] ?? []) as $quota) {
            if (! is_array($quota) || ! (bool) ($quota['max'] ?? false)) {
                continue;
            }
            $target = (int) ($quota['target'] ?? 0);
            if ($target <= 0) {
                continue;
            }
            if (! $this->quotaApplies($quota, $submission)) {
                continue;
            }
            if ($this->quotaCount($quota, $submission) > $target) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ quotas

    /**
     * Progression des quotas d'une enquête (schéma OpenAPI `QuotaProgress`), utilisée par le manifest
     * mobile et la supervision. `$enumeratorId` restreint les portées `enumerator`.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    public function quotaProgress(Survey $survey, array $settings, ?int $enumeratorId = null, ?string $zone = null): array
    {
        $quotas = array_values(array_filter((array) ($settings['quotas'] ?? []), 'is_array'));
        if ($quotas === []) {
            return [];
        }

        $rows = $this->validRows($survey->id);
        $out = [];

        foreach ($quotas as $quota) {
            $key = (string) ($quota['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $scope = (string) ($quota['scope'] ?? 'survey');
            $target = (int) ($quota['target'] ?? 0);
            $max = (bool) ($quota['max'] ?? false);
            $filtered = array_values(array_filter($rows, fn (array $row) => $this->matchesFilter($quota, $row)));

            $current = 0;
            $breakdown = [];

            switch ($scope) {
                case 'zone':
                    $counts = $this->countBy($filtered, fn (array $row) => $row['zone']);
                    $current = $zone !== null ? ($counts[$zone] ?? 0) : (int) (max([0, ...array_values($counts)]));
                    $breakdown = $this->breakdown($counts, $target);
                    break;
                case 'enumerator':
                    $counts = $this->countBy($filtered, fn (array $row) => $row['enumerator_id'] === null ? null : (string) $row['enumerator_id']);
                    $current = $enumeratorId !== null ? ($counts[(string) $enumeratorId] ?? 0) : (int) (max([0, ...array_values($counts)]));
                    $breakdown = $this->breakdown($counts, $target);
                    break;
                case 'answer':
                    $question = (string) ($quota['question'] ?? '');
                    $counts = $this->countBy($filtered, fn (array $row) => self::normalizeValue($row['answers'][$question] ?? null));
                    $targets = is_array($quota['targets'] ?? null) ? $quota['targets'] : [];
                    $current = $counts === [] ? 0 : (int) max(array_values($counts));
                    $breakdown = $this->breakdown($counts, $target, $targets);
                    break;
                case 'distinct':
                    $question = (string) ($quota['question'] ?? '');
                    $counts = $this->countBy($filtered, fn (array $row) => self::normalizeValue($row['answers'][$question] ?? null));
                    $current = count($counts);
                    $breakdown = $this->breakdown($counts, null);
                    break;
                case 'survey':
                default:
                    $current = count($filtered);
                    break;
            }

            $out[] = [
                'key' => $key,
                'label' => $this->labelOf($quota, (string) ($settings['default_language'] ?? 'fr')),
                'scope' => $scope,
                'target' => $target,
                'current' => $current,
                'pct' => $target > 0 ? round($current / $target * 100, 1) : 0.0,
                'max' => $max,
                'exceeded' => $max && $target > 0 && $current > $target,
                'breakdown' => $breakdown,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $quota
     * @param  array<string, mixed>  $row
     */
    private function matchesFilter(array $quota, array $row): bool
    {
        if (! array_key_exists('filter', $quota) || $quota['filter'] === null) {
            return true;
        }

        return LogicEvaluator::truthy($this->logic->evaluate(
            $quota['filter'],
            $row['answers'],
            [
                '_status' => $row['status'],
                '_zone' => $row['zone'],
                '_enumerator' => $row['enumerator_id'],
                '_lang' => $row['language'],
            ],
        )->value);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, callable $keyOf): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $key = $keyOf($row);
            if ($key === null || $key === '') {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $targets
     * @return list<array<string, mixed>>
     */
    private function breakdown(array $counts, ?int $target, array $targets = []): array
    {
        $out = [];
        arsort($counts);
        foreach ($counts as $value => $current) {
            $out[] = [
                'value' => (string) $value,
                'current' => $current,
                'target' => isset($targets[$value]) ? (int) $targets[$value] : $target,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $quota
     * @param  array<string, mixed>  $settings
     */
    private function quotaApplies(array $quota, Submission $submission): bool
    {
        $answers = is_array($submission->answers) ? $submission->answers : [];

        return $this->matchesFilter($quota, [
            'answers' => $answers,
            'status' => $submission->status === SubmissionStatus::ScreenedOut ? 'screened_out' : 'completed',
            'zone' => $submission->zone,
            'enumerator_id' => $submission->enumerator_id,
            'language' => $submission->language,
        ]);
    }

    /** @param array<string, mixed> $quota */
    private function quotaCount(array $quota, Submission $submission): int
    {
        $rows = $this->validRows($submission->survey_id);
        $filtered = array_values(array_filter($rows, fn (array $row) => $this->matchesFilter($quota, $row)));
        $answers = is_array($submission->answers) ? $submission->answers : [];

        return match ((string) ($quota['scope'] ?? 'survey')) {
            'zone' => count(array_filter($filtered, fn (array $row) => $row['zone'] === $submission->zone)),
            'enumerator' => count(array_filter($filtered, fn (array $row) => $row['enumerator_id'] === $submission->enumerator_id)),
            'answer' => $this->countAnswer($filtered, (string) ($quota['question'] ?? ''), $answers),
            'distinct' => count($this->countBy($filtered, fn (array $row) => self::normalizeValue($row['answers'][(string) ($quota['question'] ?? '')] ?? null))),
            default => count($filtered),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $answers
     */
    private function countAnswer(array $rows, string $question, array $answers): int
    {
        $value = self::normalizeValue($answers[$question] ?? null);
        if ($value === null || $value === '') {
            return 0;
        }

        return count(array_filter($rows, fn (array $row) => self::normalizeValue($row['answers'][$question] ?? null) === $value));
    }

    /**
     * Soumissions comptées dans les quotas : non rejetées et non hors-cible (README § 12).
     *
     * @return list<array<string, mixed>>
     */
    private function validRows(int $surveyId): array
    {
        return DB::table('submissions')
            ->where('survey_id', $surveyId)
            ->whereNotIn('status', [SubmissionStatus::Rejected->value, SubmissionStatus::ScreenedOut->value])
            ->get(['id', 'zone', 'enumerator_id', 'status', 'language', 'answers'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'zone' => $row->zone,
                'enumerator_id' => $row->enumerator_id === null ? null : (int) $row->enumerator_id,
                'status' => 'completed',
                'language' => $row->language,
                'answers' => is_string($row->answers) ? (json_decode($row->answers, true) ?: []) : (array) $row->answers,
            ])
            ->all();
    }

    // ------------------------------------------------------------------ utilitaires

    /** @return array<string, mixed> */
    private function settingsOf(Submission $submission): array
    {
        $version = $submission->relationLoaded('version')
            ? $submission->version
            : SurveyVersion::query()->find($submission->survey_version_id);

        return $version instanceof SurveyVersion ? $version->settings() : [];
    }

    /**
     * Instant de début dans le fuseau *déclaré par l'appareil* : `started_at` brut du payload,
     * à défaut `geo.start.ts` / `geo.end.ts`, à défaut la colonne (UTC).
     */
    private function localStart(Submission $submission, ?string $localStartIso = null): Carbon
    {
        $candidates = [
            $localStartIso,
            is_array($submission->geo) ? ($submission->geo['start']['ts'] ?? null) : null,
            is_array($submission->geo) ? ($submission->geo['end']['ts'] ?? null) : null,
        ];

        foreach ($candidates as $raw) {
            if (is_string($raw) && $raw !== '') {
                try {
                    return Carbon::parse($raw);
                } catch (\Throwable) {
                    // ignoré : on essaie le candidat suivant
                }
            }
        }

        return $submission->started_at ?? now();
    }

    /** @return array{lat: float, lng: float}|null */
    private function firstPoint(Submission $submission): ?array
    {
        if ($submission->geo_lat !== null && $submission->geo_lng !== null) {
            return ['lat' => (float) $submission->geo_lat, 'lng' => (float) $submission->geo_lng];
        }
        foreach (['start', 'end'] as $slot) {
            $point = is_array($submission->geo) ? ($submission->geo[$slot] ?? null) : null;
            if (is_array($point) && isset($point['lat'], $point['lng'])) {
                return ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }

        return null;
    }

    /**
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null
     */
    public static function normalizeBbox(mixed $bbox): ?array
    {
        if (is_array($bbox) && isset($bbox['bbox'])) {
            $bbox = $bbox['bbox'];
        }
        if (is_array($bbox) && array_is_list($bbox) && count($bbox) === 4) {
            [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', $bbox);

            return ['min_lat' => $minLat, 'max_lat' => $maxLat, 'min_lng' => $minLng, 'max_lng' => $maxLng];
        }
        if (is_array($bbox) && isset($bbox['min_lat'], $bbox['max_lat'], $bbox['min_lng'], $bbox['max_lng'])) {
            return [
                'min_lat' => (float) $bbox['min_lat'],
                'max_lat' => (float) $bbox['max_lat'],
                'min_lng' => (float) $bbox['min_lng'],
                'max_lng' => (float) $bbox['max_lng'],
            ];
        }

        return null;
    }

    /**
     * Normalisation d'une valeur de réponse pour le regroupement (README § 12 : trim, minuscules,
     * sans diacritiques) ; les listes sont jointes par `;`.
     */
    public static function normalizeValue(mixed $value): ?string
    {
        if (LogicEvaluator::isEmpty($value)) {
            return null;
        }
        if (is_array($value)) {
            $value = implode(';', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value));
        }
        $text = LogicEvaluator::toStr($value);
        $decomposed = class_exists(\Normalizer::class)
            ? (\Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text)
            : $text;
        $ascii = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $ascii) ?? $ascii));
    }

    /** @param array<string, mixed> $quota */
    private function labelOf(array $quota, string $defaultLanguage): string
    {
        $label = $quota['label'] ?? null;
        if (is_string($label)) {
            return $label;
        }
        if (is_array($label)) {
            foreach ([$defaultLanguage, 'fr', 'en'] as $lang) {
                if (is_string($label[$lang] ?? null) && $label[$lang] !== '') {
                    return $label[$lang];
                }
            }
            foreach ($label as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return (string) ($quota['key'] ?? '');
    }
}
