<?php

namespace App\Services\Survey;

/**
 * Version condensée de `docs/dfs/README.md` destinée aux prompts IA (`{dfs_guide}` des prompts système
 * `form_generation` et `form_repair`).
 *
 * Ce n'est **pas** la spécification : `docs/dfs/README.md` et `docs/dfs/dfs-v1.schema.json` font foi ;
 * ce guide en reprend uniquement ce qu'un modèle doit savoir pour produire un document valide du premier
 * coup (structure, types, opérateurs Logic, pièges). Toute évolution du schéma doit être répercutée ici.
 *
 * Le texte est volontairement compact (≈ 6 000 caractères) : il est envoyé à chaque appel.
 */
final class DfsPromptGuide
{
    /** Opérateurs Logic autorisés (README § 16.3), repris tels quels dans le guide. */
    public const OPERATORS = [
        'var', '==', '!=', '<', '<=', '>', '>=', 'and', 'or', '!', 'if', 'in', 'selected', 'count_selected',
        'answered', 'empty', 'coalesce', 'concat', '+', '-', '*', '/', '%', 'regex', 'length', 'today', 'now', 'date_diff',
    ];

    /** Variables système exposées aux expressions (README § 16.5). */
    public const SYSTEM_VARS = ['_status', '_start_time', '_end_time', '_enumerator', '_device', '_zone', '_lang', '_repeat_index', '_seq'];

    /** Types de question (README § 3). */
    public const TYPES = [
        'select_one', 'select_multiple', 'rank', 'text', 'integer', 'decimal', 'currency', 'date', 'time',
        'datetime', 'geopoint', 'photo', 'signature', 'audio', 'note', 'calculate', 'stop',
    ];

    private static ?string $cache = null;

    /**
     * Guide complet (mis en cache pour la durée du processus).
     */
    public static function text(): string
    {
        return self::$cache ??= self::build();
    }

