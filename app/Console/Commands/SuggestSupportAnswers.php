<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Console\Command;

class SuggestSupportAnswers extends Command
{
    protected $signature = 'support:suggest-answers';

    protected $description = 'Найти в переписке (веб-чат + TG-support) фактологические FAQ-вопросы (Zoom/записи/расписание) и завести факт-черновик ответа для куратора. Без LLM. Ничего не отправляет сам.';

    public function handle(SupportAnswerSuggester $suggester): int
    {
        $result = $suggester->run();

        $this->info("FAQ-суггестер: просканировано {$result['scanned']}, создано черновиков {$result['created']}.");

        return self::SUCCESS;
    }
}
