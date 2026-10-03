<?php

namespace App\Console\Commands;

use App\Services\SupportQuestions\GoldReviewService;
use Illuminate\Console\Command;

/**
 * H5773 — заморозка защищённой выборки gold-ревью с CLI.
 *
 * Тот же сервис, что и экран /admin/telegram-support/support-questions-gold-review:
 * вызывает существующую стратификацию support:questions-review, импортирует
 * лист в замороженные таблицы и печатает ТОЛЬКО агрегаты (окно, размер,
 * fingerprint). Тексты сообщений не читаются и не печатаются.
 */
class QuestionsGoldFreezeCommand extends Command
{
    protected $signature = 'support:questions-gold-freeze
        {--from= : ISO-дата начала окна выборки (вместе с --to; окно всего бэкфилла — как у H5709)}
        {--to= : ISO-дата конца окна выборки (эксклюзивно)}
        {--sample=100 : размер стратифицированной выборки}';

    protected $description = 'Заморозить выборку gold-ревью недельных вопросов (агрегаты наружу, без текстов).';

    public function handle(): int
    {
        $aggregates = app(GoldReviewService::class)->freezeFromCommand(
            ['from' => $this->option('from'), 'to' => $this->option('to')],
            max(1, (int) $this->option('sample')),
            null,
        );

        $this->line(json_encode($aggregates, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
