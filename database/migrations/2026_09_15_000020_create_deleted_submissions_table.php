<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-07 : liste noire des `uuid` de soumissions supprimées définitivement côté web
 * (`DELETE /submissions/{id}`, B-10). Le mobile qui renvoie un de ces uuid reçoit `duplicate`
 * au lieu de recréer la fiche (docs/openapi/survey.yaml, `deleteSubmission`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_submissions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('survey_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fiche_code', 64)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index('survey_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_submissions');
    }
};
