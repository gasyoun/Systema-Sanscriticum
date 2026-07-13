<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Views pull the bundle through @vite; without a built
        // public/build/manifest.json the directive throws
        // ManifestNotFoundException (→ 500), which locally turns every
        // view-rendering feature test into a false failure until the
        // frontend is built. Disable Vite for all tests — the standard
        // Laravel test stub — so the suite never depends on a build.
        $this->withoutVite();
    }
}
