<?php

namespace App\Services\Survey;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\Survey;
use App\Models\SurveyVersion;
use App\Services\Dfs\QuestionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Filtres partagés par `GET /surveys/{id}/submissions` (B-10 liste) et
 * `GET /surveys/{id}/submissions/export` : `status`, `channel`, `enumerator_id`, `zone`, `version`,
 * `from`/`to` (sur `ended_at`), `flag`, `q`.
 *
 * Aucune requête JSON-path (portabilité SQLite/MySQL) : le drapeau est cherché par `LIKE` sur la
 * colonne `flags` sérialisée, et `q` pré-filtre par `LIKE` puis compare réellement les valeurs en PHP,
 * en ignorant les questions `tags: ["pii"]`.
 */
class SubmissionFilter
{
    /** Nombre maximal de fiches inspectées par la recherche plein texte `q`. */
    public const SEARCH_SCAN_LIMIT = 5000;

    /**
     * @param  int|null  $restrictToEnumerator  limite aux fiches d'un enquêteur (rôle `enqueteur`)
     * @return Builder<Submission>
     */
    public function apply(Survey $survey, Request $request, ?int $restrictToEnumerator = null): Builder
    {
        $query = Submission::query()->where('survey_id', $survey->id);

        if ($restrictToEnumerator !== null) {
            $query->where('enumerator_id', $restrictToEnumerator);
        }

        // Sans filtre `status`, toutes les fiches sont listées (y compris `rejected`, visibles en revue).
        $status = $request->query('status');
        if (is_string($status) && in_array($status, SubmissionStatus::values(), true)) {
            $query->where('status', $status);
        }

        $channel = $request->query('channel');
        if (is_string($channel) && $channel !== '') {
            $query->where('channel', $channel);
        }

        $enumeratorId = (int) $request->query('enumerator_id', 0);
        if ($enumeratorId > 0) {
            $query->where('enumerator_id', $enumeratorId);
        }

        $zone = $request->query('zone');
        if (is_string($zone) && $zone !== '') {
            $query->where('zone', $zone);
        }

        $version = (int) $request->query('version', 0);
        if ($version > 0) {
            $query->whereIn('survey_version_id', SurveyVersion::query()
                ->forSurvey($survey->id)->where('version', $version)->pluck('id'));
        }

        foreach ([['from', '>='], ['to', '<=']] as [$param, $operator]) {
            $date = self::date($request->query($param));
            if ($date !== null) {
                $query->where('ended_at', $operator, $param === 'from' ? $date->startOfDay() : $date->endOfDay());
            }
        }

        $flag = $request->query('flag');
        if (is_string($flag) && in_array($flag, Submission::FLAGS, true)) {
            $query->where('flags', 'like', '%"'.$flag.'"%');
        }

        $needle = $request->query('q');
        if (is_string($needle) && trim($needle) !== '') {
            $query->whereIn('id', $this->searchIds($survey, trim($needle)));
        }

        return $query;
    }

    private static function date(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Identifiants correspondant à `q` : `fiche_code` ou valeur d'une réponse **non `pii`**.
     *
     * @return list<int>
     */
    public function searchIds(Survey $survey, string $needle): array
    {
        $pii = self::piiKeys($survey);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $needle).'%';

        $rows = Submission::query()
            ->where('survey_id', $survey->id)
            ->where(fn ($q) => $q->where('fiche_code', 'like', $like)->orWhere('answers', 'like', $like))
            ->limit(self::SEARCH_SCAN_LIMIT)
            ->get(['id', 'fiche_code', 'answers']);

        $ids = [];
        $lower = mb_strtolower($needle);
        foreach ($rows as $row) {
            if ($row->fiche_code !== null && str_contains(mb_strtolower((string) $row->fiche_code), $lower)) {
                $ids[] = (int) $row->id;

                continue;
            }
            foreach ((array) $row->answers as $key => $value) {
                if (in_array((string) $key, $pii, true) || ! is_scalar($value)) {
                    continue;
                }
                if (str_contains(mb_strtolower((string) $value), $lower)) {
                    $ids[] = (int) $row->id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Clés de questions `tags: ["pii"]` de la version publiée.
     *
     * @return list<string>
     */
    public static function piiKeys(Survey $survey): array
    {
        $version = $survey->publishedVersion ?? $survey->versions()->orderByDesc('version')->first();
        if ($version === null) {
            return [];
        }

        $keys = [];
        foreach (QuestionCatalog::fromDefinition($version->definition ?? [])->nodes() as $node) {
            if (($node['kind'] ?? null) === 'question' && ReponsesLayout::isPii(is_array($node['def'] ?? null) ? $node['def'] : [])) {
                $keys[] = (string) $node['key'];
            }
        }

        return $keys;
    }
}
