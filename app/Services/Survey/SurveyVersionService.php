<?php

namespace App\Services\Survey;

use App\Enums\ProjectRole;
use App\Enums\SurveyStatus;
use App\Enums\VersionStatus;
use App\Events\SurveyPublished;
use App\Exceptions\DfsInvalidDefinitionException;
use App\Exceptions\SurveyConflictException;
use App\Models\EnumeratorAssignment;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyDatasource;
use App\Models\SurveyProject;
use App\Models\SurveyVersion;
use App\Models\TargetDatabase;
use App\Models\User;
use App\Services\Dfs\DfsValidator;
use App\Services\Dfs\ValidationResult;
use App\Support\QuestionIndexBuilder;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie des questionnaires et de leurs versions DFS (B-05, README § 15, contrat tags
 * Questionnaires / Versions / Assignations).
 *
 * Règles :
 *   - un seul brouillon à la fois ; une version publiée est immuable : toute modification passe par un
 *     brouillon `version + 1` (créé automatiquement par `saveDraft`, ou explicitement par `fork`) ;
 *   - `saveDraft` : verrou optimiste `base_revision` (409 `revision_conflict`), validation structurelle
 *     bloquante (422), sémantique en avertissements, idempotent à contenu identique ;
 *   - `publish` : validation complète (schéma + sémantique + compatibilité avec la dernière version
 *     publiée) → 422 ; `definition_hash` = SHA-256 du texte canonique **relu depuis la base** (donc égal
 *     au hash de `SurveyVersion::canonicalJson()` servi plus tard au mobile, B-07) ;
 *   - `definition.id` (uuid stable du questionnaire) et `definition.version` sont imposés par le serveur.
 *
 * API pour les autres tâches :
 *   - B-06b (génération IA / import XLSForm) : `createSurvey($project, $user, $title, $definition)` crée
 *     l'enquête et son brouillon v1 ; `saveDraft($survey, $definition, $baseRevision, $user)` remplace le
 *     brouillon d'une enquête existante (utiliser `$survey->draftVersion?->revision ?? $survey->currentVersion->revision`
 *     comme `base_revision`) ; `validateDefinition($survey, $definition)` pour un contrôle sans effet.
 *   - B-07 (mobile) : `publishedVersion($survey)` puis `$version->canonicalJson()` (texte à servir tel quel)
 *     et `$version->definition_hash` (SHA-256 de ce texte) ; `$version->question_index` pour l'indexation.
 */
class SurveyVersionService
{
    /** Codes d'erreur structurels : bloquent l'enregistrement d'un brouillon (les autres ne bloquent que la publication). */
    public const STRUCTURAL_CODES = ['invalid_json', 'unsupported_version', 'schema'];

    public const DEFAULT_LANGUAGE = 'fr';

    public function __construct(
        private readonly DfsValidator $validator,
        private readonly MembershipService $membership,
        private readonly WebLinkService $webLinks,
    ) {}

    // ------------------------------------------------------------------ création

    /**
     * Crée un questionnaire `draft` et sa version 1 (brouillon, révision 0). Sans `definition`, un squelette
     * minimal valide est généré (titre, langue `fr`, une section d'accueil).
     *
     * @param  array<string, mixed>|null  $definition
     *
     * @throws DfsInvalidDefinitionException erreurs structurelles (422)
     */
    public function createSurvey(SurveyProject $project, User $user, string $title, ?array $definition = null): Survey
    {
        $title = trim($title);
        $definition ??= $this->skeletonDefinition($title);
        $definition = $this->normalizeDefinition($definition, (string) Str::uuid(), 1);

        $this->assertStructurallyValid($definition);

        return DB::transaction(function () use ($project, $user, $title, $definition) {
            $survey = Survey::create([
                'project_id' => $project->id,
                'created_by' => $user->id,
                'title' => $title,
                'slug' => $this->uniqueSlug($project->id, $title),
                'status' => SurveyStatus::Draft,
            ]);

            $version = SurveyVersion::create([
                'survey_id' => $survey->id,
                'version' => 1,
                'status' => VersionStatus::Draft,
                'definition' => $definition,
                'revision' => 0,
            ]);

            $survey->forceFill(['current_version_id' => $version->id])->save();
            $this->webLinks->ensure($survey, $user);

            return $survey;
        });
    }

