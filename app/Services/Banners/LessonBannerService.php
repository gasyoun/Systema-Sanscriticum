<?php

declare(strict_types=1);

namespace App\Services\Banners;

use App\Models\Group;
use App\Models\LessonBanner;
use App\Models\LessonBannerTemplate;
use App\Models\Schedule;
use App\Services\Schedule\LessonSeriesInfo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Плашки занятий: какие занятия рисуем, каким шаблоном, какой номер и дата,
 * куда кладём и когда перерисовываем.
 *
 * Номер занятия — ТОЛЬКО из тега титула «(#13, 08.09.26)» через
 * LessonSeriesInfo::number(): отдельной колонки нет, и считать номер заново
 * (по порядку строк) значит разойтись с тем, что видят студенты в расписании
 * при первой же отмене. Обзорное занятие (is_overview) номера не имеет — на
 * плашку идёт overview_text шаблона.
 *
 * Имя файла на Диске — UTC-дата старта: ZOOM 1.4 ищет «start_time.split('T')[0]»
 * из вебхука Zoom, а Zoom отдаёт UTC. Для занятия в 01:00 МСК это ВЧЕРАШНЯЯ
 * дата, и текст на плашке (МСК) с именем файла намеренно расходится.
 */
final class LessonBannerService
{
    public function __construct(private readonly LessonBannerRenderer $renderer) {}

    /**
     * Занятия, которым нужна плашка: живые, с группой в рабочем статусе,
     * стартуют в окне [сейчас, сейчас + $days дней].
     *
     * @return Collection<int, Schedule>
     */
    public function dueSchedules(int $days, ?CarbonInterface $now = null): Collection
    {
        $now = $now ? Carbon::instance($now) : now();
        $statuses = (array) config('lesson_banners.group_statuses', ['forming', 'active']);

        return Schedule::query()
            ->with('group')
            ->whereNotNull('group_id')
            ->whereNotNull('start')
            ->whereBetween('start', [$now, $now->copy()->addDays(max(0, $days))])
            ->whereHas('group', fn ($q) => $q->whereIn('status', $statuses))
            ->orderBy('start')
            ->get();
    }

    /**
     * Прогон по окну: создать/обновить плашки. Падение одного занятия не
     * валит остальные — оно логируется и попадает в счётчик failed.
     *
     * @return array{rendered: int, unchanged: int, no_template: int, no_number: int, failed: int}
     */
    public function run(int $days, bool $force = false, ?CarbonInterface $now = null): array
    {
        $counts = ['rendered' => 0, 'unchanged' => 0, 'no_template' => 0, 'no_number' => 0, 'failed' => 0];

        foreach ($this->dueSchedules($days, $now) as $schedule) {
            try {
                $outcome = $this->syncSchedule($schedule, $force);
            } catch (Throwable $e) {
                Log::error('Плашка занятия не отрисована.', [
                    'schedule_id' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);
                $outcome = 'failed';
            }
            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Одна плашка. Возвращает rendered | unchanged | no_template | no_number.
     */
    public function syncSchedule(Schedule $schedule, bool $force = false): string
    {
        $template = LessonBannerTemplate::resolveFor($this->courseIdFor($schedule), $schedule->group_id);
        $isOverview = (bool) $schedule->is_overview;
        $number = $isOverview ? null : LessonSeriesInfo::number($schedule->title);

        /** @var LessonBanner $banner */
        $banner = LessonBanner::query()->firstOrNew(['schedule_id' => $schedule->id]);

        if ($template === null) {
            return $this->markSkipped($banner, LessonBanner::NO_TEMPLATE, null, $number);
        }

        if (! $isOverview && $number === null) {
            return $this->markSkipped($banner, LessonBanner::NO_NUMBER, $template->id, null);
        }

        $texts = $this->texts($template, $schedule, $number);
        $filename = self::driveFilename($schedule);
        $hash = sha1(implode('|', [$template->id, $template->version, $number ?? 'overview', $texts['date'], $texts['number'], $filename]));

        $disk = (string) config('lesson_banners.image_disk', 'public');
        $fileIsThere = $banner->image_path !== null
            && $banner->image_disk !== null
            && Storage::disk($banner->image_disk)->exists($banner->image_path);

        if (! $force && $banner->exists && $banner->render_status === LessonBanner::RENDERED
            && $banner->render_hash === $hash && $fileIsThere) {
            return 'unchanged';
        }

        $jpeg = $this->renderer->render($template, $texts);

        $path = trim((string) config('lesson_banners.image_dir', 'lesson-banners'), '/')
            .'/'.($schedule->group_id ?? 'no-group')
            .'/'.$schedule->id.'-'.substr($hash, 0, 10).'.jpg';
        Storage::disk($disk)->put($path, $jpeg);

        $oldDisk = $banner->image_disk;
        $oldPath = $banner->image_path;

        $banner->fill([
            'template_id' => $template->id,
            'render_status' => LessonBanner::RENDERED,
            'render_hash' => $hash,
            'lesson_number' => $number,
            'image_disk' => $disk,
            'image_path' => $path,
            'drive_filename' => $filename,
            'rendered_at' => now(),
            // Новая картинка — доставку заново: n8n заменит файл на Диске.
            'delivery_status' => null,
            'delivery_error' => null,
            'delivered_at' => null,
        ])->save();

        if ($oldPath !== null && $oldDisk !== null && ($oldPath !== $path || $oldDisk !== $disk)) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return 'rendered';
    }

    /**
     * Тексты полей для занятия (и для превью в админке).
     *
     * @return array{date: string, number: string}
     */
    public function texts(LessonBannerTemplate $template, Schedule $schedule, ?int $number): array
    {
        return $this->textsFor($template, Carbon::parse($schedule->start), $number, (bool) $schedule->is_overview);
    }

    /** @return array{date: string, number: string} */
    public function textsFor(LessonBannerTemplate $template, CarbonInterface $start, ?int $number, bool $isOverview): array
    {
        $dateField = $template->field('date');
        $numberField = $template->field('number');

        $date = Carbon::instance($start)
            ->setTimezone((string) config('lesson_banners.display_timezone', 'Europe/Moscow'))
            ->locale('ru')
            ->isoFormat((string) ($dateField['format'] ?? 'D MMMM'));

        $numberText = $isOverview || $number === null
            ? (string) ($numberField['overview_text'] ?? 'Обзорное занятие')
            : str_replace('{N}', (string) $number, (string) ($numberField['format'] ?? 'Занятие {N}'));

        return ['date' => $date, 'number' => $numberText];
    }

    /** «ГГГГ-ММ-ДД.jpg» по UTC-дате старта — ровно так ищет ZOOM 1.4. */
    public static function driveFilename(Schedule $schedule): string
    {
        return Carbon::parse($schedule->start)->utc()->format('Y-m-d').'.jpg';
    }

    /**
     * Курс занятия: колонка schedules.course_id, иначе единственный курс группы.
     * Группа с несколькими курсами без course_id у занятия — курса нет, и тогда
     * сработает только шаблон, заведённый на саму группу.
     */
    private function courseIdFor(Schedule $schedule): ?int
    {
        if ($schedule->course_id !== null) {
            return (int) $schedule->course_id;
        }

        $group = $schedule->group;
        if (! $group instanceof Group) {
            return null;
        }

        $courseIds = $group->courses()->pluck('courses.id');

        return $courseIds->count() === 1 ? (int) $courseIds->first() : null;
    }

    private function markSkipped(LessonBanner $banner, string $status, ?int $templateId, ?int $number): string
    {
        $banner->fill([
            'template_id' => $templateId,
            'render_status' => $status,
            'lesson_number' => $number,
        ])->save();

        return $status;
    }
}
