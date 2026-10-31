<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentCalendarRubric;
use App\Models\ContentCalendarSlot;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Content\RubricQueue;
use App\Services\Content\RubricScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Рубрики календаря (28-10-2026): если ближайший выход рубрики в
 * следующие 24 ч ничем не занят — одно сообщение админам в Telegram. Сам
 * пропуск ничего не публикует: нет поста — нет выхода. Повторно о том же
 * моменте не пишет (last_empty_alert_for).
 */
class RubricGapsCommand extends Command
{
    protected $signature = 'content:rubric-gaps';

    protected $description = 'Warn admins when a content-calendar rubric has no post for its next run within 24 h';

    private const WINDOW_HOURS = 24;

    public function handle(RubricScheduler $scheduler, TelegramAdminNotifier $notifier): int
    {
        if (config('features.content_calendar') !== true) {
            $this->warn('content_calendar flag is OFF — no-op.');

            return self::SUCCESS;
        }

        $now = Carbon::now('Europe/Moscow');
        $until = $now->copy()->addHours(self::WINDOW_HOURS);
        $alerts = 0;

        foreach (ContentCalendarRubric::query()->where('is_active', true)->get() as $rubric) {
            $gap = null;
            foreach ($scheduler->occurrences($rubric, $now, 10) as $at) {
                if ($at->gt($until)) {
                    break;
                }
                $taken = $rubric->slots()
                    ->where('status', '!=', ContentCalendarSlot::STATUS_CANCELED)
                    ->where('publish_at', $at)
                    ->exists();
                if (! $taken) {
                    $gap = $at;
                    break;
                }
            }

            if ($gap === null || ($rubric->last_empty_alert_for !== null && $rubric->last_empty_alert_for->eq($gap))) {
                continue;
            }

            $drafts = $rubric->slots()->whereIn('status', RubricQueue::ENQUEUEABLE)->count();
            $notifier->notifyAdmins("Календарь контента: в рубрике «{$rubric->name}» нет поста на {$gap->format('d.m H:i')} МСК — выход будет пропущен. "
                .($drafts > 0
                    ? "В рубрике {$drafts} черновиков: «Рубрики календаря» → «Черновики в очередь»."
                    : 'Добавьте пост в рубрику.'));
            $rubric->forceFill(['last_empty_alert_for' => $gap])->save();
            $alerts++;
        }

        $this->info("rubric-gaps: alerts={$alerts}");

        return self::SUCCESS;
    }
}
