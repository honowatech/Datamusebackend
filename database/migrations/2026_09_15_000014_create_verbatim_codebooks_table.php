<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verbatim_codebooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('question_key', 40);
            $table->unsignedInteger('version')->default(1);
            // [{key, label, description?, examples?: []}]
            $table->json('themes');
            // ai | manual
            $table->string('source', 20)->default('ai');
            $table->timestamps();

            $table->unique(['survey_id', 'question_key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verbatim_codebooks');
    }
};
