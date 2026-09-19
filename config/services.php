<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fournisseurs IA (clés serveur globales, modèles par défaut)
    |--------------------------------------------------------------------------
    |
    | Résolution d'une clé : corps de requête `apiKey` → clé chiffrée de l'utilisateur → clé serveur
    | (App\Support\ApiKeyResolver). Ne jamais lire env() ailleurs que dans config/ (config:cache).
    |
    */

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pilote IA : appels réels (`live`) ou rejeu local (`replay`)
    |--------------------------------------------------------------------------
    |
    | `LLM_DRIVER=replay` (développement et démonstration UNIQUEMENT — refusé si `APP_ENV=production`)
    | remplace tout appel réseau par une réponse lue dans `storage/app/llm-replay/{prompt}/`.
    | Aucune clé API n'est alors exigée et tout ce qui est produit est marqué « simulé ».
    | Bascule vers le test réel : `LLM_DRIVER=live` + `GEMINI_API_KEY` (ou `DEEPSEEK_API_KEY`).
    |
    */

    'llm' => [
        'driver' => env('LLM_DRIVER', 'live'),
        'replay_path' => env('LLM_REPLAY_PATH', storage_path('app/llm-replay')),
        // Latence simulée, en millisecondes (0 = instantané, utilisé par la suite de tests).
        'replay_latency_min_ms' => (int) env('LLM_REPLAY_LATENCY_MIN_MS', 500),
        'replay_latency_max_ms' => (int) env('LLM_REPLAY_LATENCY_MAX_MS', 2000),
    ],

];
