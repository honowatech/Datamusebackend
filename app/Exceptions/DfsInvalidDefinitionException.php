<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Définition DFS invalide : rendue `422` avec `errors` = liste `{path, code, message, severity}`
 * (contrat : réponse `DfsValidationError`).
 */
class DfsInvalidDefinitionException extends RuntimeException
{
    /**
     * @param  array<int, array{path: string, code: string, message: string, severity?: string}>  $errors
     * @param  array<int, array{path: string, code: string, message: string, severity?: string}>  $warnings
     */
    public function __construct(public readonly array $errors, public readonly array $warnings = [], ?string $message = null)
    {
        $count = count($errors);

        parent::__construct($message ?? sprintf(
            'La définition du questionnaire est invalide (%d erreur%s).',
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
            'warnings' => array_values($this->warnings),
        ], 422);
    }
}
