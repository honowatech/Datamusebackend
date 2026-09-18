<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sources de données matérialisées (plan § 5.2, tâches B-09a / B-09b)
    |--------------------------------------------------------------------------
    |
    | Chaque questionnaire est matérialisé dans un fichier SQLite
    | `{datasource_dir}/user_{owner_id}/survey_{survey_id}_v{n}.sqlite`, exposé comme
    | `TargetDatabase` au chat SQL et au profilage existants.
    |
    */

    'datasource_dir' => env('SURVEY_DATASOURCE_DIR') ?: storage_path('app/imported_databases'),

    // Délai avant matérialisation après une soumission (regroupe les rafales de synchronisation).
    'materialize_delay_seconds' => (int) env('SURVEY_MATERIALIZE_DELAY', 30),

    // Nombre de fichiers versionnés conservés en plus du courant (`datasources:cleanup-old-files`).
    'datasource_keep_versions' => (int) env('SURVEY_DATASOURCE_KEEP_VERSIONS', 0),

    // Plafond de l'export synchrone CSV/XLSX (B-10) : au-delà, 422 et passage par la datasource.
    'export_max_rows' => (int) env('SURVEY_EXPORT_MAX_ROWS', 50000),

];
