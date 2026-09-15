<?php

namespace App\Support;

/**
 * Aplatit une définition DFS v1 (sections → groupes → questions, puis étapes de suivi)
 * en un index à plat `{key: {type, section, stage?, label_default, choices?, …}}`.
 *
 * Aucune expression n'est évaluée ici : les libellés interpolés `${cle}` sont conservés tels quels.
 * L'index sert de table de correspondance (matérialisation, exports, statistiques) ;
 * il est stocké dans `survey_versions.question_index`.
 */
final class QuestionIndexBuilder
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    public static function build(array $definition): array
    {
        $lang = self::defaultLanguage($definition);
        $lists = is_array($definition['choice_lists'] ?? null) ? $definition['choice_lists'] : [];
        $index = [];
        $order = 0;

        foreach ($definition['sections'] ?? [] as $section) {
            $sectionKey = (string) ($section['key'] ?? '');

            foreach ($section['items'] ?? [] as $item) {
                if (($item['type'] ?? null) === 'group') {
                    $groupKey = (string) ($item['key'] ?? '');
                    $repeated = isset($item['repeat']) && is_array($item['repeat']);

                    foreach ($item['items'] ?? [] as $child) {
                        self::addEntry($index, $child, $order, $lang, $lists, [
                            'section' => $sectionKey,
                            'group' => $groupKey,
                            'repeat' => $repeated,
                        ]);
                    }

                    continue;
                }

                self::addEntry($index, $item, $order, $lang, $lists, ['section' => $sectionKey]);
            }
        }

        foreach ($definition['follow_up_stages'] ?? [] as $stage) {
            $stageKey = (string) ($stage['key'] ?? '');

            foreach ($stage['items'] ?? [] as $item) {
                self::addEntry($index, $item, $order, $lang, $lists, [
                    'section' => null,
                    'stage' => $stageKey,
                ]);
            }
        }

        return $index;
    }

    /**
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $lists
     * @param  array<string, mixed>  $context
     */
    private static function addEntry(array &$index, array $item, int &$order, string $lang, array $lists, array $context): void
    {
        $key = $item['key'] ?? null;
        if (! is_string($key) || $key === '') {
            return;
        }

        $entry = [
            'type' => (string) ($item['type'] ?? 'text'),
            'section' => $context['section'] ?? null,
            'label_default' => self::i18n($item['label'] ?? null, $lang),
            'order' => $order++,
        ];

        if (isset($context['stage'])) {
            $entry['stage'] = $context['stage'];
        }
        if (isset($context['group'])) {
            $entry['group'] = $context['group'];
            $entry['repeat'] = (bool) ($context['repeat'] ?? false);
        }

        $choices = self::resolveChoices($item['choices'] ?? null, $lists, $lang);
        if ($choices !== null) {
            $entry['choices'] = $choices;
        }

        if (is_array($item['other'] ?? null) && isset($item['other']['choice'])) {
            $entry['other_key'] = (string) ($item['other']['key'] ?? ($key.'_other'));
            $entry['other_choice'] = (string) $item['other']['choice'];
        }

        if (isset($item['appearance']) && is_string($item['appearance'])) {
            $entry['appearance'] = $item['appearance'];
        }
        if (! empty($item['tags']) && is_array($item['tags'])) {
            $entry['tags'] = array_values($item['tags']);
        }

        $index[$key] = $entry;
    }

    /**
     * @param  array<string, mixed>  $lists
     * @return list<array{name: string, label: string}>|null
     */
    private static function resolveChoices(mixed $choices, array $lists, string $lang): ?array
    {
        if (is_string($choices)) {
            $choices = $lists[$choices] ?? null;
        }
        if (! is_array($choices)) {
            return null;
        }

        $out = [];
        foreach ($choices as $choice) {
            if (! is_array($choice) || ! isset($choice['name'])) {
                continue;
            }
            $out[] = [
                'name' => (string) $choice['name'],
                'label' => self::i18n($choice['label'] ?? null, $lang),
            ];
        }

        return $out;
    }

    private static function i18n(mixed $label, string $lang): string
    {
        if (is_string($label)) {
            return $label;
        }
        if (! is_array($label) || $label === []) {
            return '';
        }
        if (isset($label[$lang]) && is_string($label[$lang])) {
            return $label[$lang];
        }
        $first = reset($label);

        return is_string($first) ? $first : '';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function defaultLanguage(array $definition): string
    {
        $settings = $definition['settings'] ?? [];
        if (is_array($settings) && is_string($settings['default_language'] ?? null)) {
            return $settings['default_language'];
        }
        if (is_array($settings) && is_array($settings['languages'] ?? null) && $settings['languages'] !== []) {
            return (string) reset($settings['languages']);
        }

        return 'fr';
    }
}
