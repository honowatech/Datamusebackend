<?php

namespace App\Services\Survey;

use App\Enums\SubmissionStatus;
use App\Models\EnumeratorAssignment;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyVersion;
use App\Models\TargetDatabase;
use App\Models\User;
use App\Models\VerbatimCodebook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Matérialisation des soumissions d'un questionnaire en base SQLite « TargetDatabase » (plan § 5.2).
 *
 * Rebuild complet dans `survey_{id}_v{n+1}.sqlite.tmp`, renommage en `.sqlite` (bascule atomique par
 * nom versionné : jamais de rename-over d'un fichier potentiellement ouvert sous Windows/WAL), puis mise à
 * jour de `target_databases` + `survey_datasources` dans une transaction, `DB::purge('target_db')` et
 * suppression de l'ancien fichier (best effort, erreur journalisée).
 *
 * Tables produites : `reponses`, `reponses_long`, `medias`, `choix`, `questions`, `enqueteurs`, `suivi`,
 * `rep_{groupe}` (groupes répétés), `_meta`.
 */
class SurveyMaterializationService
{
    public const BATCH_SIZE = 500;

    public function __construct(private readonly ?string $baseDir = null) {}

    /**
     * Répertoire racine des fichiers SQLite (`storage/app/imported_databases` par défaut).
     */
    public function baseDir(): string
    {
        return $this->baseDir ?? (string) (config('survey.datasource_dir') ?: storage_path('app/imported_databases'));
    }

    /**
     * Chemin du fichier SQLite pour une version de fichier donnée.
     */
    public function filePathFor(Survey $survey, int $fileVersion): string
    {
        $survey->loadMissing('project');
        $ownerId = (int) ($survey->project?->owner_id ?? $survey->created_by);

        return $this->baseDir().DIRECTORY_SEPARATOR.'user_'.$ownerId.DIRECTORY_SEPARATOR.sprintf('survey_%d_v%d.sqlite', $survey->id, $fileVersion);
    }

    // ------------------------------------------------------------------ API réutilisable (export B-10)

    /**
     * Disposition des colonnes pour un questionnaire : version publiée (ou `$primary`) fusionnée avec les
     * autres versions ayant des soumissions, codebooks pris en compte, pré-analyse des réponses.
     *
     * @param  array{include_rejected?: bool, merge_versions?: bool}  $options
     */
    public function layoutFor(Survey $survey, ?SurveyVersion $primary = null, array $options = []): ReponsesLayout
    {
        $primary ??= $survey->publishedVersion ?? $survey->versions()->orderByDesc('version')->first();
        if ($primary === null) {
            throw new RuntimeException("Le questionnaire #{$survey->id} n'a aucune version à matérialiser.");
        }

        $others = [];
        if ($options['merge_versions'] ?? true) {
            $ids = Submission::query()->where('survey_id', $survey->id)
                ->when(! ($options['include_rejected'] ?? false), fn ($q) => $q->where('status', '!=', SubmissionStatus::Rejected->value))
                ->distinct()->pluck('survey_version_id')->filter()->reject(fn ($id) => (int) $id === $primary->id)->values();
            if ($ids->isNotEmpty()) {
                $others = SurveyVersion::query()->whereIn('id', $ids)->orderBy('version')->get()->all();
            }
        }

        $codedKeys = VerbatimCodebook::query()->where('survey_id', $survey->id)->distinct()->pluck('question_key')->map(fn ($k) => (string) $k)->all();

        $layout = ReponsesLayout::fromVersions($primary, $others, $codedKeys);
        $layout->scanSurvey($survey->id);
        $layout->setVersionNumbers(SurveyVersion::query()->where('survey_id', $survey->id)->pluck('version', 'id')->map(fn ($v) => (int) $v)->all());
        $layout->setEnumeratorNames($this->enumeratorNames($survey));

        return $layout->finalize();
    }

    /**
     * Colonnes de `reponses` pour une version (fusionnée par défaut avec les autres versions ayant des
     * soumissions, comme lors de la matérialisation — l'export doit produire exactement ces colonnes).
     *
     * @return list<array{name: string, type: string}>
     */
    public function columnsFor(SurveyVersion $version, bool $mergeOtherVersions = true): array
    {
        $survey = $version->survey ?? Survey::withTrashed()->findOrFail($version->survey_id);

        return $this->layoutFor($survey, $version, ['merge_versions' => $mergeOtherVersions])->columns();
    }

    /**
     * Ligne `reponses` d'une soumission (mêmes colonnes que `columnsFor()`).
     *
     * @return array<string, mixed>
     */
    public function rowFor(Submission $submission, ReponsesLayout $layout): array
    {
        $submission->loadMissing(['media', 'followUps', 'codings']);

        return $layout->rowFor($submission);
    }

    /**
     * Requête des soumissions incluses dans la matérialisation (ordre stable, relations chargées).
     *
     * @param  array{include_rejected?: bool}  $options
     * @return Builder<Submission>
     */
    public function submissionsQuery(Survey $survey, array $options = []): Builder
    {
        return Submission::query()
            ->where('survey_id', $survey->id)
            ->when(! ($options['include_rejected'] ?? false), fn ($q) => $q->where('status', '!=', SubmissionStatus::Rejected->value))
            ->with(['media', 'followUps', 'codings'])
            ->orderBy('id');
    }

    // ------------------------------------------------------------------ matérialisation

    /**
     * @param  array{include_rejected?: bool}  $options
     */
    public function materialize(Survey $survey, array $options = []): MaterializationResult
    {
        $startedAt = hrtime(true);
        $survey->loadMissing(['project', 'publishedVersion']);
        $includeRejected = (bool) ($options['include_rejected'] ?? false);

        $datasource = SurveyDatasource::query()->firstOrCreate(['survey_id' => $survey->id]);
        $previous = $datasource->target_database_id !== null ? TargetDatabase::query()->find($datasource->target_database_id) : null;
        $previousPath = $previous?->database;

        $fileVersion = (int) $datasource->file_version + 1;
        $finalPath = $this->filePathFor($survey, $fileVersion);
        $tmpPath = $finalPath.'.tmp';
        File::ensureDirectoryExists(dirname($finalPath));
        $this->removeStaleTemporaries($survey, dirname($finalPath));

        $warnings = [];
        try {
            $layout = $this->layoutFor($survey, null, $options);
            [$rowCount, $tables] = $this->build($survey, $layout, $tmpPath, $fileVersion, $includeRejected);
            $warnings = $layout->warnings();

            if (! @rename($tmpPath, $finalPath)) {
                throw new RuntimeException("Impossible de renommer {$tmpPath} en {$finalPath}.");
            }
        } catch (Throwable $e) {
            $this->unlinkQuietly($tmpPath);
            $datasource->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 2000)])->save();
            throw $e;
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $ownerId = (int) ($survey->project?->owner_id ?? $survey->created_by);
        $name = 'Enquête : '.$survey->title;

        $targetDb = DB::transaction(function () use ($previous, $ownerId, $name, $finalPath, $datasource, $fileVersion, $rowCount, $durationMs) {
            $attributes = [
                'user_id' => $ownerId,
                'name' => $name,
                'driver' => 'sqlite',
                'host' => 'localhost',
                'port' => '0',
                'username' => 'sqlite',
                'database' => $finalPath,
                'password' => '',
            ];
            if ($previous !== null) {
                $previous->fill($attributes)->save();
                $targetDb = $previous;
            } else {
                $targetDb = TargetDatabase::query()->create($attributes);
            }

            $datasource->forceFill([
                'target_database_id' => $targetDb->id,
                'file_version' => $fileVersion,
                'row_count' => $rowCount,
                'last_materialized_at' => now(),
                'last_duration_ms' => $durationMs,
                'dirty' => false,
                'dirty_since' => null,
                'last_error' => null,
            ])->save();

            return $targetDb;
        });

        DB::purge('target_db');

        if ($previousPath !== null && $previousPath !== $finalPath) {
            $warnings = array_merge($warnings, $this->deleteOldFile($previousPath));
        }

        return new MaterializationResult(
            targetDatabaseId: $targetDb->id,
            filePath: $finalPath,
            fileVersion: $fileVersion,
            rowCount: $rowCount,
            columns: $layout->columns(),
            tables: $tables,
            durationMs: $durationMs,
            warnings: $warnings,
        );
    }

    // ------------------------------------------------------------------ construction du fichier

    /**
     * @return array{0: int, 1: list<string>} nombre de lignes de `reponses`, tables créées
     */
    private function build(Survey $survey, ReponsesLayout $layout, string $tmpPath, int $fileVersion, bool $includeRejected): array
    {
        $this->unlinkQuietly($tmpPath);
        $pdo = new PDO('sqlite:'.$tmpPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA synchronous = OFF; PRAGMA journal_mode = MEMORY;');

        try {
            $tables = $this->createSchema($pdo, $layout);

            $reponsesCols = $layout->columnNames();
            $insertReponses = $this->prepareInsert($pdo, 'reponses', $reponsesCols);
            $insertLong = $this->prepareInsert($pdo, 'reponses_long', ['submission_id', 'fiche_code', 'question_key', 'repeat_index', 'valeur_texte', 'valeur_num', 'valeur_code', 'langue']);
            $insertMedia = $this->prepareInsert($pdo, 'medias', ['media_id', 'submission_id', 'fiche_code', 'question_key', 'repeat_index', 'chemin', 'mime', 'taille', 'sha256', 'etat']);
            $insertSuivi = $this->prepareInsert($pdo, 'suivi', array_keys($layout->suiviColumns()));
            $insertRepeat = [];
            foreach ($layout->repeatTables() as $group => $rep) {
                $insertRepeat[$group] = $this->prepareInsert($pdo, $rep['table'], array_keys($rep['columns']));
            }

            $rowCount = 0;
            $enumeratorCounts = [];
            $this->submissionsQuery($survey, ['include_rejected' => $includeRejected])
                ->chunk(self::BATCH_SIZE, function ($submissions) use ($pdo, $layout, $insertReponses, $insertLong, $insertMedia, $insertSuivi, $insertRepeat, &$rowCount, &$enumeratorCounts) {
                    $pdo->beginTransaction();
                    try {
                        foreach ($submissions as $submission) {
                            $insertReponses->execute($this->bindValues(array_values($layout->rowFor($submission))));
                            $rowCount++;
                            if ($submission->enumerator_id !== null) {
                                $enumeratorCounts[$submission->enumerator_id] = ($enumeratorCounts[$submission->enumerator_id] ?? 0) + 1;
                            }

                            foreach ($layout->longRowsFor($submission) as $long) {
                                $insertLong->execute($this->bindValues(array_values($long)));
                            }
                            foreach ($submission->media as $media) {
                                $insertMedia->execute($this->bindValues([
                                    $media->id, $submission->id, $submission->fiche_code, $media->question_key,
                                    $media->repeat_index !== null ? (int) $media->repeat_index + 1 : null,
                                    $media->path, $media->mime, $media->size, $media->sha256, $media->state?->value,
                                ]));
                            }
                            foreach ($layout->stageRowsFor($submission) as $stageRow) {
                                $insertSuivi->execute($this->bindValues(array_values($stageRow)));
                            }
                            foreach ($layout->repeatRowsFor($submission) as $group => $rows) {
                                foreach ($rows as $row) {
                                    $insertRepeat[$group]->execute($this->bindValues(array_values($row)));
                                }
                            }
                        }
                        $pdo->commit();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }
                });

            $pdo->beginTransaction();
            $this->fillReferenceTables($pdo, $survey, $layout, $enumeratorCounts);
            $this->fillMeta($pdo, $survey, $layout, $fileVersion, $rowCount, $includeRejected);
            $pdo->commit();

            // Pas de WAL persistant (contrairement à l'import de fichiers) : le fichier est en lecture
            // seule après construction et reste autonome (aucun -wal/-shm à renommer ou supprimer).
            $pdo->exec('PRAGMA synchronous = NORMAL;');
        } finally {
            $pdo = null;
        }

        return [$rowCount, $tables];
    }

    /**
     * @return list<string>
     */
    private function createSchema(PDO $pdo, ReponsesLayout $layout): array
    {
        $tables = [];
        $defs = [];
        foreach ($layout->columnTypes() as $name => $type) {
            $defs[] = $name === 'submission_id' ? '"submission_id" INTEGER PRIMARY KEY' : sprintf('"%s" %s', $name, $type);
        }
        $pdo->exec("CREATE TABLE \"reponses\" (\n  ".implode(",\n  ", $defs)."\n);");
        $tables[] = 'reponses';

        $pdo->exec('CREATE TABLE "reponses_long" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "submission_id" INTEGER, "fiche_code" TEXT, "question_key" TEXT, "repeat_index" INTEGER, "valeur_texte" TEXT, "valeur_num" REAL, "valeur_code" TEXT, "langue" TEXT);');
        $pdo->exec('CREATE INDEX "idx_long_question" ON "reponses_long" ("question_key");');
        $pdo->exec('CREATE INDEX "idx_long_submission" ON "reponses_long" ("submission_id");');
        $tables[] = 'reponses_long';

        $pdo->exec('CREATE TABLE "medias" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "media_id" INTEGER, "submission_id" INTEGER, "fiche_code" TEXT, "question_key" TEXT, "repeat_index" INTEGER, "chemin" TEXT, "mime" TEXT, "taille" INTEGER, "sha256" TEXT, "etat" TEXT);');
        $tables[] = 'medias';

        $pdo->exec('CREATE TABLE "choix" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "question_key" TEXT, "code" TEXT, "libelle" TEXT, "ordre" INTEGER);');
        $tables[] = 'choix';

        $pdo->exec('CREATE TABLE "questions" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "question_key" TEXT, "section" TEXT, "etape" TEXT, "groupe" TEXT, "type" TEXT, "libelle" TEXT, "ordre" INTEGER, "colonne" TEXT, "version" INTEGER);');
        $tables[] = 'questions';

        $pdo->exec('CREATE TABLE "enqueteurs" ("id" INTEGER PRIMARY KEY, "nom" TEXT, "email" TEXT, "telephone" TEXT, "zone" TEXT, "quota_cible" INTEGER, "nb_soumissions" INTEGER);');
        $tables[] = 'enqueteurs';

        $suiviDefs = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach ($layout->suiviColumns() as $name => $type) {
            $suiviDefs[] = sprintf('"%s" %s', $name, $type);
        }
        $pdo->exec("CREATE TABLE \"suivi\" (\n  ".implode(",\n  ", $suiviDefs)."\n);");
        $pdo->exec('CREATE INDEX "idx_suivi_submission" ON "suivi" ("submission_id");');
        $tables[] = 'suivi';

        foreach ($layout->repeatTables() as $rep) {
            $repDefs = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
            foreach ($rep['columns'] as $name => $type) {
                $repDefs[] = sprintf('"%s" %s', $name, $type);
            }
            $pdo->exec(sprintf("CREATE TABLE \"%s\" (\n  %s\n);", $rep['table'], implode(",\n  ", $repDefs)));
            $tables[] = $rep['table'];
        }

        $pdo->exec('CREATE TABLE "_meta" ("cle" TEXT PRIMARY KEY, "valeur" TEXT);');
        $tables[] = '_meta';

        return $tables;
    }

    /**
     * @param  array<int, int>  $enumeratorCounts
     */
    private function fillReferenceTables(PDO $pdo, Survey $survey, ReponsesLayout $layout, array $enumeratorCounts): void
    {
        $insertChoix = $this->prepareInsert($pdo, 'choix', ['question_key', 'code', 'libelle', 'ordre']);
        foreach ($layout->choiceRows() as $row) {
            $insertChoix->execute($this->bindValues(array_values($row)));
        }

        $insertQuestion = $this->prepareInsert($pdo, 'questions', ['question_key', 'section', 'etape', 'groupe', 'type', 'libelle', 'ordre', 'colonne', 'version']);
        foreach ($layout->questionRows() as $row) {
            $insertQuestion->execute($this->bindValues(array_values($row)));
        }

        $assignments = EnumeratorAssignment::query()->where('survey_id', $survey->id)->get()->keyBy('user_id');
        $ids = array_values(array_unique(array_merge($assignments->keys()->all(), array_keys($enumeratorCounts))));
        $users = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->orderBy('id')->get();
        $insertEnq = $this->prepareInsert($pdo, 'enqueteurs', ['id', 'nom', 'email', 'telephone', 'zone', 'quota_cible', 'nb_soumissions']);
        foreach ($users as $user) {
            $assignment = $assignments->get($user->id);
            $insertEnq->execute($this->bindValues([
                $user->id, $user->name, $user->email, $user->phone ?? null,
                $assignment?->zone, $assignment?->quota_target, $enumeratorCounts[$user->id] ?? 0,
            ]));
        }
    }

    private function fillMeta(PDO $pdo, Survey $survey, ReponsesLayout $layout, int $fileVersion, int $rowCount, bool $includeRejected): void
    {
        $published = $survey->publishedVersion;
        $meta = [
            'survey_id' => $survey->id,
            'survey_title' => $survey->title,
            'project_id' => $survey->project_id,
            'form_version' => $published?->version,
            'definition_hash' => $published?->definition_hash,
            'file_version' => $fileVersion,
            'generated_at' => now()->toIso8601String(),
            'row_count' => $rowCount,
            'default_language' => $layout->defaultLanguage(),
            'include_rejected' => $includeRejected ? 1 : 0,
            'repeat_index_base' => 1,
            'list_separator' => ReponsesLayout::SEPARATOR,
            'schema' => 'datamuse.survey.v1',
        ];
        $stmt = $pdo->prepare('INSERT INTO "_meta" ("cle", "valeur") VALUES (?, ?)');
        foreach ($meta as $key => $value) {
            $stmt->execute([$key, $value === null ? null : (string) $value]);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function prepareInsert(PDO $pdo, string $table, array $columns): \PDOStatement
    {
        $quoted = implode(', ', array_map(fn ($c) => '"'.$c.'"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        return $pdo->prepare(sprintf('INSERT INTO "%s" (%s) VALUES (%s)', $table, $quoted, $placeholders));
    }

    /**
     * Normalise les valeurs pour PDO SQLite (booléens → 0/1, tableaux → JSON).
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function bindValues(array $values): array
    {
        foreach ($values as $i => $v) {
            if (is_bool($v)) {
                $values[$i] = $v ? 1 : 0;
            } elseif (is_array($v)) {
                $values[$i] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($v instanceof \DateTimeInterface) {
                $values[$i] = $v->format(DATE_ATOM);
            }
        }

        return $values;
    }

    // ------------------------------------------------------------------ fichiers

    /**
     * @return array<int, string>
     */
    private function enumeratorNames(Survey $survey): array
    {
        $ids = Submission::query()->where('survey_id', $survey->id)->whereNotNull('enumerator_id')->distinct()->pluck('enumerator_id');
        $assigned = EnumeratorAssignment::query()->where('survey_id', $survey->id)->pluck('user_id');
        $all = $ids->merge($assigned)->unique()->values();
        if ($all->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('id', $all)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * Supprime l'ancien fichier (et ses compagnons -wal/-shm). Best effort : l'échec est journalisé.
     *
     * @return list<string> avertissements
     */
    private function deleteOldFile(string $path): array
    {
        $warnings = [];
        foreach ([$path, $path.'-wal', $path.'-shm'] as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }
            if (! @unlink($candidate)) {
                $message = "Ancien fichier non supprimé (sera nettoyé plus tard) : {$candidate}";
                Log::warning($message);
                $warnings[] = $message;
            }
        }

        return $warnings;
    }

    private function removeStaleTemporaries(Survey $survey, string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.sprintf('survey_%d_v*.sqlite.tmp', $survey->id)) ?: [] as $stale) {
            $this->unlinkQuietly($stale);
        }
    }

    private function unlinkQuietly(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