    private static function build(): string
    {
        $operators = implode(' ', array_map(static fn (string $o): string => '`'.$o.'`', self::OPERATORS));
        $systemVars = implode(', ', array_map(static fn (string $v): string => '`'.$v.'`', self::SYSTEM_VARS));
        $types = implode(', ', array_map(static fn (string $t): string => '`'.$t.'`', self::TYPES));

        return <<<GUIDE
        # Datamuse Form Schema v1 (DFS v1) — guide de production

        Un questionnaire DFS est **un seul objet JSON UTF-8**. Toute propriété non listée ici est interdite
        (`additionalProperties: false` partout) : n'invente aucun champ.

        ## 1. Racine
        ```
        {
          "dfs_version": "1.0",
          "id": "<uuid>",            // imposé par le serveur, mets un uuid quelconque
          "version": 1,              // imposé par le serveur
          "title": {"fr": "…"},      // I18n
          "description": {"fr": "…"},// facultatif
          "settings": { … },
          "choice_lists": { "nom_liste": [Choice, …] },   // peut être {}
          "sections": [Section, …],                        // au moins 1 section, chacune avec au moins 1 item
          "follow_up_stages": [Stage, …]                   // peut être []
        }
        ```

        ## 2. Conventions
        - **Clés** (`key`) : `^[A-Za-z][A-Za-z0-9_]{0,39}\$`, **snake_case**, ≤ 40 caractères, **uniques dans tout
          le document** (sections, groupes, questions, questions d'étapes et étapes partagent un seul espace de noms).
          Préfère des clés parlantes tirées du questionnaire (`accepte_participer`, `nb_enfants`, `montant_acompte`)
          plutôt que `Q1`. Les suffixes `_other` et `__codes` sont **réservés** aux réponses compagnons.
        - **I18n** : tout texte est un objet `{"fr": "…"}`. La langue par défaut **doit** être présente dans chaque
          texte ; les autres langues demandées sont ajoutées si tu peux traduire, sinon omises.
        - **Codes de choix** : `^[A-Za-z0-9][A-Za-z0-9_]{0,59}\$`, uniques dans leur liste, jamais traduits
          (`oui`, `non`, `ne_sait_pas`, `autre`).
        - **Expressions** : AST JSON (jamais de texte à parser), voir § 6.

        ## 3. Settings
        ```
        "settings": {
          "languages": ["fr"],               // requis
          "default_language": "fr",          // requis, doit appartenir à languages
          "fiche_code": {"pattern": "{VILLE}-{QUARTIER}-{NN}",
                          "sources": {"VILLE": "ville", "QUARTIER": "quartier"},
                          "counter": {"width": 2, "scope": "enumerator", "start": 1}},
          "quotas": [{"key": "valides", "label": {"fr": "Fiches valides"}, "target": 30,
                       "scope": "survey|zone|enumerator|answer|distinct", "question": "reseau", "max": false}],
          "kpis": [{"key": "taux_acompte", "label": {"fr": "Taux d'acompte"},
                     "numerator": <Expr>, "denominator": "valid"|"all"|<Expr>, "format": "percent"}],
          "geo": {"capture": "none|start|end|both", "required": false, "accuracy_max_m": 50},
          "timing": {"min_duration_seconds": 600, "max_duration_seconds": 1080},
          "duplicate_keys": ["whatsapp"],
          "pagination": "section"|"question",
          "allow_public_link": false,
          "enumerator_can_edit_after_submit": false,
          "followup_contact_keys": ["whatsapp"]
        }
        ```
        Un jeton `{CLE}` du `pattern` doit avoir une entrée dans `sources` pointant vers une question existante
        (`select_one`, `text` ou `calculate`). `{NN}` est le compteur.

        ## 4. Conteneurs
        - **Section** : `{"key", "label", "description"?, "relevant"?, "items": [Question|Group, …]}` — une page.
        - **Group** : `{"type": "group", "key", "label", "description"?, "relevant"?, "repeat": {"min": 0, "max": 10}?,
          "appearance": "list"|"matrix", "items": [Question, …]}` — **jamais imbriqué**. `matrix` = grille :
          toutes les questions doivent être des `select_one` partageant la même liste.
        - **Stage** (étape de suivi J+n) : `{"key", "label", "description"?, "due_offset_days": 7, "window_days": 3,
          "channel": "whatsapp"|"sms"|"call"|"visit", "relevant"?, "items": [Question, …]}` — `stop` interdit.

        ## 5. Questions
        Champs communs des types de saisie : `key`, `type`, `label`, `hint`?, `required` (bool ou Expr, défaut
        `false`), `required_message`?, `relevant`? (Expr ; absent = toujours visible), `constraint`? (Expr),
        `constraint_message`?, `read_only`?, `default`? (Expr), `appearance`?, `tags`? (`["pii"]` pour une donnée
        personnelle — téléphone, nom, adresse ; `["enumerator_only"]` pour une observation enquêteur).

        Types disponibles : {$types}.

        | Type | Champs propres |
        |---|---|
        | `select_one` | `choices` (nom de liste ou liste en ligne), `other`?, `appearance: radio / dropdown / buttons` |
        | `select_multiple` | `choices`, `other`?, `min_selected`?, `max_selected`?, `exclusive`? (codes qui désélectionnent les autres, ex. `["aucune"]`), `appearance: checkbox / chips` |
        | `rank` | `choices`, `min_ranked`?, `max_ranked`? |
        | `text` | `appearance: short / multiline`, `format: text / phone / email / url`, `min_length`?, `max_length`?, `postcode`? |
        | `integer` / `decimal` | `min`?, `max`?, (`decimals` pour `decimal`) |
        | `currency` | `currency` (ISO 4217, défaut `XAF` = FCFA), `decimals` (défaut 0), `min`?, `max`? — montant stocké sans séparateur (`20000`) |
        | `date` / `time` / `datetime` | `min`/`max` littéraux (`"2026-09-15"`, `"09:40:00"`, ISO-8601) |
        | `geopoint` | `accuracy_max_m`?, `allow_manual`? |
        | `photo` / `signature` / `audio` | `source: camera / gallery`, `max_duration_seconds`? |
        | `note` | `audience: enumerator / respondent / both`, `style: info / warning / script` — **pas** de `required`, ni `hint` |
        | `calculate` | `expression` (Expr), `label`? |
        | `stop` | `label`, `message` (I18n), `relevant` (**obligatoire**) |

        - **« Autre : précisez »** → `"other": {"choice": "autre", "label": {"fr": "Précisez"}, "required": true}`
          sur la question ; le code `autre` **doit** exister dans la liste. La réponse compagnon est `{key}_other`
          (ne la déclare **pas** comme question).
        - **Post-codage** (thèmes cochés par l'enquêteur après un verbatim, sans les lire) →
          `"postcode": {"choices": "themes_refus", "multiple": true}` sur la question `text`. Compagnon `{key}__codes`.
        - **Consigne enquêteur** (« ne pas lire », « noter le silence ») → `note` `audience: "enumerator"`,
          `style: "info"` ou `"warning"`.
        - **Script à lire tel quel** → `note` `audience: "respondent"`, `style: "script"`, le texte **mot pour mot**.
        - **Filtre STOP** (« Si non → arrêter l'entretien ») → item `stop` placé **juste après** la question filtrante,
          avec `relevant` = la condition d'arrêt et `message` = la consigne de sortie (remercier, partir).
        - **Verbatim mot pour mot** → `text` `appearance: "multiline"`, `hint` rappelant de noter les mots exacts.
        - **Montants en FCFA** → `currency` `"currency": "XAF"`, `"decimals": 0`.

        ## 6. Logic (expressions)
        Une expression est un littéral (`"oui"`, `4000`, `true`, `null`), une liste, ou un objet à **une seule clé**
        `{"op": [args]}`. Opérateurs autorisés (aucun autre) :

        {$operators}

        Exemples : `{"==": [{"var": "accepte"}, "non"]}` · `{"and": [{"==": [{"var": "d2"}, "oui"]}, {"selected": [{"var": "signaux"}, "nounou"]}]}`
        · `{"count_selected": [{"var": "signaux"}]}` · `{">=": [{"var": "nb_enfants"}, 1]}`
        · `{"date_diff": [{"today": []}, {"var": "naissance"}, "years"]}`.

        Règles : `var` prend une chaîne (clé de question, `G.0.enfant` dans un groupe répété, ou variable système).
        Variables système : {$systemVars}. Une clé inconnue vaut `null` ; comparer avec `null` donne `false`.
        `selected(reponse, code)` teste un `select_multiple` **ou** un `select_one`. Ne référence **que des questions
        déjà déclarées plus haut** dans le document.

        ## 7. Choix
        ```
        "choice_lists": {
          "oui_non": [{"name": "oui", "label": {"fr": "Oui"}}, {"name": "non", "label": {"fr": "Non"}}],
          "villes":  [{"name": "douala", "label": {"fr": "Douala"}, "abbr": "DLA"}],
          "quartiers": [{"name": "akwa", "label": {"fr": "Akwa"}, "abbr": "AKW", "filter": {"ville": "douala"}}]
        }
        ```
        `abbr` (1 à 6 majuscules/chiffres) sert au code fiche ; `filter` réalise les listes en cascade.
        Mutualise les listes réutilisées (`oui_non`) plutôt que de les répéter en ligne.

        ## 8. Étapes de suivi
        Un suivi « J+4 / J+7 / J+14 » devient trois `follow_up_stages` avec `due_offset_days` 4, 7 et 14,
        `window_days` (défaut 3), `channel` adapté (relance WhatsApp → `whatsapp`, appel → `call`) et un
        `relevant` qui restreint le suivi aux fiches concernées (ex. celles ayant versé un acompte). Les items
        d'une étape sont des questions normales (jamais de `stop`) et peuvent lire les réponses de base.

        ## 9. Pièges fréquents (à éviter absolument)
        1. Un `stop` **sans** `relevant`, ou placé dans une étape de suivi / un groupe répété.
        2. Une expression sous forme de texte (`"\${d2} = 'oui'"`) au lieu d'un AST.
        3. Une clé dupliquée, ou une question nommée `xxx_other` / `xxx__codes`.
        4. `choices` pointant vers une liste absente de `choice_lists`.
        5. Un `I18n` sans la langue par défaut, ou un label sous forme de chaîne au lieu d'un objet.
        6. Une section vide (`items: []`) ou `sections: []`.
        7. `other.choice` absent de la liste de choix de la question.
        8. `required` sur une `note`, `hint` sur un `stop`, `choices` sur un `text`.
        GUIDE;
    }
}
