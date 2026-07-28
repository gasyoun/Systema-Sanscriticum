<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Автооткрытие приёма домашних заданий (H1764, волна 1).
 *
 * Раньше открытие было ровно одним булевым флагом `lessons.homework_enabled`,
 * который преподаватель щёлкал руками. Здесь он ставится сам — на следующий
 * день после того, как у урока появилась запись.
 *
 * Владение выражено схемой, а не веткой в коде: автомат помечает открытые им
 * уроки `homework_auto_opened_at`, и закрывать (волна 2) вправе только их.
 * Урок, включённый преподавателем руками, остаётся с NULL и автомату не виден.
 */
class HomeworkAutoOpener
{
    public function __construct(
        private KocherginaExerciseSource $exercises,
        private HomeworkNotifier $notifier,
    ) {}

    /**
     * Момент открытия приёма по моменту появления записи (D3): +delay_hours,
     * затем ближайшее align_hour:00 по Москве В МОМЕНТ ИЛИ ПОСЛЕ.
     *
     * Чистая функция без обращений к БД — именно её покрывает табличный тест
     * краевых случаев. Расчёт ведётся в Europe/Moscow явно: таймзона проекта
     * прибита в config/app.php, но зависеть от таймзоны сервера тут нельзя.
     */
    public static function opensAtFor(CarbonInterface $recordingAt): CarbonInterface
    {
        $delayHours = (int) config('homework.auto_open.delay_hours', 12);
        $alignHour = (int) config('homework.auto_open.align_hour', 9);

        $ready = Carbon::parse($recordingAt)
            ->setTimezone('Europe/Moscow')
            ->addHours($delayHours);

        $opensAt = $ready->copy()->setTime($alignHour, 0, 0);

        // Запись, выложенная поздно вечером, проскакивает мимо утреннего окна
        // и открывается послезавтра. Это принятое следствие D3, не дефект:
        // открывать в 09:30 значило бы сломать ровный час рассылки.
        if ($opensAt->lessThan($ready)) {
            $opensAt->addDay();
        }

        return $opensAt;
    }

    /**
     * Уроки, которые пора открыть.
     *
     * @return Collection<int, Lesson>
     */
    public function due(): Collection
    {
        if (! config('homework.auto_open.enabled')) {
            return collect();
        }

        return Lesson::query()
            ->autoOpenCandidates()
            ->with('course')
            ->orderBy('homework_opens_at')
            ->get();
    }

    /**
     * Самый свежий прошедший урок каждого курса в охвате, у которого есть
     * запись и не включено ДЗ (D11). История дальше одного урока не трогается:
     * бэкфилл не воскрешает прошлое и не конфликтует с ритмом «одно ДЗ за раз».
     *
     * @return Collection<int, Lesson>
     */
    public function backfillCandidates(): Collection
    {
        if (! config('homework.auto_open.enabled')) {
            return collect();
        }

        $slugs = (array) config('homework.auto_open.course_slugs', []);
        $textbookLessons = (array) config('homework.auto_open.textbook_lessons', []);

        if ($slugs === [] || $textbookLessons === []) {
            return collect();
        }

        return Lesson::query()
            ->whereHas('course', fn ($c) => $c->whereIn('slug', $slugs))
            ->whereIn('textbook_lesson', $textbookLessons)
            ->where('homework_enabled', false)
            ->whereNull('homework_auto_opened_at')
            ->whereNotNull('recording_attached_at')
            ->with('course')
            ->get()
            ->groupBy('course_id')
            ->map(fn (Collection $lessons) => $lessons->sortByDesc(
                fn (Lesson $lesson) => $lesson->recording_attached_at
            )->first())
            ->values();
    }

    /**
     * Открыть приём по одному уроку. Возвращает false, если урок уже открыт
     * автоматом — повторный прогон в тот же час не откроет его дважды и не
     * пришлёт второй пуш (идемпотентность держится на `homework_auto_opened_at`).
     */
    public function open(Lesson $lesson, bool $notify = true): bool
    {
        if (filled($lesson->homework_auto_opened_at)) {
            return false;
        }

        DB::transaction(function () use ($lesson): void {
            // Условие, написанное преподавателем, автомат не трогает (A6).
            if (blank($lesson->homework_prompt)) {
                $lesson->homework_prompt = $this->promptFor($lesson);
            }

            $lesson->homework_enabled = true;
            $lesson->homework_auto_opened_at = now();
            $lesson->save();
        });

        // Пуш — ПОСЛЕ коммита транзакции, как в HomeworkService::recordReview:
        // синхронный HTTP внутри транзакции держать нельзя.
        if ($notify) {
            $this->notifier->opened($lesson);
        }

        return true;
    }

    /**
     * Условие задания (D5). Текст упражнений из оцифровки — но НЕ в уроки,
     * видимые гостю без оплаты: `is_free`/`is_preview` получают отсылочную
     * формулировку (D14). Урок при этом всё равно открывается — отсутствие
     * текста никогда не блокирует открытие.
     */
    private function promptFor(Lesson $lesson): string
    {
        $textbookLesson = $lesson->textbook_lesson === null ? null : (int) $lesson->textbook_lesson;
        $fallback = KocherginaExerciseSource::fallbackPrompt($textbookLesson);

        if ($lesson->is_free || $lesson->is_preview) {
            Log::warning('HomeworkAutoOpener: публичный урок — текст учебника не подставлен (D14)', [
                'lesson_id' => $lesson->id,
                'is_free' => (bool) $lesson->is_free,
                'is_preview' => (bool) $lesson->is_preview,
            ]);

            return $fallback;
        }

        if ($textbookLesson === null) {
            return $fallback;
        }

        $text = $this->exercises->forLesson($textbookLesson);

        return $text === null
            ? $fallback
            : "Выполните все упражнения к Занятию {$textbookLesson} учебника Кочергиной.\n\n".$text;
    }
}
