<?php

namespace App\Http\Resources;

use App\Models\SurveyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Schéma OpenAPI `ReportFile` (B-11).
 *
 * Les fichiers archivés vivent dans la colonne JSON `survey_reports.generated_files` (pas de table
 * dédiée : migration B-01). L'URL de téléchargement est **signée et temporaire** (15 min), comme pour les
 * médias de soumission : le disque est privé.
 */
class ReportFileResource extends JsonResource
{
    /** Validité de l'URL signée (contrat : 15 min, comme `SubmissionDetail.media[].signed_url`). */
    public const URL_MINUTES = 15;

    public function toArray(Request $request): array
    {
        /** @var array{file: array<string, mixed>, report: SurveyReport} $resource */
        $resource = $this->resource;

        return self::present($resource['file'], $resource['report']);
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    public static function present(array $file, SurveyReport $report): array
    {
        return [
            'id' => (int) ($file['id'] ?? 0),
            'format' => (string) ($file['format'] ?? 'pdf'),
            'filename' => (string) ($file['filename'] ?? ''),
            'label' => $file['label'] ?? null,
            'size' => (int) ($file['size'] ?? 0),
            'signed_url' => self::signedUrl($report, (int) ($file['id'] ?? 0)),
            'created_by' => $file['created_by'] ?? null,
            'created_at' => $file['created_at'] ?? null,
        ];
    }

    public static function signedUrl(SurveyReport $report, int $fileId, int $minutes = self::URL_MINUTES): string
    {
        return URL::temporarySignedRoute('reports.files.show', now()->addMinutes($minutes), [
            'report' => $report->id,
            'fileId' => $fileId,
        ]);
    }
}
