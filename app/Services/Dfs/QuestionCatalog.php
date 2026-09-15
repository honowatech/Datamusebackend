<?php

namespace App\Services\Dfs;

use stdClass;

/**
 * Aplatissement d'une définition DFS en un index des questions, dans l'ordre du document.
 *
 * `all()` renvoie `{key => [type, section, stage, group, label_default, choices]}` :
 *   - `type`          type de question (`select_one`, `text`, `calculate`, …)
 *   - `section`       clé de la section (null pour une question d'étape)
 *   - `stage`         clé de l'étape (null pour une question de base)
 *   - `group`         clé du groupe parent (null hors groupe)
 *   - `label_default` libellé dans `settings.default_language` (repli § 9), ou la clé
 *   - `choices`       liste de choix résolue (`Choice[]`) pour select_one / select_multiple / rank, sinon null
 *
 * `nodes()` renvoie la liste ordonnée de tous les nœuds (sections, groupes, questions, étapes) avec
 * leur définition, utilisée par le moteur et le validateur. Les compagnons `{key}_other` et
 * `{key}__codes` sont exposés par `companions()`.
 *
 * Distinct de `App\Support\QuestionIndexBuilder` (tâche B-01).
 */
final class QuestionCatalog
{
    /** @var array<string, array<string, mixed>> */
    private array $questions = [];

    /** @var array<int, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, int> clé → position dans `$nodes` */
    private array $nodeIndex = [];

    /** @var array<string, array{host: string, kind: string}> clé compagnon → hôte */
    private array $companions = [];

    /** @var array<string, string> clé hôte → clé compagnon `other` */
    private array $otherKeys = [];

    /** @var array<string, string> clé hôte → clé compagnon `postcode` */
    private array $postcodeKeys = [];

    /** @var string[] */
    private array $sectionKeys = [];

    /** @var string[] */
    private array $stageKeys = [];

    /** @var array<string, mixed> */
    private array $definition;

    private string $defaultLanguage;

    /**
     * @param  array<string, mixed>|stdClass  $definition  définition DFS (défauts appliqués ou non)
     */
    public static function fromDefinition(array|stdClass $definition): self
    {
        return new self(DfsDefaults::apply($definition));
    }

    /**
     * @param  array<string, mixed>  $definition  définition DFS avec défauts appliqués
     */
    public function __construct(array $definition)
    {
        $this->definition = $definition;
        $this->defaultLanguage = (string) ($definition['settings']['default_language'] ?? 'fr');

        foreach ($definition['sections'] ?? [] as $section) {
            if (! is_array($section) || ! isset($section['key'])) {
                continue;
            }
            $sectionKey = (string) $section['key'];
            $this->sectionKeys[] = $sectionKey;
            $childKeys = [];
            $sectionPos = $this->addNode(['kind' => 'section', 'key' => $sectionKey, 'type' => 'section', 'def' => $section, 'section' => $sectionKey, 'stage' => null, 'group' => null, 'parent' => null, 'repeat' => false, 'children' => []]);
            foreach ($section['items'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['key'])) {
                    continue;
                }
                if (($item['type'] ?? null) === 'group') {
                    $groupKey = (string) $item['key'];
                    $repeat = isset($item['repeat']) && is_array($item['repeat']);
                    $groupChildren = [];
                    $groupPos = $this->addNode(['kind' => 'group', 'key' => $groupKey, 'type' => 'group', 'def' => $item, 'section' => $sectionKey, 'stage' => null, 'group' => null, 'parent' => $sectionKey, 'repeat' => $repeat, 'children' => []]);
                    foreach ($item['items'] ?? [] as $child) {
                        if (! is_array($child) || ! isset($child['key'])) {
                            continue;
                        }
                        $this->addQuestion($child, $sectionKey, null, $groupKey, $repeat);
                        $groupChildren[] = (string) $child['key'];
                    }
                    $this->nodes[$groupPos]['children'] = $groupChildren;
                    $childKeys[] = $groupKey;
                } else {
                    $this->addQuestion($item, $sectionKey, null, null, false);
                    $childKeys[] = (string) $item['key'];
                }
            }
            $this->nodes[$sectionPos]['children'] = $childKeys;
        }

