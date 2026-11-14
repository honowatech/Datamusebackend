<?php

namespace App\Services\Survey;

use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyVersion;
use App\Models\TargetDatabase;
use App\Services\Analysis\LevelInference;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Jeu de données servi au navigateur pour l'analyse native (`GET /surveys/{id}/analysis/dataset`).
 *
 * Le fichier servi n'est **pas** le SQLite matérialisé brut mais une copie expurgée, construite à la
 * demande puis conservée à côté de l'original (`survey_{id}_v{n}.analysis-{etag}.sqlite`) :
 *
 *   - table `medias` vidée (chemins de stockage, empreintes : absents des exports) ;
 *   - `enqueteurs.email` et `enqueteurs.telephone` mis à NULL (les exports ne portent que
 *     `enqueteur_id` / `enqueteur_nom`) ;
 *   - variante `masked` (lecteur non analyste, qui n'a pas droit à l'export) : colonnes issues de
 *     questions `tags: ["pii"]` mises à NULL dans `reponses`, `rep_*`, `suivi`, et lignes
 *     correspondantes retirées de `reponses_long` — même règle que le masquage des fiches.
 *
 * La disposition (tables, colonnes) reste strictement celle du fichier matérialisé : le dictionnaire
 * de variables s'applique tel quel. Un `VACUUM` final garantit que les valeurs retirées ne subsistent
 * pas dans les pages libres du fichier.
 *
 * L'ETag change dès que le fichier source change (nouvelle matérialisation), que la variante change, ou
 * — pour la variante masquée — qu'une définition de version change (les tags `pii` en dépendent).
 */
class AnalysisDatasetService
{
    /** À incrémenter si les règles d'expurgation changent (invalide les copies et les caches navigateur). */
    public const REVISION = 1;

    public const VARIANT_FULL = 'full';

    public const VARIANT_MASKED = 'masked';

    public function __construct(private readonly SurveyMaterializationService $materialization) {}

    /**
     * État du jeu de données pour ce lecteur, sans rien construire. `null` = pas encore matérialisé.
     *
     * @return array{source: string, path: string, etag: string, rows: int, materialized_at: ?string, variant: string, file_version: int}|null
     */
    public function describe(Survey $survey, bool $includePii): ?array
    {
        $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->first();
        if ($datasource === null || ! $datasource->isMaterialized()) {
            return null;
        }
        $source = TargetDatabase::query()->whereKey($datasource->target_database_id)->value('database');
        if (! is_string($source) || ! is_file($source)) {
            return null;
        }

        $variant = $includePii ? self::VARIANT_FULL : self::VARIANT_MASKED;
        $parts = [
            'datamuse.analysis', self::REVISION, $survey->id, (int) $datasource->file_version,
            (int) @filemtime($source), (int) @filesize($source), $variant,
        ];
        if (! $includePii) {
            $parts[] = SurveyVersion::query()->where('survey_id', $survey->id)->orderBy('id')->pluck('definition_hash')->implode(',');
        }
        $etag = substr(sha1(implode('|', $parts)), 0, 32);

        return [
            'source' => $source,
            'path' => preg_replace('/\.sqlite$/', '', $source).'.analysis-'.$etag.'.sqlite',
            'etag' => $etag,
            'rows' => (int) $datasource->row_count,
            'materialized_at' => $datasource->last_materialized_at?->toIso8601String(),
            'variant' => $variant,
            'file_version' => (int) $datasource->file_version,
        ];
    }