    /**
     * Squelette DFS v1 minimal valide : titre, langue `fr`, une section d'accueil avec une note
     * (le schéma impose au moins une section contenant au moins un item) et une liste `oui_non`.
     *
     * @return array<string, mixed>
     */
    public function skeletonDefinition(string $title, string $language = self::DEFAULT_LANGUAGE): array
    {
        $title = trim($title) !== '' ? trim($title) : 'Nouveau questionnaire';

        return [
            'dfs_version' => '1.0',
            'id' => (string) Str::uuid(),
            'version' => 1,
            'title' => [$language => $title],
            'settings' => [
                'languages' => [$language],
                'default_language' => $language,
            ],
            'choice_lists' => [
                'oui_non' => [
                    ['name' => 'oui', 'label' => [$language => 'Oui']],
                    ['name' => 'non', 'label' => [$language => 'Non']],
                ],
            ],
            'sections' => [
                [
                    'key' => 'S1',
                    'label' => [$language => 'Section 1'],
                    'items' => [
                        [
                            'key' => 'intro',
                            'type' => 'note',
                            'label' => [$language => 'Bonjour, merci de prendre quelques minutes pour répondre à ce questionnaire.'],
                            'audience' => 'respondent',
                            'style' => 'script',
                        ],
                    ],
                ],
            ],
            'follow_up_stages' => [],
        ];
    }

    // ------------------------------------------------------------------ brouillon

    /**
     * Enregistre le brouillon (autosave). S'il n'existe pas (seule une version publiée/archivée),
     * un brouillon `max(version) + 1` est créé à partir de la version courante ; `base_revision` doit
     * alors égaler la révision de cette version source.
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws SurveyConflictException `revision_conflict` (409)
     * @throws DfsInvalidDefinitionException erreurs structurelles (422)
     */
    public function saveDraft(Survey $survey, array $definition, int $baseRevision, ?User $user = null): DraftSaveResult
    {
        return DB::transaction(function () use ($survey, $definition, $baseRevision) {
            $survey = $this->lock($survey);
            $draft = $this->draftOf($survey);
            $created = false;

            if ($draft === null) {
                $source = $this->currentOf($survey);
                if ($source === null) {
                    throw SurveyConflictException::noDraft();
                }
                if ($source->revision !== $baseRevision) {
                    throw SurveyConflictException::revisionConflict($source->revision, $this->conflictMeta($source));
                }

                $draft = $this->newDraftFrom($survey, $source);
                $created = true;
            } elseif ($draft->revision !== $baseRevision) {
                throw SurveyConflictException::revisionConflict($draft->revision, $this->conflictMeta($draft));
            }

            $definition = $this->normalizeDefinition($definition, $this->stableId($survey), $draft->version);
            $warnings = $this->assertStructurallyValid($definition);

            if (! $created && SurveyVersion::encodeCanonical($draft->definition ?? []) === SurveyVersion::encodeCanonical($definition)) {
                return new DraftSaveResult($draft, $warnings, false, false);
            }

            $draft->definition = $definition;
            $draft->revision = $draft->revision + 1;
            $draft->save();

            $this->syncTitleFromDefinition($survey, $definition);
            $survey->forceFill(['current_version_id' => $draft->id])->save();

            return new DraftSaveResult($draft, $warnings, true, $created);
        });
    }

    /**
     * Rapport de validation complet (schéma + sémantique + compatibilité) du brouillon courant, sans effet
     * de bord. À défaut de brouillon, la version courante est validée.
     *
     * @return array{valid: bool, revision: int|null, version: int|null, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function validateDraft(Survey $survey): array
    {
        $version = $this->draftOf($survey) ?? $this->currentOf($survey);
        $result = $version === null
            ? new ValidationResult([['path' => '/', 'code' => 'no_version', 'message' => 'Ce questionnaire ne possède aucune version.', 'severity' => 'error']])
            : $this->validateDefinition($survey, $version->definition ?? []);

        return [
            'valid' => $result->isValid(),
            'revision' => $version?->revision,
            'version' => $version?->version,
            'errors' => array_values($result->errors),
            'warnings' => array_values($result->warnings),
        ];
    }

    /**
     * Validation complète d'une définition dans le contexte du questionnaire : schéma, règles sémantiques,
     * puis compatibilité avec la dernière version publiée (clés jamais renommées, types stables).
     *
     * @param  array<string, mixed>  $definition
     */
    public function validateDefinition(Survey $survey, array $definition): ValidationResult
    {
        return $this->validatorFor($survey)($definition);
    }

