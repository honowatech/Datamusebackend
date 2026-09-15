<?php

namespace App\Events;

use App\Models\Survey;
use App\Models\SurveyVersion;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Émis après la publication (commit) d'une version de questionnaire (B-05).
 * Écouteurs prévus : matérialisation initiale de la datasource (B-09), invalidation de l'ETag
 * du manifest mobile (B-07).
 */
class SurveyPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Survey $survey,
        public readonly SurveyVersion $version,
        public readonly ?SurveyVersion $previous = null,
    ) {}
}
