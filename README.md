# Datamuse — API Laravel

API du projet **Datamuse** : analyse de données par IA (chat SQL, profilage, export) **et** module
**Enquêtes** (questionnaires versionnés, collecte mobile hors ligne, liens publics, statistiques,
supervision terrain, verbatims, synthèse et rapports générés par IA).

- Laravel 12 · PHP 8.2 · Sanctum (jetons sans expiration) · SQLite en développement, MySQL/PostgreSQL en
  production.
- Enveloppe des nouveaux endpoints : `{success, data, meta?}` / `{success: false, message, errors?}`.
- Contrat de référence : [`../docs/openapi/survey.yaml`](../docs/openapi/survey.yaml) (OpenAPI 3.1) et
  [`../docs/dfs/README.md`](../docs/dfs/README.md) (schéma de questionnaire « DFS v1 », normatif).
- Avancement et écarts assumés : [`../docs/AVANCEMENT.md`](../docs/AVANCEMENT.md).

---

## 1. Démarrage rapide

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Base SQLite de développement
touch database/database.sqlite          # Windows : type nul > database\database.sqlite
php artisan migrate:fresh --seed        # crée aussi la démonstration MunaGo (§ 3)

php artisan serve --host=0.0.0.0        # http://localhost:8000/api
```

Dans un **second** terminal, le worker de file (indispensable : génération IA, matérialisation des
sources de données, rapports) :

```bash
php artisan queue:work --tries=1 --timeout=600
```

Et, si l'on veut les tâches planifiées (reconstruction des sources `dirty`, suivis manqués) :

```bash
php artisan schedule:work
```

`composer dev` lance en une commande le serveur, la file, les logs (`pail`) et Vite.

### Variables d'environnement utiles

| Variable | Rôle |
|---|---|
| `FRONTEND_URL`, `CORS_EXTRA_ORIGINS` | origines autorisées (`config/cors.php`) |
| `GEMINI_API_KEY`, `DEEPSEEK_API_KEY` | clés IA de repli (chaque utilisateur peut enregistrer les siennes, chiffrées) |
| `GEMINI_MODEL`, `DEEPSEEK_MODEL` | modèles utilisés (`config/services.php`) |
| `SURVEY_MEDIA_DISK`, `SURVEY_MEDIA_MAX_MB` | disque **privé** des médias de collecte, plafond (25 Mo) |
| `SURVEY_DATASOURCE_DIR` | répertoire des SQLite matérialisés (défaut : `storage/app/imported_databases`) |
| `SURVEY_EXPORT_MAX_ROWS` | plafond de l'export CSV/XLSX synchrone (50 000) |
| `QUEUE_CONNECTION` | `database` en développement comme en production |

Déploiement : voir [`../DEPLOYMENT_CHECKLIST.md`](../DEPLOYMENT_CHECKLIST.md), notamment la section
« Module Enquêtes — file, planificateur, médias, IA ».

---

## 2. Le module Enquêtes en bref

```
Builder web (Next.js)  ──DFS JSON──▶  API Laravel  ──manifest + DFS──▶  Flutter « Terrain » (hors ligne)
      ▲  stats, IA                    /api/surveys                       soumissions + médias
      │                               /api/mobile   ◀────────────────────
      │                               /api/public   ◀── lien public web
      │                               jobs (queue)
      └───────────────  TargetDatabase SQLite (1 par questionnaire)  ◀─ SurveyMaterializationService
                                     │
              chat SQL · profilage · analyse IA · export Excel (code existant, inchangé)
