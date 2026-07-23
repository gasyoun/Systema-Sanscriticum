<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\SuppressedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1449 A3 — mail:scan-bounces is a no-op by default (mail.bounce_scan.enabled=false)
 * and never touches a real network connection in tests.
 */
class ScanBouncesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_a_noop_when_bounce_scan_disabled(): void
    {
        config(['mail.bounce_scan.enabled' => false]);

        $this->artisan('mail:scan-bounces')->assertSuccessful();

        $this->assertSame(0, SuppressedEmail::query()->count());
    }

    public function test_command_no_ops_when_enabled_but_host_not_configured(): void
    {
        config([
            'mail.bounce_scan.enabled' => true,
            'mail.bounce_scan.host' => null,
            'mail.bounce_scan.username' => null,
        ]);

        $this->artisan('mail:scan-bounces')->assertSuccessful();

        $this->assertSame(0, SuppressedEmail::query()->count());
    }
}
