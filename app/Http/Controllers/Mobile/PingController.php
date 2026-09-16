<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /mobile/ping` — sonde de connectivité publique (contrat OpenAPI `mobilePing`).
 *
 * Répond `204` sans corps ; le middleware `server.time` pose `X-Server-Time`, que le mobile
 * utilise pour estimer `device_time_offset_ms` avant même de s'authentifier.
 */
class PingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return response()->noContent();
    }
}
