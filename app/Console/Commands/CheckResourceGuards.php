<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use App\Support\ServerGuards\SystemInspector;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Ресурсный сторож (H4194, SLI-3 census S1): свободная память / своп / load1.
 *
 * Motive: `cabinet:probe` не читает ни одного из этих трёх чисел — L1 (/tmp
 * скретч съел своп) нашёл человек руками через `du -sh /tmp`, L8 (load 370)
 * увидели только когда контейнер уже не отвечал. Пороги — docs/server-resource-guards.md
 * §1/§9 (реальные инциденты .92), заведены в config/guard_pack.php.
 *
 * Идёт ОТДЕЛЬНОЙ строкой cron через systema-watchdog-run.sh, а не внутри
 * schedule:run — livelock по памяти останавливает сам планировщик первым
 * (§3 того же документа), сторож не должен зависеть от того, что сторожит.
 *
 * Fail-open: /proc недоступен (не Linux, контейнер без cgroup v1 meminfo) —
 * молчим, а не падаем. Windows-дев и CI без /proc должны оставаться зелёными.
 */
class CheckResourceGuards extends Command
{
    protected $signature = 'guards:resources {--dry : Только показать вердикт, без уведомлений}';

    protected $description = 'RAM/swap/load1 предохранитель — критично при выходе за пороги config/guard_pack.php';

    public function handle(SystemInspector $sys): int
    {
        $meminfo = $sys->fileContents('/proc/meminfo');
        $loadavg = $sys->fileContents('/proc/loadavg');
        $cpuinfo = $sys->fileContents('/proc/cpuinfo');

        if ($meminfo === null || $loadavg === null) {
            $this->comment('/proc/meminfo или /proc/loadavg не читается — проверка недоступна на этой машине (не Linux?).');

            return self::SUCCESS;
        }

        $memTotalKb = $this->extractKb($meminfo, 'MemTotal');
        $memAvailKb = $this->extractKb($meminfo, 'MemAvailable');
        $swapTotalKb = $this->extractKb($meminfo, 'SwapTotal');
        $swapFreeKb = $this->extractKb($meminfo, 'SwapFree');

        if ($memTotalKb === null || $memAvailKb === null) {
            $this->comment('MemTotal/MemAvailable не найдены в /proc/meminfo — проверка пропущена.');

            return self::SUCCESS;
        }

        $swapUsedKb = ($swapTotalKb !== null && $swapFreeKb !== null) ? max(0, $swapTotalKb - $swapFreeKb) : null;
        $cores = $this->countCores($cpuinfo) ?? 1;
        $load1 = $this->extractLoad1($loadavg);

        $memAvailRatio = $memTotalKb > 0 ? $memAvailKb / $memTotalKb : 1.0;
        $swapRatio = ($swapUsedKb !== null && $memTotalKb > 0) ? $swapUsedKb / $memTotalKb : null;
        $loadPerCore = $load1 !== null ? $load1 / $cores : null;

        $memThreshold = (float) config('guard_pack.mem_available_ratio_critical', 0.15);
        $swapThreshold = (float) config('guard_pack.swap_used_ratio_critical', 0.25);
        $loadThreshold = (float) config('guard_pack.load1_per_core_critical', 2.0);

        $alerts = [];
        if ($memAvailRatio < $memThreshold) {
            $alerts[] = sprintf(
                'mem avail %.1f%% (< %.0f%%): %d МБ свободно из %d МБ',
                $memAvailRatio * 100,
                $memThreshold * 100,
                intdiv($memAvailKb, 1024),
                intdiv($memTotalKb, 1024),
            );
        }
        if ($swapRatio !== null && $swapRatio > $swapThreshold) {
            $alerts[] = sprintf(
                'swap used %.1f%% RAM (> %.0f%%): %d МБ',
                $swapRatio * 100,
                $swapThreshold * 100,
                intdiv((int) $swapUsedKb, 1024),
            );
        }
        if ($loadPerCore !== null && $loadPerCore > $loadThreshold) {
            $alerts[] = sprintf(
                'load1 %.2f (%.1fx/core, порог %.1fx на %d ядер)',
                $load1,
                $loadPerCore,
                $loadThreshold,
                $cores,
            );
        }

        $this->table(['Метрика', 'Значение'], [
            ['mem avail', sprintf('%d МБ / %d МБ (%.1f%%)', intdiv($memAvailKb, 1024), intdiv($memTotalKb, 1024), $memAvailRatio * 100)],
            ['swap used', $swapRatio === null ? 'н/д' : sprintf('%d МБ (%.1f%% RAM)', intdiv((int) $swapUsedKb, 1024), $swapRatio * 100)],
            ['load1', $load1 === null ? 'н/д' : sprintf('%.2f (%d ядер)', $load1, $cores)],
        ]);

        if ($alerts === []) {
            $this->info('Ресурсы в норме.');

            return self::SUCCESS;
        }

        $this->warn('Пороги превышены:');
        foreach ($alerts as $alert) {
            $this->line('  • '.$alert);
        }

        if ($this->option('dry')) {
            $this->comment('--dry: уведомления не отправлены.');

            return self::SUCCESS;
        }

        $this->notifyAdmins('Ресурсы сервера на пределе', implode(' ', $alerts));

        return self::SUCCESS;
    }

    private function extractKb(string $meminfo, string $key): ?int
    {
        if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)\s+kB/m', $meminfo, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    private function extractLoad1(string $loadavg): ?float
    {
        $parts = preg_split('/\s+/', trim($loadavg)) ?: [];

        return isset($parts[0]) ? (float) $parts[0] : null;
    }

    private function countCores(?string $cpuinfo): ?int
    {
        if ($cpuinfo === null) {
            return null;
        }
        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }

    private function notifyAdmins(string $title, string $body): void
    {
        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей с ролью админа — уведомление не отправлено.');

            return;
        }

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($title)
                ->danger()
                ->body($body)
                ->sendToDatabase($recipient);
        }

        $this->info('Уведомление отправлено получателям: '.$recipients->count());
    }
}
