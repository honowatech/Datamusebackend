<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin | analyste | enqueteur (App\Enums\UserRole)
            $table->string('role', 20)->default('analyste')->after('password');
            $table->string('phone', 30)->nullable()->after('role');
            $table->string('locale', 10)->default('fr')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'locale']);
        });
    }
};
