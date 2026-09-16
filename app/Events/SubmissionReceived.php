<?php

namespace App\Events;

use App\Models\Submission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Émis après commit à chaque soumission acceptée ou mise à jour par la synchronisation
 * (mobile B-07, lien public B-12, web B-10).
 *
 * Écouteurs prévus :
 *  - B-08 : création / réévaluation des `FollowUpEntry` des étapes de suivi (README § 14) ;
 *  - B-09b : `MaterializeSurveyDatasourceJob` (ShouldBeUnique par questionnaire, delay 30 s).
 *
 * `$created` distingue une première réception (`accepted`) d'un remplacement (`updated`) ;
 * les drapeaux qualité sont déjà calculés et persistés au moment de l'émission.
 */
class SubmissionReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Submission $submission,
        public readonly bool $created = true,
    ) {}
}
