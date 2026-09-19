<?php

namespace App\Services\Survey;

use App\Models\FollowUpEntry;

/**
 * Résultat de la synchronisation d'**une** réponse de suivi (schéma OpenAPI `FollowUpSyncResult`).
 *
 * `status` : `accepted` (entrée `pending` → `done`) · `updated` (entrée `done` remplacée) ·
 * `duplicate` (serveur inchangé) · `rejected` (validation DFS, corrigeable) ·
 * `conflict` (`parent_unknown`, `stage_skipped`, `stage_missed`, `survey_closed`, `not_assigned`).
 */
final class FollowUpSyncResult
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const UPDATED = 'updated';

    public const REJECTED = 'rejected';

    public const CONFLICT = 'conflict';

    public const REASON_PARENT_UNKNOWN = 'parent_unknown';

    public const REASON_STAGE_SKIPPED = 'stage_skipped';

    public const REASON_STAGE_MISSED = 'stage_missed';

    public const REASON_SURVEY_CLOSED = 'survey_closed';

    public const REASON_NOT_ASSIGNED = 'not_assigned';

    /**
     * @param  list<array<string, mixed>>|null  $errors  `DfsIssue[]`
     * @param  array<string, list<string>>  $errorsByKey
     * @param  list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>  $pendingMedia
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $status,
        public readonly ?string $parentSubmissionUuid = null,
        public readonly ?string $stageKey = null,
        public readonly ?int $entryId = null,
        public readonly ?string $entryStatus = null,
        public readonly ?array $errors = null,
        public readonly array $errorsByKey = [],
        public readonly ?string $conflictReason = null,
        public readonly array $pendingMedia = [],
    ) {}

    /**
     * @param  list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>  $pendingMedia
     */
    public static function stored(string $uuid, string $status, FollowUpEntry $entry, ?string $parentUuid, array $pendingMedia = []): self
    {
        return new self(
            uuid: $uuid,
            status: $status,
            parentSubmissionUuid: $parentUuid,
            stageKey: $entry->stage_key,
            entryId: $entry->id,
            entryStatus: $entry->status->value,
            pendingMedia: $pendingMedia,
        );
    }

    /**
     * @param  list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>  $pendingMedia
     */
    public static function duplicate(string $uuid, ?FollowUpEntry $entry, ?string $parentUuid, ?string $stageKey = null, array $pendingMedia = []): self
    {
        return new self(
            uuid: $uuid,
            status: self::DUPLICATE,
            parentSubmissionUuid: $parentUuid,
            stageKey: $entry?->stage_key ?? $stageKey,
            entryId: $entry?->id,
            entryStatus: $entry?->status->value,
            pendingMedia: $pendingMedia,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, list<string>>  $byKey
     */
    public static function rejected(string $uuid, array $errors, array $byKey = [], ?string $parentUuid = null, ?string $stageKey = null, ?FollowUpEntry $entry = null): self
    {
        return new self(
            uuid: $uuid,
            status: self::REJECTED,
            parentSubmissionUuid: $parentUuid,
            stageKey: $stageKey,
            entryId: $entry?->id,
            entryStatus: $entry?->status->value,
            errors: $errors,
            errorsByKey: $byKey,
        );
    }

    public static function conflict(string $uuid, string $reason, ?string $parentUuid = null, ?string $stageKey = null, ?FollowUpEntry $entry = null): self
    {
        return new self(
            uuid: $uuid,
            status: self::CONFLICT,
            parentSubmissionUuid: $parentUuid,
            stageKey: $entry?->stage_key ?? $stageKey,
            entryId: $entry?->id,
            entryStatus: $entry?->status->value,
            conflictReason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'uuid' => $this->uuid,
            'parent_submission_uuid' => $this->parentSubmissionUuid,
            'stage_key' => $this->stageKey,
            'status' => $this->status,
            'entry_id' => $this->entryId,
            'entry_status' => $this->entryStatus,
            // Rattachés à la soumission parente et envoyés sur
            // `POST /mobile/follow-ups/{parentUuid}/{stageKey}/media/{questionKey}`.
            'pending_media' => $this->pendingMedia,
        ];

        if ($this->errors !== null) {
            $out['errors'] = $this->errors;
            $out['errors_by_key'] = $this->errorsByKey;
        }
        if ($this->conflictReason !== null) {
            $out['conflict_reason'] = $this->conflictReason;
        }

        return $out;
    }
}
