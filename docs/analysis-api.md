# API d'analyse native — socle (phase 1), rigueur d'enquête (chantier 3), faits saillants (chantier 4), explorateur (chantier 5)

Le calcul statistique se fait dans le navigateur. Le backend fournit deux choses : le **dictionnaire
de variables** d'une enquête et son **fichier SQLite**. Aucun appel LLM, PHP pur.

Toutes les routes sont sous `/api`, derrière `auth:sanctum` (jeton Bearer), comme `stats/*`.

| Route | Rôle requis | Réponse |
|---|---|---|
| `GET /surveys/{id}/analysis/dictionary` | superviseur du projet (`viewSubmissions`, comme `stats/*`) | JSON |
| `GET /surveys/{id}/analysis/dataset` | idem | SQLite binaire |
| `GET /target-dbs/{id}/analysis/dictionary` | propriétaire du fichier importé | JSON |
| `GET /surveys/{id}/analysis/weighting` | superviseur | JSON |
| `PUT /surveys/{id}/analysis/weighting` | analyste du projet (`update`) | JSON |
| `GET /surveys/{id}/analysis/quotas` | superviseur | JSON |
| `GET /surveys/{id}/analysis/cohorts` | superviseur | JSON |
| `GET /surveys/{id}/analysis/highlights` | superviseur | JSON |
| `PUT /surveys/{id}/analysis/highlights` | analyste du projet (`update`) | JSON |
| `GET /surveys/{id}/analysis/segments`, `…/computed-variables` | superviseur | JSON |
| `POST`, `PUT …/{key}`, `DELETE …/{key}` sur ces deux ressources | analyste du projet (`update`) | JSON / `204` |

Erreurs communes : `401` sans jeton, `403` hors du projet ou rôle enquêteur, `404` enquête inconnue.

## 1. `GET /surveys/{id}/analysis/dictionary`

Paramètre facultatif : `lang` (ex. `?lang=en`) — même mécanisme que `stats/questions` : libellé dans
la langue demandée, sinon langue par défaut du questionnaire, sinon première traduction disponible.

La réponse suit l'enveloppe habituelle du module Enquêtes : l'objet du contrat est dans `data`.

```json
{
  "success": true,
  "data": {
    "dataset": {
      "survey_id": 12,
      "rows": 240,
      "materialized_at": "2026-11-13T09:41:07+00:00",
      "etag": "5f2c0a9d3b7e41c8a6d2e0f4b1c39a77"
    },
    "variables": [
      {
        "name": "region",
        "label": "Région",
        "level": "nominal",
        "dfs_type": "select_one",
        "table": "reponses",
        "column": "region",
        "choices": [{ "code": "nord", "label": "Nord" }, { "code": "sud", "label": "Sud" }],
        "multiple": false
      }
    ]
  }
}
```

### `dataset`

- `rows` : nombre de lignes de la table `reponses` (une par soumission non rejetée).
- `materialized_at` : date ISO 8601 de la dernière matérialisation.
- `etag` : valeur **sans guillemets** de l'en-tête `ETag` que renverra `analysis/dataset` pour ce
  même utilisateur. Sert de clé de cache côté navigateur.
- Enquête pas encore matérialisée : la route répond quand même `200`, avec `rows: 0`,
  `materialized_at: null`, `etag: null`, et les variables **prévues** (non vérifiées dans un fichier).

### `variables`

Les huit champs du contrat sont toujours présents, dans cet ordre. `choices` est toujours un tableau
(vide s'il n'y a pas de modalités déclarées). `multiple` vaut `true` si et seulement si
`level = "multiple"`.

