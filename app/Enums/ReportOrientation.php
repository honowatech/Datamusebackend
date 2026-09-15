<?php

namespace App\Enums;

enum ReportOrientation: string
{
    case Commercial = 'commercial';
    case Synthese = 'synthese';
    case Executif = 'executif';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
