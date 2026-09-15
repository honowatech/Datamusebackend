<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verbatim_codings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('question_key', 40);
            $table->foreignId('codebook_id')->constrained('verbatim_codebooks')->cascadeOnDelete();
            // Liste des clés de thèmes attribuées.
            $table->json('themes');
            // positive | neutral | negative | mixed
            $table->string('sentiment', 20)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            // ai | manual
            $table->string('source', 20)->default('ai');
            $table->timestamps();

            $table->unique(['submission_id', 'question_key', 'codebook_id']);
            $table->index(['survey_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verbatim_codings');
    }
};
