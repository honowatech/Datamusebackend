<?php

namespace App\Console\Commands;

use App\Services\Survey\FollowUpService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * B-08 — tâche planifiée horaire (README DFS § 14, plan § 5.4).
 *
 * 1. Réévalue `relevant` des entrées `pending` échues avec les réponses de base et celles des étapes
 *    déjà complétées → `skipped` si l'étape n'est plus pertinente (« J+14 seulement si retiré à J+7 ») ;
 * 2. marque `missed` les entrées `pending` dont `window_ends_at` est dépassée.
 */
class MarkMissedFollowUpsCommand extends Command
{
    protected $signature = 'follow-ups:mark-missed
                            {--at= : Instant de référence (ISO-8601), défaut maintenant}';

    protected $description = 'Réévalue les suivis échus et marque « manqué » ceux dont la fenêtre est dépassée';

    public function handle(FollowUpService $followUps): int
    {
        $at = $this->option('at');
        $counts = $followUps->markMissed($at ? Carbon::parse((string) $at) : null);

        $this->info(sprintf(
            'Suivis : %d passé(s) en « ignoré », %d passé(s) en « manqué ».',
            $counts['skipped'],
            $counts['missed'],
        ));

        return self::SUCCESS;
    }
}
