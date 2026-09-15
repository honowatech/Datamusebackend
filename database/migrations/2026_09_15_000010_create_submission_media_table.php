<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->string('question_key', 40);
            // Index (base 0) de l'instance de groupe répété ; 0 hors répétition afin que
            // l'unicité (submission, question_key, repeat_index) soit effective (NULL <> NULL en SQL).
            $table->unsignedSmallInteger('repeat_index')->default(0);
            $table->string('disk', 40)->default('local');
            $table->string('path')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64);
            // pending | uploaded | failed (App\Enums\MediaState)
            $table->string('state', 20)->default('pending');
            $table->timestamps();

            $table->unique(['submission_id', 'question_key', 'repeat_index']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_media');
    }
};
