<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\ApiController;
use App\Http\Resources\MobileFormManifestResource;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Services\Survey\MobileManifestService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /mobile/forms` (manifest) et `GET /mobile/forms/{surveyId}` (définition publiée), B-07.
 *
 * Manifest : questionnaires `active` publiés assignés à l'enquêteur — ou de tout projet où il est
 * superviseur / analyste. `ETag` = `"sha256-…"` du manifest canonique ; `If-None-Match` identique
 * → `304` **sans corps** (contrat `NotModified`), l'heure serveur restant lisible dans l'en-tête
 * `X-Server-Time` posé par le middleware `server.time`.
 *
 * Définition : `data.definition` est la **chaîne** JSON canonique figée à la publication
 * (`SurveyVersion::canonicalJson()`), et `definition_hash` son sha256 : le client hache les octets
 * reçus, compare, puis parse (README § 15). `?version=` permet de récupérer une version archivée
 * pour un brouillon local commencé avant une republication.
 */
class FormController extends ApiController
{
    public function __construct(private readonly MobileManifestService $manifests) {}

    public function index(Request $request): SymfonyResponse
    {
        $manifest = $this->manifests->build($request->user());
        $etag = $this->manifests->etag($manifest);

        if ($this->matchesEtag($request, $etag)) {
            return response()->noContent(Response::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        return $this->ok(MobileFormManifestResource::collection($manifest), [], 200, ['ETag' => $etag]);
    }

    public function show(Request $request, int $surveyId): SymfonyResponse
    {
        $survey = Survey::query()->findOrFail($surveyId);

        if (! $this->manifests->canAccess($request->user(), $survey)) {
            return $this->fail("Vous n'êtes pas affecté à ce questionnaire.", 403);
        }

        $requested = $request->integer('version');
        $version = $requested > 0
            ? SurveyVersion::query()->forSurvey($survey->id)->where('version', $requested)->first()
            : $survey->publishedVersion;

        if ($version === null || $version->isDraft()) {
            return $this->fail('Aucune version publiée pour ce questionnaire.', 404);
        }

        $definition = $version->canonicalJson();
        $hash = $version->definition_hash ?: SurveyVersion::computeHash($definition);
        $etag = '"sha256-'.$hash.'"';

        if ($this->matchesEtag($request, $etag)) {
            return response()->noContent(Response::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        return $this->ok([
            'survey_id' => $survey->id,
            'version' => (int) $version->version,
            'status' => $version->status->value,
            'definition_hash' => $hash,
            'definition' => $definition,
            'published_at' => $version->published_at?->toIso8601String(),
            'archived_at' => $version->isArchived() ? $version->updated_at?->toIso8601String() : null,
        ], [], 200, ['ETag' => $etag]);
    }

    /**
     * `If-None-Match` accepte une liste ; la comparaison ignore le préfixe faible `W/`.
     */
    private function matchesEtag(Request $request, string $etag): bool
    {
        $header = (string) $request->header('If-None-Match', '');
        if ($header === '') {
            return false;
        }
        if (trim($header) === '*') {
            return true;
        }

        foreach (explode(',', $header) as $candidate) {
            if (ltrim(trim($candidate), 'W/') === $etag) {
                return true;
            }
        }

        return false;
    }
}
