<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DatabaseConnectionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessMetricController;

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
