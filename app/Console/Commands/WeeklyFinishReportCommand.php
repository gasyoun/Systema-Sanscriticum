<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendTelegramChatMessageJob;
use App\Services\Schedule\WeeklyFinishReport;
use Illuminate\Console\Command;

/**
 * H4392 (MG 08-09-2026): еженедельный пост «Кто на чём закончил» в чат
 * «Институт» — по каждой идущей группе весь ростер с последним реальным
 * посещением каждого студента и пропуски 2+ подряд (WeeklyFinishReport).
 *
 * Чат получателя — TELEGRAM_INSTITUTE_CHAT_ID (services.telegram.institute_chat_id);
 * пусто → команда отказывает (не молчит: получатель обязан быть явным).
 * Флаг-гейт features.weekly_finish_report — единая точка включения дорожки.
 */
class WeeklyFinishReportCommand extends Command
{
    protected $signature = 'care:weekly-finish {--dry-run : напечатать пост, не отправлять}';

    protected $description = 'Еженедельный пост в «Институт»: кто в какой идущей группе на чём закончил (H4392)';

    public function handle(): int
    {
        if (! config('features.weekly_finish_report', false)) {
            $this->info('Флаг features.weekly_finish_report выключен — выхожу.');

            return self::SUCCESS;
        }

        $report = WeeklyFinishReport::build();

        if ($report === []) {
            $this->info('Идущих групп нет — пост не собирается.');

            return self::SUCCESS;
        }

        $chunks = WeeklyFinishReport::telegramChunks($report);

        if ($this->option('dry-run')) {
            foreach ($chunks as $i => $chunk) {
                $this->line($i > 0 ? "\n--- chunk ".($i + 1).'/'.count($chunks).' ---' : '');
                $this->line($chunk);
            }
            $this->newLine();
            $this->info('dry-run: '.count($chunks).' сообщ., '.array_sum(array_map('mb_strlen', $chunks)).' символов — НЕ отправлено.');

            return self::SUCCESS;
        }

        $chatId = (string) config('services.telegram.institute_chat_id');
        if ($chatId === '') {
            $this->error('TELEGRAM_INSTITUTE_CHAT_ID пуст — получатель не задан, отправка отменена.');

            return self::FAILURE;
        }

        foreach ($chunks as $chunk) {
            SendTelegramChatMessageJob::dispatch($chatId, $chunk);
        }

        $this->info('Отправлено '.count($chunks).' сообщ. в чат '.$chatId.' ('.count($report).' групп).');

        return self::SUCCESS;
    }
}
