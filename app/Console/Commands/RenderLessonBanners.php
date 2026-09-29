<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Schedule;
use App\Services\Banners\LessonBannerService;
use Illuminate\Console\Command;

/**
 * Плашки занятий: отрисовать JPEG «дата + номер» на ближайшие дни.
 *
 * Идемпотентна: неизменившиеся плашки (тот же render_hash, файл на месте)
 * не трогает. --force перерисовывает все окно (после правки шрифта или
 * выравнивания в spec без смены версии шаблона). --schedule=ID — одно занятие
 * вне окна (ручная проверка).
 *
 * Без features.lesson_banners — no-op с сообщением.
 */
class RenderLessonBanners extends Command
{
    protected $signature = 'lesson-banners:render
        {--days= : Окно вперед в днях (по умолчанию lesson_banners.lead_days)}
        {--force : Перерисовать даже неизменившиеся}
        {--schedule= : Отрисовать одно занятие по id (вне окна)}';

    protected $description = 'Отрисовать плашки занятий (дата + номер) на ближайшие дни.';

    public function handle(LessonBannerService $banners): int
    {
        if (! config('features.lesson_banners', false)) {
            $this->info('lesson_banners выключен (LESSON_BANNERS=false) — ничего не делаю.');

            return self::SUCCESS;
        }

        $scheduleId = $this->option('schedule');
        if ($scheduleId !== null && $scheduleId !== '') {
            $schedule = Schedule::query()->with('group')->find((int) $scheduleId);
            if ($schedule === null) {
                $this->error("Занятие #{$scheduleId} не найдено.");

                return self::FAILURE;
            }

            $outcome = $banners->syncSchedule($schedule, (bool) $this->option('force'));
            $this->info("Занятие #{$schedule->id}: {$outcome}");

            return self::SUCCESS;
        }

        $daysRaw = $this->option('days');
        $days = is_numeric($daysRaw) ? (int) $daysRaw : (int) config('lesson_banners.lead_days', 7);

        $counts = $banners->run($days, (bool) $this->option('force'));

        $this->info(sprintf(
            'Плашки занятий на %d дн.: отрисовано %d, без изменений %d, нет шаблона %d, нет номера %d, ошибок %d.',
            $days,
            $counts['rendered'],
            $counts['unchanged'],
            $counts['no_template'],
            $counts['no_number'],
            $counts['failed'],
        ));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
