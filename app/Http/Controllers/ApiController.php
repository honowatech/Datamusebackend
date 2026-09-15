<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AddsServerTime;
use App\Models\AiJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrôleur de base des nouveaux endpoints (module Enquêtes).
 *
 * Enveloppe : `{success: true, data, meta?}` / `{success: false, message, errors?}`
 * (docs/openapi/survey.yaml, schémas Envelope / ErrorEnvelope / Meta).
 */
abstract class ApiController extends Controller
{
    use AddsServerTime;

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 200;

    /**
     * Réponse de succès.
     */
    protected function ok(mixed $data, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        $body = ['success' => true, 'data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status, $headers);
    }

    /**
     * Réponse `202 Accepted` d'une opération asynchrone : `data.job_id` (+ `data.kind`) et `meta.job_id`.
     */
    protected function accepted(AiJob|string $jobId, mixed $data = null, array $meta = []): JsonResponse
    {
        $job = $jobId instanceof AiJob ? $jobId : null;
        $uuid = $job?->uuid ?? $jobId;

        $payload = ['job_id' => $uuid];
        if ($job !== null) {
            $payload['kind'] = $job->kind?->value;
        }
        if (is_array($data)) {
            $payload = array_merge($payload, $data);
        } elseif ($data !== null) {
            $payload['result'] = $data;
        }

        return $this->ok($payload, array_merge($meta, ['job_id' => $uuid]), 202);
    }

    /**
     * Réponse d'erreur. `$errors` = `{champ: [messages]}` ou liste `{path, code, message}` (DFS).
     * `$extra` permet d'ajouter des clés racine prévues par le contrat (`code`, `reason`, `data`).
     */
    protected function fail(string $message, int $status = 400, ?array $errors = null, array $extra = [], array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json(array_merge($body, $extra), $status, $headers);
    }

    /**
     * Réponse paginée : `data` = collection de ressources, `meta.pagination = {page, per_page, total, last_page}`.
     *
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resource, array $meta = []): JsonResponse
    {
        return $this->ok(
            $resource::collection($paginator->items()),
            array_merge($meta, ['pagination' => self::paginationMeta($paginator)]),
        );
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    public static function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => max(1, $paginator->lastPage()),
        ];
    }

    /**
     * Taille de page demandée, bornée à [1, 200] (contrat : défaut 25).
     */
    protected function perPage(Request $request, int $default = self::DEFAULT_PER_PAGE): int
    {
        $perPage = (int) $request->query('per_page', $default);

        return max(1, min(self::MAX_PER_PAGE, $perPage ?: $default));
    }

    /**
     * Tri `?sort=champ` / `?sort=-champ` restreint à une liste blanche.
     *
     * @param  list<string>  $allowed
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    protected function sort(Request $request, array $allowed, string $default = '-created_at'): array
    {
        $sort = (string) $request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        return [$column, $direction];
    }
}
