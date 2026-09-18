<?php

namespace App\Http\Resources;

use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schéma OpenAPI `SubmissionSummary` (B-10, tag Soumissions).
 *
 * `media_pending` et `follow_ups` sont lus dans les relations si elles sont chargées (`with`), sinon
 * dans les compteurs `withCount` ; à défaut ils valent 0 / tableau vide — jamais de requête N+1
 * silencieuse.
 *
 * @mixin Submission
 */
class SubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::summary($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'uuid' => $submission->uuid,
            'survey_id' => $submission->survey_id,
            'version' => self::versionNumber($submission),
            'status' => $submission->status?->value,
            'channel' => $submission->channel?->value,
            'fiche_code' => $submission->fiche_code,
            'zone' => $submission->zone,
            'language' => $submission->language,
            'enumerator' => UserResource::ref($submission->relationLoaded('enumerator') ? $submission->enumerator : null)
                ?? ($submission->enumerator_id !== null ? ['id' => (int) $submission->enumerator_id, 'name' => ''] : null),
            'started_at' => $submission->started_at?->toIso8601String(),
            'ended_at' => $submission->ended_at?->toIso8601String(),
            'duration_seconds' => (int) ($submission->duration_seconds ?? 0),
            'received_at' => $submission->received_at?->toIso8601String(),
            'end_reason' => $submission->end_reason,
            'geo' => self::geo($submission),
            'flags' => array_values($submission->flags ?? []),
            'suspicion_score' => (int) ($submission->suspicion_score ?? 0),
            'media_pending' => self::mediaPending($submission),
            'follow_ups' => self::followUpCounts($submission),
            'reviewed_by' => UserResource::ref($submission->relationLoaded('reviewer') ? $submission->reviewer : null)
                ?? ($submission->reviewed_by !== null ? ['id' => (int) $submission->reviewed_by, 'name' => ''] : null),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'quality_notes' => $submission->quality_notes,
        ];
    }

    /**
     * @return array{lat: float, lng: float, accuracy: float|null}|null
     */
    public static function geo(Submission $submission): ?array
    {
        if ($submission->geo_lat === null || $submission->geo_lng === null) {
            return null;
        }

        return [
            'lat' => (float) $submission->geo_lat,
            'lng' => (float) $submission->geo_lng,
            'accuracy' => $submission->geo_accuracy !== null ? (float) $submission->geo_accuracy : null,
        ];
    }

    public static function versionNumber(Submission $submission): ?int
    {
        if ($submission->relationLoaded('version') && $submission->version !== null) {
            return (int) $submission->version->version;
        }

        $attribute = $submission->getAttribute('version_number');

        return $attribute === null ? null : (int) $attribute;
    }

    public static function mediaPending(Submission $submission): int
    {
        if ($submission->relationLoaded('media')) {
            return $submission->media->filter(fn ($m) => ! $m->isUploaded())->count();
        }

        return (int) ($submission->getAttribute('media_pending_count') ?? 0);
    }

    /**
     * @return array<string, int>
     */
    public static function followUpCounts(Submission $submission): array
    {
        $counts = ['pending' => 0, 'done' => 0, 'missed' => 0, 'skipped' => 0];
        if (! $submission->relationLoaded('followUps')) {
            return $counts;
        }
        foreach ($submission->followUps as $entry) {
            $status = $entry->status?->value;
            if ($status !== null && array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}
