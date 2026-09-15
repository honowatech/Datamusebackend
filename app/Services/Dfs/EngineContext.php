<?php

namespace App\Services\Dfs;

use Closure;

/**
 * Contexte d'exécution d'un `FormEngine` : identité de l'entretien, horloge et allocation du
 * compteur de code fiche (README § 11 et § 16.5).
 *
 * - `enumeratorId`, `deviceId`, `zone`, `lang` alimentent `_enumerator`, `_device`, `_zone`, `_lang`
 *   (`lang` null → `settings.default_language`).
 * - `startTime` (ISO-8601) alimente `_start_time` ; null → instant de construction du moteur.
 * - `endTime` force `_end_time` ; sinon figé par `FormEngine::finalize()`.
 * - `status` force `_status` ; sinon statut courant du moteur.
 * - `seq` : compteur déjà alloué (`_seq`) ; sinon `seqProvider` est appelé une seule fois quand toutes
 *   les sources du code fiche sont répondues, avec `['scope' => …, 'enumerator_id' => …,
 *   'device_id' => …, 'zone' => …, 'sources' => [jeton => valeur]]`, et doit renvoyer un entier
 *   (ou null si l'allocation est impossible).
 * - `now` (ISO-8601 avec décalage) et `today` (YYYY-MM-DD) fixent l'horloge (`now`, `today`,
 *   `date_diff`) ; null → horloge machine.
 */
final class EngineContext
{
    public ?Closure $seqProvider;

    public function __construct(
        public ?int $enumeratorId = null,
        public ?string $deviceId = null,
        public ?string $zone = null,
        public ?string $lang = null,
        public ?string $startTime = null,
        public ?string $endTime = null,
        public ?string $status = null,
        public ?int $seq = null,
        ?callable $seqProvider = null,
        public ?string $now = null,
        public ?string $today = null,
    ) {
        $this->seqProvider = $seqProvider === null ? null : Closure::fromCallable($seqProvider);
    }

    /**
     * Applique des variables système nommées (`_enumerator`, `_device`, `_zone`, `_lang`, `_start_time`,
     * `_end_time`, `_status`, `_seq`, `_today`, `_now` / `now` / `today`) : format des étapes `context`
     * de `engine-tests.json`.
     *
     * @param  array<string, mixed>  $vars
     */
    public function applySystemVars(array $vars): void
    {
        foreach ($vars as $name => $value) {
            switch ($name) {
                case '_enumerator':
                    $this->enumeratorId = $value === null ? null : (int) $value;
                    break;
                case '_device':
                    $this->deviceId = $value === null ? null : (string) $value;
                    break;
                case '_zone':
                    $this->zone = $value === null ? null : (string) $value;
                    break;
                case '_lang':
                    $this->lang = $value === null ? null : (string) $value;
                    break;
                case '_start_time':
                    $this->startTime = $value === null ? null : (string) $value;
                    break;
                case '_end_time':
                    $this->endTime = $value === null ? null : (string) $value;
                    break;
                case '_status':
                    $this->status = $value === null ? null : (string) $value;
                    break;
                case '_seq':
                    $this->seq = $value === null ? null : (int) $value;
                    break;
                case '_today':
                case 'today':
                    $this->today = $value === null ? null : (string) $value;
                    break;
                case '_now':
                case 'now':
                    $this->now = $value === null ? null : (string) $value;
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Variables d'horloge transmises à l'évaluateur (`now`, `today`).
     *
     * @return array<string, string>
     */
    public function clockVars(): array
    {
        $vars = [];
        if ($this->now !== null) {
            $vars['now'] = $this->now;
        }
        if ($this->today !== null) {
            $vars['today'] = $this->today;
        }

        return $vars;
    }
}
