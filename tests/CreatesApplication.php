<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // H7632 — the test run must be hermetic to a machine-local `.env`.
        //
        // The ops clone's `.env` is prod-shaped: 268 keys differ from their
        // config/ defaults (CRM_PIPELINE_BOARD, CLUB_MEMBERSHIP, VISUAL_DCS,
        // PD_CONSENT_ENFORCE, live Telegram chat ids, LLM keys …), which made
        // whole families of "flag defaults to OFF" contract tests red on that
        // clone alone — 162 failures in 85 classes, measured 01-11-2026.
        //
        // Reading the COMMITTED `.env.example` instead makes every clone —
        // developer, ops, CI — run against the same baseline, and it is the
        // exact file CI has always provisioned as `.env`, so CI behaviour is
        // unchanged by construction. Values the suite needs are pinned in
        // phpunit.xml (APP_KEY, sqlite in-memory DB, array cache/session, sync
        // queue, feature toggles — the H5065/H7606 block); a test that needs a
        // flag ON still sets config() itself. `.env.testing` (when a developer
        // creates one) keeps its normal Laravel meaning: it wins over this.
        if (! is_file($app->environmentPath().'/.env.testing')) {
            $app->loadEnvironmentFrom('.env.example');
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
