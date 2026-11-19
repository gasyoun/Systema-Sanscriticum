<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\BackupNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * H6540: сбой канала backup-уведомлений не роняет backup-джобу. Spatie
 * запускает уведомления синхронно внутри backup:run/clean; исключение
 * mailer'а вылетало из event() в copyToBackupDestinations и красило весь
 * прогон в exit 1 (прод 12-10-2026 02:10, SMTP «Network is unreachable»
 * в ночном окне; зип и local-копия к тому моменту уже отгружены).
 * BackupNotifiable::notify проглатывает Throwable в лог.
 */
class BackupNotifiableSwallowTest extends TestCase
{
    /** @test */
    public function channel_failure_is_swallowed_and_logged(): void
    {
        Log::spy();
        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'проглочено')
                && $context['notification'] === H6540ThrowingChannelNotification::class
                && str_contains((string) $context['exception'], 'H6540 probe')
        );

        (new BackupNotifiable)->notify(new H6540ThrowingChannelNotification);

        // Дошли сюда — исключение не вылетело из notify().
        $this->assertTrue(true);
    }
}

class H6540ThrowingChannelNotification extends Notification
{
    /**
     * Бросок в via(): канал падает до всякой маршрутизации (пустой
     * ADMIN_EMAIL делает mail-канал no-op'ом ДО вызова toMail — бросок здесь
     * единственный детерминированный способ пройти ровно по пути сбоя канала).
     */
    public function via($notifiable): array
    {
        throw new RuntimeException('H6540 probe: channel exploded');
    }
}
