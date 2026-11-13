# API d'analyse native — phase 1 (socle)

Le calcul statistique se fait dans le navigateur. Le backend fournit deux choses : le **dictionnaire
de variables** d'une enquête et son **fichier SQLite**. Aucun appel LLM, PHP pur.

Toutes les routes sont sous `/api`, derrière `auth:sanctum` (jeton Bearer), comme `stats/*`.

| Route | Rôle requis | Réponse |
|---|---|---|
| `GET /surveys/{id}/analysis/dictionary` | superviseur du projet (`viewSubmissions`, comme `stats/*`) | JSON |
| `GET /surveys/{id}/analysis/dataset` | idem | SQLite binaire |
| `GET /target-dbs/{id}/analysis/dictionary` | propriétaire du fichier importé | JSON |

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

## Reste à faire (hors phase 1)

- Téléchargement du SQLite d'un fichier importé (les imports sont en mode WAL : il faut un point de
  contrôle et une copie avant de servir le fichier).
- Pondération, découverte automatique, verbatims natifs, barre de questions.
