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
}
