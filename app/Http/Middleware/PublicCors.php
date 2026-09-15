<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS ouvert (`Access-Control-Allow-Origin: *`, sans credentials) pour la collecte publique `api/public/*`.
 *
 * Enregistré en tête de la pile globale (bootstrap/app.php) afin de répondre aux pré-vols OPTIONS avant
 * HandleCors (qui n'accepte que FRONTEND_URL) ; également disponible comme alias de route `public.cors`.
 * Idempotent : peut être appliqué deux fois sans effet secondaire.
 */
class PublicCors
{
    public const PATH_PATTERN = 'api/public/*';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(self::PATH_PATTERN)) {
            return $next($request);
        }

        if ($request->getMethod() === 'OPTIONS' && $request->headers->has('Access-Control-Request-Method')) {
            return $this->decorate(response()->noContent(), $request);
        }

        return $this->decorate($next($request), $request);
    }

    private function decorate(Response $response, Request $request): Response
    {
        $headers = $response->headers;

        $headers->set('Access-Control-Allow-Origin', '*');
        $headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $headers->set(
            'Access-Control-Allow-Headers',
            $request->headers->get('Access-Control-Request-Headers') ?: 'Content-Type, Accept, Idempotency-Key, X-Requested-With'
        );
        $headers->set('Access-Control-Expose-Headers', 'ETag, X-Server-Time, Retry-After, Location');
        $headers->set('Access-Control-Max-Age', '600');
        $headers->remove('Access-Control-Allow-Credentials');

        $vary = array_filter(array_map('trim', explode(',', (string) $headers->get('Vary', ''))));
        if (! in_array('Origin', $vary, true)) {
            $vary[] = 'Origin';
        }
        $headers->set('Vary', implode(', ', $vary));

        return $response;
    }
}
