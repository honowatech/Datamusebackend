<?php

namespace App\Services\Dfs;

use stdClass;

/**
 * Application des valeurs `default` du schéma `dfs-v1.schema.json` à une définition (README § 1,
 * garantie G1) : toute propriété absente prend sa valeur par défaut avant toute évaluation.
 *
 * Les défauts sont recopiés ici (plutôt que lus dans le schéma) pour rester indépendants du
 * chargement du fichier ; ils doivent être maintenus en phase avec `docs/dfs/dfs-v1.schema.json`.
 */
final class DfsDefaults
{
    private const SETTINGS = [
        'quotas' => [],
        'kpis' => [],
        'duplicate_keys' => [],
        'pagination' => 'section',
        'allow_public_link' => false,
        'enumerator_can_edit_after_submit' => false,
        'followup_contact_keys' => [],
    ];

    private const COUNTER = ['width' => 2, 'scope' => 'enumerator', 'start' => 1];

    private const QUESTION = [
        'select_one' => ['required' => false, 'read_only' => false, 'appearance' => 'radio'],
        'select_multiple' => ['required' => false, 'read_only' => false, 'appearance' => 'checkbox'],
        'rank' => ['required' => false, 'read_only' => false],
        'text' => ['required' => false, 'read_only' => false, 'appearance' => 'short', 'format' => 'text'],
        'integer' => ['required' => false, 'read_only' => false],
        'decimal' => ['required' => false, 'read_only' => false],
        'currency' => ['required' => false, 'read_only' => false, 'currency' => 'XAF', 'decimals' => 0],
        'date' => ['required' => false, 'read_only' => false],
        'time' => ['required' => false, 'read_only' => false],
        'datetime' => ['required' => false, 'read_only' => false],
        'geopoint' => ['required' => false, 'read_only' => false, 'allow_manual' => false],
        'photo' => ['required' => false, 'read_only' => false, 'source' => 'camera'],
        'signature' => ['required' => false, 'read_only' => false],
        'audio' => ['required' => false, 'read_only' => false],
        'note' => ['audience' => 'both', 'style' => 'info'],
        'calculate' => [],
        'stop' => [],
    ];

    /**
     * Convertit récursivement les stdClass en tableaux associatifs (un objet vide devient `[]`).
     */
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::toArray($v);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>|stdClass  $definition
     * @return array<string, mixed>
     */
    public static function apply(array|stdClass $definition): array
    {
        $def = self::toArray($definition);
        $def['choice_lists'] = isset($def['choice_lists']) && is_array($def['choice_lists']) ? $def['choice_lists'] : [];
        $def['follow_up_stages'] = isset($def['follow_up_stages']) && is_array($def['follow_up_stages']) ? $def['follow_up_stages'] : [];

        $settings = is_array($def['settings'] ?? null) ? $def['settings'] : [];
        $settings += self::SETTINGS;
        if (! isset($settings['geo']) || ! is_array($settings['geo'])) {
            $settings['geo'] = ['capture' => 'none', 'required' => false];
        } else {
            $settings['geo'] += ['capture' => 'none', 'required' => false];
        }
        if (isset($settings['fiche_code']) && is_array($settings['fiche_code'])) {
            $settings['fiche_code']['sources'] = is_array($settings['fiche_code']['sources'] ?? null) ? $settings['fiche_code']['sources'] : [];
            $counter = is_array($settings['fiche_code']['counter'] ?? null) ? $settings['fiche_code']['counter'] : [];
            $settings['fiche_code']['counter'] = $counter + self::COUNTER;
        }
        foreach ($settings['quotas'] as $i => $quota) {
            if (is_array($quota)) {
                $settings['quotas'][$i] = $quota + ['scope' => 'survey', 'max' => false];
            }
        }
        foreach ($settings['kpis'] as $i => $kpi) {
            if (is_array($kpi)) {
                $settings['kpis'][$i] = $kpi + ['denominator' => 'valid', 'format' => 'percent'];
            }
        }
        $def['settings'] = $settings;

        foreach ($def['sections'] ?? [] as $si => $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ($section['items'] ?? [] as $ii => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (($item['type'] ?? null) === 'group') {
                    $item += ['appearance' => 'list'];
                    if (isset($item['repeat']) && is_array($item['repeat'])) {
                        $item['repeat'] += ['min' => 0];
                    }
                    foreach ($item['items'] ?? [] as $ci => $child) {
                        if (is_array($child)) {
                            $item['items'][$ci] = self::question($child);
                        }
                    }
                    $def['sections'][$si]['items'][$ii] = $item;
                } else {
                    $def['sections'][$si]['items'][$ii] = self::question($item);
                }
            }
        }
        foreach ($def['follow_up_stages'] as $si => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $stage += ['window_days' => 3, 'channel' => 'call'];
            foreach ($stage['items'] ?? [] as $ii => $item) {
                if (is_array($item)) {
                    $stage['items'][$ii] = self::question($item);
                }
            }
            $def['follow_up_stages'][$si] = $stage;
        }

        return $def;
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public static function question(array $q): array
    {
        $type = $q['type'] ?? null;
        if (is_string($type) && isset(self::QUESTION[$type])) {
            $q += self::QUESTION[$type];
        }
        if (isset($q['other']) && is_array($q['other'])) {
            $q['other'] += ['required' => true];
        }
        if (isset($q['postcode']) && is_array($q['postcode'])) {
            $q['postcode'] += ['multiple' => true];
        }

        return $q;
    }
}