    /**
     * Chemin de la copie expurgée décrite par `describe()`, construite si elle n'existe pas encore.
     *
     * @param  array{source: string, path: string, variant: string}  $info
     */
    public function ensureFile(Survey $survey, array $info): string
    {
        if (is_file($info['path'])) {
            return $info['path'];
        }

        $tmp = $info['path'].'.'.Str::lower(Str::random(8)).'.part';
        if (! @copy($info['source'], $tmp)) {
            throw new RuntimeException("Impossible de préparer le jeu de données d'analyse du questionnaire #{$survey->id}.");
        }

        try {
            $this->sanitize($tmp, $survey, $info['variant']);
            if (! @rename($tmp, $info['path']) && ! is_file($info['path'])) {
                throw new RuntimeException("Impossible d'enregistrer le jeu de données d'analyse du questionnaire #{$survey->id}.");
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->removeStaleCopies($survey, $info['source']);

        return $info['path'];
    }

    // ------------------------------------------------------------------ expurgation

    private function sanitize(string $path, Survey $survey, string $variant): void
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $pdo->exec('PRAGMA secure_delete = ON;');
            $pdo->beginTransaction();

            if ($this->columns($pdo, 'medias') !== []) {
                $pdo->exec('DELETE FROM "medias"');
            }
            $this->nullify($pdo, 'enqueteurs', ['email', 'telephone']);

            if ($variant === self::VARIANT_MASKED) {
                $this->maskPii($pdo, $survey);
            }

            if ($this->columns($pdo, '_meta') !== []) {
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO "_meta" ("cle", "valeur") VALUES (?, ?)');
                $stmt->execute(['analysis_variant', $variant]);
                $stmt->execute(['analysis_revision', (string) self::REVISION]);
            }

            $pdo->commit();
            $pdo->exec('VACUUM;');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo = null;
        }
    }

    private function maskPii(PDO $pdo, Survey $survey): void
    {
        // Disposition complète (avec pré-analyse des réponses) : identique à celle de la matérialisation,
        // donc mêmes noms de colonnes, y compris les colonnes `_n` des groupes répétés.
        $layout = $this->materialization->layoutFor($survey);

        $byTable = ['reponses' => $layout->piiColumns()];
        $suivi = [];
        $longKeys = [];
        foreach ($layout->analysisBindings() as $binding) {
            $entry = $binding['entry'];
            if (! ReponsesLayout::isPii(is_array($entry['def'] ?? null) ? $entry['def'] : [])) {
                continue;
            }
            $byTable[$binding['table']] = array_merge($byTable[$binding['table']] ?? [], array_values($binding['cols']));
            $byTable['reponses'] = array_merge($byTable['reponses'], $binding['wide_cols']);
            $suivi = array_merge($suivi, $binding['suivi_cols']);
            foreach ([$entry['key'], $entry['other_key'] ?? null, $entry['codes_key'] ?? null] as $key) {
                if (is_string($key)) {
                    $longKeys[] = $key;
                }
            }
        }
        $byTable['suivi'] = $suivi;

        foreach ($byTable as $table => $columns) {
            $this->nullify($pdo, (string) $table, array_values(array_unique($columns)));
        }

        if ($longKeys !== [] && $this->columns($pdo, 'reponses_long') !== []) {
            $placeholders = implode(', ', array_fill(0, count($longKeys), '?'));
            $pdo->prepare("DELETE FROM \"reponses_long\" WHERE \"question_key\" IN ($placeholders)")->execute($longKeys);
        }
    }

    /**
     * Met à NULL les colonnes demandées qui existent réellement dans la table.
     *
     * @param  list<string>  $columns
     */
    private function nullify(PDO $pdo, string $table, array $columns): void
    {
        $existing = array_values(array_intersect($columns, $this->columns($pdo, $table)));
        if ($existing === []) {
            return;
        }
        $set = implode(', ', array_map(fn ($c) => LevelInference::quote($c).' = NULL', $existing));
        $pdo->exec('UPDATE '.LevelInference::quote($table).' SET '.$set);
    }

    /**
     * @return list<string> colonnes de la table (vide si elle n'existe pas)
     */
    private function columns(PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA table_info('.LevelInference::quote($table).')')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => (string) $r['name'], $rows);
    }

    /**
     * Supprime les copies d'analyse des versions de fichier précédentes (best effort).
     */
    private function removeStaleCopies(Survey $survey, string $source): void
    {
        $currentPrefix = preg_replace('/\.sqlite$/', '', $source).'.analysis-';
        $pattern = dirname($source).DIRECTORY_SEPARATOR.sprintf('survey_%d_v*.analysis-*', $survey->id);
        foreach (glob($pattern) ?: [] as $candidate) {
            $stalePart = str_ends_with($candidate, '.part') && (int) @filemtime($candidate) < time() - 3600;
            if (! str_starts_with($candidate, $currentPrefix) || $stalePart) {
                @unlink($candidate);
            }
        }
    }
}
