<?php

namespace App\Enums;

enum MediaState: string
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
