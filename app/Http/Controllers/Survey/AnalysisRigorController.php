<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Survey;
use App\Services\Survey\AnalysisCohortService;
use App\Services\Survey\AnalysisWeightingService;
use App\Services\Survey\SubmissionQualityService;
use App\Services\Survey\SurveyStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Analyse native, chantier 3 « rigueur d'enquête » — contrat : docs/analysis-api.md § 5 à 7.
 *
 *  - `GET  /surveys/{id}/analysis/weighting`  plan de pondération (superviseur) ;
 *  - `PUT  /surveys/{id}/analysis/weighting`  enregistrement du plan (analyste) ;
 *  - `GET  /surveys/{id}/analysis/quotas`     avancement des quotas du questionnaire ;
 *  - `GET  /surveys/{id}/analysis/cohorts`    rétention par vague de suivi (`by`, `tz`, `lang`, filtres des stats).
 *
 * Le calage lui-même (calcul des poids) et les marges d'erreur sont calculés dans le navigateur.
 */
class AnalysisRigorController extends ApiController
{
    public function weighting(Survey $survey, AnalysisWeightingService $weighting): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        return $this->ok($weighting->show($survey));
    }

    public function updateWeighting(Request $request, Survey $survey, AnalysisWeightingService $weighting): JsonResponse
    {
        // Le plan modifie les résultats de tous les lecteurs : réservé à l'analyste, comme l'édition du questionnaire.
        Gate::authorize('update', $survey);

        $result = $weighting->save($survey, (array) $request->json()->all(), $request->user());
        if (isset($result['errors'])) {
            return $this->fail('Le plan de pondération est invalide.', 422, $result['errors']);
        }

        return $this->ok($result['plan']);
    }

    public function quotas(Survey $survey, SurveyStatsService $stats, SubmissionQualityService $quality): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $quotas = array_map(function (array $quota): array {
            $target = (int) $quota['target'];
            $current = (int) $quota['current'];

            return $quota + [
                'remaining' => max(0, $target - $current),
                'reached' => $target > 0 && $current >= $target,
            ];
        }, $quality->quotaProgress($survey, $stats->settings($survey)));

        return $this->ok([
            'quotas' => $quotas,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function cohorts(Request $request, Survey $survey, AnalysisCohortService $cohorts): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $by = (string) $request->query('by', 'week');
        if (! in_array($by, AnalysisCohortService::PERIODS, true)) {
            return $this->fail('`by` doit valoir « day », « week » ou « month ».', 422, ['by' => ['`by` doit valoir « day », « week » ou « month ».']]);
        }
        $tz = (string) $request->query('tz', SurveyStatsService::DEFAULT_TZ);
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            return $this->fail("Fuseau horaire inconnu : « {$tz} ».", 422, ['tz' => ["Fuseau horaire inconnu : « {$tz} »."]]);
        }
        $lang = is_string($request->query('lang')) && $request->query('lang') !== '' ? (string) $request->query('lang') : null;

        return $this->ok($cohorts->cohorts($survey, SurveyStatsService::filters($request->query()), $by, $lang, $tz));
    }
}
