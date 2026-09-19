<?php

namespace App\Services\Survey;

use App\Enums\FollowUpStatus;
use App\Enums\ProjectRole;
use App\Enums\SubmissionStatus;
use App\Enums\SurveyStatus;
use App\Models\EnumeratorAssignment;
use App\Models\FollowUpEntry;
use App\Models\ProjectMember;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Manifest `GET /mobile/forms` (schéma OpenAPI `MobileFormManifestItem`).
 *
 * Périmètre : questionnaires `active` **et publiés**
 *  - assignés à l'utilisateur (`enumerator_assignments`, fenêtre `starts_at`/`ends_at` ouverte) ;
 *  - ou appartenant à un projet où il est superviseur / analyste (ou administrateur global).
 *
 * Chaque entrée porte la version publiée et son `definition_hash` (le client télécharge la
 * définition via `GET /mobile/forms/{surveyId}` quand ce hash lui est inconnu), l'assignation
 * (`zone`, `quota_target`, `assignment_ends_at` — repris aussi dans `assignment` pour le client),
 * les compteurs de l'enquêteur, la progression des quotas et un `settings_summary` permettant à
 * l'application d'afficher la fiche du formulaire sans parser tout le DFS.
 *
 * `etag()` produit l'empreinte forte servie dans `ETag` : sha256 du manifest canonique.
 */
class MobileManifestService
{
    public function __construct(private readonly SubmissionQualityService $quality = new SubmissionQualityService) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function build(User $user): array
    {
        $surveys = $this->visibleSurveys($user);
        if ($surveys->isEmpty()) {
            return [];
        }

        $surveyIds = $surveys->pluck('id')->all();

        $assignments = EnumeratorAssignment::query()
            ->whereIn('survey_id', $surveyIds)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('survey_id');

        // `toBase()` : l'agrégat est lu en lignes **brutes** (stdClass). Sur une collection Eloquent,
        // `Submission::$casts['status']` ferait de `status` un enum, et `pluck('total', 'status')`
        // lèverait « Illegal offset type » dès que l'enquêteur a une soumission (bogue M-13).
        $counts = Submission::query()
            ->whereIn('survey_id', $surveyIds)
            ->where('enumerator_id', $user->id)
            ->selectRaw('survey_id, status, count(*) as total')
            ->groupBy('survey_id', 'status')
            ->toBase()
            ->get()
            ->groupBy('survey_id');

        $dueFollowUps = FollowUpEntry::query()
            ->whereIn('survey_id', $surveyIds)
            ->where('enumerator_id', $user->id)
            ->where('status', FollowUpStatus::Pending->value)
            ->where('due_at', '<=', now()->addDays(7))
            ->selectRaw('survey_id, count(*) as total')
            ->groupBy('survey_id')
            ->pluck('total', 'survey_id');

        $out = [];
        foreach ($surveys as $survey) {
            /** @var SurveyVersion|null $version */
            $version = $survey->publishedVersion;
            if ($version === null) {
                continue;
            }
            $settings = $version->settings();
            $assignment = $assignments->get($survey->id);
            $statusCounts = ($counts->get($survey->id) ?? collect())->pluck('total', 'status');

            $out[] = [
                'survey_id' => $survey->id,
                'title' => $survey->title,
                'version' => (int) $version->version,
                'definition_hash' => (string) $version->definition_hash,
                'status' => $survey->status->value,
                'published_at' => $version->published_at?->toIso8601String(),
                'updated_at' => $version->updated_at?->toIso8601String(),
                'languages' => array_values((array) ($settings['languages'] ?? ['fr'])),
                'default_language' => (string) ($settings['default_language'] ?? 'fr'),
                'zone' => $assignment?->zone,
                'quota_target' => $assignment?->quota_target,
                'assignment_ends_at' => $assignment?->ends_at?->toDateString(),
                // Forme groupée attendue par l'app mobile (M-05) — doublon assumé des clés plates ci-dessus.
                'assignment' => [
                    'zone' => $assignment?->zone,
                    'quota_target' => $assignment?->quota_target,
                    'starts_at' => $assignment?->starts_at?->toDateString(),
                    'ends_at' => $assignment?->ends_at?->toDateString(),
                ],
                'my_counts' => [
                    'completed' => (int) ($statusCounts[SubmissionStatus::Submitted->value] ?? 0)
                        + (int) ($statusCounts[SubmissionStatus::Validated->value] ?? 0),
                    'screened_out' => (int) ($statusCounts[SubmissionStatus::ScreenedOut->value] ?? 0),
                    'rejected' => (int) ($statusCounts[SubmissionStatus::Rejected->value] ?? 0),
                    'follow_ups_due' => (int) ($dueFollowUps[$survey->id] ?? 0),
                ],
                'quotas' => $this->quality->quotaProgress($survey, $settings, $user->id, $assignment?->zone),
                'settings_summary' => self::settingsSummary($settings, $version),
            ];
        }

        return $out;
    }

