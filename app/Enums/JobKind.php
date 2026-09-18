<?php

namespace App\Enums;

enum JobKind: string
{
    case FormGeneration = 'form_generation';
    case Classify = 'classify';
    case Synthesis = 'synthesis';
    case Report = 'report';

    /** B-11 — régénération d'une seule section d'un rapport (`POST /reports/{id}/regenerate-section`). */
    case ReportSection = 'report_section';

    case Materialize = 'materialize';
    case Translate = 'translate';

    /** B-10 — recalcul des drapeaux qualité (`POST /surveys/{id}/supervision/recompute`). */
    case RecomputeFlags = 'recompute_flags';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
