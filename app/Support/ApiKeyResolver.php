<?php

namespace App\Support;

use App\Models\User;
use App\Services\LlmProviderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Résolution d'une clé API IA : corps de requête `apiKey` → clé chiffrée de l'utilisateur
 * (`users.gemini_api_key` / `users.deepseek_api_key`) → clé serveur `config('services.<provider>.key')`.
 *
 * Pour les jobs asynchrones, `resolveForJob()` renvoie la clé chiffrée (Crypt) à placer dans `ai_jobs.input` ;
 * le job la déchiffre avec `decryptForJob()`. La clé n'est jamais stockée en clair.
 *
 * **Mode rejeu** (`LLM_DRIVER=replay`) : aucune clé n'est exigée — la résolution renvoie un jeton factice
 * (`REPLAY_PLACEHOLDER`) pour que la chaîne complète (contrôleur → job → service) reste inchangée.
 */
class ApiKeyResolver
{
    public const GEMINI = 'gemini';

    public const DEEPSEEK = 'deepseek';

    /** @var list<string> */
    public const PROVIDERS = [self::GEMINI, self::DEEPSEEK];

    public const REQUEST_FIELD = 'apiKey';

    /** Jeton sans valeur, jamais envoyé sur le réseau : il n'existe que pour traverser la chaîne en rejeu. */
    public const REPLAY_PLACEHOLDER = 'replay-no-key-needed';

    /**
     * Fournisseur canonique : tout ce qui n'est pas `deepseek` est traité comme `gemini` (comportement historique).
     */
    public static function normalizeProvider(?string $provider): string
    {
        return strtolower((string) $provider) === self::DEEPSEEK ? self::DEEPSEEK : self::GEMINI;
    }

    public function resolve(?Request $request, User $user, string $provider): ?string
    {
        if (LlmProviderService::isReplay()) {
            return self::REPLAY_PLACEHOLDER;
        }

        $provider = self::normalizeProvider($provider);

        $fromRequest = $request?->input(self::REQUEST_FIELD);
        if (is_string($fromRequest) && trim($fromRequest) !== '') {
            return trim($fromRequest);
        }

        $fromUser = $user->getAttribute($provider.'_api_key');
        if (is_string($fromUser) && $fromUser !== '') {
            return $fromUser;
        }

        $fromConfig = config("services.{$provider}.key");
        if (is_string($fromConfig) && $fromConfig !== '') {
            return $fromConfig;
        }

        return null;
    }

    /**
     * Même résolution, mais la clé est chiffrée pour être embarquée dans le payload d'un job.
     */
    public function resolveForJob(?Request $request, User $user, string $provider): ?string
    {
        $key = $this->resolve($request, $user, $provider);

        return $key === null ? null : self::encryptForJob($key);
    }

    public static function encryptForJob(string $plainKey): string
    {
        return Crypt::encryptString($plainKey);
    }

    public static function decryptForJob(string $encryptedKey): string
    {
        return Crypt::decryptString($encryptedKey);
    }

    /**
     * Message d'erreur utilisateur lorsque aucune clé n'est disponible.
     */
    public static function missingKeyMessage(string $provider): string
    {
        return 'La clé API pour '.ucfirst(self::normalizeProvider($provider))
            ." n'est pas configurée dans les paramètres d'API de votre compte ni sur le serveur.";
    }
}