    /**
     * Empreinte forte du manifest (`ETag: "sha256-…"`).
     *
     * @param  list<array<string, mixed>>  $manifest
     */
    public function etag(array $manifest): string
    {
        return '"sha256-'.hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'"';
    }

    /**
     * Résumé des réglages utile au terrain (pas de duplication du DFS : la définition complète est
     * téléchargée séparément et vérifiée par son hash).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function settingsSummary(array $settings, SurveyVersion $version): array
    {
        $definition = $version->definition ?? [];

        return [
            'languages' => array_values((array) ($settings['languages'] ?? ['fr'])),
            'default_language' => (string) ($settings['default_language'] ?? 'fr'),
            'pagination' => (string) ($settings['pagination'] ?? 'section'),
            'geo' => [
                'capture' => (string) ($settings['geo']['capture'] ?? 'none'),
                'required' => (bool) ($settings['geo']['required'] ?? false),
                'accuracy_max_m' => $settings['geo']['accuracy_max_m'] ?? null,
            ],
            'timing' => [
                'min_duration_seconds' => $settings['timing']['min_duration_seconds'] ?? null,
                'max_duration_seconds' => $settings['timing']['max_duration_seconds'] ?? null,
            ],
            'fiche_code' => isset($settings['fiche_code']['pattern']) ? [
                'pattern' => (string) $settings['fiche_code']['pattern'],
                'counter' => (array) ($settings['fiche_code']['counter'] ?? []),
            ] : null,
            'duplicate_keys' => array_values((array) ($settings['duplicate_keys'] ?? [])),
            'followup_contact_keys' => array_values((array) ($settings['followup_contact_keys'] ?? [])),
            'enumerator_can_edit_after_submit' => (bool) ($settings['enumerator_can_edit_after_submit'] ?? false),
            'allow_public_link' => (bool) ($settings['allow_public_link'] ?? false),
            'sections_count' => count((array) ($definition['sections'] ?? [])),
            'questions_count' => count((array) ($version->question_index ?? [])),
            'follow_up_stages' => array_values(array_map(
                static fn (array $stage): array => [
                    'key' => (string) ($stage['key'] ?? ''),
                    'due_offset_days' => (int) ($stage['due_offset_days'] ?? 0),
                    'window_days' => (int) ($stage['window_days'] ?? 0),
                    'channel' => $stage['channel'] ?? null,
                ],
                array_filter((array) ($definition['follow_up_stages'] ?? []), 'is_array'),
            )),
        ];
    }

    /**
     * Le mobile peut-il lire / collecter ce questionnaire ?
     */
    public function canAccess(User $user, Survey $survey): bool
    {
        return $user->can('collect', $survey);
    }

    /**
     * @return Collection<int, Survey>
     */
    private function visibleSurveys(User $user): Collection
    {
        $assignedIds = EnumeratorAssignment::query()
            ->where('user_id', $user->id)
            ->current()
            ->pluck('survey_id');

        $supervisedProjectIds = ProjectMember::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('role', [ProjectRole::Superviseur->value, ProjectRole::Analyste->value])
            ->pluck('project_id');

        return Survey::query()
            ->with('publishedVersion')
            ->where('status', SurveyStatus::Active->value)
            ->whereNotNull('published_version_id')
            ->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($inner) => $inner
                ->whereIn('id', $assignedIds)
                ->orWhereIn('project_id', $supervisedProjectIds)
                ->orWhereHas('project', fn ($p) => $p->where('owner_id', $user->id))))
            ->orderBy('id')
            ->get();
    }
}
