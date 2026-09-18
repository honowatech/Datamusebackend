<?php

namespace App\Services\Survey;

use App\Models\Survey;

/**
 * B-11 — contexte factuel transmis au modèle pour la **synthèse** et les **rapports**.
 *
 * Principe du plan § 5.3 : le modèle ne voit **jamais** les lignes brutes, uniquement des agrégats
 * (`SurveyStatsService`), les thèmes de verbatims et quelques citations marquantes. Conséquences :
 *   - aucune question `tags: ["pii"]` n'entre dans le contexte (`SurveyStatsService::questions()` les
 *     exclut déjà, et `VerbatimService::codableKeys()` filtre les citations) ;
 *   - le volume envoyé reste borné (top 8 modalités par question, 60 questions, 3 citations par thème),
 *     ce qui rend l'appel reproductible et peu coûteux.
 *
 * `toArray()` sert au prompt (JSON compact) ; `toMarkdown()` en donne une version lisible utilisée comme
 * message utilisateur (les modèles suivent mieux un tableau de chiffres qu'un JSON profond).
 */
class SurveyInsightContext
{
    /** Nombre maximal de questions résumées. */
    public const MAX_QUESTIONS = 60;

    /** Modalités conservées par question. */
    public const TOP_CHOICES = 8;

    /** Citations conservées par question ouverte. */
    public const MAX_QUOTES = 3;

    public function __construct(
        private readonly SurveyStatsService $stats,
        private readonly VerbatimService $verbatims,
    ) {}

    /**
     * @param  array<string, mixed>  $options  `include_verbatims` (défaut vrai), `keys`, `lang`
     * @return array<string, mixed>
     */
    public function build(Survey $survey, array $options = []): array
    {
        $filters = SurveyStatsService::filters([]);
        $overview = $this->stats->overview($survey, $filters);
        $settings = $this->stats->settings($survey);
        $lang = is_string($options['lang'] ?? null) ? $options['lang'] : ($this->stats->catalog($survey)?->defaultLanguage() ?? 'fr');

        $questions = [];
        foreach ($this->stats->questions($survey, $filters, $options['keys'] ?? [], $lang, self::TOP_CHOICES) as $question) {
            if (count($questions) >= self::MAX_QUESTIONS) {
                break;
            }
            $summary = $this->summarizeQuestion($question);
            if ($summary !== null) {
                $questions[] = $summary;
            }
        }

        $context = [
            'survey' => [
                'title' => $survey->title,
                'version' => $this->stats->version($survey)?->version,
                'language' => $lang,
                'status' => $survey->status?->value,
            ],
            'sample' => [
                'n_total' => $overview['totals']['all'] ?? 0,
                'n_valid' => $overview['totals']['valid'] ?? 0,
                'n_screened_out' => $overview['totals']['screened_out'] ?? 0,
                'n_flagged' => $overview['totals']['flagged'] ?? 0,
                'by_channel' => $overview['by_channel'] ?? [],
                'period' => [
                    'from' => $overview['first_submission_at'] ?? null,
                    'to' => $overview['last_submission_at'] ?? null,
                ],
                'duration_median_seconds' => $overview['duration']['median_seconds'] ?? null,
            ],
            'end_reasons' => $overview['end_reasons'] ?? [],
            'quotas' => $overview['quotas'] ?? [],
            'kpis' => $overview['kpis'] ?? [],
            'zones' => $this->zones($survey, $filters),
            'questions' => $questions,
        ];

        if (($options['include_verbatims'] ?? true) !== false) {
            $context['verbatims'] = $this->verbatimThemes($survey);
        }

        if (($settings['kpis'] ?? []) === [] && ($context['kpis'] ?? []) === []) {
            unset($context['kpis']);
        }

        return $context;
    }

