<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * B-11 — `content_json` non conforme au schéma `ReportContent` du contrat.
 *
 * Levée par `SurveyAiService::writeReport()` / `regenerateSection()` après l'unique tentative de
 * réparation (le job passe alors `failed`), et par `PUT /reports/{id}` lorsque l'utilisateur envoie un
 * `content_json` invalide : rendue `422` avec `errors` = liste `{path, code, message, severity}`.
 */
class ReportContentInvalidException extends RuntimeException
{
    /**
     * @param  array<int, array{path: string, code: string, message: string, severity?: string}>  $errors
     */
    public function __construct(public readonly array $errors, ?string $message = null)
    {
        $count = count($errors);

        parent::__construct($message ?? sprintf(
            'Le contenu du rapport est invalide (%d erreur%s).',
            $count,
            $count > 1 ? 's' : '',
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => array_values($this->errors),
        ], 422);
    }
}
