<?php

declare(strict_types=1);

namespace Tests\Feature\Knowledge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0YZ: the coverage map must classify every answerable question into a lane and
 * must never need a real student to run — the empty probe is the default.
 */
class KnowledgeFactCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_probe_classifies_every_answerable_question(): void
    {
        $this->artisan('knowledge:fact-coverage')
            ->expectsOutputToContain('refusal cards skipped=30')
            ->expectsOutputToContain('| prose |')
            ->expectsOutputToContain('| nobody |')
            ->assertExitCode(0);
    }
}
