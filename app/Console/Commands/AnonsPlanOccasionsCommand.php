<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Anons\AnonsOccasionSelector;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * H6329: отбор поводов анонс-кампании по виду занятия — «по разовым» /
 * «по обзорным» / «по обычным». Воспроизводимый список поводов для минта
 * кампании; значение --kind затем пишется в журнал размещений
 * (anons:ops journal-add --kind=…). Без --kind — все виды.
 */
final class AnonsPlanOccasionsCommand extends Command
{
    protected $signature = 'anons:plan-occasions
        {--kind= : обзорное | разовое | обычное (без флага — все виды)}
        {--json : машинный JSON вместо построчного вывода}';

    protected $description = 'Отбор поводов анонс-кампании по виду занятия (H6329)';

    public function handle(AnonsOccasionSelector $selector): int
    {
        $kind = trim((string) $this->option('kind'));
        if ($kind === '') {
            $kind = null;
        }

        try {
            $occasions = $selector->select($kind);
        } catch (InvalidArgumentException $e) {
            // Fail-closed: неизвестный вид — отказ, а не молчаливое «пусто/всё».
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'kind' => $kind,
                'count' => $occasions->count(),
                'occasions' => $occasions->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($occasions as $occasion) {
            $this->line(sprintf(
                '%s  %s [%s]  /k/%s  #%d',
                (string) $occasion['start'],
                (string) $occasion['title'],
                (string) $occasion['kind'],
                (string) $occasion['course_slug'],
                (int) $occasion['schedule_id'],
            ));
        }

        $this->info(sprintf(
            'Поводов: %d%s.',
            $occasions->count(),
            $kind !== null ? ' (вид: '.$kind.')' : ' (все виды)',
        ));

        return self::SUCCESS;
    }
}
