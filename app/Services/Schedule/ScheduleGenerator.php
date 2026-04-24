<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Schedule;
use App\Services\Schedule\DTO\GeneratorConfig;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ScheduleGenerator
{
    private const SAFETY_LIMIT = 1500;

    public function __construct(
        private readonly TemplateRenderer $renderer,
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

        return DB::transaction(function () use ($config, $today, $cutoff) {
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
            $addSet  = array_flip($config->addDates);

            $created   = collect();
            $count     = $config->startNumber;          // для {N}
            $lessonIdx = $config->startLessonIndex;     // для {BLOCK}/{BN}
            $processed = 0;
            $iter      = 0;

            // 3. Главный цикл: создаём через withoutEvents, чтобы Observer
            //    не дёргал n8n на каждое из 60+ событий
            Schedule::withoutEvents(function () use (
                $config, &$calculationDate, &$count, &$lessonIdx,
                &$processed, &$iter, $skipSet, $addSet, $created
            ) {
                while ($processed < $config->totalLessons && $iter < self::SAFETY_LIMIT) {
                    $iter++;

                    $iso = $calculationDate->format('Y-m-d');
                    $isTargetDay = in_array($calculationDate->dayOfWeek, $config->weekdays, true);
                    $isAdd  = isset($addSet[$iso]);
                    $isSkip = isset($skipSet[$iso]);

                    if (($isTargetDay || $isAdd) && !$isSkip) {
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
                            'title'       => $rendered['title'] !== '' ? $rendered['title'] : "Занятие #{$count}",
                            'description' => $rendered['description'],
                            'link'        => $config->link,
                            'start'       => $start,
                            'end'         => $start->copy()->addMinutes($config->durationMinutes),
                            'color'       => '#3788d8',
                            'group_id'    => $config->groupId,
                            'course_id'   => $config->courseId,
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
}