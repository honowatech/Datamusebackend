<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-06b : résultat embarqué d'un job (contrat, schéma `Job.result`).
 *
 * `result_ref` reste la référence (`surveys/{id}`, `jobs/{uuid}/proposal`…) ; `output` porte le petit
 * résultat rendu tel quel par `GET /jobs/{uuid}` → `data.result` (proposition DFS, `{survey_id, version}`,
 * `content_md` d'une synthèse…). Jamais de clé API ni de donnée personnelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->json('output')->nullable()->after('result_ref');
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropColumn('output');
        });
    }
};
