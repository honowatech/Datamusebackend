<?php

namespace App\Enums;

enum JobKind: string
{
    case FormGeneration = 'form_generation';
    case Classify = 'classify';
    case Synthesis = 'synthesis';
    case Report = 'report';
    case Materialize = 'materialize';
    case Translate = 'translate';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
