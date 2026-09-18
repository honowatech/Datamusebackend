<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\MediaState;
use App\Http\Controllers\ApiController;
use App\Http\Requests\Mobile\UploadMediaRequest;
use App\Models\Submission;
use App\Models\SubmissionMedia;
use App\Models\SurveyVersion;
use App\Services\Dfs\QuestionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `POST /mobile/submissions/{uuid}/media/{questionKey}` (envoi) et `GET /media/{media}` (lecture
 * par URL signée), B-07.
 *
 * Envoi : multipart `file` + `sha256` (+ `repeat_index`, en-tête `Idempotency-Key`).
 *  - la clé doit être une question `photo` / `signature` / `audio` de la version de la soumission
 *    (sinon `422`) ;
 *  - l'empreinte réellement calculée doit correspondre à `sha256` (sinon `422 sha256_mismatch`) ;
 *  - au-delà de `SURVEY_MEDIA_MAX_MB` (25 Mo) → `413` ;
 *  - un fichier déjà reçu avec la même empreinte → `200 already_exists` (idempotent), sinon
 *    `201 uploaded` ;
 *  - stockage privé `surveys/{survey_id}/submissions/{uuid}/{key}[_{i}].{ext}` sur le disque
 *    `config('filesystems.survey_media_disk')` ; `answers.{key}.media_id` est renseigné.
 *
 * Lecture : route **signée** (middleware `signed`, sans Sanctum) — l'URL est produite par
 * `SubmissionMedia::signedUrl()` et peut être placée dans une balise `<img>`.
 */
