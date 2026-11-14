<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faits saillants d'une enquête (analyse native, chantier 4) : résultat de la découverte automatique
 * calculée dans le navigateur, enregistré pour être partagé avec l'équipe et repris par les rapports.
 * Un jeu au plus par questionnaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_analysis_highlights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->unique()->constrained()->cascadeOnDelete();
            // Matérialisation sur laquelle le calcul a porté (sert à signaler un jeu périmé).
            $table->dateTime('dataset_materialized_at')->nullable();
            $table->boolean('weighted')->default(false);
            $table->string('engine', 60)->nullable();
            // {alpha, correction, pairs_tested, …} : paramètres du calcul, libres.
            $table->json('params')->nullable();
            // [{variables, test, statistic, df, p_value, p_adjusted, effect: {name, value}, n, text: {fr, en}, details}]
            $table->json('items');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_analysis_highlights');
    }
};
