<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessMetricController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DatabaseConnectionController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\Mobile\DeviceController;
use App\Http\Controllers\Mobile\FollowUpController;
use App\Http\Controllers\Mobile\FormController;
use App\Http\Controllers\Mobile\MediaUploadController;
use App\Http\Controllers\Mobile\PingController;
use App\Http\Controllers\Mobile\SubmissionStatusController;
use App\Http\Controllers\Mobile\SubmissionSyncController;
use App\Http\Controllers\Public\PublicSurveyController;
use App\Http\Controllers\Survey\DatasourceController;
use App\Http\Controllers\Survey\InvitationController;
use App\Http\Controllers\Survey\ProjectController;
use App\Http\Controllers\Survey\ProjectMemberController;
use App\Http\Controllers\Survey\PublicLinkController;
use App\Http\Controllers\Survey\ReportController;
use App\Http\Controllers\Survey\StatsController;
use App\Http\Controllers\Survey\SubmissionController;
use App\Http\Controllers\Survey\SubmissionExportController;
use App\Http\Controllers\Survey\SupervisionController;
use App\Http\Controllers\Survey\SurveyController;
use App\Http\Controllers\Survey\SurveyGenerateController;
use App\Http\Controllers\Survey\SurveyVersionController;
use App\Http\Controllers\Survey\SurveyXlsFormController;
use App\Http\Controllers\Survey\VerbatimController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/user/settings', [AuthController::class, 'updateSettings']);

    // DB connections management
    Route::post('/target-db/connect', [DatabaseConnectionController::class, 'connect']);
    Route::post('/target-db/import-file', [DatabaseConnectionController::class, 'importFile']);
    Route::get('/target-dbs', [DatabaseConnectionController::class, 'index']);
    Route::delete('/target-dbs/{id}', [DatabaseConnectionController::class, 'destroy']);

    // Chat endpoints
    Route::post('/chat/generate-sql', [ChatController::class, 'generateSql']);
    Route::post('/chat/execute-sql', [ChatController::class, 'executeSql']);
    Route::post('/chat/analyze-data', [ChatController::class, 'analyzeData']);
    Route::post('/chat/profile-data', [ChatController::class, 'profileData']);

    // Business Metrics (Semantic Layer)
    Route::get('/business-metrics', [BusinessMetricController::class, 'index']);
    Route::post('/business-metrics', [BusinessMetricController::class, 'store']);
    Route::put('/business-metrics/{id}', [BusinessMetricController::class, 'update']);
    Route::delete('/business-metrics/{id}', [BusinessMetricController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Module Enquêtes — docs/openapi/survey.yaml
|--------------------------------------------------------------------------
| Enveloppe {success, data, meta?} ; limiteurs nommés dans AppServiceProvider ;
| erreurs rendues par bootstrap/app.php. Chaque tâche ajoute son bloc `// ==== B-xx ====`.
*/

// ==== B-02 ==== Endpoints web authentifiés (60/min/utilisateur)
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Jobs asynchrones (contrat : tag Jobs)
    Route::get('/jobs/{uuid}', [JobController::class, 'show'])->whereUuid('uuid')->name('jobs.show');

    // ==== B-03 ==== Projets, membres, invitations
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');

    Route::get('/projects/{project}/members', [ProjectMemberController::class, 'index'])->whereNumber('project')->name('projects.members.index');
    Route::put('/projects/{project}/members/{user}', [ProjectMemberController::class, 'update'])->whereNumber(['project', 'user'])->name('projects.members.update');
    Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->whereNumber(['project', 'user'])->name('projects.members.destroy');

    Route::get('/projects/{project}/invitations', [InvitationController::class, 'index'])->whereNumber('project')->name('projects.invitations.index');
    Route::post('/projects/{project}/invitations', [InvitationController::class, 'store'])->whereNumber('project')->name('projects.invitations.store');
    Route::delete('/projects/{project}/invitations/{invId}', [InvitationController::class, 'destroy'])->whereNumber(['project', 'invId'])->name('projects.invitations.destroy');
    Route::post('/invitations/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    // ==== B-05 ==== Questionnaires, versions, assignations (SurveyController, SurveyVersionController)
    Route::get('/projects/{project}/surveys', [SurveyController::class, 'index'])->whereNumber('project')->name('projects.surveys.index');
    Route::post('/projects/{project}/surveys', [SurveyController::class, 'store'])->whereNumber('project')->name('projects.surveys.store');

    // ==== B-06 ==== Routes fixes déclarées AVANT /surveys/{survey} (collision évitée aussi par whereNumber).
    Route::post('/surveys/generate', [SurveyGenerateController::class, 'generate'])->middleware('throttle:ai')->name('surveys.generate');
    Route::post('/surveys/import/xlsform', [SurveyXlsFormController::class, 'import'])->name('surveys.import.xlsform');

    Route::get('/surveys/{survey}', [SurveyController::class, 'show'])->whereNumber('survey')->name('surveys.show');
    Route::put('/surveys/{survey}', [SurveyController::class, 'update'])->whereNumber('survey')->name('surveys.update');
    Route::delete('/surveys/{survey}', [SurveyController::class, 'destroy'])->whereNumber('survey')->name('surveys.destroy');
    Route::post('/surveys/{survey}/duplicate', [SurveyController::class, 'duplicate'])->whereNumber('survey')->name('surveys.duplicate');

    Route::get('/surveys/{survey}/versions', [SurveyVersionController::class, 'index'])->whereNumber('survey')->name('surveys.versions.index');
    Route::get('/surveys/{survey}/versions/{n}', [SurveyVersionController::class, 'show'])->whereNumber(['survey', 'n'])->name('surveys.versions.show');
    Route::post('/surveys/{survey}/versions/{n}/fork', [SurveyVersionController::class, 'fork'])->whereNumber(['survey', 'n'])->name('surveys.versions.fork');
    Route::put('/surveys/{survey}/draft', [SurveyVersionController::class, 'saveDraft'])->whereNumber('survey')->name('surveys.draft.save');
    Route::post('/surveys/{survey}/validate', [SurveyVersionController::class, 'validateDraft'])->whereNumber('survey')->name('surveys.validate');
    Route::post('/surveys/{survey}/publish', [SurveyVersionController::class, 'publish'])->whereNumber('survey')->name('surveys.publish');

    Route::get('/surveys/{survey}/assignments', [SurveyVersionController::class, 'assignments'])->whereNumber('survey')->name('surveys.assignments.index');
    Route::put('/surveys/{survey}/assignments', [SurveyVersionController::class, 'syncAssignments'])->whereNumber('survey')->name('surveys.assignments.sync');

    // ==== B-06 ==== Génération IA, XLSForm, traduction (throttle:ai sur les opérations IA)
    // `/surveys/generate` et `/surveys/import/xlsform` sont déclarées plus haut (avant `/surveys/{survey}`).
    Route::get('/surveys/{survey}/export/xlsform', [SurveyXlsFormController::class, 'export'])->whereNumber('survey')->name('surveys.export.xlsform');
    Route::post('/surveys/{survey}/ai/translate', [SurveyGenerateController::class, 'translate'])->whereNumber('survey')->middleware('throttle:ai')->name('surveys.ai.translate');

    // ==== B-09b ==== Source de données matérialisée (SQLite « TargetDatabase »)
    Route::get('/surveys/{survey}/datasource', [DatasourceController::class, 'show'])->whereNumber('survey')->name('surveys.datasource.show');
    Route::post('/surveys/{survey}/datasource/rebuild', [DatasourceController::class, 'rebuild'])->whereNumber('survey')->name('surveys.datasource.rebuild');

    // ==== B-10 ==== Soumissions web, export, stats, supervision
    // Route fixe `/submissions/export` déclarée AVANT `/submissions` (pas de collision : segments distincts).
    Route::get('/surveys/{survey}/submissions', [SubmissionController::class, 'index'])->whereNumber('survey')->name('surveys.submissions.index');
    Route::get('/surveys/{survey}/submissions/export', SubmissionExportController::class)->whereNumber('survey')->name('surveys.submissions.export');
    Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->whereNumber('submission')->name('submissions.show');
    Route::patch('/submissions/{submission}', [SubmissionController::class, 'update'])->whereNumber('submission')->name('submissions.update');
    Route::delete('/submissions/{submission}', [SubmissionController::class, 'destroy'])->whereNumber('submission')->name('submissions.destroy');

    // ==== F-B ==== Fiche de réponse : consultation structurée et export CSV d'UNE soumission.
    Route::get('/submissions/{submission}/sheet', [SubmissionController::class, 'sheet'])
        ->whereNumber('submission')->name('submissions.sheet');
    // ==== /F-B ====

    Route::get('/surveys/{survey}/stats/overview', [StatsController::class, 'overview'])->whereNumber('survey')->name('surveys.stats.overview');
    Route::get('/surveys/{survey}/stats/questions', [StatsController::class, 'questions'])->whereNumber('survey')->name('surveys.stats.questions');
    Route::get('/surveys/{survey}/stats/timeline', [StatsController::class, 'timeline'])->whereNumber('survey')->name('surveys.stats.timeline');
    Route::get('/surveys/{survey}/stats/enumerators', [StatsController::class, 'enumerators'])->whereNumber('survey')->name('surveys.stats.enumerators');
    Route::get('/surveys/{survey}/stats/zones', [StatsController::class, 'zones'])->whereNumber('survey')->name('surveys.stats.zones');
    Route::get('/surveys/{survey}/stats/geo', [StatsController::class, 'geo'])->whereNumber('survey')->name('surveys.stats.geo');
    Route::get('/surveys/{survey}/stats/crosstab', [StatsController::class, 'crosstab'])->whereNumber('survey')->name('surveys.stats.crosstab');

    Route::get('/surveys/{survey}/supervision/flags', [SupervisionController::class, 'flags'])->whereNumber('survey')->name('surveys.supervision.flags');
    Route::post('/surveys/{survey}/supervision/recompute', [SupervisionController::class, 'recompute'])->whereNumber('survey')->name('surveys.supervision.recompute');

    // ==== B-11 ==== Verbatims, synthèse, rapports
    // Les routes fixes `verbatims/classify` et `verbatims/codebooks` sont déclarées AVANT
    // `verbatims/{questionKey}` (une question ne peut pas porter ces clés : validateur DFS).
    Route::post('/surveys/{survey}/verbatims/classify', [VerbatimController::class, 'classify'])
        ->whereNumber('survey')->middleware('throttle:ai')->name('surveys.verbatims.classify');
    Route::get('/surveys/{survey}/verbatims/codebooks', [VerbatimController::class, 'codebooks'])
        ->whereNumber('survey')->name('surveys.verbatims.codebooks.index');
    Route::put('/surveys/{survey}/verbatims/codebooks', [VerbatimController::class, 'storeCodebook'])
        ->whereNumber('survey')->name('surveys.verbatims.codebooks.store');
    Route::get('/surveys/{survey}/verbatims/codebooks/{codebook}', [VerbatimController::class, 'showCodebook'])
        ->whereNumber(['survey', 'codebook'])->name('surveys.verbatims.codebooks.show');
    Route::put('/surveys/{survey}/verbatims/codebooks/{codebook}', [VerbatimController::class, 'updateCodebook'])
        ->whereNumber(['survey', 'codebook'])->name('surveys.verbatims.codebooks.update');
    Route::get('/surveys/{survey}/verbatims/{questionKey}', [VerbatimController::class, 'index'])
        ->whereNumber('survey')->where('questionKey', '[A-Za-z][A-Za-z0-9_]{0,39}')->name('surveys.verbatims.index');

    Route::post('/surveys/{survey}/synthesis', [ReportController::class, 'synthesis'])
        ->whereNumber('survey')->middleware('throttle:ai')->name('surveys.synthesis');

    Route::get('/surveys/{survey}/reports', [ReportController::class, 'index'])->whereNumber('survey')->name('surveys.reports.index');
    Route::post('/surveys/{survey}/reports', [ReportController::class, 'store'])
        ->whereNumber('survey')->middleware('throttle:ai')->name('surveys.reports.store');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->whereNumber('report')->name('reports.show');
    Route::put('/reports/{report}', [ReportController::class, 'update'])->whereNumber('report')->name('reports.update');
    Route::post('/reports/{report}/regenerate-section', [ReportController::class, 'regenerateSection'])
        ->whereNumber('report')->middleware('throttle:ai')->name('reports.regenerate-section');
    Route::post('/reports/{report}/files', [ReportController::class, 'storeFile'])->whereNumber('report')->name('reports.files.store');

    // ==== B-12 ==== Gestion des liens publics (/surveys/{survey}/public-links)
    Route::get('/surveys/{survey}/public-links', [PublicLinkController::class, 'index'])->whereNumber('survey')->name('surveys.public-links.index');
    Route::post('/surveys/{survey}/public-links', [PublicLinkController::class, 'store'])->whereNumber('survey')->name('surveys.public-links.store');
    Route::delete('/surveys/{survey}/public-links/{linkId}', [PublicLinkController::class, 'destroy'])->whereNumber(['survey', 'linkId'])->name('surveys.public-links.destroy');
});

// ==== B-07 ==== Synchronisation mobile : 600/min/utilisateur, meta.server_time + X-Server-Time sur chaque réponse
Route::get('/mobile/ping', PingController::class)->middleware(['server.time', 'throttle:mobile'])->name('mobile.ping'); // public, 204
Route::prefix('mobile')->middleware(['server.time', 'auth:sanctum', 'throttle:mobile'])->group(function () {
    Route::post('/devices', [DeviceController::class, 'store'])->name('mobile.devices.store');

    Route::get('/forms', [FormController::class, 'index'])->name('mobile.forms.index');
    Route::get('/forms/{surveyId}', [FormController::class, 'show'])->whereNumber('surveyId')->name('mobile.forms.show');

    // Route fixe déclarée AVANT /submissions/{uuid}/… (pas de collision : le segment est contraint par whereUuid).
    Route::get('/submissions/status', [SubmissionStatusController::class, 'index'])->name('mobile.submissions.status');
    Route::post('/submissions', [SubmissionSyncController::class, 'store'])->name('mobile.submissions.sync');
    Route::post('/submissions/{uuid}/media/{questionKey}', [MediaUploadController::class, 'store'])
        ->whereUuid('uuid')
        ->where('questionKey', '[A-Za-z][A-Za-z0-9_]{0,39}')
        ->name('mobile.submissions.media');

    // ==== B-08 ==== Suivis longitudinaux (README DFS § 14)
    Route::get('/follow-ups/due', [FollowUpController::class, 'index'])->name('mobile.followups.due');
    Route::post('/follow-ups', [FollowUpController::class, 'store'])->name('mobile.followups.sync');
    // Média capturé dans une étape : stocké sur la soumission **parente** (l'étape n'en crée pas).
    Route::post('/follow-ups/{parentUuid}/{stageKey}/media/{questionKey}', [MediaUploadController::class, 'storeStage'])
        ->whereUuid('parentUuid')
        ->where('stageKey', '[A-Za-z][A-Za-z0-9_]{0,39}')
        ->where('questionKey', '[A-Za-z][A-Za-z0-9_]{0,39}')
        ->name('mobile.followups.media');
});

// ==== B-07 ==== Médias : URL signée temporaire (Storage privé), nommée pour URL::temporarySignedRoute()
Route::get('/media/{media}', [MediaUploadController::class, 'show'])
    ->whereNumber('media')
    ->middleware('signed')
    ->name('media.show');

// ==== B-11 ==== Fichiers archivés d'un rapport : URL signée temporaire (disque privé), hors Sanctum
Route::get('/reports/{report}/files/{fileId}', [ReportController::class, 'downloadFile'])
    ->whereNumber(['report', 'fileId'])
    ->middleware('signed')
    ->name('reports.files.show');

// ==== B-12 ==== Collecte publique : CORS ouvert (PublicCors, global sur api/public/*), limites par IP
Route::prefix('public')->middleware(['public.cors'])->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        Route::get('/surveys/{token}', [PublicSurveyController::class, 'show'])
            ->where('token', '[A-Za-z0-9_-]{1,64}')
            ->name('public.surveys.show');
    });
    Route::middleware('throttle:public-write')->group(function () {
        Route::post('/surveys/{token}/submissions', [PublicSurveyController::class, 'submit'])
            ->where('token', '[A-Za-z0-9_-]{1,64}')
            ->name('public.surveys.submit');
        Route::post('/surveys/{token}/submissions/{uuid}/media/{questionKey}', [PublicSurveyController::class, 'media'])
            ->where('token', '[A-Za-z0-9_-]{1,64}')
            ->whereUuid('uuid')
            ->where('questionKey', '[A-Za-z][A-Za-z0-9_]{0,39}')
            ->name('public.surveys.media');
    });
});
