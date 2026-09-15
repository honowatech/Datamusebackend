<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Invitation expirée ou épuisée : rendue `410 Gone` avec `reason` (contrat : réponse `Gone`).
 */
class InvitationUnusableException extends RuntimeException
{
    public const REASON_EXPIRED = 'expired';

    public const REASON_USED_UP = 'used_up';

    public const REASON_INACTIVE = 'inactive';

    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? self::defaultMessage($reason));
    }

    public static function expired(): self
    {
        return new self(self::REASON_EXPIRED);
    }

    public static function usedUp(): self
    {
        return new self(self::REASON_USED_UP);
    }

    public static function defaultMessage(string $reason): string
    {
        return match ($reason) {
            self::REASON_EXPIRED => 'Cette invitation a expiré.',
            self::REASON_USED_UP => "Cette invitation a atteint son nombre maximal d'utilisations.",
            default => "Cette invitation n'est plus valide.",
        };
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'reason' => $this->reason,
        ], 410);
    }
}
