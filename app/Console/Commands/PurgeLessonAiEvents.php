<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LessonAiEvent;
use Illuminate\Console\Command;

/**
 * Чистка журнала ИИ-помощника занятия по сроку хранения.
 *
 * Срок — config('lesson_ai.events.retention_days'), по умолчанию 30 дней
 * (решение 24-11-2026). Журнал содержит личные вопросы учеников, поэтому
 * «хранить вечно» здесь не вариант: удаление — часть согласия, а не уборка.
 *
 * `--dry` считает, но не удаляет.
 */
class PurgeLessonAiEvents extends Command
{
    protected $signature = 'lesson-ai:purge-events
                            {--days= : Сколько дней хранить (по умолчанию из конфига)}
                            {--dry : Только посчитать}';

    protected $description = 'Удаляет строки журнала ИИ-помощника занятия старше срока хранения';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('lesson_ai.events.retention_days', 30)));
        $cutoff = now()->subDays($days);

        $query = LessonAiEvent::query()->where('created_at', '<', $cutoff);

        if ($this->option('dry')) {
            $this->info("Будет удалено: {$query->count()} строк старше {$days} дн. (до {$cutoff->format('d.m.Y H:i')}).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Удалено: {$deleted} строк старше {$days} дн. (до {$cutoff->format('d.m.Y H:i')}).");

        return self::SUCCESS;
    }
}
