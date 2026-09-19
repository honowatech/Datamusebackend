<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-01 — version de l'application qui a transmis la fiche (`X-App-Version`).
 *
 * `devices.app_version` décrit l'appareil *aujourd'hui* ; cette colonne fige la version au moment
 * de la réception, ce qui rend un diagnostic possible après une mise à jour de l'application
 * (« les fiches arrivées avec la 1.2.0 ont toutes un GPS absent »). Nulle pour le canal public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('app_version', 40)->nullable()->after('device_time_offset_ms');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('app_version');
        });
    }
};