    /**
     * Validateur lié à un questionnaire : `fn (array $definition): ValidationResult`.
     */
    public function validatorFor(Survey $survey): Closure
    {
        $published = $this->publishedVersion($survey);

        return function (array $definition) use ($published): ValidationResult {
            $result = $this->validator->validate($definition);
            if ($published === null) {
                return $result;
            }

            [$errors, $warnings] = $this->compatibilityIssues($definition, $published);

            return new ValidationResult(
                array_merge($result->errors, $errors),
                array_merge($result->warnings, $warnings),
            );
        };
    }

    // ------------------------------------------------------------------ publication

    /**
     * Publie le brouillon : validation complète (422), passage en `published` avec `definition_hash` figé,
     * archivage de la version publiée précédente, `surveys.status = active`, création de la datasource,
     * assignation des enquêteurs actifs du projet, événement `SurveyPublished` (après commit).
     *
     * @throws SurveyConflictException `no_draft` (409)
     * @throws DfsInvalidDefinitionException (422)
     */
    public function publish(Survey $survey, User $user, bool $assignAllEnumerators = true): SurveyVersion
    {
        [$version, $previous] = DB::transaction(function () use ($survey, $user, $assignAllEnumerators) {
            $survey = $this->lock($survey);
            $draft = $this->draftOf($survey);
            if ($draft === null) {
                throw SurveyConflictException::noDraft();
            }

            $definition = $this->normalizeDefinition($draft->definition ?? [], $this->stableId($survey), $draft->version);
            $result = $this->validateDefinition($survey, $definition);
            if (! $result->isValid()) {
                throw new DfsInvalidDefinitionException($result->errors, $result->warnings);
            }

            $previous = $this->publishedVersion($survey);

            $draft->forceFill([
                'definition' => $definition,
                'status' => VersionStatus::Published,
                'published_at' => now(),
                'published_by' => $user->id,
            ])->save();

            // Hash figé sur le texte canonique tel qu'il sera relu et servi (indépendant du moteur SQL).
            $draft->refresh();
            $draft->forceFill([
                'definition_hash' => SurveyVersion::computeHash($draft->canonicalJson()),
                'question_index' => QuestionIndexBuilder::build($draft->definition ?? []),
            ])->save();

            if ($previous !== null && $previous->id !== $draft->id) {
                $previous->forceFill(['status' => VersionStatus::Archived])->save();
            }

            $this->syncTitleFromDefinition($survey, $definition);
            $survey->forceFill([
                'published_version_id' => $draft->id,
                'current_version_id' => $draft->id,
                'status' => SurveyStatus::Active,
            ])->save();

            SurveyDatasource::query()->firstOrCreate(
                ['survey_id' => $survey->id],
                ['dirty' => true, 'dirty_since' => now()],
            );

            if ($assignAllEnumerators) {
                $this->membership->assignActiveEnumeratorsToSurvey($survey);
            }

            return [$draft, $previous];
        });

        $survey->refresh();
        event(new SurveyPublished($survey, $version, $previous));

        return $version;
    }

    /**
     * Nouveau brouillon `max(version) + 1` (révision 0) copié depuis la version `$source` (publiée ou archivée).
     *
     * @throws SurveyConflictException `draft_exists` (409)
     */
    public function fork(Survey $survey, SurveyVersion $source): SurveyVersion
    {
        return DB::transaction(function () use ($survey, $source) {
            $survey = $this->lock($survey);
            $existing = $this->draftOf($survey);
            if ($existing !== null) {
                throw SurveyConflictException::draftExists($existing->version);
            }

            $draft = $this->newDraftFrom($survey, $source);
            $draft->save();
            $survey->forceFill(['current_version_id' => $draft->id])->save();

            return $draft;
        });
    }

