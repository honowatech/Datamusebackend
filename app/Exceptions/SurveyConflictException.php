<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * État incompatible d'un questionnaire ou d'une version : rendue `409` avec `code` machine
 * (`no_draft`, `draft_exists`, `revision_conflict`, `survey_has_submissions`, `survey_not_published`…)
 * et `data` optionnelle (ex. `current_revision` pour `RevisionConflict`).
 */
class SurveyConflictException extends RuntimeException
{
    public const NO_DRAFT = 'no_draft';

    public const DRAFT_EXISTS = 'draft_exists';

    public const REVISION_CONFLICT = 'revision_conflict';

    public const SURVEY_HAS_SUBMISSIONS = 'survey_has_submissions';

    public const SURVEY_NOT_PUBLISHED = 'survey_not_published';

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(public readonly string $conflictCode, string $message, public readonly ?array $data = null)
    {
        parent::__construct($message);
    }

    public static function noDraft(): self
    {
        return new self(self::NO_DRAFT, "Aucun brouillon à publier : créez-en un via PUT /surveys/{id}/draft ou un fork d'une version.");
    }

    public static function draftExists(int $version): self
    {
        return new self(self::DRAFT_EXISTS, "Un brouillon existe déjà pour ce questionnaire (version {$version}).", ['draft_version' => $version]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function revisionConflict(int $currentRevision, array $data = []): self
    {
        return new self(
            self::REVISION_CONFLICT,
            "Le brouillon a été modifié depuis votre dernière lecture (révision {$currentRevision}).",
            ['current_revision' => $currentRevision] + $data,
        );
    }

    public static function hasSubmissions(int $count): self
    {
        return new self(
            self::SURVEY_HAS_SUBMISSIONS,
            "Ce questionnaire a {$count} soumission(s) : fermez-le plutôt que de le supprimer, ou relancez avec ?force=1.",
            ['submissions' => $count],
        );
    }

    public static function notPublished(): self
    {
        return new self(self::SURVEY_NOT_PUBLISHED, 'Le statut « active » requiert une version publiée.');
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['success' => false, 'message' => $this->getMessage(), 'code' => $this->conflictCode];
        if ($this->data !== null) {
            $body['data'] = $this->data;
        }

        return new JsonResponse($body, 409);
    }
}
