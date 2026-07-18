<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailPreflightTest extends TestCase
{
    private function setNonLocalEnv(): void
    {
        $this->app['env'] = 'production';
    }

    /** @test */
    public function it_fails_when_smtp_host_is_a_dev_mail_catcher_outside_local(): void
    {
        $this->setNonLocalEnv();
        config()->set('mail.mailers.smtp.host', 'mailpit');
        config()->set('mail.from.address', 'hello@samskrte.ru');

        $this->artisan('mail:preflight')
            ->assertFailed()
            ->expectsOutputToContain('mailpit');
    }

    /** @test */
    public function it_fails_when_sender_is_a_placeholder_address_outside_local(): void
    {
        $this->setNonLocalEnv();
        config()->set('mail.mailers.smtp.host', 'smtp.example-esp.com');
        config()->set('mail.from.address', 'hello@example.com');

        $this->artisan('mail:preflight')
            ->assertFailed()
            ->expectsOutputToContain('example.com');
    }

    /** @test */
    public function it_passes_on_a_plausible_esp_config(): void
    {
        $this->setNonLocalEnv();
        config()->set('mail.mailers.smtp.host', 'smtp.example-esp.com');
        config()->set('mail.from.address', 'hello@samskrte.ru');
        config()->set('queue.default', 'sync');

        $this->artisan('mail:preflight')->assertSuccessful();
    }

    /** @test */
    public function it_does_not_fail_in_local_even_with_dev_defaults(): void
    {
        $this->app['env'] = 'local';
        config()->set('mail.mailers.smtp.host', 'mailpit');
        config()->set('mail.from.address', 'hello@example.com');

        $this->artisan('mail:preflight')->assertSuccessful();
    }

    /** @test */
    public function it_warns_when_queue_connection_is_not_sync(): void
    {
        $this->setNonLocalEnv();
        config()->set('mail.mailers.smtp.host', 'smtp.example-esp.com');
        config()->set('mail.from.address', 'hello@samskrte.ru');
        config()->set('queue.default', 'redis');

        $this->artisan('mail:preflight')
            ->assertSuccessful()
            ->expectsOutputToContain('QUEUE_CONNECTION');
    }

    /** @test */
    public function it_touches_no_network_by_default(): void
    {
        Mail::fake();
        $this->setNonLocalEnv();
        config()->set('mail.mailers.smtp.host', 'smtp.example-esp.com');
        config()->set('mail.from.address', 'hello@samskrte.ru');
        config()->set('queue.default', 'sync');

        $this->artisan('mail:preflight')->assertSuccessful();

        Mail::assertNothingOutgoing();
    }

    /** @test */
    public function send_option_is_opt_in_and_only_fires_on_a_passing_config(): void
    {
        Mail::fake();
        $this->setNonLocalEnv();
        config()->set('mail.mailers.smtp.host', 'smtp.example-esp.com');
        config()->set('mail.from.address', 'hello@samskrte.ru');
        config()->set('queue.default', 'sync');

        $this->artisan('mail:preflight', ['--send' => 'test@samskrte.ru'])
            ->assertSuccessful()
            ->expectsOutputToContain('dispatched');
    }
}
