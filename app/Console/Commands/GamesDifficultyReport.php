<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameEvent;
use Illuminate\Console\Command;

/**
 * H4692 — трудность вопросов по завершённым match-раундам /lila.
 *
 * Источник — события item_result (payload {hints, items:[{l,r,ms,wrong}]}):
 * по каждому (drill, band, вопрос l → r) считаем число наблюдений, медиану и
 * среднее ms (время до верной связки) и wrong-rate — долю наблюдений, где пару
 * хотя бы раз проверили неверно. JSON-элементы разворачиваем в PHP, а не в
 * SQL: и MySQL-, и SQLite-совместимо, а объём — один ряд на завершённый
 * раунд, не на показ.
 */
class GamesDifficultyReport extends Command
{
    protected $signature = 'games:difficulty
        {--days=30 : Окно отчёта, дней назад от сегодня}
        {--drill= : Фильтр по тренажёру (drill)}';

    protected $description = 'Трудность вопросов /lila: медиана/среднее ms и wrong-rate по парам item_result (H4692)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $drillFilter = (string) ($this->option('drill') ?? '');

        $events = GameEvent::query()
            ->where('event', GameEvent::ITEM_RESULT)
            ->where('created_at', '>=', $since)
            ->when($drillFilter !== '', fn ($q) => $q->where('drill', $drillFilter))
            ->orderBy('created_at')
            ->get(['drill', 'band', 'payload']);

        /** @var array<string, array{drill: string, band: string, q: string, ms: list<int>, wrongs: int, n: int}> $agg */
        $agg = [];
        $rounds = 0;
        $observations = 0;

        foreach ($events as $event) {
            $items = is_array($event->payload) ? ($event->payload['items'] ?? null) : null;
            if (! is_array($items) || $items === []) {
                continue;
            }
            $rounds++;
            $drill = (string) $event->drill;
            $band = $event->band !== null ? (string) $event->band : '—';

            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['l'] ?? null) || $item['l'] === '') {
                    continue;
                }
                $l = $item['l'];
                $r = is_string($item['r'] ?? null) ? $item['r'] : '';
                $ms = is_numeric($item['ms'] ?? null) ? (int) $item['ms'] : 0;
                $wrong = is_numeric($item['wrong'] ?? null) ? (int) $item['wrong'] : 0;

                $key = $drill.'|'.$band.'|'.$l.'|'.$r;
                $agg[$key] ??= [
                    'drill' => $drill,
                    'band' => $band,
                    'q' => $l.($r === '' ? '' : ' → '.$r),
                    'ms' => [],
                    'wrongs' => 0,
                    'n' => 0,
                ];
                $agg[$key]['ms'][] = $ms;
                $agg[$key]['wrongs'] += $wrong > 0 ? 1 : 0;
                $agg[$key]['n']++;
                $observations++;
            }
        }

        $this->info("Трудность вопросов (item_result) за {$days} дн. (с {$since->toDateTimeString()}):");

        if ($agg === []) {
            $this->line('  Пока ни одного item_result в окне — завершите раунд match-игры после деплоя H4692.');

            return self::SUCCESS;
        }

        // Самые трудные первыми: больше наблюдений -> выше медиана.
        usort($agg, fn (array $a, array $b): int => [$b['n'], $this->median($b['ms'])] <=> [$a['n'], $this->median($a['ms'])]);

        $rows = [];
        foreach ($agg as $a) {
            $rows[] = [
                $a['drill'],
                $a['band'],
                $a['q'],
                $a['n'],
                $this->median($a['ms']),
                (int) round(array_sum($a['ms']) / max(1, count($a['ms']))),
                $this->pct($a['wrongs'], $a['n']),
            ];
        }

        $this->table(
            ['Тренажёр', 'Уровень', 'Вопрос', 'n', 'Медиана мс', 'Среднее мс', 'Wrong%'],
            $rows,
        );

        $this->line(sprintf(
            'Раундов (item_result): %d, наблюдений (пар): %d%s.',
            $rounds,
            $observations,
            $drillFilter !== '' ? ", фильтр drill={$drillFilter}" : '',
        ));

        return self::SUCCESS;
    }

    private function pct(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '—';
        }

        return round($part / $whole * 100, 1).'%';
    }

    /** Медиана целых мс: середина сортированного списка (чётное — среднее двух середин). */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (int) $values[$mid]
            : (int) round(((int) $values[$mid - 1] + (int) $values[$mid]) / 2);
    }
}
