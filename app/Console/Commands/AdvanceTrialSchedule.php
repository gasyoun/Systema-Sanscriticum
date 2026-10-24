<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Пробное занятие переключается само на следующее занятие (features.trial_auto_advance).
 *
 * Курс с платным пробным (trial_price > 0) закреплён за одним событием
 * расписания (trial_schedule_id). Как только это занятие началось, пин
 * переводится на следующее событие того же курса и той же группы (с тем же
 * признаком обзорного), а урок-заготовку под него создаёт прежний
 * {@see Course::syncTrialPlaceholderLesson()} (ключ — как у n8n). Следующего
 * занятия нет — курс не трогаем: остаётся алерт {@see CheckTrialFreshness}.
 *
 * Уже купившие сохраняют LessonAccessGrant на свой урок — переключение влияет
 * только на новые покупки. Платёжный путь не меняется.
 *
 *   php artisan trial:auto-advance --dry-run   # что переключилось бы
 *   php artisan trial:auto-advance             # переключить (только при флаге ON)
 */
class AdvanceTrialSchedule extends Command
{
    protected $signature = 'trial:auto-advance {--dry-run : Показать переключения, ничего не меняя}';

    protected $description = 'Переключить пробное занятие курсов на следующее занятие группы, когда текущее началось';

    public function handle(TelegramAdminNotifier $notifier): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('features.trial_auto_advance')) {
            $this->info('features.trial_auto_advance выключен — ничего не делаю (проверка: --dry-run).');

            return self::SUCCESS;
        }

        $moved = [];
        $stuck = [];

        Course::query()
            ->where('trial_price', '>', 0)
            ->whereNotNull('trial_schedule_id')
            ->orderBy('id')
            ->each(function (Course $course) use ($dryRun, &$moved, &$stuck): void {
                $current = Schedule::find($course->trial_schedule_id);

                // Занятие ещё впереди — пробное продаёт живое подключение, всё верно.
                if ($current?->start !== null && $current->start->isFuture()) {
                    return;
                }

                // Без события или без группы следующее занятие не определить однозначно.
                if ($current === null || $current->group_id === null) {
                    $stuck[] = [$course->id, $course->title, 'событие пина не найдено или без группы'];

                    return;
                }

                $next = Schedule::query()
                    ->where('course_id', $course->id)
                    ->where('group_id', $current->group_id)
                    ->where('is_overview', (bool) $current->is_overview)
                    ->where('start', '>', now())
                    ->orderBy('start')
                    ->first();

                if ($next === null) {
                    $stuck[] = [$course->id, $course->title, 'в расписании группы нет следующего занятия'];

                    return;
                }

                $moved[] = [$course->id, $course->title, $current->start?->format('d.m.Y H:i'), $next->start->format('d.m.Y H:i')];

                if ($dryRun) {
                    return;
                }

                // save() → saved-хук курса → syncTrialPlaceholderLesson(): урок-заготовка
                // под новое занятие и trial_lesson_id на неё.
                $course->trial_schedule_id = $next->id;
                $course->save();

                Log::info('trial:auto-advance — пробное переключено', [
                    'course_id' => $course->id,
                    'from_schedule_id' => $current->id,
                    'to_schedule_id' => $next->id,
                    'trial_lesson_id' => $course->fresh()?->trial_lesson_id,
                ]);
            });

        if ($moved !== []) {
            $this->table(['course_id', 'курс', 'было', 'стало'], $moved);
        }
        if ($stuck !== []) {
            $this->warn('Не переключено (оставлено как есть):');
            $this->table(['course_id', 'курс', 'причина'], $stuck);
        }
        if ($moved === [] && $stuck === []) {
            $this->info('Переключать нечего: у всех курсов пробное занятие впереди.');
        }

        if (! $dryRun && $moved !== []) {
            $text = "🎟 <b>Пробное переключено на следующее занятие</b>\n\n";
            foreach ($moved as [$id, $title, $from, $to]) {
                $text .= '• #'.$id.' «'.e((string) $title).'»: '.e((string) $from).' → '.e($to)."\n";
            }
            try {
                $notifier->notifyAdmins($text);
            } catch (\Throwable $e) {
                // Сеть до api.telegram.org не должна ронять плановую команду.
                Log::error('trial:auto-advance — уведомление админам не ушло', ['error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
