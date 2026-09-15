<?php

use App\Http\Middleware\AddServerTimeMeta;
use App\Http\Middleware\PublicCors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ==== B-02 ====
        // CORS : HandleCors (global, config/cors.php) couvre api/* et media/* pour FRONTEND_URL (+ CORS_EXTRA_ORIGINS).
        // PublicCors est placé AVANT HandleCors pour ouvrir api/public/* à toutes les origines (pré-vol compris).
        $middleware->prepend(PublicCors::class);

        // meta.server_time doit envelopper auth/throttle pour couvrir aussi les réponses 401/429 de /mobile/*.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, AddServerTimeMeta::class);

        $middleware->alias([
            'server.time' => AddServerTimeMeta::class,
            'public.cors' => PublicCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ==== B-02 ====
        // Toute erreur sur api/* est rendue en JSON avec l'enveloppe {success:false, message, errors?}
        // (docs/openapi/survey.yaml, schéma ErrorEnvelope).
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $fail = static function (string $message, int $status, array $extra = [], array $headers = []): JsonResponse {
                return new JsonResponse(array_merge(['success' => false, 'message' => $message], $extra), $status, $headers);
            };

            if ($e instanceof ValidationException) {
                return $fail($e->getMessage() ?: 'Les données fournies sont invalides.', $e->status, ['errors' => $e->errors()]);
            }

            if ($e instanceof AuthenticationException) {
                return $fail('Unauthenticated.', 401);
            }

            if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
                $message = $e->getMessage();

                return $fail($message !== '' && $message !== 'This action is unauthorized.' ? $message : "Cette action n'est pas autorisée.", 403);
            }

            if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                return $fail('Ressource introuvable.', 404);
            }

            if ($e instanceof ThrottleRequestsException || $e instanceof TooManyRequestsHttpException) {
                $headers = $e->getHeaders();
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                return $fail("Trop de requêtes. Réessayez dans {$retryAfter} secondes.", 429, [], $headers);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() !== '' ? $e->getMessage() : (JsonResponse::$statusTexts[$status] ?? 'Erreur HTTP.');

                return $fail($message, $status, [], $e->getHeaders());
            }

            // Exceptions applicatives qui savent se rendre (ex. InvitationUnusableException::render).
            if (method_exists($e, 'render')) {
                $rendered = $e->render($request);
                if ($rendered instanceof JsonResponse) {
                    return $rendered;
                }
            }

            $extra = config('app.debug')
                ? ['debug_message' => $e->getMessage(), 'exception' => $e::class]
                : [];

            return $fail('Une erreur interne est survenue.', 500, $extra);
        });
    })->create();
