<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->string('label')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_responses')->nullable();
            $table->unsignedInteger('responses_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['survey_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_links');
    }
};
