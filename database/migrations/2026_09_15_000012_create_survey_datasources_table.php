<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_datasources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('target_database_id')->nullable()->constrained('target_databases')->nullOnDelete();
            // Numéro du fichier SQLite courant (bascule atomique par nom versionné, plan § 5.2).
            $table->unsignedInteger('file_version')->default(0);
            $table->boolean('dirty')->default(false);
            $table->timestamp('dirty_since')->nullable();
            $table->timestamp('last_materialized_at')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['dirty', 'dirty_since']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_datasources');
    }
};
