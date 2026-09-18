<?php

namespace App\Services\Survey;

/**
 * Filtrage d'une définition DFS avant exposition à un lien public (B-12, schéma `PublicSurvey`).
 *
 * Sont retirés :
 *  - les questions portant `tags: ["pii"]` (téléphone, nom de compte…) ;
 *  - les questions portant `tags: ["enumerator_only"]` (observations d'enquêteur) ;
 *  - les `note` d'audience `enumerator` (consignes de passation) ;
 *  - les groupes devenus vides, puis les sections devenues vides ;
 *  - les étapes de suivi (`follow_up_stages` → `[]`) : un répondant anonyme n'est jamais relancé ;
 *  - les réglages purement serveur de `settings` (`SERVER_SETTINGS`) : quotas, KPI, clés de doublon et
 *    de recontact citent des clés de questions retirées — inutiles au client et inutilement bavards.
 *
 * Les listes de choix sont conservées telles quelles (elles sont partagées). Une expression
 * (`relevant`, `constraint`, `calculate`) qui référençait une clé retirée s'évalue désormais à `null`,
 * donc à `false` pour `relevant` / `required` (README § 16.6) : la question dépendante disparaît de
 * l'écran public au lieu de faire échouer le moteur.
 */
class PublicDefinitionFilter
{
    /** Tags qui excluent une question du formulaire public. */
    public const EXCLUDED_TAGS = ['pii', 'enumerator_only'];

    /** Réglages retirés de `settings` avant exposition publique. */
    public const SERVER_SETTINGS = ['quotas', 'kpis', 'duplicate_keys', 'followup_contact_keys'];

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function apply(array $definition): array
    {
        $sections = [];
        foreach ($definition['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }
            $items = $this->filterItems($section['items'] ?? []);
            if ($items === []) {
                continue;
            }
            $section['items'] = $items;
            $sections[] = $section;
        }

        $definition['sections'] = $sections;
        $definition['follow_up_stages'] = [];

        if (is_array($definition['settings'] ?? null)) {
            foreach (self::SERVER_SETTINGS as $key) {
                unset($definition['settings'][$key]);
            }
        }

        return $definition;
    }

    /**
     * Empreinte servie au client : sha256 du **texte JSON filtré**, calculé comme à la publication
     * (README § 15 : `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, ordre des clés conservé).
     *
     * @param  array<string, mixed>  $definition
     */
    public static function hash(array $definition): string
    {
        return hash('sha256', (string) json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) === 'group') {
                $children = $this->filterItems($item['items'] ?? []);
                if ($children === []) {
                    continue;
                }
                $item['items'] = $children;
                $out[] = $item;

                continue;
            }
            if ($this->isHidden($item)) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isHidden(array $item): bool
    {
        foreach (self::EXCLUDED_TAGS as $tag) {
            if (ReponsesLayout::isPii($item, $tag)) {
                return true;
            }
        }

        return ($item['type'] ?? null) === 'note' && ($item['audience'] ?? 'both') === 'enumerator';
    }
}
