<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_id')->nullable()->constrained()->nullOnDelete();
            // form_generation | classify | synthesis | report | materialize | translate (App\Enums\JobKind)
            $table->string('kind', 30);
            // queued | running | done | failed (App\Enums\JobStatus)
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message')->nullable();
            // Paramètres du job (clés API chiffrées côté service, jamais en clair).
            $table->json('input')->nullable();
            // Référence du résultat : "survey_version:12", "report:3", "codebook:7"...
            $table->string('result_ref')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['survey_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
