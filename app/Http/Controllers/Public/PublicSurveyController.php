<?php

namespace App\Http\Controllers\Public;

use App\Enums\SubmissionChannel;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\Mobile\MediaUploadController;
use App\Http\Requests\Mobile\UploadMediaRequest;
use App\Http\Requests\Public\PublicSubmissionRequest;
use App\Http\Resources\PublicLinkResource;
use App\Http\Resources\SubmissionSyncResultResource;
use App\Models\PublicLink;
use App\Models\Submission;
use App\Services\Dfs\LabelResolver;
use App\Services\Survey\PublicDefinitionFilter;
use App\Services\Survey\SubmissionSyncResult;
use App\Services\Survey\SubmissionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Collecte publique anonyme (B-12, contrat : tag Public) — **sans authentification**, CORS ouvert
 * (`PublicCors`), 30 lectures/min et 10 envois/min par IP.
 *
 * - `GET  /public/surveys/{token}` — définition de la version publiée **filtrée** par
 *   `PublicDefinitionFilter` (sans `pii`, sans `enumerator_only`, sans note d'enquêteur, sans étape de
 *   suivi) ; `definition_hash` est recalculé sur la définition filtrée. `404` si le token est inconnu,
 *   `410 {reason}` si le lien est expiré, désactivé ou plein.
 * - `POST /public/surveys/{token}/submissions` — `{submission, website}` : une seule fiche, passée à
 *   `SubmissionSyncService::sync(null, …, SubmissionChannel::Public)` (donc `enumerator_id` et
 *   `device_id` nuls, suivis et matérialisation déclenchés comme pour le mobile). Deux protections
 *   avant le moteur : pot de miel `website` (rempli → `accepted` factice, rien n'est écrit) et durée
 *   minimale `settings.timing.min_duration_seconds / 3` (sinon `rejected`). `responses_count` n'est
 *   incrémenté que sur une fiche réellement créée.
 * - `POST /public/surveys/{token}/submissions/{uuid}/media/{key}` — mêmes règles que le mobile,
 *   la fiche devant appartenir à ce lien et avoir moins de `MEDIA_WINDOW_HOURS` heures.
 */
class PublicSurveyController extends ApiController
{
    /** Délai pendant lequel une fiche publique accepte encore ses médias. */
    public const MEDIA_WINDOW_HOURS = 24;

    /**
     * Le lien n'a pas de colonne dans `submissions` (schéma B-01) : l'association fiche → lien est
     * mémorisée en cache le temps de la fenêtre d'envoi des médias.
     */
    public const LINK_CACHE_PREFIX = 'b12:public-submission:';

    public function __construct(
        private readonly SubmissionSyncService $sync,
        private readonly PublicDefinitionFilter $filter,
    ) {}

    // ------------------------------------------------------------------ définition

    public function show(string $token): JsonResponse
    {
        $link = $this->link($token);
        if (! $link instanceof PublicLink) {
            return $link;
        }

        $version = $link->survey?->publishedVersion;
        if ($version === null) {
            return $this->fail('Ce questionnaire n\'est pas ouvert aux réponses.', 404);
        }

        $definition = $this->filter->apply($version->definition ?? []);
        $settings = $version->settings();
        $language = (string) ($settings['default_language'] ?? 'fr');

        return $this->ok([
            'survey_id' => $link->survey_id,
            'title' => LabelResolver::resolve($definition['title'] ?? $link->survey->title, $language, $language, (string) $link->survey->title),
            'version' => (int) $version->version,
            'definition_hash' => PublicDefinitionFilter::hash($definition),
            'definition' => $definition,
            'remaining' => PublicLinkResource::remaining($link),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'min_duration_seconds' => self::minDuration($settings),
            'client_name' => $link->survey->project?->client_name,
        ]);
    }

    // ------------------------------------------------------------------ soumission

    public function submit(PublicSubmissionRequest $request, string $token): JsonResponse
    {
        $link = $this->link($token);
        if (! $link instanceof PublicLink) {
            return $link;
        }

        $payload = $request->submission();
        $uuid = strtolower((string) ($payload['uuid'] ?? ''));

        // Pot de miel : un robot a rempli `website`. On répond comme si tout allait bien, sans écrire.
        if ($request->isBot()) {
            return $this->ok(new SubmissionSyncResultResource(
                new SubmissionSyncResult(uuid: $uuid, status: SubmissionSyncResult::ACCEPTED),
            ));
        }

        $version = $link->survey?->publishedVersion;
        if ($version === null) {
            return $this->fail('Ce questionnaire n\'est pas ouvert aux réponses.', 404);
        }

        $minimum = self::minDuration($version->settings());
        if ($minimum > 0 && self::duration($payload) < $minimum) {
            return $this->ok(new SubmissionSyncResultResource(SubmissionSyncResult::rejected(
                $uuid,
                [['path' => '/ended_at', 'code' => 'too_fast', 'message' => 'Le questionnaire a été rempli trop rapidement.']],
                ['_duration' => ['Le questionnaire a été rempli trop rapidement.']],
            )));
        }

        // Le lien impose le questionnaire et la version : un payload ne peut pas viser ailleurs.
        $payload['survey_id'] = $link->survey_id;
        $payload['form_version'] = (int) $version->version;
        unset($payload['device_id'], $payload['device_time_offset_ms']);

        $result = $this->sync->sync(null, $payload, SubmissionChannel::Public);

        if ($result->status === SubmissionSyncResult::ACCEPTED && $result->serverId !== null) {
            DB::table('public_links')->where('id', $link->id)->increment('responses_count');
            Cache::put(self::LINK_CACHE_PREFIX.$result->uuid, $link->id, now()->addHours(self::MEDIA_WINDOW_HOURS));
        }

        return $this->ok(new SubmissionSyncResultResource($result));
    }

    // ------------------------------------------------------------------ médias

    public function media(UploadMediaRequest $request, string $token, string $uuid, string $questionKey): JsonResponse
    {
        $link = $this->link($token);
        if (! $link instanceof PublicLink) {
            return $link;
        }

        $submission = Submission::query()->where('uuid', strtolower($uuid))->first();
        if ($submission === null
            || $submission->survey_id !== $link->survey_id
            || $submission->channel !== SubmissionChannel::Public
            || (int) Cache::get(self::LINK_CACHE_PREFIX.strtolower($uuid), 0) !== (int) $link->id) {
            return $this->fail('Soumission introuvable pour ce lien.', 404);
        }

        if ($submission->received_at !== null && $submission->received_at->lt(now()->subHours(self::MEDIA_WINDOW_HOURS))) {
            return $this->fail('Le délai d\'envoi des fichiers de cette réponse est dépassé.', 410, null, ['reason' => 'used_up']);
        }

        return app(MediaUploadController::class)->storeFor($request, $submission, $questionKey);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Lien ouvert, ou la réponse d'erreur à renvoyer (`404` inconnu, `410 {reason}` fermé).
     */
    private function link(string $token): PublicLink|JsonResponse
    {
        $link = PublicLink::query()->where('token', $token)->with(['survey.project', 'survey.publishedVersion'])->first();

        if ($link === null || $link->survey === null) {
            return $this->fail('Lien introuvable.', 404);
        }

        if (! $link->isOpen()) {
            $reason = PublicLinkResource::state($link);

            return $this->fail(match ($reason) {
                'expired' => 'Ce lien a expiré.',
                'full' => "Ce lien n'accepte plus de réponses.",
                default => 'Ce lien a été désactivé.',
            }, 410, null, ['reason' => $reason]);
        }

        return $link;
    }

    /**
     * Durée minimale exigée d'un répondant web : un tiers de la durée d'un entretien assisté
     * (`settings.timing.min_duration_seconds`), le remplissage en autonomie étant plus rapide.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function minDuration(array $settings): int
    {
        $minimum = $settings['timing']['min_duration_seconds'] ?? 0;

        return is_numeric($minimum) ? (int) floor((int) $minimum / 3) : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function duration(array $payload): int
    {
        try {
            $startedAt = Carbon::parse((string) ($payload['started_at'] ?? ''));
            $endedAt = Carbon::parse((string) ($payload['ended_at'] ?? ''));
        } catch (Throwable) {
            return 0;
        }

        return max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
    }
}
