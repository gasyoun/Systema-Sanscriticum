<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reminders\ReminderRequestDetector;
use Illuminate\Console\Command;

class DetectReminderRequests extends Command
{
    protected $signature = 'reminders:detect-requests';

    protected $description = 'Найти в переписке (веб-чат + TG-support) просьбы «напомните мне» и завести ReminderSuggestion для куратора. Ничего не отправляет сам.';

    public function handle(ReminderRequestDetector $detector): int
    {
        $result = $detector->run();

        $this->info("Детектор напоминаний: просканировано {$result['scanned']}, создано предложений {$result['created']}.");

        return self::SUCCESS;
    }
}
