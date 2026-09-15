<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Schéma DFS v1
    |--------------------------------------------------------------------------
    |
    | Chemin du JSON Schema (2020-12) qui fait foi pour la forme des questionnaires
    | (docs/dfs/dfs-v1.schema.json à la racine du monorepo). Surcharger via DFS_SCHEMA_PATH
    | si le dossier docs/ n'est pas déployé à côté de backend/.
    |
    */

    'schema_path' => env('DFS_SCHEMA_PATH', base_path('../docs/dfs/dfs-v1.schema.json')),

    /*
    |--------------------------------------------------------------------------
    | Moteur
    |--------------------------------------------------------------------------
    */

    'max_passes' => 10,

];