    /**
     * Copie la version publiée (à défaut le brouillon, sinon la dernière) dans un nouveau questionnaire
     * `draft` du projet cible (nouvel uuid DFS, version 1, révision 0), sans soumissions ni assignations.
     */
    public function duplicate(Survey $survey, SurveyProject $target, User $user, ?string $title = null): Survey
    {
        $source = $this->publishedVersion($survey) ?? $this->draftOf($survey) ?? $this->latestOf($survey);
        if ($source === null) {
            throw SurveyConflictException::noDraft();
        }

        $title = trim((string) $title) !== '' ? trim((string) $title) : 'Copie de '.$survey->title;
        $definition = $source->definition ?? [];
        $definition['title'] = $this->withDefaultLanguageTitle($definition, $title);

        return DB::transaction(function () use ($target, $user, $title, $definition) {
            $copy = Survey::create([
                'project_id' => $target->id,
                'created_by' => $user->id,
                'title' => $title,
                'slug' => $this->uniqueSlug($target->id, $title),
                'status' => SurveyStatus::Draft,
            ]);

            $version = SurveyVersion::create([
                'survey_id' => $copy->id,
                'version' => 1,
                'status' => VersionStatus::Draft,
                'definition' => $this->normalizeDefinition($definition, (string) Str::uuid(), 1),
                'revision' => 0,
            ]);

            $copy->forceFill(['current_version_id' => $version->id])->save();
            $this->webLinks->ensure($copy, $user);

            return $copy;
        });
    }

    // ------------------------------------------------------------------ métadonnées

    /**
     * Met à jour titre et/ou statut. `active` requiert une version publiée ; `draft` n'est accepté que
     * sans version publiée. Un changement de titre est répercuté dans le brouillon (révision incrémentée).
     *
     * @throws SurveyConflictException `survey_not_published` (409)
     * @throws ValidationException statut incohérent (422)
     */
    public function updateSurvey(Survey $survey, ?string $title, ?SurveyStatus $status): Survey
    {
        return DB::transaction(function () use ($survey, $title, $status) {
            $survey = $this->lock($survey);

            if ($status !== null) {
                if ($status === SurveyStatus::Active && ! $survey->isPublished()) {
                    throw SurveyConflictException::notPublished();
                }
                if ($status === SurveyStatus::Draft && $survey->isPublished()) {
                    throw ValidationException::withMessages(['status' => ['Un questionnaire publié ne peut pas revenir en brouillon (utilisez « closed »).']]);
                }
                $survey->status = $status;
            }

            $title = $title === null ? null : trim($title);
            if ($title !== null && $title !== '' && $title !== $survey->title) {
                $survey->title = $title;

                $draft = $this->draftOf($survey);
                if ($draft !== null) {
                    $definition = $draft->definition ?? [];
                    $definition['title'] = $this->withDefaultLanguageTitle($definition, $title);
                    $draft->definition = $definition;
                    $draft->revision = $draft->revision + 1;
                    $draft->save();
                }
            }

            $survey->save();

            return $survey;
        });
    }

    /**
     * Suppression douce. Refusée (`survey_has_submissions`) s'il existe des soumissions, sauf `$force`.
     * Supprime la datasource matérialisée et sa TargetDatabase (fichier SQLite compris).
     *
     * @throws SurveyConflictException (409)
     */
    public function delete(Survey $survey, bool $force = false): void
    {
        $count = Submission::query()->forSurvey($survey->id)->count();
        if ($count > 0 && ! $force) {
            throw SurveyConflictException::hasSubmissions($count);
        }

        DB::transaction(function () use ($survey) {
            $datasource = SurveyDatasource::query()->where('survey_id', $survey->id)->first();
            if ($datasource !== null) {
                $target = $datasource->target_database_id ? TargetDatabase::query()->find($datasource->target_database_id) : null;
                $datasource->delete();
                if ($target !== null) {
                    if ($target->driver === 'sqlite' && is_string($target->database) && is_file($target->database)) {
                        @unlink($target->database);
                    }
                    $target->delete();
                }
            }

            $survey->delete();
        });
    }

    // ------------------------------------------------------------------ assignations

