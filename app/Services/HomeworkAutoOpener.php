<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Services\Access\TelegramAdminNotifier;
use App\Support\HomeworkAutoOpenScope;
use App\Support\HomeworkReviewPolicy;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Автооткрытие приёма домашних заданий (H1764 + generic/Hindi track).
 *
 * Раньше открытие было ровно одним булевым флагом `lessons.homework_enabled`,
 * который преподаватель щёлкал руками. Здесь он ставится сам.
 *
 * Два трека:
 *  - Kochergina (H1764): hourly `homework:auto-open`, +delay → 09:00 MSK,
 *    текст из оцифровки учебника, охват `course_slugs` + `textbook_lessons`.
 *  - Generic (Hindi pilot): сразу после первого `recording_attached_at` на
 *    `Lesson::saved`, prompt из `generic_prompt`, охват `generic_course_slugs`.
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
     * Курс в пилоте generic-трека (хинди и т.п.): фиксированный prompt, без
     * textbook_lesson. Пустой список slug — трек спит.
     */
    public function isGenericPilot(Lesson $lesson): bool
    {
        if (! config('homework.auto_open.enabled')) {
            return false;
        }

        $slugs = (array) config('homework.auto_open.generic_course_slugs', []);
        if ($slugs === []) {
            return false;
        }

        $lesson->loadMissing('course');
        $slug = $lesson->course?->slug;

        return is_string($slug) && $slug !== '' && in_array($slug, $slugs, true);
    }

    /**
     * Немедленное открытие после первой записи (generic track).
     * Возвращает false, если урок не в охвате, уже открыт руками/автоматом
     * или kill-switch выключен — без побочных эффектов.
     */
    public function tryOpenImmediate(Lesson $lesson): bool
    {
        if (! config('homework.auto_open.generic_immediate', true)) {
            return false;
        }

        if (! $this->isGenericPilot($lesson)) {
            return false;
        }

        if (filled($lesson->homework_auto_opened_at)) {
            return false;
        }

        // Ручное включение — не перехватываем владение и не шлём второй «открыли».
        if ($lesson->homework_enabled) {
            return false;
        }

        if (blank($lesson->recording_attached_at)) {
            return false;
        }

        // Якорь в чате группы (звено C) шлёт сам open() при notify: true —
        // с 18-08-2026 по обоим трекам, не только generic (H3068).
        return $this->open($lesson, notify: true);
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

        $textbookLessons = (array) config('homework.auto_open.textbook_lessons', []);
        $courseIds = HomeworkAutoOpenScope::courseIdsInScope();

        if ($courseIds === [] || $textbookLessons === []) {
            return collect();
        }

        $allowMissing = (bool) config('homework.auto_open.open_without_textbook_lesson', true);

        // Бэкфилл эпохой НЕ режется: он и существует затем, чтобы догнать один
        // прошедший урок на курс осознанным ручным вызовом (D11). Эпоха
        // защищает автоматический hourly-проход, а не явную команду человека.
        return Lesson::query()
            ->whereIn('course_id', $courseIds)
            ->where(fn ($q) => $q
                ->whereIn('textbook_lesson', $textbookLessons)
                ->when($allowMissing, fn ($qq) => $qq->orWhereNull('textbook_lesson')))
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

        // Потолок Кочергиной (H3078): «обязательная сдача заканчивается до 6
        // урока, 6 уже не сдают». Проверяется ЗДЕСЬ, а не только в выборке:
        // если два урока курса подошли в один прогон, первый добирает потолок,
        // и второй обязан упереться — предварительный фильтр этого не видит.
        if (HomeworkAutoOpenScope::kocherginaCapReached($lesson)) {
            Log::info('HomeworkAutoOpener: потолок обязательных ДЗ курса достигнут — урок не открыт', [
                'lesson_id' => $lesson->id,
                'course_id' => $lesson->course_id,
            ]);

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

            // Якорь в чате группы (звено C для reply #ДЗ) — и, что важнее для
            // студента, ссылка на урок ТАМ, ГДЕ ЗАДАЮТ ВОПРОС. До 18-08-2026
            // пост уходил только по generic-треку, поэтому группы Кочергиной
            // спрашивали куратора «куда прикреплять ДЗ» вручную (H3068).
            $this->postGroupInvite($lesson);
        }

        $this->warnIfMappingMissing($lesson);
        $this->announceUnreviewedOpen($lesson);

        return true;
    }

    /**
     * Открытие на курсе, где работы никто не разбирает (H3087, рулинг MG
     * 18-08-2026: «не надо там ничего открывать молча»).
     *
     * Студентам пуш уходит как обычно — молчание было не в их сторону, а в
     * человеческую: автомат включал приём на «Продлёнке» и «Напевном
     * санскрите» и рассылал десяткам студентов «открылось домашнее задание»,
     * причём разбирать эти работы никто не собирался, и ни один человек об
     * этом не узнавал. Теперь узнаёт.
     *
     * Шлётся и при бэкфилле (`notify: false`): тот молчит в сторону студентов,
     * но человеку знать надо тем более.
     */
    private function announceUnreviewedOpen(Lesson $lesson): void
    {
        if (! HomeworkReviewPolicy::isUnreviewedLesson($lesson)) {
            return;
        }

        Log::info('HomeworkAutoOpener: открыт приём на курсе без проверки', [
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
        ]);

        if (! config('homework.reviewers.unreviewed_open_alert', true)) {
            return;
        }

        try {
            $lesson->loadMissing('course');
            $url = $lesson->course
                ? route('student.lesson', [$lesson->course->slug, $lesson->id])
                : '';
            $recipients = $this->notifier->studentsFor($lesson)->count();

            app(TelegramAdminNotifier::class)->notifyAdmins(
                '📥 Открыл приём ДЗ на курсе БЕЗ проверки: <b>'
                .e((string) ($lesson->course?->title ?: $lesson->course?->slug ?: '—')).'</b>'."\n"
                .'Урок: '.e((string) $lesson->title)."\n"
                .'Студентам ушло уведомление ('.$recipients.' чел.), но разбирать работы никто не будет — '
                .'курс в списке «приём без проверки».'
                .($url !== '' ? "\n".$url : ''),
            );
        } catch (\Throwable $e) {
            Log::warning('HomeworkAutoOpener: unreviewed-open alert failed', [
                'lesson_id' => $lesson->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Пост «Приём ДЗ открыт» в чат(ы) группы. Ошибку глотаем: недоступный
     * Telegram не должен откатывать уже совершённое открытие (для добора есть
     * `php artisan homework:repost-open-invite`).
     */
    private function postGroupInvite(Lesson $lesson): void
    {
        try {
            app(HomeworkTelegramTagService::class)->postOpenInvite($lesson);
        } catch (\Throwable $e) {
            Log::warning('HomeworkAutoOpener: postOpenInvite failed', [
                'lesson_id' => $lesson->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Урок открылся без `textbook_lesson` — приём работает, но условие
     * отсылочное вместо текста упражнений. Это уже не тихий отказ (урок
     * открыт), а деградация качества, поэтому напоминание, а не блокировка.
     */
    private function warnIfMappingMissing(Lesson $lesson): void
    {
        if (filled($lesson->textbook_lesson) || $this->isGenericPilot($lesson)) {
            return;
        }

        Log::warning('HomeworkAutoOpener: урок открыт без textbook_lesson — условие отсылочное', [
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
        ]);

        if (! config('homework.auto_open.missing_mapping_alert', true)) {
            return;
        }

        try {
            $lesson->loadMissing('course');
            $url = $lesson->course
                ? route('student.lesson', [$lesson->course->slug, $lesson->id])
                : '';

            app(TelegramAdminNotifier::class)->notifyAdmins(
                '📝 Приём ДЗ открыт БЕЗ <code>textbook_lesson</code>: <b>'
                .e((string) $lesson->title).'</b>'."\n"
                .'Условие отсылочное («все упражнения к этому занятию»). '
                .'Проставьте номер занятия учебника в админке урока, чтобы подставился текст.'
                .($url !== '' ? "\n".$url : ''),
            );
        } catch (\Throwable $e) {
            Log::warning('HomeworkAutoOpener: missing-mapping alert failed', [
                'lesson_id' => $lesson->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Условие задания. Generic-пилот — фиксированная строка из конфига.
     * Kochergina — текст упражнений из оцифровки, но НЕ в уроки, видимые
     * гостю без оплаты (D14). Урок при этом всё равно открывается.
     */
    private function promptFor(Lesson $lesson): string
    {
        if ($this->isGenericPilot($lesson)) {
            return (string) config('homework.auto_open.generic_prompt', 'Домашнее задание');
        }

        // H3078: с инверсией охвата в трек попали курсы, не имеющие к учебнику
        // Кочергиной никакого отношения («Продлёнка санскрита», «Напевный
        // санскрит», «Чтение Айтареи»). Подставлять им «упражнения к занятию
        // учебника Кочергиной» было бы прямой ложью в тексте задания.
        $lesson->loadMissing('course');

        if (! HomeworkAutoOpenScope::isKochergina($lesson->course?->slug)) {
            return (string) config('homework.auto_open.generic_prompt', 'Домашнее задание');
        }

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
