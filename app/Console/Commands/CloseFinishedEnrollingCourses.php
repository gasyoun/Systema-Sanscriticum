<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Support\CourseCadence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Закрывает донабор сам (MG 18-10-2026: «сам без меня сразу переведи»).
 *
 * Пока идут занятия, enrolling-курс продаётся по блокам и носит бейдж
 * «Продолжается донабор» (см. Course::recruitmentLabel()). Когда последнее
 * занятие потока прошло, донабор кончился: купить такой курс можно только
 * в записи — значит формат обязан стать recorded, иначе витрина вернёт бейдж
 * «Идет набор» и продолжит продавать блоки курса, на который уже не попасть.
 *
 * Единственный источник правды о конце потока — расписание: курс без календаря
 * не трогаем (решать нечего), курс с непройденными занятиями не трогаем.
 */
class CloseFinishedEnrollingCourses extends Command
{
    protected $signature = 'courses:close-finished-enrolling
                            {--course= : Только этот курс (id)}
                            {--dry-run : Показать, что было бы закрыто, ничего не меняя}';

    protected $description = 'Переводит enrolling-курсы с прошедшим последним занятием в «В записи»';

    /**
     * Минимум занятий в самом длинном потоке, чтобы считать календарь настоящим
     * курсом. У enrolling-курсов в проде лежат одиночные строки расписания
     * (одноразовые события в группах-наборах): у «Грамматика санскрита с нуля —
     * набор 2026» две записи 2026 года, обе в прошлом, — без порога автозакрытие
     * увело бы живой набор в «В записи». Короткий настоящий курс (1–3 занятия)
     * закрывается вручную.
     */
    private const MIN_SESSIONS = 4;

    public function handle(): int
    {
        $query = Course::query()->where('format', 'enrolling');

        if ($courseId = $this->option('course')) {
            $query->whereKey((int) $courseId);
        }

        $courses = $query->get(['id', 'title', 'format']);

        if ($courses->isEmpty()) {
            $this->info('Нет enrolling-курсов — закрывать нечего.');

            return self::SUCCESS;
        }

        $cadenceByCourse = CourseCadence::forMany($courses);
        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;

        foreach ($courses as $course) {
            $cadence = $cadenceByCourse[$course->id] ?? null;

            if (! $cadence || ! $cadence->hasCalendar() || ! $cadence->isFinished()) {
                continue;
            }

            if ($cadence->total() < self::MIN_SESSIONS) {
                Log::info('courses:close-finished-enrolling — пропуск: в потоке меньше занятий, чем порог', [
                    'course_id' => $course->id,
                    'title' => $course->title,
                    'sessions' => $cadence->total(),
                    'min_sessions' => self::MIN_SESSIONS,
                ]);

                continue;
            }

            $last = $cadence->last();
            $closed++;

            if ($dryRun) {
                $this->line("[dry-run] #{$course->id} «{$course->title}» — последнее занятие "
                    .($last?->start?->format('d.m.Y H:i') ?? '—'));

                continue;
            }

            $course->format = 'recorded';
            $course->save();

            Log::info('courses:close-finished-enrolling — курс переведён в записи', [
                'course_id' => $course->id,
                'title' => $course->title,
                'last_lesson' => $last?->start?->toDateTimeString(),
            ]);

            $this->info("#{$course->id} «{$course->title}» → В записи (последнее занятие "
                .($last?->start?->format('d.m.Y H:i') ?? '—').')');
        }

        $this->info($dryRun
            ? "К закрытию: {$closed}."
            : "Переведено в «В записи»: {$closed}.");

        return self::SUCCESS;
    }
}
