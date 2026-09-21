<?php

namespace App\Services\Survey;

use App\Models\SubmissionMedia;
use App\Services\Dfs\LabelResolver;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Valeur **lisible** d'une réponse, pour la fiche (F-B3) et son export CSV (F-B5).
 *
 * Règles du plan § 2 : choix → libellé ; multiple / classement → libellés joints par « ; » ;
 * `currency` → `5 000 FCFA` ; date → `21/09/2026` ; heure → `14:05` ; date-heure → `21/09/2026 14:05` ;
 * géopoint → `4.0511, 9.7043 (±12 m)` ; média → nom du fichier (résolu par l'appelant, qui seul
 * connaît la ligne `submission_media`).
 *
 * Aucune valeur n'est inventée : une réponse vide rend `null`, jamais `""`.
 */
final class SubmissionDisplayFormatter
{
    /** Séparateur des valeurs multiples (plan § 2). */
    public const SEPARATOR = ' ; ';

    /** Séparateur des milliers d'un montant (espace simple : lisible en CSV comme à l'écran). */
    public const THOUSANDS = ' ';

    /** Symboles usuels ; hors de cette table, le code ISO 4217 est affiché tel quel. */
    private const CURRENCIES = [
        'XAF' => 'FCFA',
        'XOF' => 'FCFA',
        'EUR' => '€',
        'USD' => '$',
    ];

    /**
     * @param  array<string, mixed>  $def  définition DFS de la question (défauts appliqués)
     * @param  array<int, array<string, mixed>>|null  $choices  choix résolus (select_one/multiple/rank)
     */
    public static function format(array $def, mixed $value, ?array $choices, string $lang, string $default): ?string
    {
        if (self::isEmpty($value)) {
            return null;
        }

        return match ((string) ($def['type'] ?? '')) {
            'select_one' => self::choiceLabel($choices, self::text($value), $lang, $default),
            'select_multiple', 'rank' => self::joinChoices($choices, $value, $lang, $default),
            'currency' => self::currency($def, $value),
            'integer', 'decimal' => is_numeric($value) ? self::number($value) : self::text($value),
            'date' => self::date($value),
            'time' => self::time($value),
            'datetime' => self::datetime($value),
            'geopoint' => self::geopoint($value),
            'photo', 'signature', 'audio' => null, // nom du fichier : fourni par l'appelant
            'calculate' => self::calculate($value, $lang),
            default => self::text($value),
        };
    }

    /**
     * Libellés d'une liste de codes de post-codage (`{key}__codes`).
     *
     * @param  array<int, array<string, mixed>>|null  $choices
     * @return list<string>
     */
    public static function codeLabels(mixed $value, ?array $choices, string $lang, string $default): array
    {
        $codes = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($codes as $code) {
            if (! is_scalar($code)) {
                continue;
            }
            $out[] = self::choiceLabel($choices, (string) $code, $lang, $default) ?? (string) $code;
        }

        return $out;
    }

    /** Nom de fichier affiché pour un média (`surveys/1/submissions/…/photo_recu.jpg` → `photo_recu.jpg`). */
    public static function mediaName(?string $path, string $questionKey, ?string $mime): string
    {
        if (is_string($path) && $path !== '') {
            return basename($path);
        }

        return $questionKey.'.'.SubmissionMedia::extensionFor((string) $mime);
    }

    // ------------------------------------------------------------------ par type

    /**
     * @param  array<int, array<string, mixed>>|null  $choices
     */
    private static function choiceLabel(?array $choices, ?string $code, string $lang, string $default): ?string
    {
        if ($code === null) {
            return null;
        }
        foreach ($choices ?? [] as $choice) {
            $choice = (array) $choice;
            if ((string) ($choice['name'] ?? '') === $code) {
                return LabelResolver::resolve($choice['label'] ?? $code, $lang, $default, $code);
            }
        }

        return $code;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $choices
     */
    private static function joinChoices(?array $choices, mixed $value, string $lang, string $default): ?string
    {
        $codes = is_array($value) ? $value : [$value];
        $labels = [];
        foreach ($codes as $code) {
            if (! is_scalar($code)) {
                continue;
            }
            $labels[] = self::choiceLabel($choices, (string) $code, $lang, $default) ?? (string) $code;
        }

        return $labels === [] ? null : implode(self::SEPARATOR, $labels);
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private static function currency(array $def, mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return self::text($value);
        }
        $decimals = max(0, (int) ($def['decimals'] ?? 0));
        $code = strtoupper((string) ($def['currency'] ?? 'XAF'));

        return number_format((float) $value, $decimals, ',', self::THOUSANDS).' '.(self::CURRENCIES[$code] ?? $code);
    }

    private static function number(mixed $value): string
    {
        $float = (float) $value;

        return floor($float) === $float && abs($float) < 1e15
            ? (string) (int) $float
            : rtrim(rtrim(number_format($float, 6, '.', ''), '0'), '.');
    }

    private static function date(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1
            ? $m[3].'/'.$m[2].'/'.$m[1]
            : $text;
    }

    private static function time(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }

        return preg_match('/^(\d{2}):(\d{2})(:\d{2})?$/', $text, $m) === 1 ? $m[1].':'.$m[2] : $text;
    }

    private static function datetime(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }
        try {
            return Carbon::parse($text)->format('d/m/Y H:i');
        } catch (Throwable) {
            return $text;
        }
    }

    private static function geopoint(mixed $value): ?string
    {
        $point = is_array($value) ? $value : null;
        if ($point === null || ! isset($point['lat'], $point['lng']) || ! is_numeric($point['lat']) || ! is_numeric($point['lng'])) {
            return null;
        }
        $text = self::coordinate($point['lat']).', '.self::coordinate($point['lng']);

        return isset($point['accuracy']) && is_numeric($point['accuracy'])
            ? $text.' (±'.self::number(round((float) $point['accuracy'])).' m)'
            : $text;
    }

    private static function coordinate(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private static function calculate(mixed $value, string $lang): ?string
    {
        if (is_bool($value)) {
            return $value
                ? ($lang === 'en' ? 'Yes' : 'Oui')
                : ($lang === 'en' ? 'No' : 'Non');
        }
        if (is_numeric($value)) {
            return self::number($value);
        }

        return self::text($value);
    }

    // ------------------------------------------------------------------ utilitaires

    private static function text(mixed $value): ?string
    {
        $text = ReponsesLayout::scalarText($value);

        return $text === null || $text === '' ? null : $text;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_object($value) && (array) $value === []);
    }
}
