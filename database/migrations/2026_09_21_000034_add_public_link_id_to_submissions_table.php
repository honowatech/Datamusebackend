<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-B2 — rattachement durable d'une fiche `public` à son lien.
 *
 * Jusqu'ici l'association fiche → lien n'existait qu'en **cache 24 h**
 * (`b12:public-submission:{uuid}`, écart noté en B-10 / B-12) : passé ce délai, plus rien ne disait
 * d'où venait une réponse en ligne. La colonne la fige ; `nullOnDelete` fait qu'effacer un lien ne
 * détruit pas les fiches qu'il a produites (elles redeviennent simplement « en ligne, lien inconnu »).
 *
 * Rattrapage : une fiche `public` déjà en base est rattachée **seulement** si son questionnaire n'a
 * qu'un seul lien — au-delà, l'origine est indécidable et la colonne reste nulle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->foreignId('public_link_id')->nullable()->after('device_id')
                ->constrained('public_links')->nullOnDelete();
            $table->index(['public_link_id', 'received_at']);
        });

        // Rattrapage : questionnaires n'ayant qu'un seul lien public.
        $single = DB::table('public_links')
            ->select('survey_id', DB::raw('MIN(id) as link_id'), DB::raw('COUNT(*) as total'))
            ->groupBy('survey_id')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        foreach ($single as $row) {
            DB::table('submissions')
                ->where('survey_id', $row->survey_id)
                ->where('channel', 'public')
                ->whereNull('public_link_id')
                ->update(['public_link_id' => $row->link_id]);
        }
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['public_link_id', 'received_at']);
            $table->dropConstrainedForeignId('public_link_id');
        });
    }
};
