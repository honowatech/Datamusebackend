<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-03 — fournisseur IA réellement utilisé par le job : `gemini`, `deepseek` ou `replay`
 * (rejeu local, réponse simulée). Jusqu'ici la seule trace était `ai_jobs.input.provider`,
 * qui porte le fournisseur *demandé*, pas celui qui a répondu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
