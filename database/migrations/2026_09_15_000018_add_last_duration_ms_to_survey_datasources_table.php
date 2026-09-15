<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_datasources', function (Blueprint $table) {
            // Durée de la dernière matérialisation (plan § 5.2, tâche B-09).
            $table->unsignedInteger('last_duration_ms')->nullable()->after('row_count');
        });
    }

    public function down(): void
    {
        Schema::table('survey_datasources', function (Blueprint $table) {
            $table->dropColumn('last_duration_ms');
        });
    }
};
