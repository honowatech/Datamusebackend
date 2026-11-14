<?php

namespace App\Services\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\SubmissionStatus;
use App\Models\Survey;
use App\Services\Dfs\LabelResolver;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rétention par vague de suivi (`GET /surveys/{id}/analysis/cohorts`) — suivis J+4 / J+7 / J+14…
 *
 * Une cohorte regroupe les soumissions **entrées dans le suivi** (au moins une étape planifiée) pendant
 * la même période d'entretien (jour, semaine ou mois, d'après `ended_at`). Pour chaque étape :
 *
 *   - `planned`       entrées de suivi créées (l'étape peut ne pas concerner tout le monde : `relevant`) ;
 *   - `done` / `missed` / `skipped` / `pending` ;
 *   - `retention`     `done / base` : part de la cohorte encore suivie à cette étape ;
 *   - `response_rate` `done / (done + missed + skipped)` : taux de réponse parmi les étapes closes.
 *
 * Agrégats bornés et calculés en SQL + PHP sur la base principale : ils ne dépendent pas de la
 * matérialisation et restent disponibles quand le fichier SQLite n'est pas à jour.
 */
class AnalysisCohortService
{
    public const PERIODS = ['day', 'week', 'month'];

    public function __construct(private readonly SurveyStatsService $stats) {}

    /**
     * @param  array{from: ?string, to: ?string, enumerator_id: ?int, zone: ?string, status: ?string}  $filters
     * @return array<string, mixed>
     */
    public function cohorts(Survey $survey, array $filters, string $by = 'week', ?string $lang = null, string $tz = SurveyStatsService::DEFAULT_TZ): array
    {
        $stages = $this->stages($survey, $lang);
        $zone = new DateTimeZone($tz);

        $query = DB::table('follow_up_entries')
            ->join('submissions', 'submissions.id', '=', 'follow_up_entries.submission_id')
            ->where('follow_up_entries.survey_id', $survey->id);

        if ($filters['status'] !== null) {
            $query->where('submissions.status', $filters['status']);
        } else {
            $query->where('submissions.status', '!=', SubmissionStatus::Rejected->value);
        }
        if ($filters['enumerator_id'] !== null) {
            $query->where('submissions.enumerator_id', $filters['enumerator_id']);
        }
        if ($filters['zone'] !== null) {
            $query->where('submissions.zone', $filters['zone']);
        }
        if ($filters['from'] !== null) {
            $query->where('submissions.ended_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if ($filters['to'] !== null) {
            $query->where('submissions.ended_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $empty = ['planned' => 0, 'done' => 0, 'missed' => 0, 'skipped' => 0, 'pending' => 0];
        $overall = ['submissions' => [], 'stages' => []];
        $cohorts = [];

        $rows = $query->orderBy('follow_up_entries.id')->select([
            'follow_up_entries.submission_id', 'follow_up_entries.stage_key', 'follow_up_entries.status',
            'submissions.ended_at', 'submissions.received_at',
        ])->cursor();

        foreach ($rows as $row) {
            $stageKey = (string) $row->stage_key;
            $status = in_array($row->status, FollowUpStatus::values(), true) ? (string) $row->status : FollowUpStatus::Pending->value;
            if (! isset($stages[$stageKey])) {
                // Étape retirée du questionnaire publié : conservée, en fin de liste.
                $stages[$stageKey] = ['key' => $stageKey, 'label' => $stageKey, 'due_offset_days' => null, 'window_days' => null];
            }

            $enteredAt = $row->ended_at ?? $row->received_at;
            [$period, $start] = $enteredAt === null ? ['inconnue', null] : self::period(Carbon::parse($enteredAt, (string) config('app.timezone', 'UTC'))->setTimezone($zone), $by);

            $cohorts[$period] ??= ['period' => $period, 'start' => $start, 'submissions' => [], 'stages' => []];
            foreach ([&$overall, &$cohorts[$period]] as &$bucket) {
                $bucket['submissions'][(int) $row->submission_id] = true;
                $bucket['stages'][$stageKey] ??= $empty;
                $bucket['stages'][$stageKey]['planned']++;
                $bucket['stages'][$stageKey][$status]++;
            }
            unset($bucket);
        }

        ksort($cohorts);
        $stageList = array_values($stages);

        $format = function (array $bucket) use ($stageList, $empty): array {
            $base = count($bucket['submissions']);
            $out = [];
            foreach ($stageList as $stage) {
                $counts = $bucket['stages'][$stage['key']] ?? $empty;
                $closed = $counts['done'] + $counts['missed'] + $counts['skipped'];
                $out[] = ['key' => $stage['key']] + $counts + [
                    'retention' => $base > 0 ? round($counts['done'] / $base, 4) : null,
                    'response_rate' => $closed > 0 ? round($counts['done'] / $closed, 4) : null,
                ];
            }

            return ['base' => $base, 'stages' => $out];
        };

        return [
            'by' => $by,
            'tz' => $tz,
            'stages' => $stageList,
            'overall' => $format($overall),
            'cohorts' => array_values(array_map(
                fn (array $cohort) => ['period' => $cohort['period'], 'start' => $cohort['start']] + $format($cohort),
                $cohorts,
            )),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Étapes de suivi du questionnaire, dans l'ordre du document.
     *
     * @return array<string, array{key: string, label: string, due_offset_days: ?int, window_days: ?int}>
     */
    private function stages(Survey $survey, ?string $lang): array
    {
        $catalog = $this->stats->catalog($survey);
        if ($catalog === null) {
            return [];
        }
        $default = $catalog->defaultLanguage();

        $out = [];
        foreach ($catalog->nodes() as $node) {
            if (($node['kind'] ?? null) !== 'stage') {
                continue;
            }
            $key = (string) $node['key'];
            $def = is_array($node['def'] ?? null) ? $node['def'] : [];
            $out[$key] = [
                'key' => $key,
                'label' => LabelResolver::resolve($def['label'] ?? $key, $lang ?? $default, $default, $key),
                'due_offset_days' => isset($def['due_offset_days']) ? (int) $def['due_offset_days'] : null,
                'window_days' => isset($def['window_days']) ? (int) $def['window_days'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string} libellé de période et date de début (`AAAA-MM-JJ`)
     */
    private static function period(Carbon $date, string $by): array
    {
        return match ($by) {
            'day' => [$date->format('Y-m-d'), $date->format('Y-m-d')],
            'month' => [$date->format('Y-m'), $date->format('Y-m-01')],
            default => [$date->format('o-\WW'), $date->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d')],
        };
    }
}
