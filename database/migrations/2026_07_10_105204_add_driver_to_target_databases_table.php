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
        Schema::table('target_databases', function (Blueprint $table) {
            $table->string('driver')->default('mysql')->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('target_databases', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }
};
