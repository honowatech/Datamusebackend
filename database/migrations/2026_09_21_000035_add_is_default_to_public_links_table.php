<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lien web par défaut d'un questionnaire (onglet « Lien Web »).
 *
 * Chaque questionnaire porte systématiquement **un** lien `is_default = true`, créé avec lui, pour
 * pouvoir inviter à tout moment des répondants en ligne. « Régénérer » désactive ce lien et en crée
 * un nouveau ; les liens B-12 créés à la main restent `is_default = false`.
 *
 * Rattrapage : un lien par défaut neuf pour chaque questionnaire existant (les liens déjà émis, dont
 * les paramètres — étiquette, expiration, plafond — ont été choisis, ne sont pas promus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_links', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->index(['survey_id', 'is_default']);
        });

        $now = Carbon::now();
        DB::table('surveys')->select(['id', 'created_by'])->orderBy('id')->each(function (object $survey) use ($now) {
            DB::table('public_links')->insert([
                'survey_id' => $survey->id,
                'token' => Str::random(40),
                'label' => 'Lien web',
                'responses_count' => 0,
                'is_active' => true,
                'is_default' => true,
                'created_by' => $survey->created_by,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('public_links', function (Blueprint $table) {
            $table->dropIndex(['survey_id', 'is_default']);
            $table->dropColumn('is_default');
        });
    }
};