    /**
     * Résumé d'une `QuestionStats` : on ne garde que ce qui est interprétable par le modèle.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>|null
     */
    private function summarizeQuestion(array $question): ?array
    {
        $n = (int) ($question['n'] ?? 0);
        if ($n === 0) {
            return null;
        }

        $base = [
            'key' => $question['key'],
            'label' => $question['label'],
            'section' => $question['section'] ?? null,
            'n' => $n,
            'asked' => $question['asked'] ?? $n,
        ];

        return match ($question['kind'] ?? null) {
            'choice' => $base + [
                'kind' => 'choice',
                'multiple' => (bool) ($question['multiple'] ?? false),
                'distribution' => array_map(
                    static fn (array $d): array => ['label' => $d['label'], 'count' => $d['count'], 'pct' => $d['pct']],
                    array_slice(array_filter($question['distribution'] ?? [], static fn (array $d): bool => $d['count'] > 0), 0, self::TOP_CHOICES),
                ),
            ],
            'numeric' => $base + [
                'kind' => 'numeric',
                'mean' => $question['mean'] ?? null,
                'median' => $question['median'] ?? null,
                'min' => $question['min'] ?? null,
                'max' => $question['max'] ?? null,
                'unit' => $question['unit'] ?? null,
            ],
            'text' => $base + [
                'kind' => 'text',
                'themes' => array_slice($question['themes'] ?? [], 0, self::TOP_CHOICES),
            ],
            'date' => $base + ['kind' => 'date', 'min' => $question['min'] ?? null, 'max' => $question['max'] ?? null],
            default => null,
        };
    }

