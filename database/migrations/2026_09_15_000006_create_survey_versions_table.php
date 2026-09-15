<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // draft | published | archived (App\Enums\VersionStatus)
            $table->string('status', 20)->default('draft');
            $table->json('definition');
            // sha256 hexadécimal du texte JSON canonique servi (README § 15) ; figé à la publication.
            $table->char('definition_hash', 64)->nullable();
            // Révision du brouillon (verrou optimiste PUT /surveys/{id}/draft {base_revision}).
            $table->unsignedInteger('revision')->default(1);
            $table->json('question_index')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['survey_id', 'version']);
            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_versions');
    }
};
