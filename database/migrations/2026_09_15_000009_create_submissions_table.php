<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_version_id')->constrained('survey_versions')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('survey_projects')->cascadeOnDelete();
            $table->foreignId('enumerator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            // mobile | public | web (App\Enums\SubmissionChannel)
            $table->string('channel', 10)->default('mobile');
            // submitted | screened_out | validated | rejected (App\Enums\SubmissionStatus)
            $table->string('status', 20)->default('submitted');
            $table->string('fiche_code', 64)->nullable();
            $table->string('zone', 100)->nullable();
            $table->string('language', 10)->default('fr');
            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();
            $table->decimal('geo_accuracy', 8, 2)->nullable();
            // Captures GPS complètes {start, end} telles que reçues (settings.geo).
            $table->json('geo')->nullable();
            $table->json('answers');
            // Clé du `stop` déclencheur (status screened_out).
            $table->string('end_reason', 40)->nullable();
            $table->char('answers_hash', 64)->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamp('received_at')->nullable();
            // Drapeaux qualité : ["too_fast", "duplicate", "off_hours", "gps_missing", "gps_outside_zone", "clock_skew"]
            $table->json('flags')->nullable();
            $table->unsignedTinyInteger('suspicion_score')->default(0);
            $table->text('quality_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Décalage horloge appareil moins serveur (ms) mesuré à la dernière synchronisation.
            $table->integer('device_time_offset_ms')->nullable();
            $table->timestamps();

            $table->unique(['survey_id', 'fiche_code']);
            $table->index(['survey_id', 'enumerator_id', 'ended_at']);
            $table->index(['survey_id', 'status']);
            $table->index(['survey_id', 'answers_hash']);
            $table->index(['project_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
