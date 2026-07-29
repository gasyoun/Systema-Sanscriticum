<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\WebChatDailyRollupAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H1837 — дневная свёртка веб-чата в `support_daily_rollups`.
 *
 * Telegram-сторону сворачивает сам синк (по затронутым датам); у веб-чата синка
 * нет — сообщения пишутся живьём, поэтому свёртку гоняет эта команда. По
 * умолчанию считает вчерашний день (сутки уже закрыты) плюс сегодняшний, чтобы
 * дашборд не отставал на день; `--days=N` догоняет прошлое, `--date=` считает
 * одну конкретную дату. Идемпотентна: updateOrCreate по (тред, дата).
 */
class AggregateWebChatRollups extends Command
{
    protected $signature = 'support:rollup-web
        {--date= : Одна дата YYYY-MM-DD вместо окна}
        {--days=2 : Сколько последних дней пересчитать (включая сегодня)}
        {--force : Считать даже при выключенном features.web_chat_deflection_rollup}';

    protected $description = 'Пересчитать дневные rollup-строки веб-чата поддержки (паритет с Telegram-свёрткой).';

    public function handle(WebChatDailyRollupAggregator $aggregator): int
    {
        // Единственный новый пишущий путь задачи — за флагом, ВЫКЛ по умолчанию.
        // `--force` оставлен для разового догона истории при включении на проде.
        if (! config('features.web_chat_deflection_rollup') && ! $this->option('force')) {
            $this->line('Web-chat rollups: features.web_chat_deflection_rollup выключен — пропуск.');

            return self::SUCCESS;
        }

        $dates = $this->datesToAggregate();
        $total = 0;

        foreach ($dates as $date) {
            $count = $aggregator->aggregateDate($date);
            $total += $count;
            $this->line(sprintf('%s — %d тред-дней', $date, $count));
        }

        $this->info(sprintf('Web-chat rollups: %d за %d дн.', $total, count($dates)));

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function datesToAggregate(): array
    {
        if ($this->option('date')) {
            return [CarbonImmutable::parse((string) $this->option('date'))->toDateString()];
        }

        $days = max(1, (int) $this->option('days'));
        $today = CarbonImmutable::now(config('app.timezone'));

        return collect(range($days - 1, 0))
            ->map(fn (int $back): string => $today->subDays($back)->toDateString())
            ->all();
    }
}
