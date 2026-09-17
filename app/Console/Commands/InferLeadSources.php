<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use App\Support\LeadSourceInference;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * H5021 — ночной вывод источника лида: для строк без source (человек) и без
 * inferred_source (машина) выводим канал из UTM / статьи / referrer / кабинета /
 * канала лид-магнита и пишем inferred_source + inference_rule + source_inferred_at.
 *
 * Инварианты:
 *  - leads.source (введён человеком) НИКОГДА не перетирается и не заполняется;
 *  - без --recompute уже выведенные строки не трогаем (идемпотентно за ночь);
 *  - запись — update по id мимо Eloquent-событий: без строк в lead_audits и
 *    без bump updated_at, это машинная разметка, а не правка менеджера;
 *  - --dry-run печатает тот же отчёт (доля до/после, разрез по правилам), не пишет.
 */
final class InferLeadSources extends Command
{
    protected $signature = 'leads:infer-source
        {--dry-run : Только отчёт (доля с источником до/после, разрез по правилам), ничего не пишем}
        {--recompute : Пересчитать и уже выведенные inferred_source (source руками — никогда)}
        {--chunk=500 : Размер пачки}';

    protected $description = 'H5021: вывести источник лида из UTM/referrer/статьи/лид-магнита в inferred_source (ночью; --dry-run для отчёта)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $recompute = (bool) $this->option('recompute');
        $chunk = max(50, (int) $this->option('chunk'));

        $total = Lead::query()->count();
        $before = self::withSourceQuery()->count();

        $query = Lead::query()
            ->where(fn (Builder $q) => $q->whereNull('source')->orWhere('source', ''))
            ->when(! $recompute, fn (Builder $q) => $q->whereNull('inferred_source'))
            ->orderBy('id');

        $byRule = [];
        $bySource = [];
        $unresolved = 0;
        $written = 0;
        $now = now();

        $query->chunkById($chunk, function ($leads) use (&$byRule, &$bySource, &$unresolved, &$written, $dry, $now) {
            foreach ($leads as $lead) {
                $hit = LeadSourceInference::infer($lead);
                if ($hit === null) {
                    $unresolved++;

                    continue;
                }
                $byRule[$hit['rule']] = ($byRule[$hit['rule']] ?? 0) + 1;
                $bySource[$hit['source']] = ($bySource[$hit['source']] ?? 0) + 1;
                if ($dry) {
                    continue;
                }
                // Мимо модели (toBase): без lead_audits и без bump updated_at —
                // это машинная разметка, а не правка менеджера.
                Lead::query()->whereKey($lead->id)->toBase()->update([
                    'inferred_source' => $hit['source'],
                    'inference_rule' => $hit['rule'],
                    'source_inferred_at' => $now,
                ]);
                $written++;
            }
        });

        $newlyResolved = array_sum($byRule);
        $after = $dry
            ? min($total, $before + ($recompute ? 0 : $newlyResolved))
            : self::withSourceQuery()->count();

        $this->info(sprintf(
            'leads:infer-source — %s · %s',
            $now->format('Y-m-d H:i'),
            $dry ? 'DRY-RUN (ничего не записано)' : ($written.' строк записано'),
        ));
        $this->line(sprintf(
            'Доля лидов с источником: до %s (%d/%d) → после %s (%d/%d); не выведено: %d',
            self::pct($before, $total), $before, $total,
            self::pct($after, $total), $after, $total,
            $unresolved,
        ));

        arsort($byRule);
        $this->table(['правило', 'лидов'], $this->rows($byRule));

        arsort($bySource);
        $this->table(['источник', 'лидов'], $this->rows(array_slice($bySource, 0, 15, true)));

        return self::SUCCESS;
    }

    /** Лид «с источником» = ручной source ИЛИ машинный inferred_source не пуст. */
    public static function withSourceQuery(): Builder
    {
        return Lead::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $s) => $s->whereNotNull('source')->where('source', '!=', ''))
            ->orWhere(fn (Builder $s) => $s->whereNotNull('inferred_source')->where('inferred_source', '!=', '')));
    }

    /**
     * @param  array<string,int>  $counts
     * @return list<array{0:string,1:int}>
     */
    private function rows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $n) {
            $rows[] = [(string) $key, $n];
        }

        return $rows === [] ? [['—', 0]] : $rows;
    }

    private static function pct(int $part, int $total): string
    {
        return $total > 0 ? number_format(100 * $part / $total, 1, ',', ' ').'%' : '—';
    }
}