| Type DFS | `level` | Contenu de `column` |
|---|---|---|
| `select_one` | `nominal` ; `ordinal` si échelle chiffrée (voir plus bas) | code du choix |
| `select_multiple` | `multiple` | codes séparés par `;` |
| `rank` | `multiple` | codes séparés par `;`, **dans l'ordre du classement** |
| `integer`, `decimal`, `currency` | `numeric` | nombre |
| `date` | `date` | `AAAA-MM-JJ` |
| `datetime` | `date` | ISO 8601 |
| `time` | `date` | `HH:MM` (heure seule : se fier à `dfs_type`) |
| `geopoint` | `geo` | latitude ; voir `columns` |
| `text` | `text` | texte libre |
| `calculate` | inféré sur les valeurs du fichier (`numeric`, `ordinal`, `nominal`, `date` ou `text`) ; `text` tant qu'il n'y a pas de fichier | valeur calculée |
| `photo`, `signature`, `audio`, `note`, `stop` | — | absents du dictionnaire |

Précisions :

- **`ordinal`** : le schéma DFS ne déclare pas l'ordinalité. Un `select_one` est dit ordinal quand il
  a au moins 3 choix dont les codes sont tous numériques et strictement croissants ou décroissants
  dans l'ordre du questionnaire (`1…5`, `0…10`). C'est une heuristique : l'interface doit permettre
  de corriger le niveau.
- **`geo`** : champ supplémentaire `columns: { "lat", "lng", "precision" }` (noms de colonnes) ;
  `column` répète la colonne de latitude.
