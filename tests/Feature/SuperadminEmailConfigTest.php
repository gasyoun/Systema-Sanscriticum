<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BackupNotifiable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Backup\Config\Config as BackupConfig;
use Tests\TestCase;

/**
 * H3312: superadmin email больше не живёт литералом в коде. Единый канон -
 * config('services.admin.email') из env ADMIN_EMAIL, fail-closed: пусто ->
 * Horizon deny / backup notify skip с warning, без крашей.
 */
class SuperadminEmailConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function services_admin_email_resolves_from_env_without_literal_fallback(): void
    {
        putenv('ADMIN_EMAIL=env-admin@example.test');
        $_ENV['ADMIN_EMAIL'] = 'env-admin@example.test';
        $_SERVER['ADMIN_EMAIL'] = 'env-admin@example.test';

        try {
            // Повторное исполнение конфиг-файла в текущем процессе: env()
            // читает реальную переменную окружения, литерала в файле нет.
            $services = require config_path('services.php');

            $this->assertSame('env-admin@example.test', $services['admin']['email']);
        } finally {
            putenv('ADMIN_EMAIL');
            unset($_ENV['ADMIN_EMAIL'], $_SERVER['ADMIN_EMAIL']);
        }
    }

    /** @test */
    public function no_personal_email_literal_remains_in_live_code(): void
    {
        foreach ([
            file_get_contents(config_path('services.php')),
            file_get_contents(config_path('backup.php')),
            file_get_contents(app_path('Providers/HorizonServiceProvider.php')),
        ] as $source) {
            $this->assertIsString($source);
            $this->assertStringNotContainsString('pe4kin', $source);
        }

        $this->assertStringContainsString(
            "env('ADMIN_EMAIL', '')",
            (string) file_get_contents(config_path('services.php')),
        );
    }

    /** @test */
    public function horizon_gate_allows_user_with_configured_admin_email(): void
    {
        config(['services.admin.email' => 'root-admin@example.test']);

        $admin = User::factory()->create(['email' => 'root-admin@example.test']);
        $other = User::factory()->create(['email' => 'student@example.test']);

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($other)->allows('viewHorizon'));
    }

    /** @test */
    public function horizon_gate_fails_closed_when_admin_email_unset(): void
    {
        config(['services.admin.email' => '']);

        // Исторический захардкоженный адрес тоже не получает доступ.
        $legacyAdmin = User::factory()->create(['email' => 'pe4kinsmart@gmail.com']);

        Log::shouldReceive('warning')->twice();

        $this->assertFalse(Gate::forUser($legacyAdmin)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }

    /** @test */
    public function backup_mail_recipient_follows_admin_email(): void
    {
        config(['services.admin.email' => 'root-admin@example.test']);

        $notifiable = new BackupNotifiable;

        $this->assertSame(['root-admin@example.test'], $notifiable->routeNotificationForMail());
    }

    /** @test */
    public function backup_mail_fails_closed_without_admin_email(): void
    {
        config(['services.admin.email' => '']);

        Log::shouldReceive('warning')->once();

        $notifiable = new BackupNotifiable;

        $this->assertSame([], $notifiable->routeNotificationForMail());
    }

    /** @test */
    public function notifications_reach_no_one_when_admin_email_unset(): void
    {
        config(['services.admin.email' => '']);

        Log::spy();
        Mail::fake();

        (new BackupNotifiable)->notify(new class extends Notification
        {
            public function via(): array
            {
                return ['mail'];
            }

            public function toMail($notifiable): MailMessage
            {
                return (new MailMessage)->line('probe');
            }
        });

        Mail::assertNothingSent();
    }

    /** @test */
    public function spatie_backup_config_parses_even_with_empty_admin_email(): void
    {
        // Плейсхолдер в backup.notifications.mail.to проходит filter_var-
        // валидацию spatie: парсинг конфига не падает (нет crash loop).
        config(['services.admin.email' => '']);

        $config = app(BackupConfig::class);

        $this->assertSame('backup-notifications-unset@example.com', $config->notifications->mail->to);
    }
}
