<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('target_database_id')->constrained('target_databases')->onDelete('cascade');
            $table->string('term'); // e.g. 'Chiffre d\'Affaires', 'Client Actif'
            $table->text('sql_definition'); // e.g. 'SUM(commandes.montant_ttc)'
            $table->text('description')->nullable(); // Human-readable description
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_metrics');
    }
};
