<?php

namespace App\Enums;

enum ProjectRole: string
{
    case Analyste = 'analyste';
    case Superviseur = 'superviseur';
    case Enqueteur = 'enqueteur';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Rôles autorisés à gérer le questionnaire et à lire les résultats. */
    public function canManage(): bool
    {
        return $this !== self::Enqueteur;
    }

    /** Rang hiérarchique : enqueteur (1) < superviseur (2) < analyste (3). */
    public function rank(): int
    {
        return match ($this) {
            self::Enqueteur => 1,
            self::Superviseur => 2,
            self::Analyste => 3,
        };
    }

    /** Vrai si ce rôle inclut les droits de `$min` (un rôle supérieur inclut les inférieurs). */
    public function atLeast(self $min): bool
    {
        return $this->rank() >= $min->rank();
    }
}
