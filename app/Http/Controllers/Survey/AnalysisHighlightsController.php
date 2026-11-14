<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Survey;
use App\Services\Survey\AnalysisHighlightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Analyse native, chantier 4 « découverte automatique » — contrat : docs/analysis-api.md § 8.
 *
 *  - `GET /surveys/{id}/analysis/highlights`  faits saillants enregistrés (superviseur) ;
 *  - `PUT /surveys/{id}/analysis/highlights`  enregistrement du jeu calculé par le navigateur (analyste).
 */
class AnalysisHighlightsController extends ApiController
{
    public function show(Survey $survey, AnalysisHighlightService $highlights): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        return $this->ok($highlights->show($survey));
    }

    public function update(Request $request, Survey $survey, AnalysisHighlightService $highlights): JsonResponse
    {
        // Le jeu enregistré est celui que toute l'équipe et les rapports verront : réservé à l'analyste.
        Gate::authorize('update', $survey);

        $result = $highlights->save($survey, (array) $request->json()->all(), $request->user());
        if (isset($result['errors'])) {
            return $this->fail('Les faits saillants sont invalides.', 422, $result['errors']);
        }

        return $this->ok($result['highlights']);
    }
}
