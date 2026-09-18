<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Submission;
use App\Models\Survey;
use App\Services\Survey\ReponsesLayout;
use App\Services\Survey\SubmissionFilter;
use App\Services\Survey\SurveyMaterializationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use OpenSpout\Writer\WriterInterface;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /surveys/{id}/submissions/export?format=csv|xlsx` (B-10, tag Soumissions).
 *
 * Les colonnes sont **exactement** celles de la table `reponses` de la datasource : la disposition
 * vient de `SurveyMaterializationService::layoutFor()` (donc `columnsFor()`) et chaque ligne de
 * `ReponsesLayout::rowFor()`. Un export et un `SELECT * FROM reponses` donnent ainsi les mêmes
 * en-têtes, dans le même ordre, y compris pour les questions disparues d'une version à l'autre.
 *
 * - filtres identiques à la liste (`SubmissionFilter`) ;
 * - `include_pii=1` conserve les colonnes issues de questions `tags: ["pii"]` (réservé aux analystes,
 *   ce que la policy `exportSubmissions` garantit déjà) ; sinon elles sont retirées ;
 * - au-delà de `MAX_ROWS` (50 000) → `422` (passer par l'export Excel de la datasource) ;
 * - écriture en flux : les soumissions sont lues par lots de `CHUNK` et écrites au fil de l'eau.
 */
class SubmissionExportController extends ApiController
{
    /** Plafond synchrone par défaut (contrat : > 50 000 lignes → 422), surchargeable par `survey.export_max_rows`. */
    public const MAX_ROWS = 50_000;

    /** Taille des lots de lecture (mémoire constante). */
    public const CHUNK = 250;

    public function __construct(
        private readonly SubmissionFilter $filter,
        private readonly SurveyMaterializationService $materialization,
    ) {}

    public function __invoke(Request $request, Survey $survey): StreamedResponse|JsonResponse
    {
        Gate::authorize('exportSubmissions', $survey);

        $format = strtolower((string) $request->query('format', ''));
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            return $this->fail('Le paramètre `format` doit valoir « csv » ou « xlsx ».', 422, [
                'format' => ['Le paramètre `format` doit valoir « csv » ou « xlsx ».'],
            ]);
        }

        if (! $survey->isPublished() && $survey->versions()->count() === 0) {
            return $this->fail("Ce questionnaire n'a aucune version à exporter.", 404);
        }

        $query = $this->filter->apply($survey, $request);
        $maxRows = max(1, (int) config('survey.export_max_rows', self::MAX_ROWS));
        $total = (clone $query)->count();
        if ($total > $maxRows) {
            return $this->fail(
                sprintf("L'export synchrone est limité à %s lignes (%s demandées) : utilisez l'export Excel de la source de données.", number_format($maxRows, 0, ',', ' '), number_format($total, 0, ',', ' ')),
                422,
                ['format' => ['Trop de lignes pour un export synchrone.']],
            );
        }

        $layout = $this->materialization->layoutFor($survey);
        $columns = $layout->columnNames();
        if (! $request->boolean('include_pii')) {
            $columns = array_values(array_diff($columns, $layout->piiColumns()));
        }

        $filename = $this->filename($survey, $format);
        $rows = $this->rows($query, $layout, $columns);

        return $format === 'csv'
            ? $this->streamCsv($filename, $columns, $rows)
            : $this->streamXlsx($filename, $columns, $rows);
    }

    // ------------------------------------------------------------------ lignes

    /**
     * Générateur des lignes de l'export, dans l'ordre stable `id`.
     *
     * @param  Builder<Submission>  $query
     * @param  list<string>  $columns
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows($query, ReponsesLayout $layout, array $columns): \Generator
    {
        $cursor = (clone $query)
            ->with(['media', 'followUps', 'codings'])
            ->orderBy('id');

        foreach ($cursor->lazy(self::CHUNK) as $submission) {
            /** @var Submission $submission */
            $full = $layout->rowFor($submission);
            $row = [];
            foreach ($columns as $name) {
                $row[$name] = $full[$name] ?? null;
            }
            yield $row;
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  \Generator<int, array<string, mixed>>  $rows
     */
    private function streamCsv(string $filename, array $columns, \Generator $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 : Excel (Windows) reconnaît alors les accents sans import manuel.
            fwrite($handle, "\u{FEFF}");
            fputcsv($handle, $columns, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::scalar(...), array_values($row)), ',', '"', '\\');
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @param  \Generator<int, array<string, mixed>>  $rows
     */
    private function streamXlsx(string $filename, array $columns, \Generator $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($filename, $columns, $rows): void {
            $writer = SimpleExcelWriter::streamDownload(
                $filename,
                'xlsx',
                fn (WriterInterface $w) => $w->openToFile('php://output'),
            );
            $writer->addHeader($columns);
            foreach ($rows as $row) {
                $writer->addRow(array_map(self::scalar(...), $row));
            }
            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ------------------------------------------------------------------ utilitaires

    private static function scalar(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 1 : 0,
            is_int($value), is_float($value) => $value,
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }

    private function filename(Survey $survey, string $format): string
    {
        $slug = Str::slug($survey->title) ?: 'enquete-'.$survey->id;

        return sprintf('%s-reponses-%s.%s', $slug, now()->format('Ymd-Hi'), $format);
    }
}
