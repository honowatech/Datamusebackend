<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan de pondération d'une enquête (analyse native, chantier 3) : marges cibles du calage et bornes
 * des poids. Un plan au plus par questionnaire ; les poids eux-mêmes sont calculés dans le navigateur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_weighting_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            // [{variable, targets: [{code, share}]}]
            $table->json('margins');
            $table->decimal('min_weight', 8, 4)->nullable();
            $table->decimal('max_weight', 8, 4)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_weighting_plans');
    }
};
