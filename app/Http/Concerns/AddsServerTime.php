<?php

namespace App\Http\Concerns;

use Illuminate\Support\Carbon;

/**
 * Ajoute `meta.server_time` (ISO-8601) aux réponses ; le mobile en déduit `device_time_offset_ms`.
 * Le middleware AddServerTimeMeta l'injecte automatiquement sur `/mobile/*` ; ce trait sert aux
 * contrôleurs qui veulent l'ajouter explicitement (ou sur d'autres routes).
 */
trait AddsServerTime
{
    public static function serverTime(): string
    {
        return Carbon::now()->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function withServerTime(array $meta = []): array
    {
        return $meta + ['server_time' => static::serverTime()];
    }
}