    /**
     * Thèmes des questions ouvertes codées + citations marquantes (les plus longues de chaque thème,
     * tronquées) — jamais de question `pii`.
     *
     * @return list<array<string, mixed>>
     */
    private function verbatimThemes(Survey $survey): array
    {
        $out = [];
        foreach ($this->verbatims->codableKeys($survey) as $key) {
            $codebook = $this->verbatims->latestCodebook($survey, $key);
            if ($codebook === null) {
                continue;
            }

            $coded = $this->verbatims->listCoded($survey, $key, ['codebook_id' => $codebook->id]);
            if ($coded['counts'] === []) {
                continue;
            }

            $labels = $this->verbatims->themeLabels($survey, $key);
            $question = $this->verbatims->textQuestion($survey, $key);

            $themes = [];
            foreach ($coded['counts'] as $theme => $count) {
                $quotes = [];
                foreach ($coded['items'] as $item) {
                    if (count($quotes) >= self::MAX_QUOTES || ! in_array($theme, $item['themes'], true)) {
                        continue;
                    }
                    $quotes[] = mb_substr($item['text'], 0, 240);
                }
                $themes[] = [
                    'label' => $labels[$theme] ?? $theme,
                    'count' => $count,
                    'pct' => count($coded['items']) === 0 ? 0.0 : round($count / count($coded['items']) * 100, 1),
                    'quotes' => $quotes,
                ];
            }

            $out[] = [
                'question_key' => $key,
                'question' => $question['label'],
                'n_coded' => count(array_filter($coded['items'], static fn (array $i): bool => $i['themes'] !== [])),
                'themes' => $themes,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function zones(Survey $survey, array $filters): array
    {
        $zones = $this->stats->zones($survey, $filters);

        return array_map(
            static fn (array $z): array => array_intersect_key($z, array_flip(['zone', 'total', 'valid', 'screened_out'])),
            array_slice(is_array($zones) ? array_values($zones) : [], 0, 20),
        );
    }

    /**
     * Version markdown compacte : c'est le message utilisateur envoyé au modèle.
     *
     * @param  array<string, mixed>  $context
     */
    public function toMarkdown(array $context): string
    {
        $out = [];
        $survey = $context['survey'] ?? [];
        $sample = $context['sample'] ?? [];

        $out[] = '# Enquête : '.($survey['title'] ?? '');
        $out[] = sprintf(
            "## Échantillon\n- Fiches exploitables : %d (dont %d valides, %d hors cible)\n- Période : %s → %s\n- Durée médiane : %s s\n- Canaux : %s",
            (int) ($sample['n_total'] ?? 0),
            (int) ($sample['n_valid'] ?? 0),
            (int) ($sample['n_screened_out'] ?? 0),
            (string) ($sample['period']['from'] ?? '?'),
            (string) ($sample['period']['to'] ?? '?'),
            (string) ($sample['duration_median_seconds'] ?? '?'),
            json_encode($sample['by_channel'] ?? [], JSON_UNESCAPED_UNICODE),
        );

        if (($context['kpis'] ?? []) !== []) {
            $lines = ['## Indicateurs clés'];
            foreach ($context['kpis'] as $kpi) {
                $lines[] = sprintf('- %s : %s (%s/%s)',
                    $kpi['label'] ?? $kpi['key'] ?? '?',
                    is_numeric($kpi['value'] ?? null) ? round((float) $kpi['value'] * 100, 1).' %' : 'n/d',
                    (string) ($kpi['numerator'] ?? '?'),
                    (string) ($kpi['denominator'] ?? '?'),
                );
            }
            $out[] = implode("\n", $lines);
        }

        if (($context['quotas'] ?? []) !== []) {
            $lines = ['## Quotas'];
            foreach ($context['quotas'] as $quota) {
                $lines[] = sprintf('- %s : %s/%s (%s %%)',
                    $quota['label'] ?? $quota['key'] ?? '?',
                    (string) ($quota['current'] ?? '?'),
                    (string) ($quota['target'] ?? '?'),
                    (string) ($quota['pct'] ?? '?'),
                );
            }
            $out[] = implode("\n", $lines);
        }

        if (($context['end_reasons'] ?? []) !== []) {
            $lines = ['## Motifs de sortie (hors cible)'];
            foreach ($context['end_reasons'] as $reason) {
                $lines[] = sprintf('- %s : %d', $reason['label'] ?? $reason['key'] ?? '?', (int) ($reason['count'] ?? 0));
            }
            $out[] = implode("\n", $lines);
        }

        if (($context['zones'] ?? []) !== []) {
            $lines = ['## Zones'];
            foreach ($context['zones'] as $zone) {
                $lines[] = sprintf('- %s : %d fiches (%d valides)', $zone['zone'] ?? '?', (int) ($zone['total'] ?? 0), (int) ($zone['valid'] ?? 0));
            }
            $out[] = implode("\n", $lines);
        }

        if (($context['questions'] ?? []) !== []) {
            $lines = ['## Résultats par question'];
            foreach ($context['questions'] as $question) {
                $lines[] = sprintf('### %s (`%s`, n = %d)', $question['label'] ?? '', $question['key'] ?? '', (int) ($question['n'] ?? 0));
                if (($question['kind'] ?? null) === 'choice') {
                    foreach ($question['distribution'] ?? [] as $d) {
                        $lines[] = sprintf('- %s : %d (%s %%)', $d['label'] ?? '', (int) ($d['count'] ?? 0), (string) ($d['pct'] ?? '0'));
                    }
                } elseif (($question['kind'] ?? null) === 'numeric') {
                    $lines[] = sprintf('- moyenne %s · médiane %s · min %s · max %s',
                        (string) ($question['mean'] ?? '?'), (string) ($question['median'] ?? '?'),
                        (string) ($question['min'] ?? '?'), (string) ($question['max'] ?? '?'));
                } elseif (($question['kind'] ?? null) === 'text') {
                    foreach ($question['themes'] ?? [] as $theme) {
                        $lines[] = sprintf('- thème « %s » : %d (%s %%)', $theme['label'] ?? '', (int) ($theme['count'] ?? 0), (string) ($theme['pct'] ?? '0'));
                    }
                }
            }
            $out[] = implode("\n", $lines);
        }

        foreach ($context['verbatims'] ?? [] as $block) {
            $lines = [sprintf('## Verbatims — %s (`%s`, %d codés)', $block['question'] ?? '', $block['question_key'] ?? '', (int) ($block['n_coded'] ?? 0))];
            foreach ($block['themes'] ?? [] as $theme) {
                $lines[] = sprintf('- **%s** : %d (%s %%)', $theme['label'] ?? '', (int) ($theme['count'] ?? 0), (string) ($theme['pct'] ?? '0'));
                foreach ($theme['quotes'] ?? [] as $quote) {
                    $lines[] = '  > '.str_replace("\n", ' ', $quote);
                }
            }
            $out[] = implode("\n", $lines);
        }

        return implode("\n\n", $out);
    }
}
