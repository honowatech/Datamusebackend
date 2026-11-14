<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Définitions de l'explorateur sans code (analyse native, chantier 5) : segments enregistrés et
 * variables calculées, toutes deux exprimées dans le langage Logic du DFS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_analysis_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            // segment | variable
            $table->string('kind', 20);
            $table->string('key', 40);
            $table->string('label', 120);
            $table->string('description', 500)->nullable();
            // Variables calculées seulement : nominal | ordinal | numeric | date | text
            $table->string('level', 20)->nullable();
            // Expression Logic (booléenne pour un segment)
            $table->json('expression');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['survey_id', 'kind', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_analysis_definitions');
    }
};
