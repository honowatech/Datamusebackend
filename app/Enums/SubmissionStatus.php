<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Submitted = 'submitted';
    case ScreenedOut = 'screened_out';
    case Validated = 'validated';
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Statuts exclus du dénominateur « valid » (README § 13). */
    public static function invalid(): array
    {
        return [self::Rejected, self::ScreenedOut];
    }

    public function isValid(): bool
    {
        return ! in_array($this, self::invalid(), true);
    }
}
