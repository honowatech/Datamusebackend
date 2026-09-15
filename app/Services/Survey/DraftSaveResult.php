<?php

namespace App\Services\Survey;

use App\Models\SurveyVersion;

/**
 * Résultat de `SurveyVersionService::saveDraft()` : le brouillon (révision à jour), les avertissements
 * sémantiques (non bloquants pour l'enregistrement, bloquants pour la publication) et `changed`
 * (false lorsque le contenu était identique : réponse idempotente sans incrément de révision).
 */
final class DraftSaveResult
{
    /**
     * @param  array<int, array{path: string, code: string, message: string, severity: string}>  $warnings
     */
    public function __construct(
        public readonly SurveyVersion $version,
        public readonly array $warnings,
        public readonly bool $changed,
        public readonly bool $created,
    ) {}
}
