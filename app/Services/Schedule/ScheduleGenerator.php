<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Schedule\DTO\GeneratorConfig;
use App\Services\Zoom\ZoomService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ScheduleGenerator
{
    private const SAFETY_LIMIT = 1500;

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly ZoomService $zoom,
    ) {}

    /**
     * Генерирует расписание по конфигу и возвращает коллекцию созданных моделей.
     *
     * @return Collection<int, Schedule>
     */
    public function generate(GeneratorConfig $config): Collection
    {
        $today = Carbon::today();
        $cutoff = $config->startDate->isFuture()
            ? $config->startDate->copy()
            : $today->copy();

        $course = $config->courseId ? Course::find($config->courseId) : null;

        // GC-B1 rescope (H1642): «первая генерация потока» — единственный трек,
        // за которым закреплено авто-создание. Если явной ссылки в форме нет,
        // курс есть и ещё БЕЗ своей recurring-встречи — создаём ОДНУ на курс
        // (никогда — на Schedule). Идемпотентно: наличие zoom_meeting_id — маркер
        // «встреча уже есть», второй раз не создаём. Флаг ВЫКЛ ⇒ этот блок
        // no-op, поведение байт-в-байт как до H1642.
        if (
            $config->link === null
            && $course !== null
            && ! $course->zoom_meeting_id
            && config('features.zoom_auto_create', false)
        ) {
            $this->autoCreateCourseMeeting($course);
        }

        // Ссылка занятия: явная из модалки, иначе единая Zoom-ссылка курса.
        $link = $config->link ?: $course?->zoom_link;

        return DB::transaction(function () use ($config, $today, $cutoff, $link) {
            // 1. preserve: режем только будущие; иначе — всё по группе
            if ($config->preserve) {
                Schedule::where('group_id', $config->groupId)
                    ->where('start', '>=', $cutoff)
                    ->delete();
            } else {
                Schedule::where('group_id', $config->groupId)->delete();
            }

            // 2. Стартовая дата для итерации (не уходим в прошлое при preserve)
            $calculationDate = $config->startDate->copy();
            if ($config->preserve && $calculationDate->lt($today)) {
                $calculationDate = $today->copy();
            }

            $skipSet = array_flip($config->skipDates);
            $addSet = array_flip($config->addDates);

            $created = collect();
            $count = $config->startNumber;          // для {N}
            $lessonIdx = $config->startLessonIndex;     // для {BLOCK}/{BN}
            $processed = 0;
            $iter = 0;

            // 3. Главный цикл: создаём через withoutEvents, чтобы Observer
            //    не дёргал n8n на каждое из 60+ событий
            Schedule::withoutEvents(function () use (
                $config, $link, &$calculationDate, &$count, &$lessonIdx,
                &$processed, &$iter, $skipSet, $addSet, $created
            ) {
                while ($processed < $config->totalLessons && $iter < self::SAFETY_LIMIT) {
                    $iter++;

                    $iso = $calculationDate->format('Y-m-d');
                    $isTargetDay = in_array($calculationDate->dayOfWeek, $config->weekdays, true);
                    $isAdd = isset($addSet[$iso]);
                    $isSkip = isset($skipSet[$iso]);

                    if (($isTargetDay || $isAdd) && ! $isSkip) {
                        $start = $calculationDate->copy()
                            ->setTimeFromTimeString($config->startTime);

                        $rendered = $this->renderer->render(
                            $config->template,
                            $count,
                            $lessonIdx,
                            $calculationDate,
                            $config->title,
                        );

                        $schedule = Schedule::create([
                            'title' => $rendered['title'] !== '' ? $rendered['title'] : "Занятие #{$count}",
                            'description' => $rendered['description'],
                            'link' => $link,
                            'start' => $start,
                            'end' => $start->copy()->addMinutes($config->durationMinutes),
                            'color' => '#3788d8',
                            'group_id' => $config->groupId,
                            'course_id' => $config->courseId,
                        ]);

                        $created->push($schedule);
                        $count++;
                        $lessonIdx++;
                        $processed++;
                    }

                    $calculationDate->addDay();
                }
            });

            return $created;
        });
    }

    /**
     * GC-B1 rescope (H1642): создаёт ОДНУ recurring Zoom-встречу на курс и
     * сохраняет через `Course::setZoomLinkAttribute()` — meeting_id из
     * `join_url` выводится тем же мутатором, что и при ручном вводе в
     * Filament, парсер не дублируется. Исключение Zoom API намеренно
     * пробрасывается наружу (как и все остальные методы ZoomService) —
     * `ListSchedules::runGeneration()` уже ловит `\Throwable` и показывает
     * админу danger-уведомление вместо тихого проглатывания.
     */
    private function autoCreateCourseMeeting(Course $course): void
    {
        $meeting = $this->zoom->createMeeting(['topic' => $course->title]);

        $course->zoom_link = $meeting['join_url'];
        $course->save();
    }
}
