<?php

namespace Tests;

use FilesystemIterator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Répertoire des fichiers SQLite matérialisés, isolé par test (B-09b).
     *
     * La file de test est `sync` : publier un questionnaire ou recevoir une soumission déclenche un
     * `MaterializeSurveyDatasourceJob` dans la requête. Sans cette isolation, les fichiers atterriraient
     * dans `storage/app/imported_databases` et s'accumuleraient d'une exécution à l'autre.
     */
    private ?string $datasourceDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->datasourceDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'datamuse_ds_'.Str::lower(Str::random(10));
        config(['survey.datasource_dir' => $this->datasourceDir]);
    }

    protected function tearDown(): void
    {
        $dir = $this->datasourceDir;
        $this->datasourceDir = null;

        // Le chat SQL (`/chat/execute-sql`) laisse une connexion ouverte sur le fichier matérialisé :
        // sous Windows elle en interdirait la suppression.
        if ($this->app !== null) {
            try {
                DB::purge('target_db');
            } catch (Throwable) {
                // connexion jamais configurée : rien à purger
            }
        }

        parent::tearDown();

        // Après `parent::tearDown()` l'application est détruite : pas de façade ici.
        gc_collect_cycles();
        self::removeDirectory($dir);
    }

    /** Suppression récursive « best effort » (un fichier encore verrouillé est simplement laissé). */
    private static function removeDirectory(?string $dir): void
    {
        if ($dir === null || ! is_dir($dir)) {
            return;
        }

        // Les avertissements de suppression ne doivent jamais transformer un test en « risky ».
        set_error_handler(static fn (): bool => true);

        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($dir);
        } finally {
            restore_error_handler();
        }
    }
}
