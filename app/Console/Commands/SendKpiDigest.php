<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Pages\DelegationKpi;
use App\Models\User;
use App\Services\DelegationKpiService;
use App\Support\Roles;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Недельный KPI-дайджест делегирования (H259, фаза D). Раз в неделю считает
 * снимок {@see DelegationKpiService} и шлёт финдиру/бухгалтеру сводку всех фаз с
 * пометкой цвета. Это «ритм обзора» с зубами: панель бесполезна, если в неё не
 * заглядывают (урок антикейса «Лингвистик» — финплан без внедрения и ритма).
 *
 * По умолчанию шлёт всегда (еженедельная сводка — часть ритма); с --only-alerts
 * шлёт только когда есть красный флаг (как receivables:check).
 */
class SendKpiDigest extends Command
{
    protected $signature = 'finance:kpi-digest
        {--dry : Только показать сводку, без отправки уведомлений}
        {--only-alerts : Слать только при красном флаге}';

    protected $description = 'Недельная KPI-сводка делегирования финдиру/бухгалтеру (запускать раз в неделю).';

    public function handle(DelegationKpiService $service): int
    {
        $snap = $service->snapshot();

        $this->line('KPI делегирования на '.$snap['period'].' — общий статус: '.$snap['level']);
        foreach ($snap['cards'] as $card) {
            $this->line('  ['.$card['level'].'] '.$card['label'].': '.$card['value']);
        }

        if ($this->option('only-alerts') && $snap['ok']) {
            $this->info('Красных флагов нет; --only-alerts: дайджест не отправлен.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->comment('--dry: уведомления не отправлены.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::ACCOUNTANT])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей с ролью финдира/бухгалтера — дайджест не отправлен.');

            return self::FAILURE;
        }

        // Тело — по строке на фазу с эмодзи-светофором для быстрого чтения.
        $marks = ['success' => '🟢', 'warning' => '🟡', 'danger' => '🔴', 'gray' => '⚪'];
        $lines = array_map(
            fn (array $c) => ($marks[$c['level']] ?? '⚪').' '.$c['label'].': '.$c['value'],
            $snap['cards'],
        );
        $body = implode("\n", $lines);

        foreach ($recipients as $recipient) {
            $notification = Notification::make()
                ->title('Недельный KPI делегирования')
                ->body($body)
                ->actions([
                    Action::make('open')
                        ->label('Открыть панель')
                        ->url(DelegationKpi::getUrl(), shouldOpenInNewTab: true),
                ]);

            $snap['ok'] ? $notification->info() : $notification->danger();

            $notification->sendToDatabase($recipient);
        }

        $this->info('Дайджест отправлен получателям: '.$recipients->count());

        return self::SUCCESS;
    }
}
