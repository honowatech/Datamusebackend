<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Analyste = 'analyste';
    case Enqueteur = 'enqueteur';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
