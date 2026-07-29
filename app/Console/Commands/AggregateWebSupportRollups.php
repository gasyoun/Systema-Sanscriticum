<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\WebSupportRollupAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H1837 (S10) — агрегация веб-стороны поддержки в `support_daily_rollups`.
 *
 * TG-сторона агрегируется как побочный эффект синка userbot'а
 * (TelegramSupportSyncService), у веб-канала такого синка нет — сообщения пишутся
 * напрямую контроллерами/джобами. Поэтому нужна отдельная периодическая команда.
 *
 * Окно по умолчанию — `support.rollup.web_backfill_days` (не один день!): KPI
 * «висит без ответа N часов» у вчерашнего разговора дозревает только сегодня, и
 * без перекрытия он навсегда остался бы false.
 *
 * Гейт `features.support_web_rollups` проверяется ЗДЕСЬ, а не в сервисе: сервис
 * должен оставаться вызываемым в тестах и ручном бэкфилле. Пока флаг OFF —
 * команда честно ничего не пишет и говорит об этом.
 */
class AggregateWebSupportRollups extends Command
{
    protected $signature = 'support:rollup-web
                            {--date= : конкретная дата (Y-m-d); по умолчанию окно из конфига}
                            {--days= : сколько последних дней пересчитать (перебивает конфиг)}
                            {--force : выполнить даже при выключенном features.support_web_rollups}';

    protected $description = 'Дневные rollup\'ы веб-чата/VK/TG-бота (S10) в support_daily_rollups';

    public function handle(WebSupportRollupAggregator $aggregator): int
    {
        if (! config('features.support_web_rollups') && ! $this->option('force')) {
            $this->line('features.support_web_rollups=false — пропуск (ничего не записано).');

            return self::SUCCESS;
        }

        $dates = $this->dates();
        $total = 0;

        foreach ($dates as $date) {
            $written = $aggregator->aggregateDate($date);
            $total += $written;
            $this->line(sprintf('%s: %d rollup-строк', $date, $written));
        }

        $this->info(sprintf('Готово: %d дн., %d строк.', count($dates), $total));

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function dates(): array
    {
        if ($date = $this->option('date')) {
            return [CarbonImmutable::parse($date, config('app.timezone'))->toDateString()];
        }

        $days = max(1, (int) ($this->option('days') ?? config('support.rollup.web_backfill_days', 2)));
        $today = CarbonImmutable::now(config('app.timezone'));

        // От старой даты к новой — порядок не важен для корректности, но так
        // читаемее в логе планировщика.
        return collect(range($days - 1, 0))
            ->map(fn (int $back): string => $today->subDays($back)->toDateString())
            ->all();
    }
}
