<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use Tests\TestCase;

/**
 * Pins the positive half of the G4 contract: the feature becomes active only
 * when the environment opts in before Laravel boots.
 */
class SrsFlagOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SRS_ENABLED=true');
        $_ENV['SRS_ENABLED'] = 'true';
        $_SERVER['SRS_ENABLED'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);

        parent::tearDown();
    }

    public function test_explicit_environment_override_enables_srs(): void
    {
        $this->assertTrue(config('srs.enabled'));
        $this->assertTrue(app('router')->has('student.srs'));
        $this->assertTrue(app('router')->has('student.srs.deck'));
        $this->assertTrue(app('router')->has('student.srs.stats'));
        $this->assertTrue(app('router')->has('student.srs.decks'));
        $this->assertTrue(app('router')->has('srs.index'));
        $this->assertTrue(app('router')->has('srs.deck'));
    }
}
