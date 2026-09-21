<?php

namespace App\Http\Controllers\Survey;

use App\Http\Controllers\ApiController;
use App\Models\Submission;
use App\Models\Survey;
use App\Services\Survey\ReponsesLayout;
use App\Services\Survey\SubmissionQualityService;
use App\Services\Survey\SubmissionSheetBuilder;
use App\Services\Survey\SurveyMaterializationService;
use App\Support\CsvWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /submissions/{id}/export?format=csv&layout=long|wide&lang=&include_pii=&include_unasked=`
 * (F-B5, tag Soumissions).
 *
 * **Un seul générateur de CSV** : le web télécharge le fichier produit ici, il n'en fabrique aucun.
 * L'écriture (BOM UTF-8, séparateur, garde anti-injection de formules) vient de `App\Support\CsvWriter`,
 * partagée avec l'export d'enquête (B-10) — les deux fichiers s'ouvrent donc à l'identique.
 *
 * - `layout=long` (défaut) : lisible par un humain. Bloc d'en-tête `champ,valeur`, une ligne vide, puis
 *   `fiche_code, section, question_key, question, reponse, code, autre, themes, etape` — une ligne par
 *   question posée, une de plus par post-codage enquêteur, puis les questions des étapes de suivi
 *   complétées (`etape = j4`…). `include_unasked=1` ajoute les questions non posées.
 * - `layout=wide` : exactement `ReponsesLayout::columnNames()` / `rowFor()` — les colonnes de la table
 *   `reponses` et de l'export d'enquête, sur une ligne, pour recoller avec les analyses.
 *
 * Droits : ceux de la fiche (`SubmissionPolicy::view`). `include_pii=1` exige en plus la policy
 * `exportSubmissions` (analyste) ; sans elle, les questions `pii` sont simplement absentes du fichier.
 */
class SubmissionSheetExportController extends ApiController
{
    /** Colonnes de la disposition `long` (contrat F-B1). */
    public const LONG_COLUMNS = ['fiche_code', 'section', 'question_key', 'question', 'reponse', 'code', 'autre', 'themes', 'etape'];

    public function __construct(
        private readonly SubmissionSheetBuilder $builder,
        private readonly SurveyMaterializationService $materialization,
    ) {}

    public function __invoke(Request $request, Submission $submission): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $submission);

        $format = strtolower((string) $request->query('format', 'csv'));
        if ($format !== 'csv') {
            return $this->fail('Le paramètre `format` doit valoir « csv ».', 422, [
                'format' => ['Le paramètre `format` doit valoir « csv ».'],
            ]);
        }

        $layout = strtolower((string) $request->query('layout', 'long'));
        if (! in_array($layout, ['long', 'wide'], true)) {
            return $this->fail('Le paramètre `layout` doit valoir « long » ou « wide ».', 422, [
                'layout' => ['Le paramètre `layout` doit valoir « long » ou « wide ».'],
            ]);
        }

        $survey = $submission->survey ?? Survey::query()->find($submission->survey_id);
        if ($survey === null) {
            return $this->fail('Questionnaire introuvable.', 404);
        }

        $includePii = $request->boolean('include_pii');
        if ($includePii && ! $request->user()->can('exportSubmissions', $survey)) {
            return $this->fail("L'export des données personnelles est réservé aux analystes du projet.", 403);
        }

        $lang = $request->query('lang');
        $lang = is_string($lang) && $lang !== '' ? $lang : null;

        $rows = $layout === 'wide'
            ? $this->wideRows($submission, $survey, $includePii)
            : $this->longRows($submission, $lang, $includePii, $request->boolean('include_unasked'));

        return $this->stream($this->filename($survey, $submission, $layout), $rows);
    }

    // ------------------------------------------------------------------ disposition « long »

    /**
     * @return list<array<int, mixed>>
     */
    private function longRows(Submission $submission, ?string $lang, bool $includePii, bool $includeUnasked): array
    {
        $sheet = $this->builder->build($submission, $lang, $includePii);
        $ficheCode = (string) ($submission->fiche_code ?? $submission->uuid);

        $rows = $this->headerBlock($sheet, $submission, $ficheCode);
        $rows[] = [];
        $rows[] = self::LONG_COLUMNS;

        foreach ($sheet['sections'] as $section) {
            foreach ($section['items'] as $item) {
                foreach ($this->itemRows($item, $ficheCode, (string) $section['label'], '', $includeUnasked) as $row) {
                    $rows[] = $row;
                }
            }
        }

        foreach ($sheet['follow_ups'] as $stage) {
            foreach ($stage['items'] as $item) {
                foreach ($this->itemRows($item, $ficheCode, (string) $stage['label'], (string) $stage['stage_key'], $includeUnasked) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Bloc d'en-tête `champ,valeur` — ce qu'un lecteur doit savoir avant de lire les réponses.
     *
     * @param  array<string, mixed>  $sheet
     * @return list<array<int, mixed>>
     */
    private function headerBlock(array $sheet, Submission $submission, string $ficheCode): array
    {
        $origin = $sheet['origin'];
        $who = match ($origin['channel']) {
            'public' => $origin['public_link']['label'] ?? 'Lien public',
            default => $origin['enumerator']['name'] ?? '',
        };

        return [
            ['champ', 'valeur'],
            ['Enquête', $sheet['survey']['title']],
            ['Version', $sheet['survey']['version']],
            ['Fiche', $ficheCode],
            ['Origine', $origin['label']],
            ['Enquêteur ou lien', $who],
            ['Début', self::moment($submission->started_at)],
            ['Fin', self::moment($submission->ended_at)],
            ['Durée', SubmissionQualityService::humanDuration((int) ($submission->duration_seconds ?? 0))],
            ['Statut', $submission->status?->value],
        ];
    }

    /**
     * Lignes produites par une question : la réponse, puis son post-codage enquêteur s'il existe.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<int, mixed>>
     */
    private function itemRows(array $item, string $ficheCode, string $section, string $stage, bool $includeUnasked): array
    {
        if ($item['masked'] === true) {
            // Sans `include_pii`, une question personnelle n'apparaît pas du tout — même règle que
            // l'export d'enquête, qui retire les colonnes `pii`.
            return [];
        }
        if ($item['asked'] !== true && ! $includeUnasked) {
            return [];
        }

        $label = $item['label'];
        $group = $item['group'] ?? null;
        if (is_array($group)) {
            $label = ($group['label'] ?? $group['key'])
                .($group['repeat_index'] !== null ? ' '.$group['repeat_index'] : '')
                .' — '.$label;
        }

        $rows = [[
            $ficheCode,
            $section,
            $item['key'],
            $label,
            $item['display'] ?? '',
            ReponsesLayout::scalarText($item['value']) ?? '',
            $item['other'] ?? '',
            implode(';', $item['themes'] ?? []),
            $stage,
        ]];

        $codes = $item['codes'] ?? null;
        if (is_array($codes) && $codes !== []) {
            $rows[] = [
                $ficheCode,
                $section,
                $item['key'].'__codes',
                $label.' — post-codage',
                implode(' ; ', $codes),
                '',
                '',
                '',
                $stage,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ disposition « wide »

    /**
     * Colonnes de `reponses` / de l'export d'enquête, une seule ligne.
     *
     * @return list<array<int, mixed>>
     */
    private function wideRows(Submission $submission, Survey $survey, bool $includePii): array
    {
        $submission->loadMissing(['media', 'followUps', 'codings']);

        $layout = $this->materialization->layoutFor($survey);
        $columns = $layout->columnNames();
        if (! $includePii) {
            $columns = array_values(array_diff($columns, $layout->piiColumns()));
        }

        $full = $layout->rowFor($submission);
        $row = [];
        foreach ($columns as $name) {
            $row[] = $full[$name] ?? null;
        }

        return [$columns, $row];
    }

    // ------------------------------------------------------------------ sortie

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function stream(string $filename, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            CsvWriter::write($handle, $rows);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            // Le téléchargement web lit le nom du fichier dans l'en-tête : il doit franchir le CORS.
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    /** `{slug-enquete}_{fiche_code|uuid8}_{long|wide}.csv` (contrat F-B1). */
    private function filename(Survey $survey, Submission $submission, string $layout): string
    {
        $slug = Str::slug($survey->title) ?: 'enquete-'.$survey->id;
        $fiche = $submission->fiche_code !== null && $submission->fiche_code !== ''
            ? $submission->fiche_code
            : substr((string) $submission->uuid, 0, 8);

        return sprintf('%s_%s_%s.csv', $slug, $fiche, $layout);
    }

    private static function moment(?Carbon $at): string
    {
        return $at === null ? '' : $at->format('d/m/Y H:i');
    }
}
