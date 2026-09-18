<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Module Enquêtes — tâches planifiées (plan § 5.4)
|--------------------------------------------------------------------------
| Nécessite `php artisan schedule:work` (dev) ou une entrée cron
| `* * * * * php artisan schedule:run` (production), en plus de `php artisan queue:work`.
*/

// ==== B-08 ==== Suivis échus : réévaluation de `relevant` puis passage en « manqué ».
Schedule::command('follow-ups:mark-missed')->hourly()->withoutOverlapping();
