<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('survey_projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug', 120);
            // draft | active | closed (App\Enums\SurveyStatus)
            $table->string('status', 20)->default('draft');
            // Références vers survey_versions sans contrainte FK (dépendance circulaire ;
            // la cohérence est garantie par les services de publication).
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->unsignedBigInteger('published_version_id')->nullable()->index();
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamp('last_submission_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'slug']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
