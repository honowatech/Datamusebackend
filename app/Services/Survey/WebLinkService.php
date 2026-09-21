<?php

namespace App\Services\Survey;

use App\Models\PublicLink;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lien web par défaut d'un questionnaire (onglet « Lien Web ») : un seul lien `is_default`, créé avec
 * le questionnaire, sans expiration ni plafond de réponses.
 */
class WebLinkService
{
    public const LABEL = 'Lien web';

    /** Lien par défaut du questionnaire, créé s'il manque (questionnaires antérieurs, fabriques de test). */
    public function ensure(Survey $survey, ?User $user = null): PublicLink
    {
        return DB::transaction(function () use ($survey, $user) {
            Survey::query()->whereKey($survey->id)->lockForUpdate()->first();

            return $survey->publicLinks()->where('is_default', true)->orderByDesc('id')->first()
                ?? $this->create($survey, $user);
        });
    }

    /**
     * Désactive le lien par défaut courant (ses réponses restent rattachées, l'ancienne URL répond
     * `410 inactive`) et en émet un nouveau.
     */
    public function regenerate(Survey $survey, User $user): PublicLink
    {
        return DB::transaction(function () use ($survey, $user) {
            Survey::query()->whereKey($survey->id)->lockForUpdate()->first();

            $survey->publicLinks()->where('is_default', true)->update(['is_default' => false, 'is_active' => false]);

            return $this->create($survey, $user);
        });
    }

    private function create(Survey $survey, ?User $user): PublicLink
    {
        return PublicLink::query()->create([
            'survey_id' => $survey->id,
            'label' => self::LABEL,
            'is_default' => true,
            'created_by' => $user?->id ?? $survey->created_by,
        ]);
    }
}
