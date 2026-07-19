<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StorageUsageService;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Дежурный по файловому хранилищу (H1345): раз в сутки измеряет вес каталогов
 * загрузок и свободное место на диске, алертит админов при выходе за пороги
 * config/storage_watch.php — тот же паттерн, что {@see CheckReceivablesThreshold}
 * и {@see CheckTelegramSupportSessionHealth}.
 *
 * Мотив: до этой команды рост медиа не измерялся ничем. Загрузки материалов
 * занятий не ограничены по количеству файлов, а storage/app целиком попадает в
 * еженедельный бэкап — то есть каждый лишний гигабайт бьёт дважды, и заметить
 * это можно было только по факту падения сервера.
 *
 * Read-only: ничего не удаляет и не чистит. Уборка — отдельное решение человека,
 * автоматически стирать студенческие работы недопустимо.
 */
class CheckStorageUsage extends Command
{
    protected $signature = 'storage:check {--dry : Только показать вердикт, без отправки уведомлений}';

    protected $description = 'Проверка роста файлового хранилища и свободного места — алерт админам при превышении порогов.';

    public function handle(StorageUsageService $service): int
    {
        $snapshot = $service->snapshot();

        // Таблица печатается всегда: команда полезна и как ручной инструмент
        // «сколько сейчас занято», а не только как молчаливый сторож.
        $rows = [];
        foreach ($snapshot['directories'] as $dir) {
            $rows[] = [
                $dir['path'],
                $service->megabytes($dir['bytes']),
                number_format($dir['limit_mb'], 0, '.', ' ').' МБ',
                (int) round($dir['ratio'] * 100).'%',
                $this->marker($dir['level']).($dir['truncated'] ? ' (усечено)' : ''),
            ];
        }

        $rows[] = [
            'ВСЕГО storage/app',
            $service->megabytes($snapshot['total_bytes']),
            number_format($snapshot['total_limit_mb'], 0, '.', ' ').' МБ',
            (int) round($snapshot['total_bytes'] / max(1, $snapshot['total_limit_mb'] * 1048576) * 100).'%',
            $this->marker($snapshot['total_level']),
        ];

        $this->table(['Каталог', 'Занято', 'Потолок', 'Доля', 'Статус'], $rows);

        $this->line('Свободно на диске: '.match ($snapshot['free_disk_level']) {
            'off' => 'проверка выключена (STORAGE_WATCH_MIN_FREE_DISK_MB=0)',
            'unknown' => 'измерить не удалось (контейнер или open_basedir)',
            default => $service->megabytes((int) $snapshot['free_disk_bytes']),
        });

        if ($snapshot['ok']) {
            $this->info('Хранилище в норме.');

            return self::SUCCESS;
        }

        $this->warn('Пороги превышены:');
        foreach ($snapshot['alerts'] as $alert) {
            $this->line('  • '.$alert);
        }

        if ($this->option('dry')) {
            $this->comment('--dry: уведомления не отправлены.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей с ролью админа — уведомление не отправлено.');

            return self::FAILURE;
        }

        $body = implode(' ', $snapshot['alerts']);
        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Хранилище растёт: пора вмешаться')
                ->danger()
                ->body($body)
                ->sendToDatabase($recipient);
        }

        $this->info('Уведомление отправлено получателям: '.$recipients->count());

        return self::SUCCESS;
    }

    private function marker(string $level): string
    {
        return match ($level) {
            'red' => 'КРАСНЫЙ',
            'yellow' => 'жёлтый',
            default => 'ок',
        };
    }
}