class MediaUploadController extends ApiController
{
    /** Types acceptés (contrat OpenAPI `uploadSubmissionMedia`). */
    public const ALLOWED_MIME = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/webp',
        'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac', 'audio/ogg', 'audio/mpeg',
    ];

    /** Types DFS portant un fichier. */
    public const MEDIA_TYPES = ['photo', 'signature', 'audio'];

    public function store(UploadMediaRequest $request, string $uuid, string $questionKey): JsonResponse
    {
        $uuid = strtolower($uuid);
        $submission = Submission::query()->where('uuid', $uuid)->first();
        if ($submission === null) {
            return $this->fail('Soumission introuvable.', 404);
        }
        if (! $request->user()->can('collect', $submission->survey)) {
            return $this->fail("Vous n'êtes pas affecté à ce questionnaire.", 403);
        }
        if ($submission->enumerator_id !== null
            && $submission->enumerator_id !== $request->user()->id
            && ! $request->user()->can('reviewSubmissions', $submission->survey)) {
            return $this->fail("Cette soumission n'est pas la vôtre.", 403);
        }

        return $this->storeFor($request, $submission, $questionKey);
    }

    /**
     * Cœur de l'envoi, **sans** contrôle d'accès : l'appelant a déjà établi sa légitimité
     * (enquêteur propriétaire pour `store()`, lien public ouvert pour B-12).
     */
    public function storeFor(UploadMediaRequest $request, Submission $submission, string $questionKey): JsonResponse
    {
        $uuid = $submission->uuid;
        $repeatIndex = $request->repeatIndex();
        $sha256 = $request->sha256();

        // Réponse mémorisée d'une tentative identique (réseau instable) : 24 h.
        $idempotencyKey = $request->idempotencyKey();
        $cacheKey = $idempotencyKey === null
            ? null
            : sprintf('b07:media-idem:%s:%s:%s:%d', $idempotencyKey, $uuid, $questionKey, $repeatIndex);
        if ($cacheKey !== null && ($cached = Cache::get($cacheKey)) !== null) {
            return $this->ok($cached, [], 200);
        }

        $version = $submission->version ?? SurveyVersion::query()->find($submission->survey_version_id);
        if ($version === null || ! $this->isMediaQuestion($version, $questionKey)) {
            return $this->fail("La clé « {$questionKey} » n'est pas une question média de ce formulaire.", 422, [
                'question_key' => ["La clé « {$questionKey} » n'est pas une question média de ce formulaire."],
            ]);
        }

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
        if (! in_array(strtolower($mime), self::ALLOWED_MIME, true)) {
            return $this->fail("Type de fichier non accepté ({$mime}).", 422, [
                'file' => ["Type de fichier non accepté ({$mime})."],
            ]);
        }

        $actual = strtolower((string) hash_file('sha256', $file->getRealPath()));
        if ($actual !== $sha256) {
            return $this->fail("L'empreinte du fichier ne correspond pas à `sha256`.", 422, [
                'sha256' => ['sha256_mismatch'],
            ]);
        }

        $media = SubmissionMedia::query()->firstOrNew([
            'submission_id' => $submission->id,
            'question_key' => $questionKey,
            'repeat_index' => $repeatIndex,
        ]);

        if ($media->exists && $media->isUploaded() && $media->sha256 === $sha256 && $media->fileExists()) {
            $payload = $this->payload($media, 'already_exists');
            $this->remember($cacheKey, $payload);

            return $this->ok($payload, [], 200);
        }

        $disk = (string) config('filesystems.survey_media_disk', 'local');
        $path = SubmissionMedia::storagePath(
            $submission->survey_id,
            $submission->uuid,
            $questionKey,
            $repeatIndex,
            SubmissionMedia::extensionFor($mime, $file->getClientOriginalExtension() ?: 'bin'),
        );

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $media->forceFill([
            'submission_id' => $submission->id,
            'question_key' => $questionKey,
            'repeat_index' => $repeatIndex,
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'sha256' => $sha256,
            'state' => MediaState::Uploaded,
        ])->save();

        $this->linkMediaId($submission, $questionKey, $repeatIndex, $media);

        $payload = $this->payload($media, 'uploaded');
        $this->remember($cacheKey, $payload);

        return $this->ok($payload, [], 201);
    }

    /**
     * `GET /media/{media}` — route signée (`URL::temporarySignedRoute`), sans Sanctum.
     */
    public function show(Request $request, SubmissionMedia $media): StreamedResponse
    {
        abort_unless($media->isUploaded() && $media->path !== null && $media->fileExists(), 404, 'Média introuvable.');

        return $media->storage()->download(
            $media->path,
            basename($media->path),
            [
                'Content-Type' => $media->mime,
                'Cache-Control' => 'private, max-age=900',
                'Content-Disposition' => $request->boolean('download')
                    ? 'attachment; filename="'.basename($media->path).'"'
                    : 'inline; filename="'.basename($media->path).'"',
            ],
        );
    }

    // ------------------------------------------------------------------ internes

    private function isMediaQuestion(SurveyVersion $version, string $questionKey): bool
    {
        $indexed = $version->question($questionKey);
        if (is_array($indexed) && isset($indexed['type'])) {
            return in_array((string) $indexed['type'], self::MEDIA_TYPES, true);
        }

        $catalog = QuestionCatalog::fromDefinition($version->definition ?? []);
        $question = $catalog->get($questionKey);

        return $question !== null && in_array((string) ($question['type'] ?? ''), self::MEDIA_TYPES, true);
    }

    /**
     * Renseigne `answers.{key}.media_id` (README § 17) une fois le fichier reçu.
     */
    private function linkMediaId(Submission $submission, string $questionKey, int $repeatIndex, SubmissionMedia $media): void
    {
        $answers = is_array($submission->answers) ? $submission->answers : [];
        $value = $answers[$questionKey] ?? null;

        if (is_array($value) && ! array_is_list($value) && isset($value['sha256'])) {
            $answers[$questionKey]['media_id'] = $media->id;
        } elseif (is_array($value) && array_is_list($value) && isset($value[$repeatIndex]['sha256'])) {
            $answers[$questionKey][$repeatIndex]['media_id'] = $media->id;
        } else {
            return;
        }

        $submission->forceFill(['answers' => $answers])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SubmissionMedia $media, string $status): array
    {
        return [
            'media_id' => $media->id,
            'status' => $status,
            'question_key' => $media->question_key,
            'repeat_index' => $media->repeat_index,
            'sha256' => $media->sha256,
            'size' => (int) $media->size,
            'mime' => $media->mime,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remember(?string $cacheKey, array $payload): void
    {
        if ($cacheKey !== null) {
            Cache::put($cacheKey, $payload, now()->addDay());
        }
    }
}