        foreach ($definition['follow_up_stages'] ?? [] as $stage) {
            if (! is_array($stage) || ! isset($stage['key'])) {
                continue;
            }
            $stageKey = (string) $stage['key'];
            $this->stageKeys[] = $stageKey;
            $childKeys = [];
            $stagePos = $this->addNode(['kind' => 'stage', 'key' => $stageKey, 'type' => 'stage', 'def' => $stage, 'section' => null, 'stage' => $stageKey, 'group' => null, 'parent' => null, 'repeat' => false, 'children' => []]);
            foreach ($stage['items'] ?? [] as $item) {
                if (! is_array($item) || ! isset($item['key'])) {
                    continue;
                }
                $this->addQuestion($item, null, $stageKey, null, false);
                $childKeys[] = (string) $item['key'];
            }
            $this->nodes[$stagePos]['children'] = $childKeys;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function addNode(array $node): int
    {
        $node['index'] = count($this->nodes);
        $this->nodes[] = $node;
        $this->nodeIndex[$node['key']] = $node['index'];

        return $node['index'];
    }

    /**
     * @param  array<string, mixed>  $q
     */
    private function addQuestion(array $q, ?string $section, ?string $stage, ?string $group, bool $repeat): void
    {
        $key = (string) $q['key'];
        $type = (string) ($q['type'] ?? '');
        $this->addNode(['kind' => 'question', 'key' => $key, 'type' => $type, 'def' => $q, 'section' => $section, 'stage' => $stage, 'group' => $group, 'parent' => $group ?? $section ?? $stage, 'repeat' => $repeat, 'children' => []]);

        $choices = null;
        if (in_array($type, ['select_one', 'select_multiple', 'rank'], true)) {
            $choices = $this->resolveChoices($q['choices'] ?? null);
        }
        $label = $q['label'] ?? null;
        $this->questions[$key] = [
            'type' => $type,
            'section' => $section,
            'stage' => $stage,
            'group' => $group,
            'label_default' => is_array($label) || is_string($label) ? LabelResolver::resolve($label, $this->defaultLanguage, $this->defaultLanguage, $key) : $key,
            'choices' => $choices,
        ];

        if (isset($q['other']) && is_array($q['other']) && in_array($type, ['select_one', 'select_multiple'], true)) {
            $otherKey = isset($q['other']['key']) && is_string($q['other']['key']) ? $q['other']['key'] : $key.'_other';
            $this->otherKeys[$key] = $otherKey;
            $this->companions[$otherKey] = ['host' => $key, 'kind' => 'other'];
        }
        if (isset($q['postcode']) && is_array($q['postcode']) && $type === 'text') {
            $codesKey = isset($q['postcode']['key']) && is_string($q['postcode']['key']) ? $q['postcode']['key'] : $key.'__codes';
            $this->postcodeKeys[$key] = $codesKey;
            $this->companions[$codesKey] = ['host' => $key, 'kind' => 'postcode'];
        }
    }

    /**
     * Résout une référence `choices` (nom de liste ou liste en ligne) en `Choice[]` (null si inconnue).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function resolveChoices(mixed $ref): ?array
    {
        if (is_string($ref)) {
            $list = $this->definition['choice_lists'][$ref] ?? null;

            return is_array($list) ? array_values($list) : null;
        }
        if (is_array($ref) && array_is_list($ref)) {
            return $ref;
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->questions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        return $this->questions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->questions[$key]);
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->questions);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function choicesFor(string $key): ?array
    {
        return $this->questions[$key]['choices'] ?? null;
    }

    /**
     * Choix résolus d'un post-codage (`postcode.choices`) pour la question hôte.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function postcodeChoicesFor(string $hostKey): ?array
    {
        $def = $this->node($hostKey)['def'] ?? null;

        return is_array($def) && isset($def['postcode']['choices']) ? $this->resolveChoices($def['postcode']['choices']) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function node(string $key): ?array
    {
        return isset($this->nodeIndex[$key]) ? $this->nodes[$this->nodeIndex[$key]] : null;
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodeIndex[$key]);
    }

    /**
     * @return string[]
     */
    public function sectionKeys(): array
    {
        return $this->sectionKeys;
    }

    /**
     * @return string[]
     */
    public function stageKeys(): array
    {
        return $this->stageKeys;
    }

    /**
     * @return array<string, array{host: string, kind: string}>
     */
    public function companions(): array
    {
        return $this->companions;
    }

    public function otherKeyOf(string $hostKey): ?string
    {
        return $this->otherKeys[$hostKey] ?? null;
    }

    public function postcodeKeyOf(string $hostKey): ?string
    {
        return $this->postcodeKeys[$hostKey] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->definition;
    }

    public function defaultLanguage(): string
    {
        return $this->defaultLanguage;
    }
}
