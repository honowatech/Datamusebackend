<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schéma DFS v1
    |--------------------------------------------------------------------------
    |
    | Chemin du JSON Schema (2020-12) qui fait foi pour la forme des questionnaires
    | (docs/dfs/dfs-v1.schema.json à la racine du monorepo). Le lot de production, qui ne
    | contient que backend/, en embarque une copie dans resources/dfs/ (scripts/build-production.sh).
    | Surcharger via DFS_SCHEMA_PATH.
    |
    */

    'schema_path' => env('DFS_SCHEMA_PATH') ?: (is_file(base_path('../docs/dfs/dfs-v1.schema.json'))
        ? base_path('../docs/dfs/dfs-v1.schema.json')
        : resource_path('dfs/dfs-v1.schema.json')),

    /*
    |--------------------------------------------------------------------------
    | Moteur
    |--------------------------------------------------------------------------
    */

    'max_passes' => 10,

];
