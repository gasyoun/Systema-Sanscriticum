<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenLessonCards\OpenLessonTelegram;
use Illuminate\Console\Command;

/**
 * Описание @samskrte_bot до /start («Что умеет этот бот?») и короткое
 * описание в профиле — из config/open_lesson_cards.php. Картинку к описанию
 * Bot API не задаёт: только @BotFather → /setdescription → Edit Description Picture.
 */
class SetOpenLessonBotProfile extends Command
{
    protected $signature = 'open-lessons:bot-profile';

    protected $description = 'Задать описание @samskrte_bot (до /start) из config/open_lesson_cards.php';

    public function handle(OpenLessonTelegram $telegram): int
    {
        $calls = [
            'setMyDescription' => ['description' => (string) config('open_lesson_cards.bot_description')],
            'setMyShortDescription' => ['short_description' => (string) config('open_lesson_cards.bot_short_description')],
        ];

        foreach ($calls as $method => $payload) {
            $response = $telegram->call($method, $payload);
            if ($response['status'] !== OpenLessonTelegram::OK) {
                $this->error("{$method}: ".($response['description'] ?? 'ошибка'));

                return self::FAILURE;
            }
            $this->info("{$method}: ok");
        }

        $this->line('Картинка к описанию — вручную в @BotFather (/setdescription → Edit Description Picture).');

        return self::SUCCESS;
    }
}
