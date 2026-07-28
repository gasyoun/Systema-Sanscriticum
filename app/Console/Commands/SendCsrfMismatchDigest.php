<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CsrfMismatchDigestService;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * H1773 — до 28-07-2026 CSRF-несовпадение (голый 419) не считалось нигде;
 * инцидент 27-07-2026 на /login и /shop/login нашли только по ручному
 * чтению nginx-логов после жалоб студентов. Эта команда — замена того
 * ручного чтения: считает записи канала csrf_mismatch (config/logging.php)
 * за окно, показывает разбивку по маршрутам и алертит админов, только если
 * суммарное число вышло за config/csrf.php::digest_threshold — тот же
 * паттерн, что {@see \App\Console\Commands\CheckStorageUsage} и
 * {@see \App\Console\Commands\CheckReceivablesThreshold}.
 *
 * Ниже порога — молчит: ежедневный отчёт, который шлётся всегда, это отчёт,
 * который никто не читает.
 */
class SendCsrfMismatchDigest extends Command
{
    protected $signature = 'csrf:mismatch-digest
        {--days= : Окно в днях (по умолчанию config csrf.digest_window_days)}
        {--dry : Только показать сводку, без отправки уведомлений}';

    protected $description = 'Дайджест CSRF-несовпадений по маршрутам за окно; алерт админам при превышении порога.';

    public function handle(CsrfMismatchDigestService $service): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('csrf.digest_window_days', 1);

        $snap = $service->snapshot($days);

        if ($snap['ok']) {
            $this->info(sprintf(
                'CSRF-несовпадений за %d дн.: %d (порог %d) — под порогом, дайджест не отправлен.',
                $snap['window_days'],
                $snap['total'],
                $snap['threshold'],
            ));

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'CSRF-несовпадений за %d дн.: %d (порог %d) — выше порога:',
            $snap['window_days'],
            $snap['total'],
            $snap['threshold'],
        ));
        foreach ($snap['per_route'] as $route => $count) {
            $this->line("  {$route}: {$count}");
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

        $topRoutes = collect($snap['per_route'])
            ->map(fn (int $count, string $route) => "{$route}: {$count}")
            ->take(5)
            ->implode(', ');

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('CSRF-несовпадения выше нормы')
                ->danger()
                ->body(sprintf(
                    'За %d дн. зафиксировано %d несовпадений (порог %d). По маршрутам: %s',
                    $snap['window_days'],
                    $snap['total'],
                    $snap['threshold'],
                    $topRoutes,
                ))
                ->sendToDatabase($recipient);
        }

        $this->info('Уведомление отправлено получателям: '.$recipients->count());

        return self::SUCCESS;
    }
}
