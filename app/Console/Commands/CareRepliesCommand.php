<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CareChatReplyLog;
use Illuminate\Console\Command;

/**
 * H4362 — читатель журнала ответов чата «Отдел заботы» (CareChatReplyLog).
 *
 *   php artisan care:replies                      # все строки (до --limit)
 *   php artisan care:replies --since=2026-09-09T07:00:00Z
 *
 * Вывод — по одной JSON-строке на ответ (JSONL), поля см. CareChatReplyLog.
 * Потребитель: Uprava tools/guided_test_lane.py replies.
 */
final class CareRepliesCommand extends Command
{
    protected $signature = 'care:replies
        {--since= : ISO-время (UTC): показать только ответы, пойманные строго позже}
        {--limit=500 : максимум строк (последние N)}';

    protected $description = 'Печатает JSONL-журнал ответов в чате «Отдел заботы» (H4362).';

    public function handle(): int
    {
        $since = $this->option('since');
        $rows = CareChatReplyLog::read(
            is_string($since) && $since !== '' ? $since : null,
            max(1, (int) $this->option('limit')),
        );
        foreach ($rows as $row) {
            $this->line(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
