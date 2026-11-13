<?php

namespace Tests\Feature\Analysis;

use App\Services\DataProfilingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataProfilingStddevTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.profiling_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::connection('profiling_test')->statement('CREATE TABLE mesures (valeur REAL, constante INTEGER, libelle TEXT)');
        // Jeu classique : moyenne 5, écart-type de population exactement 2.
        foreach ([2, 4, 4, 4, 5, 5, 7, 9] as $v) {
            DB::connection('profiling_test')->table('mesures')->insert(['valeur' => $v, 'constante' => 3, 'libelle' => 'a']);
        }
        DB::connection('profiling_test')->table('mesures')->insert(['valeur' => null, 'constante' => 3, 'libelle' => 'b']);
    }

    protected function tearDown(): void
    {
        DB::purge('profiling_test');

        parent::tearDown();
    }

    public function test_standard_deviation_is_really_computed_on_sqlite(): void
    {
        $profile = app(DataProfilingService::class)->profileResults('SELECT valeur, constante, libelle FROM mesures', 'profiling_test');

        $valeur = $profile['columns']['valeur'];
        $this->assertSame('numeric', $valeur['type']);
        $this->assertSame(8, $valeur['count']);
        $this->assertSame(1, $valeur['null_count']);
        $this->assertEqualsWithDelta(5.0, $valeur['mean'], 1e-12);
        $this->assertEqualsWithDelta(2.0, $valeur['stddev'], 1e-12, 'écart-type de population (comme STDDEV() de MySQL)');

        $this->assertSame(0.0, $profile['columns']['constante']['stddev']);
        $this->assertArrayNotHasKey('stddev', $profile['columns']['libelle']);
    }
}
