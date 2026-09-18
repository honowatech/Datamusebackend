<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Mobile\SyncFollowUpsRequest;
use App\Services\Survey\FollowUpService;
use App\Services\Survey\FollowUpSyncResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Suivis longitudinaux côté mobile (B-08, contrat : tag Mobile).
 *
 * `GET /mobile/follow-ups/due?from&to&survey_id` — entrées `pending` de l'enquêteur dont `due_at` tombe
 * dans `[from, to]` (défauts : aujourd'hui − 30 j → aujourd'hui + 7 j, donc en retard incluses), avec
 * `respondent_label`, `fiche_code` et `parent_answers_subset` (schéma `FollowUpDue`).
 *
 * `POST /mobile/follow-ups` — lot de réponses d'étape (≤ 50), validées par `FormEngine::enterStage()`,
 * idempotentes par couple (soumission parente, étape) et rendues élément par élément
 * (schéma `FollowUpSyncResult`) ; la réponse HTTP reste `200` même si certaines entrées échouent.
 */
class FollowUpController extends ApiController
{
    public function __construct(private readonly FollowUpService $followUps) {}

    public function index(Request $request): JsonResponse
    {
        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return $this->fail('La date `from` doit précéder `to`.', 422, [
                'from' => ['La date `from` doit précéder `to`.'],
            ]);
        }

        $surveyId = $request->query('survey_id');
        $surveyId = is_numeric($surveyId) ? (int) $surveyId : null;

        return $this->ok($this->followUps->due(
            $request->user(),
            $from?->startOfDay(),
            $to?->endOfDay(),
            $surveyId,
        ));
    }

    public function store(SyncFollowUpsRequest $request): JsonResponse
    {
        $results = $this->followUps->syncBatch($request->user(), $request->entries());

        return $this->ok([
            'results' => array_map(static fn (FollowUpSyncResult $r) => $r->toArray(), $results),
        ]);
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
