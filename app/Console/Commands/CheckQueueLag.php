<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Очередь-сторож (H4194, SLI-3 census S2): pending-jobs count + возраст самого
 * старого задания на очередь.
 *
 * Motive: `cabinet:probe` ходит по HTTP-поверхностям — застрявший `queue:work`
 * (N2-класс: письма/уведомления не отправляются, а страницы отдают 200) не
 * трогает ни одну из них. Прямое чтение таблицы `jobs`, а не
 * `queue:monitor` — последний требует настроенных size-порогов на очередь в
 * config/queue.php, которых в этом проекте нет, и добавлять их ради одной
 * пробы было бы третьим фреймворком поверх уже двух (docs idiom).
 *
 * Отдельная строка cron через systema-watchdog-run.sh — тот же мотив, что у
 * guards:resources: сторож не должен зависеть от того же планировщика,
 * зависание которого он обязан заметить.
 */
class CheckQueueLag extends Command
{
    protected $signature = 'guards:queue-lag {--dry : Только показать вердикт, без уведомлений}';

    protected $description = 'Возраст самого старого задания в очереди — критично при выходе за порог config/guard_pack.php';

    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('jobs')) {
            $this->comment('Таблица jobs не существует — очередь на database-драйвере не используется, проверка пропущена.');

            return self::SUCCESS;
        }

        $now = time();
        $maxAgeMinutes = (int) config('guard_pack.queue_oldest_job_max_minutes', 30);

        $rows = DB::table('jobs')
            ->selectRaw('queue, count(*) as pending, min(available_at) as oldest_available_at')
            ->groupBy('queue')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Очереди пусты.');

            return self::SUCCESS;
        }

        $table = [];
        $alerts = [];
        foreach ($rows as $row) {
            $ageMinutes = intdiv(max(0, $now - (int) $row->oldest_available_at), 60);
            $table[] = [$row->queue, $row->pending, $ageMinutes.' мин'];

            if ($ageMinutes > $maxAgeMinutes) {
                $alerts[] = sprintf(
                    '%s: %d ожидающих, старейшее %d мин (порог %d)',
                    $row->queue,
                    $row->pending,
                    $ageMinutes,
                    $maxAgeMinutes,
                );
            }
        }

        $this->table(['Очередь', 'Ожидает', 'Возраст старейшего'], $table);

        if ($alerts === []) {
            $this->info('Очереди в норме.');

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

        $this->notifyAdmins('Очередь встала', implode(' ', $alerts));

        return self::SUCCESS;
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
