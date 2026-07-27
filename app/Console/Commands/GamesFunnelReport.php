<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GameEvent;
use Illuminate\Console\Command;

/**
 * Отчёт по воронке бесплатных тренажёров /lila (H1360).
 *
 * plays -> completes -> walls -> CTA за N дней, по одной строке на (drill, band).
 * Честность про охват (как cabinet:baseline): семейства тренажёров, у которых за
 * окно НЕТ ни одного события, перечисляются отдельно как «нет данных», а не молча
 * показываются нулём — иначе «отсутствие поверхности» не отличить от «нет игроков».
 */
class GamesFunnelReport extends Command
{
    protected $signature = 'games:funnel {--days=14 : Окно отчёта, дней назад от сегодня}';

    protected $description = 'Воронка бесплатных тренажёров /lila: показы -> решения -> стена -> CTA за N дней (H1360)';

    /** Известные семейства тренажёров (по каталогу public/lila/). */
    private const KNOWN_DRILLS = ['ligatures', 'roots', 'sort', 'match', 'cloze', 'index'];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $funnel = GameEvent::funnel($since);

        $rows = [];
        foreach ($funnel as $r) {
            $rows[] = [
                $r['drill'],
                $r['band'] ?? '—',
                $r['plays'],
                $r['completes'],
                $r['walls'],
                $r['cta'],
                $this->pct($r['completes'], $r['plays']),
            ];
        }

        $this->info("Воронка тренажёров за {$days} дн. (с {$since->toDateTimeString()}):");

        if ($rows === []) {
            $this->line('  Пока ни одного события в окне.');
        } else {
            $this->table(
                ['Тренажёр', 'Уровень', 'Показы', 'Решения', 'Стена', 'CTA', 'Реш./показ'],
                $rows,
            );
        }

        // Честность про охват: семейства без данных за окно.
        $seen = array_unique(array_map(static fn ($r): string => $r['drill'], $funnel));
        $missing = array_values(array_diff(self::KNOWN_DRILLS, $seen));
        if ($missing !== []) {
            $this->line('Без данных за окно (поверхность есть, событий нет):');
            foreach ($missing as $drill) {
                $this->line("  - {$drill}");
            }
        }

        return self::SUCCESS;
    }

    private function pct(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '—';
        }

        return round($part / $whole * 100, 1).'%';
    }
}
