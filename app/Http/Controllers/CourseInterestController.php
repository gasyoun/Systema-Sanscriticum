<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseInterestRequest;
use App\Services\CuratorNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Публичная форма интереса на курс (H5066): join — в следующий набор,
 * recording — купить запись, revive — возобновить занятия, если соберётся
 * группа. Под флагом features.course_interest_form (default OFF → 404).
 *
 * Анти-спам как в формах анкеты/рассылки: honeypot + time-trap
 * (config/newsletter.php antibot) + rate-limit (1 заявка / 5 сек / IP).
 * Никаких платежей и письма-автоответа: запись в БД + TG-уведомление
 * кураторам (CuratorNotifier, no-op если чат не настроен).
 *
 * {course} — slug курса (канон или alias, см. Course::resolveBySlug). Для ещё
 * не заведённых курсов-анонсов (напр. «Космография») slug существует только в
 * URL: заявка сохраняется как course_title-only строка с course_id = null.
 */
class CourseInterestController extends Controller
{
    public function show(string $course = ''): View
    {
        $this->abortIfDisabled();

        [$courseModel, $courseTitle] = $this->resolveCourse($course);

        return view('course-interest.show', [
            'course' => $courseModel,
            'courseTitle' => $courseTitle,
            'courseSlug' => $course,
            'counts' => $courseModel !== null
                ? CourseInterestRequest::countsForCourse($courseModel->id)
                : [],
            'intentLabels' => CourseInterestRequest::intentLabels(),
        ]);
    }

    /** Минимальный iframe-вариант для встраивания на samskrtam.ru. */
    public function embed(string $course = ''): View
    {
        $this->abortIfDisabled();

        [$courseModel, $courseTitle] = $this->resolveCourse($course);

        return view('course-interest.embed', [
            'course' => $courseModel,
            'courseTitle' => $courseTitle,
            'courseSlug' => $course,
            'counts' => $courseModel !== null
                ? CourseInterestRequest::countsForCourse($courseModel->id)
                : [],
            'intentLabels' => CourseInterestRequest::intentLabels(),
        ]);
    }

    public function store(Request $request, string $course = ''): RedirectResponse
    {
        $this->abortIfDisabled();

        // Rate-limit как у lead-формы и рассылки: 1 заявка / 5 сек / IP.
        $rlKey = 'course-interest:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 1)) {
            abort(429, 'Слишком частые запросы. Подождите несколько секунд.');
        }
        RateLimiter::hit($rlKey, 5);

        $validated = $request->validate([
            'intent' => ['required', 'in:'.implode(',', CourseInterestRequest::INTENTS)],
            // Хоть один контакт обязателен: email или Telegram.
            'email' => ['nullable', 'email', 'max:255', 'required_without:telegram'],
            'telegram' => ['nullable', 'string', 'max:255', 'required_without:email'],
            'name' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // Анти-бот: honeypot-поле должно остаться пустым + time-trap от
        // мгновенных сабмитов. При срабатывании возвращаем ТОТ ЖЕ «успешный»
        // ответ (анти-enumeration), но ничего не создаём и куратору не шлём.
        if (filled($request->input('website')) || ! $this->passedTimeTrap($request->input('ff_ts'))) {
            return $this->successRedirect($course);
        }

        [$courseModel, $courseTitle] = $this->resolveCourse($course);

        $interest = CourseInterestRequest::create([
            'course_id' => $courseModel?->id,
            'course_title' => $courseModel !== null ? '' : $courseTitle,
            'intent' => $validated['intent'],
            'name' => $validated['name'] ?? null,
            'email' => $validated['email'] ?? null,
            'telegram' => $validated['telegram'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'status' => CourseInterestRequest::STATUS_NEW,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Уведомление кураторам в общий TG-чат (no-op, если чат не настроен).
        app(CuratorNotifier::class)->courseInterestReceived($interest);

        return $this->successRedirect($course);
    }

    private function abortIfDisabled(): void
    {
        abort_unless((bool) config('features.course_interest_form'), 404);
    }

    private function successRedirect(string $course): RedirectResponse
    {
        return redirect()
            ->route('course-interest.show', ['course' => $course])
            ->with('course_interest_status', 'Заявка принята! Куратор увидит её и напишет вам.');
    }

    /**
     * Курс по slug из URL + человекочитаемый тайтл. Slug не существует в базе
     * (анонс ещё не заведён) → [null, тайтл из slug]; пустой slug → [null, ''].
     *
     * @return array{0: ?Course, 1: string}
     */
    private function resolveCourse(string $course): array
    {
        if ($course === '') {
            return [null, ''];
        }

        $model = Course::resolveBySlug($course);
        if ($model !== null) {
            return [$model, (string) $model->title];
        }

        // Анонс без карточки курса: kosmografiya → «Kosmografiya».
        return [null, Str::headline($course)];
    }

    /**
     * Time-trap: метка времени рендера формы (см. NewsletterSubscribeController).
     * Отклоняет отсутствующую/битую метку, мгновенный сабмит и протухшую форму.
     */
    private function passedTimeTrap(mixed $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $renderedAt = (int) decrypt($token);
        } catch (\Throwable) {
            return false;
        }

        $elapsed = now()->timestamp - $renderedAt;

        return $elapsed >= (int) config('newsletter.antibot.min_fill_seconds')
            && $elapsed <= (int) config('newsletter.antibot.max_form_age_seconds');
    }
}
