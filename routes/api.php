<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessMetricController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DatabaseConnectionController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\Survey\InvitationController;
use App\Http\Controllers\Survey\ProjectController;
use App\Http\Controllers\Survey\ProjectMemberController;
use App\Http\Controllers\Survey\SurveyController;
use App\Http\Controllers\Survey\SurveyVersionController;
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

    // Réservé B-06b — routes fixes déclarées AVANT /surveys/{survey} (collision évitée aussi par whereNumber) :
    // Route::post('/surveys/generate', [SurveyGenerateController::class, 'generate'])->middleware('throttle:ai')->name('surveys.generate');
    // Route::post('/surveys/import/xlsform', [XlsFormController::class, 'import'])->name('surveys.import.xlsform');

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
    // Route::middleware('throttle:ai')->group(function () { /surveys/generate, /surveys/{survey}/ai/translate });

    // ==== B-10 ==== Soumissions web, export, stats, supervision
    // ==== B-11 ==== Verbatims, synthèse, rapports
    // ==== B-12 ==== Gestion des liens publics (/surveys/{survey}/public-links)
});

// ==== B-07 ==== Synchronisation mobile : 600/min/utilisateur, meta.server_time + X-Server-Time sur chaque réponse
// Route::get('/mobile/ping', PingController::class)->middleware('server.time'); // public, 204
Route::prefix('mobile')->middleware(['server.time', 'auth:sanctum', 'throttle:mobile'])->group(function () {
    // Route::post('/devices', ...); Route::get('/forms', ...); Route::get('/forms/{surveyId}', ...);
    // Route::post('/submissions', ...); Route::post('/submissions/{uuid}/media/{questionKey}', ...);
    // Route::get('/submissions/status', ...);
    // ==== B-08 ==== Route::get('/follow-ups/due', ...); Route::post('/follow-ups', ...);
});

// ==== B-07 ==== Médias : URL signée temporaire (Storage privé), nommée pour URL::temporarySignedRoute()
// Route::get('/media/{media}', [MediaController::class, 'show'])->middleware('signed')->name('media.show');

// ==== B-12 ==== Collecte publique : CORS ouvert (PublicCors, global sur api/public/*), limites par IP
Route::prefix('public')->middleware(['public.cors'])->group(function () {
    Route::middleware('throttle:public-read')->group(function () {
        // Route::get('/surveys/{token}', ...);
    });
    Route::middleware('throttle:public-write')->group(function () {
        // Route::post('/surveys/{token}/submissions', ...);
        // Route::post('/surveys/{token}/submissions/{uuid}/media/{questionKey}', ...);
    });
});
