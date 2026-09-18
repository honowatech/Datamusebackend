<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Survey;
use App\Services\Survey\SurveyCrosstabService;
use App\Services\Survey\SurveyStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Agrégats du dashboard résultats (B-10, contrat : tag Statistiques) — rôle superviseur.
 *
 * Tous les endpoints partagent les filtres `from`, `to`, `enumerator_id`, `zone`, `status` et sont
 * mis en cache 20 s (`meta.cached_at`).
 *
 *  - `GET …/stats/overview`     totaux, durées, motifs de fin, quotas et KPI (`settings.kpis`) ;
 *  - `GET …/stats/questions`    distributions par question (`keys`, `lang`, `top`) ;
 *  - `GET …/stats/timeline`     comptes par jour ou par heure (`by`, `tz`) ;
 *  - `GET …/stats/enumerators`  performance par enquêteur (sparkline 7 j) ;
 *  - `GET …/stats/zones`        répartition par zone + quotas `scope: zone` ;
 *  - `GET …/stats/geo`          points GPS pour la carte (`bbox`, ≤ 5 000, `meta.sampled`) ;
 *  - `GET …/stats/crosstab`     tableau croisé sur le SQLite matérialisé (`409` si non prêt).
 */
class StatsController extends ApiController
{
    public function __construct(
        private readonly SurveyStatsService $stats,
        private readonly SurveyCrosstabService $crosstab,
    ) {}

    public function overview(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $cached = $this->stats->remember($survey, 'overview', $filters, fn () => $this->stats->overview($survey, $filters));

        return $this->ok($cached['data'], ['cached_at' => $cached['cached_at']]);
    }

    public function questions(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $keys = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query('keys', '')),
        ), fn (string $k) => $k !== ''));

        if (count($keys) > 100) {
            return $this->fail('Au plus 100 clés de question par appel.', 422, ['keys' => ['Au plus 100 clés de question par appel.']]);
        }

        $lang = is_string($request->query('lang')) && $request->query('lang') !== '' ? (string) $request->query('lang') : null;
        $top = max(1, min(100, (int) $request->query('top', 20)));

        $params = $filters + ['keys' => $keys, 'lang' => $lang, 'top' => $top];
        $cached = $this->stats->remember($survey, 'questions', $params, fn () => $this->stats->questions($survey, $filters, $keys, $lang, $top));

        return $this->ok($cached['data'], ['cached_at' => $cached['cached_at']]);
    }

    public function timeline(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $by = $request->query('by') === 'hour' ? 'hour' : 'day';
        $tz = (string) $request->query('tz', SurveyStatsService::DEFAULT_TZ);
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            return $this->fail("Fuseau horaire inconnu : « {$tz} ».", 422, ['tz' => ["Fuseau horaire inconnu : « {$tz} »."]]);
        }

        $params = $filters + ['by' => $by, 'tz' => $tz];
        $cached = $this->stats->remember($survey, 'timeline', $params, fn () => $this->stats->timeline($survey, $filters, $by, $tz));

        return $this->ok($cached['data'], ['cached_at' => $cached['cached_at']]);
    }

    public function enumerators(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $cached = $this->stats->remember($survey, 'enumerators', $filters, fn () => $this->stats->enumerators($survey, $filters));

        return $this->ok($cached['data'], ['cached_at' => $cached['cached_at']]);
    }

    public function zones(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $cached = $this->stats->remember($survey, 'zones', $filters, fn () => $this->stats->zones($survey, $filters));

        return $this->ok($cached['data'], ['cached_at' => $cached['cached_at']]);
    }

    public function geo(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $bbox = is_string($request->query('bbox')) && $request->query('bbox') !== '' ? (string) $request->query('bbox') : null;
        if ($bbox !== null && preg_match('/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/', $bbox) !== 1) {
            return $this->fail('`bbox` doit valoir `minLng,minLat,maxLng,maxLat`.', 422, ['bbox' => ['`bbox` doit valoir `minLng,minLat,maxLng,maxLat`.']]);
        }

        $cached = $this->stats->remember($survey, 'geo', $filters + ['bbox' => $bbox], fn () => $this->stats->geo($survey, $filters, $bbox));

        return $this->ok($cached['data']['points'], [
            'cached_at' => $cached['cached_at'],
            'sampled' => $cached['data']['sampled'],
            'total' => $cached['data']['total'],
        ]);
    }

    public function crosstab(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);
        $filters = $this->filters($request);

        $row = trim((string) $request->query('row', ''));
        $col = trim((string) $request->query('col', ''));
        if ($row === '' || $col === '') {
            return $this->fail('`row` et `col` sont obligatoires.', 422, [
                'row' => $row === '' ? ['`row` est obligatoire.'] : [],
                'col' => $col === '' ? ['`col` est obligatoire.'] : [],
            ]);
        }

        $lang = is_string($request->query('lang')) && $request->query('lang') !== '' ? (string) $request->query('lang') : null;

        try {
            $cached = $this->stats->remember(
                $survey,
                'crosstab',
                $filters + ['row' => $row, 'col' => $col, 'lang' => $lang],
                fn () => $this->crosstab->compute($survey, $row, $col, $filters, $lang),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, ['row' => [$e->getMessage()]]);
        }

        if ($cached['data'] === null) {
            return $this->fail(
                "La source de données du questionnaire n'est pas encore prête : reconstruisez-la avant de croiser des questions.",
                409,
                null,
                ['code' => 'datasource_not_ready'],
            );
        }

        return $this->ok($cached['data'], ['cached_at' => $cached['cached_at']]);
    }

    // ------------------------------------------------------------------ filtres

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return SurveyStatsService::filters($request->query());
    }
}
