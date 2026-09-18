<?php

namespace App\Listeners;

use App\Events\SubmissionReceived;
use App\Services\Survey\FollowUpService;

/**
 * B-08 — création / mise à jour des entrées de suivi à chaque soumission reçue (README DFS § 14).
 *
 * Écouteur **synchrone** (découvert automatiquement dans `app/Listeners`) : la création d'entrées est
 * une simple écriture et le mobile doit voir ses suivis dès la synchronisation suivante. Toute erreur
 * est rapportée sans faire échouer la réception (`FollowUpService::syncEntriesFor`).
 */
class CreateFollowUpEntries
{
    public function __construct(private readonly FollowUpService $followUps) {}

    public function handle(SubmissionReceived $event): void
    {
        $this->followUps->syncEntriesFor($event->submission);
    }
}
