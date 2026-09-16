<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Survey\ImportXlsFormRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Services\Dfs\XlsFormConverter;
use App\Services\Survey\SurveyVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Import / export XLSForm (contrat : tag Import/Export, README DFS § 18) :
 *   - `POST /surveys/import/xlsform`          classeur Kobo/ODK/Datamuse → questionnaire brouillon (synchrone) ;
 *   - `GET  /surveys/{survey}/export/xlsform` version publiée (ou `?version=n`) → `.xlsx` à 3 feuilles.
 *
 * Les constructions non convertibles ne bloquent jamais : elles sont renvoyées dans `data.warnings[]`
 * (import) ou dans l'en-tête `X-Export-Warnings` (export, JSON compact), au format `DfsIssue`
 * `{path, code, message, severity}` enrichi de `{sheet, row, key}` pour l'affichage du builder (W-08).
 */
class SurveyXlsFormController extends ApiController
{
    public const WARNINGS_HEADER = 'X-Export-Warnings';

    /** Code `DfsIssue` des diagnostics produits par le convertisseur. */
    public const WARNING_CODE = 'xlsform';

    public function __construct(
        private readonly XlsFormConverter $converter,
        private readonly SurveyVersionService $versions,
    ) {}

    /**
     * POST /surveys/import/xlsform — analyste du projet cible. Multipart `project_id`, `file` (≤ 5 Mo).
     */
    public function import(ImportXlsFormRequest $request): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        if ($file->getSize() > ImportXlsFormRequest::MAX_BYTES) {
            return $this->fail('Fichier trop volumineux (maximum 5 Mo).', 413);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');
        if (! in_array($extension, ImportXlsFormRequest::EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['Le fichier doit être un classeur XLSForm (.xlsx, .xls ou .csv).'],
            ]);
        }

        $project = SurveyProject::query()->findOrFail($request->projectId());
        Gate::authorize('createSurvey', $project);

        // SimpleExcelReader lit d'après l'extension : on travaille sur une copie nommée.
        $path = $this->copyToTemp($file, $extension);

        try {
            $result = $this->converter->fromXlsForm($path);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => ['Le classeur XLSForm est illisible (feuille « survey » absente ou fichier corrompu).'],
            ]);
        } finally {
            $this->cleanup($path);
        }

        $definition = $result['definition'];
        $warnings = self::asIssues($result['warnings'] ?? []);
        $title = $request->title() ?? self::titleOf($definition, $file);

        $survey = $this->versions->createSurvey($project, $request->user(), $title, $definition);

        return $this->ok([
            'survey' => new SurveyResource($survey->fresh(['publishedVersion', 'draftVersion', 'creator'])),
            'warnings' => $warnings,
        ], [], 201);
    }

    /**
     * GET /surveys/{survey}/export/xlsform?version=n — analyste du projet. Version par défaut : publiée,
     * sinon brouillon courant. Téléchargement `{slug}-v{n}.xlsx`, avertissements en en-tête.
     */
    public function export(Request $request, Survey $survey): BinaryFileResponse|JsonResponse
    {
        Gate::authorize('exportSubmissions', $survey);

        $request->validate(['version' => ['nullable', 'integer', 'min:1']]);

        $version = $this->versionFor($survey, $request->query('version'));
        if ($version === null) {
            return $this->fail('Ce questionnaire ne possède aucune version exportable.', 404);
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xlsform_export_'.bin2hex(random_bytes(6)).'.xlsx';
        $export = $this->converter->toXlsForm($version->definition ?? [], $path);
        $warnings = self::asIssues($export['warnings'] ?? []);

        $filename = Str::slug($survey->slug !== '' ? $survey->slug : $survey->title).'-v'.$version->version.'.xlsx';

        return response()
            ->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                self::WARNINGS_HEADER => (string) json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            ->deleteFileAfterSend();
    }

    // ------------------------------------------------------------------ helpers

    private function versionFor(Survey $survey, mixed $requested): ?SurveyVersion
    {
        if ($requested !== null && $requested !== '') {
            return SurveyVersion::query()
                ->forSurvey($survey->id)
                ->where('version', (int) $requested)
                ->first();
        }

        return $this->versions->publishedVersion($survey) ?? $this->versions->currentOf($survey);
    }

    /**
     * Avertissements du convertisseur (`{sheet, row, key, message}`) au format `DfsIssue` du contrat.
     *
     * @param  array<int, array{sheet?: string, row?: int|null, key?: string|null, message?: string}>  $warnings
     * @return array<int, array{path: string, code: string, message: string, severity: string, sheet: string, row: int|null, key: string|null}>
     */
    public static function asIssues(array $warnings): array
    {
        return array_values(array_map(static function (array $w): array {
            $sheet = (string) ($w['sheet'] ?? 'survey');
            $row = isset($w['row']) && $w['row'] !== null ? (int) $w['row'] : null;

            return [
                'path' => '/'.$sheet.($row !== null ? '/'.$row : ''),
                'code' => self::WARNING_CODE,
                'message' => (string) ($w['message'] ?? ''),
                'severity' => 'warning',
                'sheet' => $sheet,
                'row' => $row,
                'key' => isset($w['key']) && is_string($w['key']) ? $w['key'] : null,
            ];
        }, $warnings));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function titleOf(array $definition, UploadedFile $file): string
    {
        $title = $definition['title'] ?? [];
        if (is_array($title) && $title !== []) {
            $default = $definition['settings']['default_language'] ?? null;
            $candidate = (is_string($default) ? ($title[$default] ?? null) : null) ?? reset($title);
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 200);
            }
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return mb_substr(trim($name) !== '' ? trim($name) : 'Questionnaire importé', 0, 200);
    }

    private function copyToTemp(UploadedFile $file, string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xlsform_import_'.bin2hex(random_bytes(6)).'.'.$extension;
        copy($file->getRealPath() ?: $file->getPathname(), $path);

        return $path;
    }

    private function cleanup(string $path): void
    {
        // OpenSpout garde le flux zip ouvert jusqu'au ramassage des cycles (bloquant sous Windows).
        gc_collect_cycles();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
