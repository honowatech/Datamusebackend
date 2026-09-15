<?php

namespace App\Enums;

enum SubmissionChannel: string
{
    case Mobile = 'mobile';
    case Public = 'public';
    case Web = 'web';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