    /**
     * Remplace la matrice d'assignations (unique survey/user). Chaque `user_id` doit être un membre actif
     * `enqueteur` du projet (422 `assignments.{i}.user_id`).
     *
     * @param  array<int, array{user_id: int, zone?: string|null, quota_target?: int|null, starts_at?: string|null, ends_at?: string|null}>  $rows
     * @return Collection<int, EnumeratorAssignment>
     *
     * @throws ValidationException
     */
    public function syncAssignments(Survey $survey, array $rows): Collection
    {
        $userIds = array_values(array_unique(array_map(static fn (array $r) => (int) $r['user_id'], $rows)));

        $members = ProjectMember::query()
            ->forProject($survey->project_id)
            ->active()
            ->role(ProjectRole::Enqueteur)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();

        $errors = [];
        foreach ($rows as $i => $row) {
            if (! in_array((int) $row['user_id'], $members, true)) {
                $errors["assignments.{$i}.user_id"] = ["L'utilisateur {$row['user_id']} n'est pas un enquêteur actif du projet."];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($survey, $rows, $userIds) {
            EnumeratorAssignment::query()
                ->where('survey_id', $survey->id)
                ->whereNotIn('user_id', $userIds)
                ->delete();

            foreach ($rows as $row) {
                EnumeratorAssignment::query()->updateOrCreate(
                    ['survey_id' => $survey->id, 'user_id' => (int) $row['user_id']],
                    [
                        'zone' => $row['zone'] ?? null,
                        'quota_target' => $row['quota_target'] ?? null,
                        'starts_at' => $row['starts_at'] ?? null,
                        'ends_at' => $row['ends_at'] ?? null,
                    ],
                );
            }

            return EnumeratorAssignment::query()
                ->with('user')
                ->where('survey_id', $survey->id)
                ->orderBy('id')
                ->get();
        });
    }

    // ------------------------------------------------------------------ lecture

    public function publishedVersion(Survey $survey): ?SurveyVersion
    {
        return SurveyVersion::query()->forSurvey($survey->id)->published()->orderByDesc('version')->first();
    }

    public function draftOf(Survey $survey): ?SurveyVersion
    {
        return SurveyVersion::query()->forSurvey($survey->id)->draft()->orderByDesc('version')->first();
    }

    public function latestOf(Survey $survey): ?SurveyVersion
    {
        return SurveyVersion::query()->forSurvey($survey->id)->orderByDesc('version')->first();
    }

    /**
     * Version « courante » pour l'édition : brouillon, sinon publiée, sinon la plus récente.
     */
    public function currentOf(Survey $survey): ?SurveyVersion
    {
        return $this->draftOf($survey) ?? $this->publishedVersion($survey) ?? $this->latestOf($survey);
    }

    /**
     * Uuid DFS stable du questionnaire (celui de sa première version), conservé entre versions.
     */
    public function stableId(Survey $survey): string
    {
        $first = SurveyVersion::query()->forSurvey($survey->id)->orderBy('version')->first(['definition']);
        $id = $first?->definition['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : (string) Str::uuid();
    }

    // ------------------------------------------------------------------ internes

    /**
     * Compatibilité avec la dernière version publiée (README § 15) : une clé existante ne doit pas changer
     * de type (erreur `type_changed`) ; une liste de choix ne devrait pas perdre un code (`choice_removed`),
     * supprimer une question est permis (`key_removed`, avertissement).
     *
     * @param  array<string, mixed>  $definition
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function compatibilityIssues(array $definition, SurveyVersion $published): array
    {
        $previous = is_array($published->question_index) && $published->question_index !== []
            ? $published->question_index
            : QuestionIndexBuilder::build($published->definition ?? []);
        $current = QuestionIndexBuilder::build($definition);
        $pointers = $this->keyPointers($definition);

        $errors = [];
        $warnings = [];

        foreach ($previous as $key => $entry) {
            $key = (string) $key;
            if (! isset($current[$key])) {
                $warnings[] = [
                    'path' => '/sections',
                    'code' => 'key_removed',
                    'message' => "La question « {$key} » de la version {$published->version} n'existe plus (les anciennes réponses seront NULL).",
                    'severity' => 'warning',
                ];

                continue;
            }

            $pointer = $pointers[$key] ?? '/sections';
            $oldType = (string) ($entry['type'] ?? '');
            $newType = (string) ($current[$key]['type'] ?? '');
            if ($oldType !== '' && $newType !== '' && $oldType !== $newType) {
                $errors[] = [
                    'path' => $pointer.'/type',
                    'code' => 'type_changed',
                    'message' => "La question « {$key} » était de type « {$oldType} » dans la version {$published->version} ; elle ne peut pas devenir « {$newType} » (créez une nouvelle clé).",
                    'severity' => 'error',
                ];
            }

            $oldChoices = array_column($entry['choices'] ?? [], 'name');
            $newChoices = array_column($current[$key]['choices'] ?? [], 'name');
            $removed = array_values(array_diff($oldChoices, $newChoices));
            if ($oldChoices !== [] && $removed !== []) {
                $warnings[] = [
                    'path' => $pointer.'/choices',
                    'code' => 'choice_removed',
                    'message' => sprintf('La question « %s » perd le(s) code(s) « %s » présent(s) dans la version %d.', $key, implode('», «', $removed), $published->version),
                    'severity' => 'warning',
                ];
            }
        }

        return [$errors, $warnings];
    }

    /**
     * Pointeurs JSON (RFC 6901) de chaque question par clé.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function keyPointers(array $definition): array
    {
        $pointers = [];

        foreach ($definition['sections'] ?? [] as $i => $section) {
            foreach ($section['items'] ?? [] as $j => $item) {
                $base = "/sections/{$i}/items/{$j}";
                if (($item['type'] ?? null) === 'group') {
                    foreach ($item['items'] ?? [] as $k => $child) {
                        if (is_string($child['key'] ?? null)) {
                            $pointers[$child['key']] = "{$base}/items/{$k}";
                        }
                    }

                    continue;
                }
                if (is_string($item['key'] ?? null)) {
                    $pointers[$item['key']] = $base;
                }
            }
        }

        foreach ($definition['follow_up_stages'] ?? [] as $i => $stage) {
            foreach ($stage['items'] ?? [] as $j => $item) {
                if (is_string($item['key'] ?? null)) {
                    $pointers[$item['key']] = "/follow_up_stages/{$i}/items/{$j}";
                }
            }
        }

        return $pointers;
    }

    /**
     * Validation structurelle bloquante ; renvoie les avertissements (dont les erreurs sémantiques,
     * bloquantes seulement à la publication).
     *
     * @param  array<string, mixed>  $definition
     * @return array<int, array{path: string, code: string, message: string, severity: string}>
     *
     * @throws DfsInvalidDefinitionException
     */
    private function assertStructurallyValid(array $definition): array
    {
        $result = $this->validator->validate($definition);

        $structural = array_values(array_filter($result->errors, static fn (array $e) => in_array($e['code'], self::STRUCTURAL_CODES, true)));
        $semantic = array_values(array_filter($result->errors, static fn (array $e) => ! in_array($e['code'], self::STRUCTURAL_CODES, true)));

        if ($structural !== []) {
            throw new DfsInvalidDefinitionException($structural, array_merge($semantic, $result->warnings));
        }

        return array_merge($semantic, $result->warnings);
    }

    /**
     * Impose `id` (uuid stable) et `version` ; conserve l'ordre des autres clés.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizeDefinition(array $definition, string $id, int $version): array
    {
        $definition['dfs_version'] = is_string($definition['dfs_version'] ?? null) ? $definition['dfs_version'] : '1.0';
        $definition['id'] = $id;
        $definition['version'] = $version;

        return $definition;
    }

    private function newDraftFrom(Survey $survey, SurveyVersion $source): SurveyVersion
    {
        $number = (int) (SurveyVersion::query()->forSurvey($survey->id)->max('version') ?? 0) + 1;

        return new SurveyVersion([
            'survey_id' => $survey->id,
            'version' => $number,
            'status' => VersionStatus::Draft,
            'definition' => $this->normalizeDefinition($source->definition ?? [], $this->stableId($survey), $number),
            'revision' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function syncTitleFromDefinition(Survey $survey, array $definition): void
    {
        $lang = $definition['settings']['default_language'] ?? self::DEFAULT_LANGUAGE;
        $title = $definition['title'][$lang] ?? null;
        if (is_string($title) && trim($title) !== '' && trim($title) !== $survey->title) {
            $survey->title = Str::limit(trim($title), 200, '');
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    private function withDefaultLanguageTitle(array $definition, string $title): array
    {
        $lang = $definition['settings']['default_language'] ?? self::DEFAULT_LANGUAGE;
        $i18n = is_array($definition['title'] ?? null) ? $definition['title'] : [];
        $i18n[$lang] = $title;

        return $i18n;
    }

    /**
     * @return array<string, mixed>
     */
    private function conflictMeta(SurveyVersion $version): array
    {
        return [
            'version' => $version->version,
            'updated_at' => $version->updated_at?->toIso8601String(),
            'updated_by' => null,
        ];
    }

    private function uniqueSlug(int $projectId, string $title): string
    {
        $base = Str::limit(Str::slug($title) ?: 'questionnaire', 100, '');
        $slug = $base;
        $n = 1;

        while (Survey::withTrashed()->forProject($projectId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    private function lock(Survey $survey): Survey
    {
        return Survey::query()->lockForUpdate()->findOrFail($survey->id);
    }
}
