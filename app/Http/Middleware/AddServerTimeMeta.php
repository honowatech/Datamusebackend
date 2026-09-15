<?php

namespace App\Http\Middleware;

use App\Http\Concerns\AddsServerTime;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injecte `meta.server_time` dans toute réponse JSON enveloppée (`{success: …}`) et l'en-tête
 * `X-Server-Time` sur toutes les réponses (y compris sans corps, ex. `204`). Appliqué au groupe `/mobile/*`.
 */
class AddServerTimeMeta
{
    use AddsServerTime;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $now = self::serverTime();

        $response->headers->set('X-Server-Time', $now);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data) && array_key_exists('success', $data)) {
                $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
                $meta['server_time'] = $now;
                $data['meta'] = $meta;
                $response->setData($data);
            }
        }

        return $response;
    }
}
