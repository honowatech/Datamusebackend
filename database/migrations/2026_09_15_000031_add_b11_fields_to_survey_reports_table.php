<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-11 — champs du schéma `Report` absents de la migration B-01 :
 *   - `job_id`  : uuid du dernier `AiJob` (génération ou régénération de section) ;
 *   - `error`   : message d'échec rendu tel quel par `GET /reports/{id}` ;
 *   - `options` : reste du `ReportIn` (`tone`, `length`, `sections[]`, `include_verbatims`), aplati par
 *                 `ReportResource` pour respecter le contrat ;
 *   - `meta`    : drapeaux de cohérence, notamment `content_json_stale` (markdown édité seul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_reports', function (Blueprint $table) {
            $table->char('job_id', 36)->nullable()->after('status');
            $table->text('error')->nullable()->after('model');
            $table->json('options')->nullable()->after('generated_files');
            $table->json('meta')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('survey_reports', function (Blueprint $table) {
            $table->dropColumn(['job_id', 'error', 'options', 'meta']);
        });
    }
};
