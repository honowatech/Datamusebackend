<?php

namespace App\Services\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\SubmissionStatus;
use App\Models\EnumeratorAssignment;
use App\Models\FollowUpEntry;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\VerbatimCoding;
use App\Services\Dfs\LabelResolver;
use App\Services\Dfs\LogicEvaluator;
use App\Services\Dfs\QuestionCatalog;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Agrégats du dashboard résultats et de la supervision (B-10, contrat : tag Statistiques).
 *
 * Tout est calculé **en mémoire** à partir des colonnes `submissions` (jamais de requête JSON-path :
 * portabilité SQLite / MySQL) ; seul le tableau croisé interroge le SQLite matérialisé. Chaque
 * réponse est mise en cache `CACHE_TTL` secondes par questionnaire, endpoint et jeu de filtres
 * (`meta.cached_at` porte l'instant du calcul).
 *
 * Ensembles de référence (README DFS § 13) :
 *   `all`   = soumissions non `rejected` (hors cible incluses) ;
 *   `valid` = `all` sans les `screened_out`.
 * Le contexte d'évaluation d'une soumission est constitué de ses réponses de base **fusionnées** avec
 * celles de ses étapes de suivi complétées, plus les variables système `_status`, `_zone`,
 * `_enumerator`, `_lang`, `_device`, `_start_time`, `_end_time`.
 */
class SurveyStatsService
{
    /** Durée du cache des agrégats (contrat : 20 s). */
    public const CACHE_TTL = 20;

    /** Plafond de points renvoyés par `stats/geo` (au-delà : échantillonnage régulier). */
    public const GEO_MAX_POINTS = 5000;

    /** Nombre de classes d'un histogramme numérique. */
    public const HISTOGRAM_BINS = 10;

    /** Fuseau par défaut de la chronologie (terrain MunaGo). */
    public const DEFAULT_TZ = 'Africa/Douala';

    /** Types de question exclus des distributions par défaut. */
    public const NON_ANALYZABLE = ['note', 'stop', 'photo', 'signature', 'audio'];

    public function __construct(
        private readonly SubmissionQualityService $quality = new SubmissionQualityService,
        private readonly LogicEvaluator $logic = new LogicEvaluator,
    ) {}

    // ------------------------------------------------------------------ cache

    /**
     * Mémorise le résultat d'un agrégat pendant `CACHE_TTL` secondes.
     *
     * Un résultat `null` (tableau croisé sur une datasource pas encore prête) n'est **jamais** mis en
     * cache : la reconstruction doit être visible dès qu'elle est terminée.
     *
     * @param  array<string, mixed>  $params
     * @return array{data: mixed, cached_at: string}
     */
    public function remember(Survey $survey, string $endpoint, array $params, Closure $compute): array
    {
        ksort($params);
        $key = sprintf('b10:stats:%d:%s:%s', $survey->id, $endpoint, md5((string) json_encode($params)));

        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('data', $cached)) {
            return $cached;
        }

        $payload = ['data' => $compute(), 'cached_at' => now()->toIso8601String()];
        if ($payload['data'] !== null) {
            Cache::put($key, $payload, self::CACHE_TTL);
        }

        return $payload;
    }

    // ------------------------------------------------------------------ jeu de données

    /**
     * Filtres normalisés communs à tous les agrégats.
     *
     * @param  array<string, mixed>  $input
     * @return array{from: ?string, to: ?string, enumerator_id: ?int, zone: ?string, status: ?string}
     */
    public static function filters(array $input): array
    {
        $status = $input['status'] ?? null;

        return [
            'from' => is_string($input['from'] ?? null) && $input['from'] !== '' ? (string) $input['from'] : null,
            'to' => is_string($input['to'] ?? null) && $input['to'] !== '' ? (string) $input['to'] : null,
            'enumerator_id' => isset($input['enumerator_id']) && (int) $input['enumerator_id'] > 0 ? (int) $input['enumerator_id'] : null,
            'zone' => is_string($input['zone'] ?? null) && $input['zone'] !== '' ? (string) $input['zone'] : null,
            'status' => is_string($status) && in_array($status, SubmissionStatus::values(), true) ? $status : null,
        ];
    }

    /**
     * Charge les soumissions du questionnaire correspondant aux filtres, réponses de suivi fusionnées.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rows(Survey $survey, array $filters): array
    {
        $query = Submission::query()->where('survey_id', $survey->id);

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['enumerator_id'] !== null) {
            $query->where('enumerator_id', $filters['enumerator_id']);
        }
        if ($filters['zone'] !== null) {
            $query->where('zone', $filters['zone']);
        }
        if ($filters['from'] !== null) {
            $query->where('ended_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if ($filters['to'] !== null) {
            $query->where('ended_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $submissions = $query->orderBy('id')->get([
            'id', 'uuid', 'survey_version_id', 'status', 'channel', 'fiche_code', 'zone', 'language',
            'enumerator_id', 'started_at', 'ended_at', 'duration_seconds', 'received_at',
            'geo_lat', 'geo_lng', 'geo_accuracy', 'flags', 'suspicion_score', 'end_reason', 'answers',
        ]);

        $stageAnswers = $this->completedStageAnswers($submissions->pluck('id')->all());
        $versions = SurveyVersion::query()->where('survey_id', $survey->id)->pluck('version', 'id')->map(fn ($v) => (int) $v)->all();

        $rows = [];
        foreach ($submissions as $submission) {
            $answers = is_array($submission->answers) ? $submission->answers : [];
            $status = $submission->status?->value ?? SubmissionStatus::Submitted->value;
            $rows[] = [
                'id' => (int) $submission->id,
                'uuid' => $submission->uuid,
                'version' => $versions[$submission->survey_version_id] ?? null,
                'status' => $status,
                // `_status` du DFS : le serveur expose `submitted` / `validated` comme `completed`.
                'logic_status' => $status === SubmissionStatus::ScreenedOut->value ? 'screened_out' : 'completed',
                'channel' => $submission->channel?->value,
                'fiche_code' => $submission->fiche_code,
                'zone' => $submission->zone,
                'language' => $submission->language,
                'enumerator_id' => $submission->enumerator_id === null ? null : (int) $submission->enumerator_id,
                'started_at' => $submission->started_at,
                'ended_at' => $submission->ended_at,
                'duration' => $submission->duration_seconds === null ? null : (int) $submission->duration_seconds,
                'received_at' => $submission->received_at,
                'geo_lat' => $submission->geo_lat === null ? null : (float) $submission->geo_lat,
                'geo_lng' => $submission->geo_lng === null ? null : (float) $submission->geo_lng,
                'geo_accuracy' => $submission->geo_accuracy === null ? null : (float) $submission->geo_accuracy,
                'flags' => array_values(is_array($submission->flags) ? $submission->flags : []),
                'suspicion' => (int) ($submission->suspicion_score ?? 0),
                'end_reason' => $submission->end_reason,
                'answers' => $answers,
                'context' => $answers + ($stageAnswers[$submission->id] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * Réponses des étapes de suivi **complétées**, fusionnées par soumission (clés globales, § 14).
     *
     * @param  list<int>  $submissionIds
     * @return array<int, array<string, mixed>>
     */
    private function completedStageAnswers(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }

        $out = [];
        FollowUpEntry::query()
            ->whereIn('submission_id', $submissionIds)
            ->where('status', FollowUpStatus::Done->value)
            ->get(['submission_id', 'answers'])
            ->each(function (FollowUpEntry $entry) use (&$out): void {
                $answers = is_array($entry->answers) ? $entry->answers : [];
                $out[(int) $entry->submission_id] = ($out[(int) $entry->submission_id] ?? []) + $answers;
            });

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function notRejected(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $r) => $r['status'] !== SubmissionStatus::Rejected->value));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function valid(array $rows): array
    {
        return array_values(array_filter(
            self::notRejected($rows),
            fn (array $r) => $r['status'] !== SubmissionStatus::ScreenedOut->value,
        ));
    }

    // ------------------------------------------------------------------ overview

    /**
     * Schéma `StatsOverview`.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function overview(Survey $survey, array $filters): array
    {
        $rows = $this->rows($survey, $filters);
        $settings = $this->settings($survey);

        $all = self::notRejected($rows);
        $valid = self::valid($rows);

        $byStatus = [];
        $byChannel = [];
        $byVersion = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
            if ($row['status'] === SubmissionStatus::Rejected->value) {
                continue;
            }
            $channel = (string) ($row['channel'] ?? 'mobile');
            $byChannel[$channel] = ($byChannel[$channel] ?? 0) + 1;
            if ($row['version'] !== null) {
                $byVersion[(string) $row['version']] = ($byVersion[(string) $row['version']] ?? 0) + 1;
            }
        }

        $durations = array_values(array_filter(
            array_map(fn (array $r) => $r['duration'], $all),
            fn ($d) => $d !== null,
        ));

        $sevenDaysAgo = now()->subDays(7);
        $activeEnumerators = [];
        foreach ($all as $row) {
            if ($row['enumerator_id'] !== null && $row['ended_at'] !== null && $row['ended_at']->greaterThanOrEqualTo($sevenDaysAgo)) {
                $activeEnumerators[$row['enumerator_id']] = true;
            }
        }

        $endedAt = array_values(array_filter(array_map(fn (array $r) => $r['ended_at'], $all)));

        return [
            'totals' => [
                'all' => count($all),
                'valid' => count($valid),
                'screened_out' => count(array_filter($rows, fn (array $r) => $r['status'] === SubmissionStatus::ScreenedOut->value)),
                'rejected' => $byStatus[SubmissionStatus::Rejected->value] ?? 0,
                'validated' => $byStatus[SubmissionStatus::Validated->value] ?? 0,
                'flagged' => count(array_filter($all, fn (array $r) => $r['flags'] !== [])),
                'enumerators_active' => count($activeEnumerators),
                'follow_ups_pending' => FollowUpEntry::query()
                    ->where('survey_id', $survey->id)
                    ->where('status', FollowUpStatus::Pending->value)
                    ->count(),
                'media_pending' => DB::table('submission_media')
                    ->join('submissions', 'submissions.id', '=', 'submission_media.submission_id')
                    ->where('submissions.survey_id', $survey->id)
                    ->where('submission_media.state', '!=', 'uploaded')
                    ->count(),
            ],
            'by_status' => $byStatus,
            'by_channel' => $byChannel,
            'by_version' => $byVersion,
            'duration' => [
                'mean_seconds' => $durations === [] ? null : round(array_sum($durations) / count($durations), 1),
                'median_seconds' => self::percentile($durations, 0.5),
                'p25_seconds' => self::percentile($durations, 0.25),
                'p75_seconds' => self::percentile($durations, 0.75),
                'min_seconds' => $durations === [] ? null : (int) min($durations),
                'max_seconds' => $durations === [] ? null : (int) max($durations),
            ],
            'end_reasons' => $this->endReasons($survey, $rows),
            'quotas' => $this->quality->quotaProgress($survey, $settings),
            'kpis' => $this->kpis($settings, $rows),
            'first_submission_at' => $endedAt === [] ? null : min($endedAt)->toIso8601String(),
            'last_submission_at' => $endedAt === [] ? null : max($endedAt)->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Répartition des `stop` déclencheurs (libellé du `message` de l'item `stop`).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, count: int}>
     */
    private function endReasons(Survey $survey, array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $reason = $row['end_reason'];
            if (is_string($reason) && $reason !== '') {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return [];
        }
        arsort($counts);

        $catalog = $this->catalog($survey);
        $lang = $catalog?->defaultLanguage() ?? 'fr';
        $out = [];
        foreach ($counts as $key => $count) {
            $def = $catalog?->node((string) $key)['def'] ?? null;
            $label = is_array($def)
                ? LabelResolver::resolve($def['label'] ?? $def['message'] ?? (string) $key, $lang, $lang, (string) $key)
                : (string) $key;
            $out[] = ['key' => (string) $key, 'label' => $label, 'count' => $count];
        }

        return $out;
    }

    /**
     * KPI `settings.kpis` (README § 13).
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function kpis(array $settings, array $rows): array
    {
        $all = self::notRejected($rows);
        $valid = self::valid($rows);
        $language = (string) ($settings['default_language'] ?? 'fr');
        $out = [];

        foreach ((array) ($settings['kpis'] ?? []) as $kpi) {
            if (! is_array($kpi) || ! is_string($kpi['key'] ?? null)) {
                continue;
            }
            $denominatorSpec = $kpi['denominator'] ?? 'valid';
            $denominator = match (true) {
                $denominatorSpec === 'all' => $all,
                $denominatorSpec === 'valid', $denominatorSpec === null => $valid,
                default => array_values(array_filter($all, fn (array $r) => $this->truthy($denominatorSpec, $r))),
            };
            $numerator = array_values(array_filter(
                $denominator,
                fn (array $r) => $this->truthy($kpi['numerator'] ?? null, $r),
            ));

            $count = count($denominator);
            $out[] = [
                'key' => (string) $kpi['key'],
                'label' => self::label($kpi['label'] ?? null, $language, (string) $kpi['key']),
                'value' => $count === 0 ? null : round(count($numerator) / $count, 4),
                'numerator' => count($numerator),
                'denominator' => $count,
                'format' => is_string($kpi['format'] ?? null) ? $kpi['format'] : 'percent',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function truthy(mixed $expr, array $row): bool
    {
        if ($expr === null) {
            return false;
        }

        return LogicEvaluator::truthy($this->logic->evaluate($expr, $row['context'], self::systemVars($row))->value);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function systemVars(array $row): array
    {
        return [
            '_status' => $row['logic_status'],
            '_zone' => $row['zone'],
            '_enumerator' => $row['enumerator_id'],
            '_lang' => $row['language'],
            '_start_time' => $row['started_at']?->toIso8601String(),
            '_end_time' => $row['ended_at']?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ questions

    /**
     * Schéma `QuestionStats` (`oneOf` selon `kind`).
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $keys  vide → toutes les questions analysables (hors note/stop/média/pii)
     * @return list<array<string, mixed>>
     */
    public function questions(Survey $survey, array $filters, array $keys = [], ?string $lang = null, int $top = 20): array
    {
        $catalog = $this->catalog($survey);
        if ($catalog === null) {
            return [];
        }
        $default = $catalog->defaultLanguage();
        $lang ??= $default;
        $rows = self::notRejected($this->rows($survey, $filters));

        $selected = $keys !== []
            ? array_values(array_filter($keys, fn (string $k) => $catalog->has($k)))
            : $this->analyzableKeys($catalog);

        $out = [];
        foreach ($selected as $key) {
            $node = $catalog->node($key);
            $info = $catalog->get($key);
            if ($node === null || $info === null) {
                continue;
            }
            $out[] = $this->questionStats($survey, $catalog, $key, $node, $info, $rows, $lang, $default, $top);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function analyzableKeys(QuestionCatalog $catalog): array
    {
        $keys = [];
        foreach ($catalog->nodes() as $node) {
            if (($node['kind'] ?? null) !== 'question') {
                continue;
            }
            $def = is_array($node['def'] ?? null) ? $node['def'] : [];
            if (in_array((string) $node['type'], self::NON_ANALYZABLE, true) || ReponsesLayout::isPii($def)) {
                continue;
            }
            $keys[] = (string) $node['key'];
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $info
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function questionStats(Survey $survey, QuestionCatalog $catalog, string $key, array $node, array $info, array $rows, string $lang, string $default, int $top): array
    {
        $def = is_array($node['def'] ?? null) ? $node['def'] : [];
        $type = (string) $node['type'];
        $relevant = $def['relevant'] ?? null;

        $asked = 0;
        $answered = [];
        foreach ($rows as $row) {
            if ($relevant !== null && ! $this->truthy($relevant, $row)) {
                continue;
            }
            $asked++;
            $value = $row['context'][$key] ?? null;
            if (! LogicEvaluator::isEmpty($value)) {
                $answered[] = ['row' => $row, 'value' => $value];
            }
        }

        $base = [
            'key' => $key,
            'type' => $type,
            'label' => LabelResolver::resolve($def['label'] ?? $key, $lang, $default, $key),
            'section' => $node['section'] ?? $node['stage'] ?? null,
            'n' => count($answered),
            'asked' => $asked,
        ];

        return match (true) {
            in_array($type, ['select_one', 'select_multiple', 'rank'], true) => $base + $this->choiceStats($catalog, $key, $type, $info, $answered, $lang, $default, $top),
            in_array($type, ['integer', 'decimal', 'currency'], true) => $base + $this->numericStats($answered, $def),
            $type === 'calculate' => $base + $this->calculateStats($answered, $def, $top),
            in_array($type, ['date', 'datetime'], true) => $base + $this->dateStats($answered),
            $type === 'geopoint' => $base + $this->geoQuestionStats($answered),
            in_array($type, ['photo', 'signature', 'audio'], true) => $base + $this->mediaStats($survey, $key),
            default => $base + $this->textStats($survey, $key, $def, $answered, $top),
        };
    }

    /**
     * @param  array<string, mixed>  $info
     * @param  list<array{row: array<string, mixed>, value: mixed}>  $answered
     * @return array<string, mixed>
     */
    private function choiceStats(QuestionCatalog $catalog, string $key, string $type, array $info, array $answered, string $lang, string $default, int $top): array
    {
        $labels = [];
        foreach ((array) ($info['choices'] ?? []) as $choice) {
            if (is_array($choice) && isset($choice['name'])) {
                $labels[(string) $choice['name']] = LabelResolver::resolve($choice['label'] ?? $choice['name'], $lang, $default, (string) $choice['name']);
            }
        }

        $counts = array_fill_keys(array_keys($labels), 0);
        $selectedTotal = 0;
        $rankPositions = [];

        foreach ($answered as $item) {
            $codes = is_array($item['value']) ? array_values($item['value']) : [$item['value']];
            $selectedTotal += count($codes);
            foreach ($codes as $position => $code) {
                $code = LogicEvaluator::toStr($code);
                if ($code === '') {
                    continue;
                }
                $counts[$code] = ($counts[$code] ?? 0) + 1;
                if ($type === 'rank') {
                    $rankPositions[$code][] = $position + 1;
                }
            }
        }

        $n = count($answered);
        $distribution = [];
        foreach ($counts as $code => $count) {
            $distribution[] = [
                'code' => (string) $code,
                'label' => $labels[$code] ?? (string) $code,
                'count' => $count,
                'pct' => $n === 0 ? 0.0 : round($count / $n * 100, 1),
            ];
        }
        usort($distribution, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        $otherKey = $catalog->otherKeyOf($key);
        $otherSamples = [];
        $otherCount = 0;
        if ($otherKey !== null) {
            foreach ($answered as $item) {
                $value = $item['row']['context'][$otherKey] ?? null;
                if (! LogicEvaluator::isEmpty($value)) {
                    $otherCount++;
                    if (count($otherSamples) < min(20, $top)) {
                        $otherSamples[] = LogicEvaluator::toStr($value);
                    }
                }
            }
        }

        $stats = [
            'kind' => 'choice',
            'distribution' => $distribution,
            'other_count' => $otherCount,
            'other_samples' => $otherSamples,
            'multiple' => $type === 'select_multiple',
            'mean_selected' => $n === 0 ? null : round($selectedTotal / $n, 2),
        ];

        if ($type === 'rank') {
            $means = [];
            foreach ($rankPositions as $code => $positions) {
                $means[(string) $code] = round(array_sum($positions) / count($positions), 2);
            }
            $stats['rank_mean_position'] = $means;
        }

        return $stats;
    }

    /**
     * @param  list<array{row: array<string, mixed>, value: mixed}>  $answered
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function numericStats(array $answered, array $def): array
    {
        $values = [];
        foreach ($answered as $item) {
            $number = LogicEvaluator::toNumber($item['value']);
            if ($number !== null) {
                $values[] = (float) $number;
            }
        }
        sort($values);

        return [
            'kind' => 'numeric',
            'mean' => $values === [] ? null : round(array_sum($values) / count($values), 3),
            'median' => self::percentile($values, 0.5),
            'p25' => self::percentile($values, 0.25),
            'p75' => self::percentile($values, 0.75),
            'min' => $values === [] ? null : $values[0],
            'max' => $values === [] ? null : $values[count($values) - 1],
            'sum' => $values === [] ? null : array_sum($values),
            'currency' => is_string($def['currency'] ?? null) ? $def['currency'] : (($def['type'] ?? null) === 'currency' ? 'XAF' : null),
            'histogram' => self::histogram($values),
        ];
    }

    /**
     * Un `calculate` peut produire un nombre (→ `numeric`) ou une chaîne (→ `text`).
     *
     * @param  list<array{row: array<string, mixed>, value: mixed}>  $answered
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    private function calculateStats(array $answered, array $def, int $top): array
    {
        $numeric = $answered !== [];
        foreach ($answered as $item) {
            if (LogicEvaluator::toNumber($item['value']) === null) {
                $numeric = false;
                break;
            }
        }

        if ($numeric) {
            return $this->numericStats($answered, $def);
        }

        $counts = [];
        foreach ($answered as $item) {
            $value = LogicEvaluator::toStr($item['value']);
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);
        $counts = array_slice($counts, 0, $top, true);
        $n = count($answered);

        $distribution = [];
        foreach ($counts as $value => $count) {
            $distribution[] = [
                'code' => (string) $value,
                'label' => (string) $value,
                'count' => $count,
                'pct' => $n === 0 ? 0.0 : round($count / $n * 100, 1),
            ];
        }

        return ['kind' => 'choice', 'distribution' => $distribution, 'other_count' => 0, 'other_samples' => [], 'multiple' => false, 'mean_selected' => null];
    }

    /**
     * @param  list<array{row: array<string, mixed>, value: mixed}>  $answered
     * @return array<string, mixed>
     */
    private function dateStats(array $answered): array
    {
        $byDay = [];
        $dates = [];
        foreach ($answered as $item) {
            $value = LogicEvaluator::toStr($item['value']);
            $day = substr($value, 0, 10);
            if ($day === '') {
                continue;
            }
            $dates[] = $value;
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }
        ksort($byDay);

        return [
            'kind' => 'date',
            'min' => $dates === [] ? null : min($dates),
            'max' => $dates === [] ? null : max($dates),
            'by_day' => array_map(fn ($date, $count) => ['date' => (string) $date, 'count' => $count], array_keys($byDay), array_values($byDay)),
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     * @param  list<array{row: array<string, mixed>, value: mixed}>  $answered
     * @return array<string, mixed>
     */
    private function textStats(Survey $survey, string $key, array $def, array $answered, int $top): array
    {
        $pii = ReponsesLayout::isPii($def);
        $latest = [];
        $lengths = [];

        foreach (array_reverse($answered) as $item) {
            $value = LogicEvaluator::toStr($item['value']);
            $lengths[] = mb_strlen($value);
            if ($pii || count($latest) >= $top) {
                continue;
            }
            $latest[] = [
                'submission_id' => $item['row']['id'],
                'fiche_code' => $item['row']['fiche_code'],
                'value' => $value,
                'ended_at' => $item['row']['ended_at']?->toIso8601String(),
            ];
        }

        return [
            'kind' => 'text',
            'latest' => $latest,
            'themes' => $this->themeDistribution($survey, $key, count($answered)),
            'mean_length' => $lengths === [] ? null : round(array_sum($lengths) / count($lengths), 1),
        ];
    }

    /**
     * Distribution des thèmes du dernier livre de codes (B-11 alimente `verbatim_codings`).
     *
     * @return list<array{code: string, label: string, count: int, pct: float}>
     */
    private function themeDistribution(Survey $survey, string $key, int $n): array
    {
        $codings = VerbatimCoding::query()
            ->where('survey_id', $survey->id)
            ->where('question_key', $key)
            ->orderByDesc('codebook_id')
            ->get(['themes']);

        if ($codings->isEmpty()) {
            return [];
        }

        $counts = [];
        foreach ($codings as $coding) {
            foreach ((array) $coding->themes as $theme) {
                $code = is_array($theme) ? (string) ($theme['key'] ?? '') : (is_scalar($theme) ? (string) $theme : '');
                if ($code === '') {
                    continue;
                }
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        arsort($counts);

        $out = [];
        foreach ($counts as $code => $count) {
            $out[] = [
                'code' => (string) $code,
                'label' => (string) $code,
                'count' => $count,
                'pct' => $n === 0 ? 0.0 : round($count / $n * 100, 1),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{row: array<string, mixed>, value: mixed}>  $answered
     * @return array<string, mixed>
     */
    private function geoQuestionStats(array $answered): array
    {
        $accuracies = [];
        foreach ($answered as $item) {
            $value = $item['value'];
            if (is_array($value) && is_numeric($value['accuracy'] ?? null)) {
                $accuracies[] = (float) $value['accuracy'];
            }
        }

        return [
            'kind' => 'geopoint',
            'uploaded' => count($answered),
            'pending' => 0,
            'mean_accuracy_m' => $accuracies === [] ? null : round(array_sum($accuracies) / count($accuracies), 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaStats(Survey $survey, string $key): array
    {
        $counts = DB::table('submission_media')
            ->join('submissions', 'submissions.id', '=', 'submission_media.submission_id')
            ->where('submissions.survey_id', $survey->id)
            ->where('submission_media.question_key', $key)
            ->selectRaw('submission_media.state as state, COUNT(*) as total')
            ->groupBy('submission_media.state')
            ->pluck('total', 'state');

        $uploaded = (int) ($counts['uploaded'] ?? 0);

        return [
            'kind' => 'media',
            'uploaded' => $uploaded,
            'pending' => (int) $counts->sum() - $uploaded,
            'mean_accuracy_m' => null,
        ];
    }

    // ------------------------------------------------------------------ timeline / enquêteurs / zones

    /**
     * Schéma `Timeline`.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function timeline(Survey $survey, array $filters, string $by = 'day', string $tz = self::DEFAULT_TZ): array
    {
        $by = $by === 'hour' ? 'hour' : 'day';
        $rows = self::notRejected($this->rows($survey, $filters));

        $points = [];
        foreach ($rows as $row) {
            $moment = $row['ended_at'] ?? $row['received_at'];
            if ($moment === null) {
                continue;
            }
            $local = $moment->copy()->setTimezone($tz);
            $bucket = $by === 'hour' ? $local->format('Y-m-d\TH:00') : $local->format('Y-m-d');

            $points[$bucket] ??= ['t' => $bucket, 'total' => 0, 'by_status' => [], 'by_enumerator' => []];
            $points[$bucket]['total']++;
            $points[$bucket]['by_status'][$row['status']] = ($points[$bucket]['by_status'][$row['status']] ?? 0) + 1;
            if ($row['enumerator_id'] !== null) {
                $id = (string) $row['enumerator_id'];
                $points[$bucket]['by_enumerator'][$id] = ($points[$bucket]['by_enumerator'][$id] ?? 0) + 1;
            }
        }
        ksort($points);

        return ['by' => $by, 'tz' => $tz, 'points' => array_values($points)];
    }

    /**
     * Schéma `EnumeratorStats` : une ligne par enquêteur affecté, même sans soumission.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function enumerators(Survey $survey, array $filters): array
    {
        $rows = self::notRejected($this->rows($survey, $filters));

        $assignments = EnumeratorAssignment::query()
            ->where('survey_id', $survey->id)
            ->with('user:id,name')
            ->get();

        $byUser = [];
        foreach ($assignments as $assignment) {
            $byUser[(int) $assignment->user_id] = [
                'user' => ['id' => (int) $assignment->user_id, 'name' => (string) ($assignment->user?->name ?? '')],
                'zone' => $assignment->zone,
                'quota_target' => $assignment->quota_target === null ? null : (int) $assignment->quota_target,
                'rows' => [],
            ];
        }
        foreach ($rows as $row) {
            $id = $row['enumerator_id'];
            if ($id === null) {
                continue;
            }
            $byUser[$id] ??= ['user' => ['id' => $id, 'name' => ''], 'zone' => $row['zone'], 'quota_target' => null, 'rows' => []];
            $byUser[$id]['rows'][] = $row;
        }

        $missingNames = array_keys(array_filter($byUser, fn (array $u) => $u['user']['name'] === ''));
        if ($missingNames !== []) {
            $names = DB::table('users')->whereIn('id', $missingNames)->pluck('name', 'id');
            foreach ($missingNames as $id) {
                $byUser[$id]['user']['name'] = (string) ($names[$id] ?? '');
            }
        }

        $followUps = DB::table('follow_up_entries')
            ->where('survey_id', $survey->id)
            ->selectRaw('enumerator_id, status, COUNT(*) as total')
            ->groupBy('enumerator_id', 'status')
            ->get();

        $out = [];
        foreach ($byUser as $id => $entry) {
            $userRows = $entry['rows'];
            $durations = array_values(array_filter(array_map(fn (array $r) => $r['duration'], $userRows), fn ($d) => $d !== null));
            $scores = array_map(fn (array $r) => $r['suspicion'], $userRows);
            $endedAt = array_values(array_filter(array_map(fn (array $r) => $r['ended_at'], $userRows)));

            $flagsByType = [];
            foreach ($userRows as $row) {
                foreach ($row['flags'] as $flag) {
                    $flagsByType[$flag] = ($flagsByType[$flag] ?? 0) + 1;
                }
            }

            $out[] = [
                'user' => $entry['user'],
                'zone' => $entry['zone'],
                'quota_target' => $entry['quota_target'],
                'total' => count($userRows),
                'valid' => count(self::valid($userRows)),
                'screened_out' => count(array_filter($userRows, fn (array $r) => $r['status'] === SubmissionStatus::ScreenedOut->value)),
                'validated' => count(array_filter($userRows, fn (array $r) => $r['status'] === SubmissionStatus::Validated->value)),
                'rejected' => 0,
                'flagged' => count(array_filter($userRows, fn (array $r) => $r['flags'] !== [])),
                'flags_by_type' => $flagsByType,
                'mean_duration_seconds' => $durations === [] ? null : round(array_sum($durations) / count($durations), 1),
                'mean_suspicion_score' => $scores === [] ? null : round(array_sum($scores) / count($scores), 1),
                'first_submission_at' => $endedAt === [] ? null : min($endedAt)->toIso8601String(),
                'last_submission_at' => $endedAt === [] ? null : max($endedAt)->toIso8601String(),
                'sparkline_7d' => self::sparkline($userRows),
                'follow_ups_pending' => (int) ($followUps->firstWhere(fn ($f) => (int) $f->enumerator_id === $id && $f->status === FollowUpStatus::Pending->value)?->total ?? 0),
                'follow_ups_missed' => (int) ($followUps->firstWhere(fn ($f) => (int) $f->enumerator_id === $id && $f->status === FollowUpStatus::Missed->value)?->total ?? 0),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['total'] <=> $a['total'] ?: strcmp((string) $a['user']['name'], (string) $b['user']['name']));

        return $out;
    }

    /**
     * Soumissions par jour de J-6 à aujourd'hui.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private static function sparkline(array $rows): array
    {
        $buckets = [];
        for ($i = 6; $i >= 0; $i--) {
            $buckets[now()->subDays($i)->format('Y-m-d')] = 0;
        }
        foreach ($rows as $row) {
            $day = $row['ended_at']?->format('Y-m-d');
            if ($day !== null && array_key_exists($day, $buckets)) {
                $buckets[$day]++;
            }
        }

        return array_values($buckets);
    }

    /**
     * Schéma `ZoneStats`.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function zones(Survey $survey, array $filters): array
    {
        $rows = self::notRejected($this->rows($survey, $filters));
        $settings = $this->settings($survey);

        $quotaByZone = [];
        foreach ($this->quality->quotaProgress($survey, $settings) as $quota) {
            if (($quota['scope'] ?? null) !== 'zone') {
                continue;
            }
            foreach ($quota['breakdown'] ?? [] as $line) {
                $quotaByZone[(string) $line['value']] = [
                    'key' => $quota['key'],
                    'label' => $quota['label'],
                    'scope' => 'zone',
                    'target' => (int) ($line['target'] ?? $quota['target']),
                    'current' => (int) $line['current'],
                    'pct' => ($line['target'] ?? $quota['target']) > 0 ? round($line['current'] / (int) ($line['target'] ?? $quota['target']) * 100, 1) : 0.0,
                    'max' => (bool) $quota['max'],
                    'exceeded' => (bool) $quota['max'] && (int) $line['current'] > (int) ($line['target'] ?? $quota['target']),
                ];
            }
        }

        $byZone = [];
        foreach ($rows as $row) {
            $zone = $row['zone'];
            $byZone[$zone ?? ''] ??= [];
            $byZone[$zone ?? ''][] = $row;
        }

        $out = [];
        foreach ($byZone as $zone => $zoneRows) {
            $durations = array_values(array_filter(array_map(fn (array $r) => $r['duration'], $zoneRows), fn ($d) => $d !== null));
            $out[] = [
                'zone' => $zone === '' ? null : $zone,
                'total' => count($zoneRows),
                'valid' => count(self::valid($zoneRows)),
                'screened_out' => count(array_filter($zoneRows, fn (array $r) => $r['status'] === SubmissionStatus::ScreenedOut->value)),
                'flagged' => count(array_filter($zoneRows, fn (array $r) => $r['flags'] !== [])),
                'enumerators' => count(array_unique(array_filter(array_map(fn (array $r) => $r['enumerator_id'], $zoneRows)))),
                'quota' => $quotaByZone[$zone] ?? null,
                'mean_duration_seconds' => $durations === [] ? null : round(array_sum($durations) / count($durations), 1),
            ];
        }

        usort($out, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    /**
     * Schéma `GeoPoint[]` (≤ `GEO_MAX_POINTS`, échantillonnage régulier au-delà).
     *
     * @param  array<string, mixed>  $filters
     * @return array{points: list<array<string, mixed>>, sampled: bool, total: int}
     */
    public function geo(Survey $survey, array $filters, ?string $bbox = null): array
    {
        $box = self::parseBbox($bbox);
        $catalog = $this->catalog($survey);
        $geoKeys = [];
        if ($catalog !== null) {
            foreach ($catalog->all() as $key => $info) {
                if (($info['type'] ?? null) === 'geopoint') {
                    $geoKeys[] = (string) $key;
                }
            }
        }

        $points = [];
        foreach (self::notRejected($this->rows($survey, $filters)) as $row) {
            [$lat, $lng, $accuracy, $source] = self::pointOf($row, $geoKeys);
            if ($lat === null || $lng === null) {
                continue;
            }
            if ($box !== null && ($lng < $box[0] || $lat < $box[1] || $lng > $box[2] || $lat > $box[3])) {
                continue;
            }
            $points[] = [
                'submission_id' => $row['id'],
                'uuid' => $row['uuid'],
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
                'source' => $source,
                'status' => $row['status'],
                'enumerator_id' => $row['enumerator_id'],
                'zone' => $row['zone'],
                'fiche_code' => $row['fiche_code'],
                'ended_at' => $row['ended_at']?->toIso8601String(),
                'flags' => $row['flags'],
            ];
        }

        $total = count($points);
        if ($total <= self::GEO_MAX_POINTS) {
            return ['points' => $points, 'sampled' => false, 'total' => $total];
        }

        $step = (int) ceil($total / self::GEO_MAX_POINTS);
        $sampled = [];
        foreach ($points as $i => $point) {
            if ($i % $step === 0) {
                $sampled[] = $point;
            }
        }

        return ['points' => array_slice($sampled, 0, self::GEO_MAX_POINTS), 'sampled' => true, 'total' => $total];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $geoKeys
     * @return array{0: float|null, 1: float|null, 2: float|null, 3: string}
     */
    private static function pointOf(array $row, array $geoKeys): array
    {
        if ($row['geo_lat'] !== null && $row['geo_lng'] !== null) {
            return [$row['geo_lat'], $row['geo_lng'], $row['geo_accuracy'], 'auto_start'];
        }
        foreach ($geoKeys as $key) {
            $value = $row['context'][$key] ?? null;
            if (is_array($value) && is_numeric($value['lat'] ?? null) && is_numeric($value['lng'] ?? null)) {
                return [
                    (float) $value['lat'],
                    (float) $value['lng'],
                    is_numeric($value['accuracy'] ?? null) ? (float) $value['accuracy'] : null,
                    'question',
                ];
            }
        }

        return [null, null, null, 'auto_start'];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null `minLng, minLat, maxLng, maxLat`
     */
    private static function parseBbox(?string $bbox): ?array
    {
        if ($bbox === null || $bbox === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4) {
            return null;
        }
        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return null;
            }
        }

        return [(float) $parts[0], (float) $parts[1], (float) $parts[2], (float) $parts[3]];
    }

    // ------------------------------------------------------------------ utilitaires

    /**
     * @return array<string, mixed>
     */
    public function settings(Survey $survey): array
    {
        return $this->version($survey)?->settings() ?? [];
    }

    public function version(Survey $survey): ?SurveyVersion
    {
        return $survey->publishedVersion ?? $survey->versions()->orderByDesc('version')->first();
    }

    public function catalog(Survey $survey): ?QuestionCatalog
    {
        $version = $this->version($survey);

        return $version === null ? null : QuestionCatalog::fromDefinition($version->definition ?? []);
    }

    /**
     * @param  array<string, mixed>|string|null  $label
     */
    public static function label(mixed $label, string $lang, string $fallback): string
    {
        if (is_string($label) || is_array($label)) {
            return LabelResolver::resolve($label, $lang, $lang, $fallback);
        }

        return $fallback;
    }

    /**
     * Percentile linéaire d'une liste **triée** (null si vide).
     *
     * @param  list<int|float>  $values
     */
    public static function percentile(array $values, float $q): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $position = $q * (count($values) - 1);
        $low = (int) floor($position);
        $high = (int) ceil($position);

        if ($low === $high) {
            return round((float) $values[$low], 3);
        }

        return round((float) $values[$low] + ($position - $low) * ((float) $values[$high] - (float) $values[$low]), 3);
    }

    /**
     * @param  list<float>  $values
     * @return list<array{from: float, to: float, count: int}>
     */
    public static function histogram(array $values, int $bins = self::HISTOGRAM_BINS): array
    {
        if ($values === []) {
            return [];
        }
        $min = min($values);
        $max = max($values);
        if ($min === $max) {
            return [['from' => $min, 'to' => $max, 'count' => count($values)]];
        }

        $width = ($max - $min) / $bins;
        $out = [];
        for ($i = 0; $i < $bins; $i++) {
            $out[] = ['from' => round($min + $i * $width, 3), 'to' => round($min + ($i + 1) * $width, 3), 'count' => 0];
        }
        foreach ($values as $value) {
            $index = min($bins - 1, (int) floor(($value - $min) / $width));
            $out[$index]['count']++;
        }

        return $out;
    }
}
