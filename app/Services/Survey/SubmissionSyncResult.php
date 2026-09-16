<?php

namespace App\Services\Survey;

use App\Models\Submission;

/**
 * Résultat de la synchronisation d'**une** soumission (schéma OpenAPI `SubmissionSyncResult`).
 *
 * `status` : `accepted` (créée) · `updated` (remplacée) · `duplicate` (serveur inchangé) ·
 * `rejected` (validation DFS, corrigeable) · `conflict` (`conflict_reason`, décision manuelle).
 */
final class SubmissionSyncResult
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const UPDATED = 'updated';

    public const REJECTED = 'rejected';

    public const CONFLICT = 'conflict';

    public const REASON_VERSION_ARCHIVED = 'version_archived';

    public const REASON_SURVEY_CLOSED = 'survey_closed';

    public const REASON_NOT_ASSIGNED = 'not_assigned';

    /**
     * @param  list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>  $pendingMedia
     * @param  list<array{path: string, code: string, message: string}>|null  $errors
     * @param  array<string, list<string>>  $errorsByKey
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $status,
        public readonly ?int $serverId = null,
        public readonly ?string $ficheCode = null,
        public readonly bool $ficheCodeReassigned = false,
        public readonly array $pendingMedia = [],
        public readonly ?array $errors = null,
        public readonly array $errorsByKey = [],
        public readonly ?string $conflictReason = null,
        public readonly ?string $serverStatus = null,
        public readonly array $flags = [],
    ) {}

    /**
     * @param  list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>  $pendingMedia
     * @param  list<string>  $flags
     */
    public static function stored(string $uuid, string $status, Submission $submission, array $pendingMedia, bool $reassigned, array $flags): self
    {
        return new self(
            uuid: $uuid,
            status: $status,
            serverId: $submission->id,
            ficheCode: $submission->fiche_code,
            ficheCodeReassigned: $reassigned,
            pendingMedia: $pendingMedia,
            serverStatus: $submission->status->value,
            flags: $flags,
        );
    }

    /**
     * @param  list<array{question_key: string, repeat_index: int|null, sha256: string, upload_url: string}>  $pendingMedia
     */
    public static function duplicate(string $uuid, ?Submission $submission = null, array $pendingMedia = []): self
    {
        return new self(
            uuid: $uuid,
            status: self::DUPLICATE,
            serverId: $submission?->id,
            ficheCode: $submission?->fiche_code,
            pendingMedia: $pendingMedia,
            serverStatus: $submission?->status->value,
            flags: $submission?->flags ?? [],
        );
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     * @param  array<string, list<string>>  $byKey
     */
    public static function rejected(string $uuid, array $errors, array $byKey = []): self
    {
        return new self(uuid: $uuid, status: self::REJECTED, errors: $errors, errorsByKey: $byKey);
    }

    public static function conflict(string $uuid, string $reason, ?Submission $submission = null): self
    {
        return new self(
            uuid: $uuid,
            status: self::CONFLICT,
            serverId: $submission?->id,
            ficheCode: $submission?->fiche_code,
            conflictReason: $reason,
            serverStatus: $submission?->status->value,
        );
    }

    public function isStored(): bool
    {
        return $this->status === self::ACCEPTED || $this->status === self::UPDATED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'server_id' => $this->serverId,
            'fiche_code' => $this->ficheCode,
            'fiche_code_reassigned' => $this->ficheCodeReassigned,
            'pending_media' => $this->pendingMedia,
            'server_status' => $this->serverStatus,
            'flags' => $this->flags,
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
