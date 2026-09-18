<?php

namespace App\Http\Controllers\Survey;

use App\Enums\SurveyStatus;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\StorePublicLinkRequest;
use App\Http\Resources\PublicLinkResource;
use App\Models\PublicLink;
use App\Models\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Gestion des liens de collecte publique (B-12, contrat : tag Liens publics).
 *
 * - `GET  /surveys/{id}/public-links`     superviseur ;
 * - `POST /surveys/{id}/public-links`     analyste ; exige `settings.allow_public_link = true` sur la
 *   version publiée et un questionnaire `active`, sinon `409` ;
 * - `DELETE /surveys/{id}/public-links/{linkId}` analyste ; désactive (`is_active = false`) sans
 *   supprimer les réponses déjà reçues — le lien répond ensuite `410 inactive`.
 */
class PublicLinkController extends ApiController
{
    public function index(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $links = $survey->publicLinks()->with('creator:id,name')->orderByDesc('id')->get();

        return $this->ok(PublicLinkResource::collection($links));
    }

    public function store(StorePublicLinkRequest $request, Survey $survey): JsonResponse
    {
        Gate::authorize('update', $survey);

        $version = $survey->publishedVersion;
        if ($version === null) {
            return $this->fail("Ce questionnaire n'a pas encore de version publiée.", 409, null, ['code' => 'not_published']);
        }
        if (! (bool) ($version->settings()['allow_public_link'] ?? false)) {
            return $this->fail(
                "La version publiée n'autorise pas la collecte par lien public (`settings.allow_public_link`).",
                409,
                null,
                ['code' => 'public_link_disabled'],
            );
        }
        if ($survey->status !== SurveyStatus::Active) {
            return $this->fail('Le questionnaire doit être actif pour recevoir des réponses publiques.', 409, null, ['code' => 'survey_not_active']);
        }

        $link = PublicLink::query()->create([
            'survey_id' => $survey->id,
            'label' => $request->input('label'),
            'expires_at' => $request->filled('expires_at') ? Carbon::parse((string) $request->input('expires_at')) : null,
            'max_responses' => $request->filled('max_responses') ? (int) $request->input('max_responses') : null,
            'created_by' => $request->user()->id,
        ]);

        return $this->ok(new PublicLinkResource($link->load('creator:id,name')), [], 201);
    }

    public function destroy(Request $request, Survey $survey, int $linkId): Response
    {
        Gate::authorize('update', $survey);

        $link = $survey->publicLinks()->whereKey($linkId)->firstOrFail();
        $link->forceFill(['is_active' => false])->save();

        return response()->noContent();
    }
}
