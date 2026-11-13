<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Survey;
use App\Models\TargetDatabase;
use App\Services\Analysis\ImportedDatasetDictionaryService;
use App\Services\Survey\AnalysisDatasetService;
use App\Services\Survey\AnalysisDictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Socle d'analyse native (phase 1) — contrat : docs/analysis-api.md.
 *
 * Le calcul statistique se fait dans le navigateur ; le backend sert le dictionnaire de variables et
 * le fichier SQLite. Mêmes règles que `stats/*` : rôle superviseur (`viewSubmissions`).
 *
 *  - `GET /surveys/{id}/analysis/dictionary`    variables, niveaux de mesure, emplacement SQLite (`lang`) ;
 *  - `GET /surveys/{id}/analysis/dataset`       SQLite d'analyse en binaire (`ETag`, `304`, `409` si non prêt) ;
 *  - `GET /target-dbs/{id}/analysis/dictionary` dictionnaire inféré d'un fichier importé.
 */
class AnalysisController extends ApiController
{
    public const NOT_READY = "La source de données du questionnaire n'est pas encore prête : reconstruisez-la avant de lancer une analyse.";

    public function __construct(
        private readonly AnalysisDictionaryService $dictionary,
        private readonly AnalysisDatasetService $datasets,
    ) {}

    public function dictionary(Request $request, Survey $survey): JsonResponse
    {
        Gate::authorize('viewSubmissions', $survey);

        $lang = is_string($request->query('lang')) && $request->query('lang') !== '' ? (string) $request->query('lang') : null;
        $includePii = $request->user()->can('exportSubmissions', $survey);
        $dataset = $this->datasets->describe($survey, $includePii);

        return $this->ok([
            'dataset' => [
                'survey_id' => $survey->id,
                'rows' => $dataset['rows'] ?? 0,
                'materialized_at' => $dataset['materialized_at'] ?? null,
                'etag' => $dataset['etag'] ?? null,
            ],
            'variables' => $this->dictionary->variables($survey, $lang, $includePii, $dataset['source'] ?? null),
        ]);
    }

    public function dataset(Request $request, Survey $survey): Response
    {
        Gate::authorize('viewSubmissions', $survey);

        $includePii = $request->user()->can('exportSubmissions', $survey);
        $dataset = $this->datasets->describe($survey, $includePii);
        if ($dataset === null) {
            return $this->fail(self::NOT_READY, 409, null, ['code' => 'datasource_not_ready']);
        }

        $headers = [
            'ETag' => '"'.$dataset['etag'].'"',
            // Toujours revalider : la réponse dépend du lecteur (variante masquée) et du dernier rebuild.
            'Cache-Control' => 'private, no-cache',
        ];

        if (self::matches($request->headers->get('If-None-Match'), $dataset['etag'])) {
            return response('', 304, $headers);
        }

        $path = $this->datasets->ensureFile($survey, $dataset);

        $response = response()->file($path, $headers + [
            'Content-Type' => 'application/vnd.sqlite3',
            'Content-Disposition' => sprintf('attachment; filename="survey-%d-analysis.sqlite"', $survey->id),
        ]);
        // `BinaryFileResponse` réécrit Cache-Control/ETag à partir du fichier : on les repose.
        $response->setEtag($dataset['etag']);
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }

    public function importedDictionary(Request $request, int $database, ImportedDatasetDictionaryService $imported): JsonResponse
    {
        $target = TargetDatabase::query()->accessibleBy($request->user())->with('surveyDatasource')->findOrFail($database);

        if ($target->surveyDatasource !== null) {
            return $this->fail(
                "Cette base est la source d'une enquête : utilisez /surveys/{$target->surveyDatasource->survey_id}/analysis/dictionary.",
                422,
                null,
                ['code' => 'survey_datasource', 'survey_id' => $target->surveyDatasource->survey_id],
            );
        }

        $path = self::importedPath($target);
        if ($path === null) {
            return $this->fail("Le dictionnaire d'analyse n'est disponible que pour les fichiers importés (Excel, CSV).", 422, null, ['code' => 'not_an_imported_file']);
        }

        try {
            return $this->ok($imported->dictionary($target, $path));
        } catch (Throwable) {
            return $this->fail('Le fichier importé est illisible.', 422, null, ['code' => 'unreadable_file']);
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * `If-None-Match` contient-il cet ETag ? Tolère la forme faible (`W/`), l'absence de guillemets et le
     * suffixe `-gzip` / `-br` qu'Apache (mod_deflate, mod_brotli) ajoute aux ETags des réponses compressées.
     */
    private static function matches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            $candidate = trim(preg_replace('/^W\//', '', $candidate) ?? '', '"');
            if (preg_replace('/-(gzip|br)$/', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chemin réel d'un fichier importé : pilote SQLite et fichier situé sous le répertoire des imports
     * (une « connexion » SQLite saisie à la main vers un autre chemin du serveur n'est pas servie).
     */
    private static function importedPath(TargetDatabase $target): ?string
    {
        if ($target->driver !== 'sqlite' || ! is_string($target->database)) {
            return null;
        }
        $path = realpath($target->database);
        $root = realpath(storage_path('app/imported_databases'));
        if ($path === false || $root === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }
}
