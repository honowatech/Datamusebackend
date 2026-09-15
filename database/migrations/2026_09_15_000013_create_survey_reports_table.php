<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('brief')->nullable();
            // commercial | synthese | executif (App\Enums\ReportOrientation)
            $table->string('orientation', 20)->default('commercial');
            $table->string('audience')->nullable();
            $table->string('language', 10)->default('fr');
            // queued | running | done | failed (App\Enums\JobStatus)
            $table->string('status', 20)->default('queued');
            $table->string('provider', 40)->nullable();
            $table->string('model', 80)->nullable();
            $table->longText('content_md')->nullable();
            $table->json('content_json')->nullable();
            // [{kind: docx|pdf, disk, path, size, uploaded_at}]
            $table->json('generated_files')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamps();

            $table->index(['survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_reports');
    }
};
