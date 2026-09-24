<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('stage_key', 40);
            $table->foreignId('enumerator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at');
            $table->dateTime('window_ends_at');
            // pending | done | missed | skipped (App\Enums\FollowUpStatus)
            $table->string('status', 20)->default('pending');
            $table->json('answers')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'stage_key']);
            $table->index(['survey_id', 'status', 'due_at']);
            $table->index(['enumerator_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_entries');
    }
};
