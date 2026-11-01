<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use Tests\TestCase;

/**
 * Explicit pre-boot `SRS_ENABLED=false` darks the whole surface (routes not
 * registered). Positive half: default ON is pinned by SrsFlagDefaultTest.
 *
 * H7606: @runInSeparateProcess обязателен. В общем процессе phpdotenv-лоадер
 * после первого boot считает SRS_ENABLED «своей» (значение из .env) и на
 * следующих boot-ах переписывает её поверх нашего putenv('false') — батч
 * падал там, где одиночный прогон зелёный. В отдельном процессе наш putenv —
 * единственный источник значения до boot.
 *
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
class SrsFlagOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SRS_ENABLED=false');
        $_ENV['SRS_ENABLED'] = 'false';
        $_SERVER['SRS_ENABLED'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);

        parent::tearDown();
    }

    public function test_explicit_false_disables_srs_routes(): void
    {
        $this->assertFalse(config('srs.enabled'));
        $this->assertFalse(app('router')->has('student.srs'));
        $this->assertFalse(app('router')->has('student.srs.deck'));
        $this->assertFalse(app('router')->has('srs.index'));
        $this->assertFalse(app('router')->has('srs.deck'));
        // Cabinet path only exists under the SRS gate (not the promo catch-all).
        $this->get('/dvaram/koloda')->assertNotFound();
    }
}