```

**Un questionnaire** est un document JSON « DFS v1 » versionné : `draft` → `published` (immuable, une
seule à la fois) → `archived`. Les clés de questions ne sont jamais renommées après publication (le
validateur le refuse). Chaque soumission est liée à sa `survey_version_id`.

**Trois implémentations du même moteur** (PHP `app/Services/Dfs/`, TypeScript `frontend/src/lib/dfs/`,
Dart `mobile/lib/dfs/`) sont validées par les **mêmes** vecteurs : `../docs/dfs/logic-tests.json`
(289 cas) et `../docs/dfs/engine-tests.json` (44 scénarios).

### Couches principales

| Dossier | Contenu |
|---|---|
| `app/Services/Dfs/` | `DfsValidator` (JSON Schema + règles sémantiques), `LogicEvaluator`, `FormEngine`, `FicheCodeGenerator`, `QuestionCatalog`, `LabelResolver` |
| `app/Services/Survey/` | versions et publication, synchronisation mobile, qualité des fiches, suivis longitudinaux, matérialisation SQLite, statistiques, tableaux croisés, XLSForm, IA (formulaire, verbatims, synthèse, rapports) |
| `app/Http/Controllers/Survey/` | endpoints web (projets, questionnaires, soumissions, stats, supervision, datasource, verbatims, rapports, liens publics) |
| `app/Http/Controllers/Mobile/` | endpoints de l'application terrain (`/api/mobile/*`) |
| `app/Http/Controllers/Public/` | collecte publique sans authentification (`/api/public/*`) |
| `app/Jobs/` | traitements asynchrones ; tout renvoie `202 {job_id}`, suivi par `GET /api/jobs/{uuid}` |
| `app/Policies/` | rôles projet `enqueteur < superviseur < analyste` (+ admin global), trait `ChecksProjectRole` |

### Rôles et débit

| Limiteur | Portée | Quota |
|---|---|---|
| `throttle:api` | endpoints web authentifiés | 60/min/utilisateur |
| `throttle:mobile` | `/api/mobile/*` | 600/min/utilisateur |
| `throttle:ai` | opérations IA (génération, traduction, classification, synthèse, rapport) | 5/min/utilisateur |
| `throttle:public-read` / `public-write` | lien public | 30/min et 10/min par IP |

### Confidentialité

- Les questions marquées `tags: ["pii"]` sont retirées de la définition servie au lien public, exclues des
  statistiques, des verbatims envoyés à l'IA et du contexte des rapports ; l'export CSV/XLSX les inclut
  seulement sur demande explicite d'un analyste (`include_pii`).
- Médias de collecte et rapports archivés : disque **privé**, servis uniquement par URL signée
  temporaire (15 min). `php artisan storage:link` est inutile.

---

## 3. Comptes et données de démonstration

`php artisan migrate:fresh --seed` enchaîne trois seeders :

| Seeder | Contenu |
|---|---|
| `SystemPromptSeeder` | prompts IA en base (chat existant + module Enquêtes). **Obligatoire.** |
| `SurveyRolesSeeder` | comptes `admin@datamuse.local` et `analyste@datamuse.local` |
| `SurveyDemoSeeder` | démonstration MunaGo complète (idempotent) |

**Mot de passe de tous les comptes : `password`.**

| Compte | Rôle |
|---|---|
| `admin@datamuse.local` | administrateur (accès global) |
| `analyste@datamuse.local` | analyste, propriétaire du projet « MunaGo Douala » |
| `enq1@test.local` | enquêteur, zone Bonamoussadi |
| `enq2@test.local` | enquêteur, zone Akwa |
| `enq3@test.local` | enquêteur, zone Makepe |

`SurveyDemoSeeder` crée, depuis `../docs/fixtures/` :

- le projet **MunaGo Douala** et le questionnaire **MunaGo — étude de marché terrain** publié en v1
  (96 items, 3 étapes de suivi J+4 / J+7 / J+14) ;
- **60 soumissions** (52 valides, 8 hors cible, dont fiches trop rapides et doublon) et 12 entrées de
  suivi ;
- un **lien public actif** : `{FRONTEND_URL}/s/munago-demo` ;
- la **source de données matérialisée** (SQLite), immédiatement interrogeable par le chat SQL.

Le seeder est idempotent : le relancer ne duplique ni le projet, ni les fiches, ni le lien. Il est ignoré
sans erreur si `docs/fixtures/` est absent (déploiement du seul dossier `backend/`).

```bash
# Démonstration seule (sur une base déjà migrée)
php artisan db:seed --class=SurveyDemoSeeder

# Prompts seuls (à rejouer après chaque déploiement modifiant un prompt)
php artisan db:seed --class=SystemPromptSeeder
```

---

## 4. Commandes

### Artisan (module Enquêtes)

| Commande | Planifiée | Rôle |
|---|---|---|
| `php artisan surveys:materialize-dirty [--sync]` | toutes les 5 min | reconstruit les sources de données marquées `dirty` |
| `php artisan follow-ups:mark-missed` | horaire | passe en `missed` les suivis hors fenêtre |
| `php artisan datasources:cleanup-old-files` | quotidien | supprime les anciens fichiers SQLite versionnés |
| `php artisan schedule:list` | — | vérifie l'enregistrement des trois tâches |
| `php artisan queue:work --tries=1 --timeout=600` | — | worker de file (obligatoire en exécution réelle) |
| `php artisan queue:failed` / `queue:retry all` | — | diagnostic des jobs en échec |

### Développement

```bash
composer dev            # serveur + file + logs + Vite
php artisan pail        # journal en direct
vendor/bin/pint         # formatage (Laravel Pint)
vendor/bin/pint --test  # vérification sans écriture
```

---

## 5. Tests

```bash
composer test           # config:clear puis php artisan test (suite complète)

# Sous-ensembles utiles
php artisan test --filter Dfs               # moteur DFS + conformité (vecteurs partagés)
php artisan test tests/Feature/Survey       # API du module Enquêtes
php artisan test --filter MobileSyncTest    # synchronisation mobile
```

La suite tourne sur une base **SQLite en mémoire** (`phpunit.xml`), file `sync` et
`Tests\TestCase` isole le répertoire des sources matérialisées dans un dossier temporaire **par test** :
publier un questionnaire ou recevoir une soumission produit donc réellement un fichier SQLite, sans
polluer `storage/`.

Les appels aux fournisseurs IA sont simulés par `Http::fake()` : **aucun test ne consomme de crédit**.

Points d'entrée notables :

| Suite | Couvre |
|---|---|
| `tests/Unit/Dfs/` | validateur, évaluateur Logic, moteur, XLSForm ; charge `../docs/dfs/*-tests.json` |
| `tests/Feature/Survey/SurveyCrudTest` | brouillon, `revision`/409, publication immuable, fork, duplication |
| `tests/Feature/Survey/MobileSyncTest` | idempotence par uuid, `ETag`, conflits, médias, drapeaux qualité |
| `tests/Feature/Survey/MaterializationTest` | colonnes de `reponses` sur la fixture MunaGo, bascule de fichier |
| `tests/Feature/Survey/StatsTest`, `SubmissionApiTest`, `SubmissionExportTest`, `SupervisionTest` | résultats, export, supervision |
| `tests/Feature/Survey/VerbatimTest`, `SurveyReportTest` | classification IA, livres de codes, synthèse, rapports |
| `tests/Feature/Survey/PublicLinkTest` | lien public, 410 expiré/plein, pot de miel |
| `tests/Feature/Survey/SurveyDemoSeederTest` | jeu de démonstration et son idempotence |

`tests/Support/MunagoFixtureLoader.php` charge la fixture MunaGo (projet, version publiée, 3 enquêteurs,
60 soumissions, suivis) dans la base de test — équivalent de `SurveyDemoSeeder`, mais réservé aux tests.

---

## 6. Dépannage

| Symptôme | Cause probable |
|---|---|
| Un job reste `queued` indéfiniment | aucun worker : lancer `php artisan queue:work` |
| « Le prompt système « … » est absent » | `php artisan db:seed --class=SystemPromptSeeder` |
| `GET …/datasource` reste `building` | worker arrêté, ou `SURVEY_DATASOURCE_DIR` non inscriptible |
| `409 datasource_not_ready` sur une synthèse ou un rapport | source jamais matérialisée : `POST /api/surveys/{id}/datasource/rebuild` |
| `413` sur un envoi de média | `upload_max_filesize`/`post_max_size` (PHP) et `client_max_body_size` (Nginx) < 25 Mo |
| Erreurs CORS depuis le web | `FRONTEND_URL` / `CORS_EXTRA_ORIGINS` puis `php artisan config:clear` |
| Clés IA ignorées après `config:cache` | ne jamais lire `env()` hors de `config/` ; passer par `App\Support\ApiKeyResolver` |
| Fichier SQLite verrouillé sous Windows | `DB::purge('target_db')` avant suppression (déjà fait par le service de matérialisation) |