- **Compagnons** d'une question, exposés comme variables à part entière :
  - `{clé}_other` (précision « autre ») → `text`, `dfs_type: "text"` ;
  - `{clé}__codes` (post-codage d'un texte) → `nominal` ou `multiple`, `dfs_type` `select_one` ou
    `select_multiple`, avec ses `choices` ;
  - `{clé}_themes` (`multiple`) et `{clé}_sentiment` (`nominal`) quand le verbatim a été codé,
    `dfs_type: "calculate"`.
- **Groupes répétés** : `table` vaut `rep_{groupe}` (une ligne par instance, jointure sur
  `submission_id`), pas `reponses`.
- **Questions d'étape de suivi** : `table` = `reponses`.
- **Métadonnées de soumission** : `dfs_type: "meta"`, `table: "reponses"`, noms préfixés par `_`
  pour ne pas entrer en collision avec une clé de question :

  | `name` | `column` | `level` |
  |---|---|---|
  | `_zone` | `zone` | nominal |
  | `_canal` | `canal` | nominal (choix `mobile`, `public`, `web`) |
  | `_statut` | `statut` | nominal |
  | `_enqueteur` | `enqueteur_id` | nominal (choix = identifiant → nom) |
  | `_langue` | `langue` | nominal |
  | `_version_formulaire` | `version_formulaire` | nominal |
  | `_motif_fin` | `motif_fin` | nominal |
  | `_debut`, `_fin`, `_recu_le` | `debut`, `fin`, `recu_le` | date (ISO 8601) |
  | `_duree_sec` | `duree_sec` | numeric |
  | `_score_suspicion` | `score_suspicion` | numeric |
  | `_position` | `lat` (+ `columns`) | geo |

- **Données personnelles** : les questions `tags: ["pii"]` ne figurent dans le dictionnaire que pour
  un analyste (droit d'export). Un superviseur ne les voit pas, et leurs colonnes sont vides dans
  son fichier.
- Quand un fichier existe, chaque variable est vérifiée dedans : une variable dont la colonne manque
  (fichier antérieur à une nouvelle version du questionnaire) est écartée.

## 2. `GET /surveys/{id}/analysis/dataset`

Renvoie le SQLite de l'enquête.

- `200` — `Content-Type: application/vnd.sqlite3`, `ETag: "<etag>"`, `Cache-Control: private, no-cache`.
- `304` sans corps si `If-None-Match` porte l'ETag courant (formes acceptées : `"etag"`, `W/"etag"`,
  `etag` nu, et le suffixe `-gzip` / `-br` qu'Apache ajoute aux réponses compressées).
- `409` tant que l'enquête n'est pas matérialisée :
  `{ "success": false, "message": "La source de données du questionnaire n'est pas encore prête : …", "code": "datasource_not_ready" }`
  (même code que `stats/crosstab`). Lancer `POST /surveys/{id}/datasource/rebuild`.

L'en-tête `ETag` est lisible depuis le navigateur (`exposed_headers` de la configuration CORS).

### Contenu du fichier

Même disposition que la source matérialisée (`reponses`, `reponses_long`, `rep_*`, `suivi`, `choix`,
`questions`, `enqueteurs`, `medias`, `_meta`), donc le dictionnaire s'applique tel quel. Le fichier
servi est une **copie expurgée**, pour ne rien exposer de plus que les exports CSV/XLSX :

| Donnée | Traitement |
|---|---|
| Table `medias` (chemins de stockage, empreintes) | vidée |
| `enqueteurs.email`, `enqueteurs.telephone` | mis à `NULL` (les exports ne portent que l'identifiant et le nom) |
| Colonnes des questions `pii` | conservées pour un analyste ; mises à `NULL` (et retirées de `reponses_long`) pour un superviseur |

`_meta` reçoit `analysis_variant` (`full` ou `masked`). L'ETag dépend de la variante : deux
utilisateurs de rôles différents n'ont pas le même.

La copie est construite au premier appel suivant une matérialisation (copie + `VACUUM`), puis
réutilisée ; ce premier appel est donc plus lent. Elle est stockée à côté du fichier d'origine
(`survey_{id}_v{n}.analysis-{etag}.sqlite`) et supprimée à la matérialisation suivante.

## 3. `GET /target-dbs/{id}/analysis/dictionary` (fichier importé)

Dictionnaire d'un fichier Excel/CSV importé. Même forme que ci-dessus, avec :

- `dataset.survey_id: null`, et en plus `target_database_id`, `name`, `tables: [{ name, rows }]` ;
  `rows` est le nombre de lignes de la plus grande table ;
- `dfs_type: null`, `label` = nom de colonne ; si le fichier a plusieurs tables, `name` vaut
  `table.colonne` ;
- `level` inféré par profilage des valeurs non vides (échantillon de 2 000) :
  1. au moins 95 % de dates (`AAAA-MM-JJ`, `JJ/MM/AAAA`, `AAAA/MM/JJ`, avec ou sans heure) → `date` ;
  2. uniquement des nombres : 2 valeurs distinctes au plus → `nominal` ; entiers, 7 valeurs
     distinctes au plus et répétées → `ordinal` ; sinon `numeric`. Un identifiant à zéro initial
     (`0612…`) n'est pas un nombre ;
  3. texte : 50 valeurs distinctes au plus, répétées et courtes → `nominal` ; sinon `text` ;
- `choices` liste les modalités observées (`code` = `label`) pour `nominal` et `ordinal`.

Erreurs : `404` si la base n'appartient pas à l'utilisateur ; `422` avec `code: "survey_datasource"`
(et `survey_id`) si la base est la source d'une enquête — passer par la route d'enquête, qui applique
le masquage ; `422` `not_an_imported_file` pour une connexion à une base externe.

Le téléchargement du fichier importé lui-même n'est pas encore exposé (voir « Reste à faire »).

## 4. Tableau croisé (`GET /surveys/{id}/stats/crosstab`)

Format inchangé, deux champs ajoutés :

- `p_value` est désormais exacte (fonction gamma incomplète régularisée) et arrondie à 6 chiffres
  **significatifs** : une association très forte donne par exemple `1.2e-15` au lieu de `0` ;
- `df` : degrés de liberté ;
- `cramers_v` : V de Cramér, entre 0 et 1 (`null` quand le khi² n'est pas défini).

## 5. `GET` / `PUT /surveys/{id}/analysis/weighting` — plan de pondération

Le serveur conserve et valide le plan ; **le calage sur marges (calcul des poids) se fait dans le
navigateur**, sur le SQLite téléchargé. Un seul plan par enquête ; `PUT` le remplace en entier.

```json
{
  "enabled": true,
  "margins": [
    { "variable": "sexe", "targets": [{ "code": "f", "share": 0.52 }, { "code": "h", "share": 0.48 }] }
  ],
  "bounds": { "min": 0.2, "max": 5 },
  "updated_at": "2026-11-14T05:12:44+00:00",
  "updated_by": { "id": 3, "name": "…" },
  "warnings": []
}
```

- Sans plan enregistré, `GET` renvoie `enabled: false`, `margins: []`, `bounds: { min: null, max: null }`.
- `margins[].variable` est le `name` d'une variable du dictionnaire, de niveau `nominal` ou `ordinal`,
  située dans la table `reponses` (une valeur par soumission) et non marquée `pii`. Au plus 10 marges,
  une seule par variable.
- `targets` : au moins deux `{ code, share }`. `share` peut être envoyé dans n'importe quelle unité
  positive (parts, pourcentages, effectifs de population) : le serveur le ramène à des parts de
  somme 1 et renvoie ces parts. Si la variable a des `choices`, chaque `code` doit en faire partie.
- `bounds` : bornage des poids, relatifs à un poids moyen de 1. `min` dans `]0, 1]`, `max` ≥ 1, ou
  `null` pour ne pas borner.
- `enabled` : facultatif en écriture (par défaut `true` s'il y a au moins une marge).
- `warnings` : marges devenues inapplicables (variable ou modalité disparue du questionnaire). Le
  plan est conservé tel quel ; le navigateur doit ignorer ces marges.
- `PUT` invalide → `422` `{ success: false, message, errors }`, les clés d'`errors` étant des chemins
  (`margins.0.variable`, `margins.1.targets.2.share`, `bounds.min`…). Rien n'est enregistré.
- Un superviseur peut lire le plan mais pas le modifier (`403`).

## 6. `GET /surveys/{id}/analysis/quotas` — avancement des quotas

Quotas déclarés dans `settings.quotas` du questionnaire publié, comptés sur les fiches valides (ni
rejetées ni hors cible) — mêmes chiffres que `stats/overview`.

```json
{
  "quotas": [
    {
      "key": "par_sexe", "label": "Fiches par sexe", "scope": "answer",
      "target": 3, "current": 3, "pct": 100.0, "max": false, "exceeded": false,
      "breakdown": [{ "value": "f", "current": 3, "target": 3 }, { "value": "h", "current": 1, "target": 3 }],
      "remaining": 0, "reached": true
    }
  ],
  "generated_at": "2026-11-14T05:12:44+00:00"
}
```

`scope` vaut `survey`, `zone`, `enumerator`, `answer` ou `distinct`. Pour `zone`, `enumerator` et
`answer`, `current` est l'effectif de la modalité la plus avancée et `breakdown` détaille chaque
modalité ; pour `distinct`, `current` est le nombre de valeurs distinctes. `remaining` et `reached`
portent sur `current` face à `target`.

## 7. `GET /surveys/{id}/analysis/cohorts` — rétention par vague de suivi

Paramètres : `by` = `day`, `week` (défaut) ou `month` ; `tz` (défaut : celui des stats) ; `lang` ;
et les filtres des stats (`from`, `to`, `enumerator_id`, `zone`, `status`). Sans filtre `status`, les
fiches rejetées sont exclues. `422` si `by` ou `tz` est invalide.

Une cohorte regroupe les soumissions **entrées dans le suivi** (au moins une étape planifiée) dont
l'entretien s'est terminé pendant la même période.

```json
{
  "by": "week",
  "tz": "Africa/Douala",
  "stages": [{ "key": "j4", "label": "Suivi à 4 jours", "due_offset_days": 4, "window_days": 3 }],
  "overall": {
    "base": 6,
    "stages": [{ "key": "j4", "planned": 6, "done": 4, "missed": 1, "skipped": 0, "pending": 1,
                 "retention": 0.6667, "response_rate": 0.8 }]
  },
  "cohorts": [
    { "period": "2026-W10", "start": "2026-03-02", "base": 4, "stages": [ … ] }
  ],
  "generated_at": "2026-11-14T05:12:44+00:00"
}
```

- `base` : nombre de soumissions de la cohorte.
- `planned` : entrées de suivi créées pour l'étape (une étape conditionnelle peut ne pas concerner
  toute la cohorte).
- `retention` = `done / base` ; `response_rate` = `done / (done + missed + skipped)`. `null` quand le
  dénominateur est nul.
- `period` : `AAAA-MM-JJ`, `AAAA-Wss` (semaine ISO, `start` = lundi) ou `AAAA-MM`.
- `stages` suit l'ordre du questionnaire ; chaque `stages` de cohorte est dans le même ordre.

Ces deux dernières routes lisent la base principale, pas le SQLite : elles fonctionnent sans
matérialisation.

## 8. `GET` / `PUT /surveys/{id}/analysis/highlights` — faits saillants

La découverte automatique (croisement de toutes les paires de variables, correction des comparaisons
multiples, classement par taille d'effet, rédaction par gabarits en français et en anglais) est
**calculée dans le navigateur**, par le même moteur que les autres analyses — c'est lui qui applique
la pondération. Le serveur **ne recalcule rien** : il enregistre le résultat pour que l'équipe voie
les mêmes faits sans refaire le calcul, que les rapports produits côté serveur puissent s'y appuyer,
et pour signaler un jeu périmé. Un seul jeu par enquête ; `PUT` le remplace en entier
(`items: []` l'efface).

```json
{
  "items": [
    {
      "variables": ["region", "satisfaction"],
      "kind": "association",
      "test": "chi2",
      "statistic": 18.42,
      "df": 4,
      "p_value": 0.00102,
      "p_adjusted": 0.0153,
      "effect": { "name": "cramers_v", "value": 0.31 },
      "n": 240,
      "text": { "fr": "La satisfaction varie nettement selon la région…", "en": "Satisfaction differs…" },
      "details": { "top_cell": { "row": "nord", "col": "5", "residual": 2.9 } }
    }
  ],
  "dataset_materialized_at": "2026-11-13T09:41:07+00:00",
  "current_materialized_at": "2026-11-13T09:41:07+00:00",
  "stale": false,
  "weighted": true,
  "engine": "datamuse-web/1.0",
  "params": { "alpha": 0.05, "correction": "benjamini-hochberg", "pairs_tested": 153 },
  "computed_at": "2026-11-14T06:02:10+00:00",
  "updated_by": { "id": 3, "name": "…" }
}
```

Corps du `PUT` : `items` (obligatoire), `dataset_materialized_at`, `weighted`, `engine`, `params`.
Les autres champs sont calculés par le serveur.

- **Ordre** : `items` est conservé dans l'ordre reçu ; c'est le classement (par taille d'effet).
  Au plus 200 faits.
- **`variables`** : 1 à 3 `name` du dictionnaire. Une variable `pii` est refusée, même venant d'un
  analyste, parce que le jeu est lu par tous les superviseurs.
- **`text`** : obligatoire dans au moins une langue (`fr`, `en`), 1 000 caractères au plus chacune.
- **`kind`, `test`, `effect.name`** : identifiants libres de 40 caractères au plus (lettres, chiffres,
  `_`, `-`, `.`) — le serveur n'en impose pas la liste, c'est le moteur du navigateur qui la définit.
- **`statistic`, `df`, `n`, `effect.value`** : nombres JSON ou `null` ; `p_value`, `p_adjusted` dans
  `[0, 1]` ou `null`. `effect` vaut `{ name, value }` ou `null`.
- **`details`** (par fait) et **`params`** : objets libres, 8 Ko au plus chacun, renvoyés tels quels.
- **`dataset_materialized_at`** : recopier `dataset.materialized_at` du dictionnaire utilisé pour le
  calcul. `stale` vaut `true` quand l'enquête a été rematérialisée depuis (ou quand cette date n'a
  pas été fournie alors qu'un fichier existe). Un jeu périmé reste servi : à l'interface de proposer
  de le recalculer.
- En lecture, un fait dont une variable a quitté le dictionnaire (nouvelle version du questionnaire,
  question devenue `pii`) n'est plus renvoyé.
- `PUT` invalide → `422`, `errors` indexé par chemin (`items.0.variables.1`, `items.3.text`…). Rien
  n'est enregistré. Un superviseur ne peut pas écrire (`403`).

## 9. Segments enregistrés et variables calculées (explorateur sans code)

Deux ressources de même forme :

- `…/analysis/segments` — un **segment** est une expression booléenne ; une soumission lui appartient
  quand elle est vraie ;
- `…/analysis/computed-variables` — une **variable calculée** produit une valeur par soumission, avec
  un niveau de mesure déclaré (`level`).

Les expressions sont écrites dans le **langage Logic** du DFS (mêmes opérateurs et arités que dans un
questionnaire : `==`, `!=`, `<`, `<=`, `>`, `>=`, `and`, `or`, `!`, `if`, `in`, `selected`,
`count_selected`, `answered`, `empty`, `coalesce`, `concat`, `+`, `-`, `*`, `/`, `%`, `regex`,
`length`, `today`, `now`, `date_diff`). `{"var": "<nom>"}` désigne une variable du dictionnaire par
son `name` (métadonnées `_zone`, `_canal`… comprises) ; sa valeur est celle de la colonne SQLite
(le code pour un choix, la liste des codes pour un niveau `multiple`).

**L'évaluation ligne à ligne se fait dans le navigateur.** Le serveur conserve les définitions et en
garantit la validité.

| Méthode | Route | Réponse |
|---|---|---|
| `GET` | `…/analysis/segments` | liste, triée par libellé |
| `POST` | `…/analysis/segments` | `201` + la définition |
| `PUT` | `…/analysis/segments/{key}` | la définition remplacée |
| `DELETE` | `…/analysis/segments/{key}` | `204` |

(idem avec `computed-variables`.)

```json
{
  "key": "tranche_age",
  "label": "Tranche d'âge",
  "description": null,
  "level": "ordinal",
  "expression": { "if": [{ "<": [{ "var": "age" }, 30] }, "jeune", "senior"] },
  "references": ["age"],
  "missing": [],
  "valid": true,
  "updated_at": "2026-11-14T08:40:12+00:00",
  "updated_by": { "id": 3, "name": "…" }
}
```

- Corps de `POST` / `PUT` : `label` (obligatoire, 120 caractères), `expression` (obligatoire),
  `description` (500 caractères), `level` (variables calculées seulement, obligatoire : `nominal`,
  `ordinal`, `numeric`, `date` ou `text`), `key` (à la création seulement).
- `key` : minuscule puis minuscules, chiffres, `_` (40 caractères). Omise, elle est dérivée du
  libellé. Elle n'est pas modifiable ; la clé d'une variable calculée ne peut pas être le nom d'une
  variable du dictionnaire. Un segment n'a pas de champ `level`.
- Variables citables : celles du dictionnaire **hors `pii`** (les définitions sont partagées avec tous
  les superviseurs). Un segment peut en plus citer une variable calculée par sa clé ; une variable
  calculée ne peut pas en citer une autre (pas de chaîne de calculs, donc pas de cycle).
- `references` liste les variables citées ; `missing` celles qui n'existent plus (nouvelle version du
  questionnaire, question devenue `pii`) ; `valid` vaut `false` dans ce cas. La définition est
  conservée : à l'interface de la signaler et de ne pas l'appliquer.
- Supprimer une variable calculée encore citée par un segment → `409`
  `{ code: "variable_in_use", segments: [clés] }`.
- Invalide → `422`, `errors` indexé par chemin dans l'expression (`expression.==.0.var`,
  `expression.and.1`, `label`, `key`, `level`). Au plus 100 définitions par ressource et par enquête ;
  expression de 16 Ko et 32 niveaux au plus.

Le **tableau croisé dynamique** n'a pas de route : il se calcule dans le navigateur. La **barre de
questions à grammaire fixe** n'est pas faite (voir « Reste à faire »).

## Mise en production des chantiers 3 à 5

Trois tables sont ajoutées (migrations `2026_11_14_000038_create_survey_weighting_plans_table`,
`2026_11_14_000039_create_survey_analysis_highlights_table` et
`2026_11_14_000040_create_survey_analysis_definitions_table`). Sur
l'hébergement mutualisé, où `artisan migrate` n'est pas disponible, l'exécuter une fois dans
phpMyAdmin **avant** de déployer le code, les exécuter une fois :

```sql
CREATE TABLE `survey_weighting_plans` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `margins` json NOT NULL,
  `min_weight` decimal(8,4) DEFAULT NULL,
  `max_weight` decimal(8,4) DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `survey_weighting_plans_survey_id_unique` (`survey_id`),
  KEY `survey_weighting_plans_updated_by_foreign` (`updated_by`),
  CONSTRAINT `survey_weighting_plans_survey_id_foreign` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `survey_weighting_plans_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_14_000038_create_survey_weighting_plans_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

CREATE TABLE `survey_analysis_highlights` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` bigint unsigned NOT NULL,
  `dataset_materialized_at` datetime DEFAULT NULL,
  `weighted` tinyint(1) NOT NULL DEFAULT 0,
  `engine` varchar(60) DEFAULT NULL,
  `params` json DEFAULT NULL,
  `items` json NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `survey_analysis_highlights_survey_id_unique` (`survey_id`),
  KEY `survey_analysis_highlights_updated_by_foreign` (`updated_by`),
  CONSTRAINT `survey_analysis_highlights_survey_id_foreign` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `survey_analysis_highlights_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_14_000039_create_survey_analysis_highlights_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

CREATE TABLE `survey_analysis_definitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `survey_id` bigint unsigned NOT NULL,
  `kind` varchar(20) NOT NULL,
  `key` varchar(40) NOT NULL,
  `label` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `level` varchar(20) DEFAULT NULL,
  `expression` json NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `survey_analysis_definitions_survey_id_kind_key_unique` (`survey_id`,`kind`,`key`),
  KEY `survey_analysis_definitions_created_by_foreign` (`created_by`),
  KEY `survey_analysis_definitions_updated_by_foreign` (`updated_by`),
  CONSTRAINT `survey_analysis_definitions_survey_id_foreign` FOREIGN KEY (`survey_id`) REFERENCES `surveys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `survey_analysis_definitions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `survey_analysis_definitions_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_14_000040_create_survey_analysis_definitions_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
```

`deploy/datamuse-install.sql` (installation à neuf) n'a pas été régénéré : il ne contient pas encore
ces tables.

## Reste à faire

- Marges d'erreur et intervalles de confiance : calculés dans le navigateur à partir du SQLite et du
  plan de pondération ; aucune route serveur.
- Téléchargement du SQLite d'un fichier importé (les imports sont en mode WAL : il faut un point de
  contrôle et une copie avant de servir le fichier).
- Brancher les faits saillants enregistrés sur la synthèse et les rapports (aujourd'hui alimentés
  par `SurveyInsightContext`) : à faire avec les rapports natifs (chantier 7).
- Barre de questions à grammaire fixe (fin du chantier 5, phase 4 du plan) : non faite. Il reste à
  décider où elle vit — dans le navigateur, hors ligne, ou côté serveur avant l'appel au LLM de
  `/chat/generate-sql`.
- Verbatims natifs et rapports natifs (chantiers 6 et 7).
