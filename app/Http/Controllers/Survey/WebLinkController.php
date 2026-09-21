<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Http\Resources\PublicLinkResource;
use App\Models\Survey;
use App\Services\Survey\WebLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lien web par défaut d'un questionnaire (onglet « Lien Web »).
 *
 * - `GET  /surveys/{id}/web-link`            tout membre du projet ; crée le lien s'il manque ;
 * - `POST /surveys/{id}/web-link/regenerate` analyste ; désactive l'ancien lien et en émet un nouveau.
 *
 * Contrairement à `POST /public-links`, le lien existe quel que soit l'état du questionnaire : il ne
 * sert le formulaire qu'une fois une version publiée (sinon `404` sur `/public/surveys/{token}`).
 */
class WebLinkController extends ApiController
{
    public function __construct(private readonly WebLinkService $links) {}

    public function show(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('view', $survey);

        $link = $this->links->ensure($survey, $request->user());

        return $this->ok(new PublicLinkResource($link->load('creator:id,name')));
    }

    public function regenerate(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $link = $this->links->regenerate($survey, $request->user());

        return $this->ok(new PublicLinkResource($link->load('creator:id,name')), [], 201);
    }
}
