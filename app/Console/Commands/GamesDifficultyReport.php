<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameEvent;
use Illuminate\Console\Command;

/**
 * H4692 — отчет трудности по вопросам бесплатных тренажеров /lila.
 *
 * Одна строка на (drill, band, вопрос): сколько раундов увидело вопрос,
 * медиана и среднее времени до первой связки пары (ms) и доля раундов,
 * где пару хоть раз проверили с ошибкой. Самые трудные — сверху.
 * Зеркало games:funnel; источник — GameEvent::difficulty().
 */
class GamesDifficultyReport extends Command
{
    protected $signature = 'games:difficulty
        {--days=30 : Окно отчета, дней назад от сегодня}
        {--limit=25 : Сколько вопросов показать}';

    protected $description = 'Трудность вопросов /lila: медиана ms до связки и wrong-rate по каждому вопросу за N дней (H4692)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $since = now()->subDays($days);

        $rows = GameEvent::difficulty($since);

        $this->info("Трудность вопросов тренажеров за {$days} дн. (с {$since->toDateTimeString()}), топ-{$limit}:");

        if ($rows === []) {
            $this->line('  Пока ни одного события item_result в окне (нужны завершенные раунды match-игр).');

            return self::SUCCESS;
        }

        $table = array_map(static fn (array $r): array => [
            $r['drill'],
            $r['band'] ?? '—',
            $r['item'],
            $r['rounds'],
            $r['median_ms'],
            $r['avg_ms'],
            $r['wrong_rate'].'%',
        ], array_slice($rows, 0, $limit));

        $this->table(
            ['Тренажер', 'Уровень', 'Вопрос', 'Раунды', 'Медиана, мс', 'Среднее, мс', 'Была ошибка'],
            $table,
        );

        $total = count($rows);
        if ($total > $limit) {
            $this->line('… и еще '.($total - $limit).' вопросов — увеличьте --limit.');
        }

        return self::SUCCESS;
    }
}
