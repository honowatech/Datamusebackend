<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Survey;
use App\Models\SurveyAnalysisDefinition;
use App\Services\Survey\AnalysisDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Analyse native, chantier 5 « explorateur sans code » — contrat : docs/analysis-api.md § 9.
 *
 * Segments enregistrés (`…/analysis/segments`) et variables calculées (`…/analysis/computed-variables`),
 * exprimés en langage Logic. Même contrôleur pour les deux ressources : la route fixe `kind`.
 *
 *  - `GET    …`        liste (superviseur) ;
 *  - `POST   …`        création, `201` (analyste) ;
 *  - `PUT    …/{key}`  remplacement (analyste) ;
 *  - `DELETE …/{key}`  suppression, `204` (analyste) ; `409` si une variable est encore citée par un segment.
 */
class AnalysisDefinitionsController extends ApiController
{
    public function __construct(private readonly AnalysisDefinitionService $definitions) {}

    public function index(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        return $this->ok($this->definitions->list($survey, $this->kind($request)));
    }

    public function store(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $result = $this->definitions->save($survey, $this->kind($request), (array) $request->json()->all(), $request->user());

        return isset($result['errors'])
            ? $this->fail('La définition est invalide.', 422, $result['errors'])
            : $this->ok($result['definition'], [], 201);
    }

    public function update(Request $request, Survey $survey, string $key): JsonResponse
    {
        Gate::authorize('update', $survey);

        $existing = $this->definitions->find($survey, $this->kind($request), $key);
        abort_if($existing === null, 404, 'Définition introuvable.');

        $result = $this->definitions->save($survey, $this->kind($request), (array) $request->json()->all(), $request->user(), $existing);

        return isset($result['errors'])
            ? $this->fail('La définition est invalide.', 422, $result['errors'])
            : $this->ok($result['definition']);
    }

    public function destroy(Request $request, Survey $survey, string $key): Response
    {
        Gate::authorize('update', $survey);

        $existing = $this->definitions->find($survey, $this->kind($request), $key);
        abort_if($existing === null, 404, 'Définition introuvable.');

        $blocking = $this->definitions->delete($survey, $existing);
        if ($blocking !== []) {
            return $this->fail(
                'Cette variable calculée est encore utilisée par des segments : '.implode(', ', $blocking).'.',
                409,
                null,
                ['code' => 'variable_in_use', 'segments' => $blocking],
            );
        }

        return response()->noContent();
    }

    private function kind(Request $request): string
    {
        return $request->route()->defaults['kind'] ?? SurveyAnalysisDefinition::KIND_SEGMENT;
    }
}
