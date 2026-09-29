<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseInterestRequest;
use App\Models\Group;
use App\Models\User;
use App\Services\CuratorNotifier;
use App\Services\Schedule\TextbookScale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    /**
     * Origin'ы, которым разрешено встраивать embed-форму в iframe — та же
     * локальная область, что у виджета расписания (PublicWidgetController).
     */
    private const FRAME_ANCESTORS = "frame-ancestors 'self' https://samskrtam.ru https://www.samskrtam.ru";

    public function show(Request $request, string $course = ''): View
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
            // H5233: префилл интента из query (?intent=join|transfer) — клик
            // по группе на /raspisanie/kochergina открывает форму с готовым
            // выбором; неизвестное значение молча падает в дефолт (join).
            'intentPrefill' => $this->queryIntent($request),
        ]);
    }

    /**
     * Минимальный iframe-вариант для встраивания на samskrtam.ru. Как у виджета
     * расписания (PublicWidgetController), `frame-ancestors` выставляется ТОЛЬКО
     * на этом ответе — глобального CSP/X-Frame-Options в проекте нет, поэтому
     * ничего site-wide не ослабляется.
     */
    public function embed(Request $request, string $course = ''): Response
    {
        $this->abortIfDisabled();

        [$courseModel, $courseTitle] = $this->resolveCourse($course);

        return response()
            ->view('course-interest.embed', [
                'course' => $courseModel,
                'courseTitle' => $courseTitle,
                'courseSlug' => $course,
                'counts' => $courseModel !== null
                    ? CourseInterestRequest::countsForCourse($courseModel->id)
                    : [],
                'intentLabels' => CourseInterestRequest::intentLabels(),
                'intentPrefill' => $this->queryIntent($request),
            ])
            ->header('Content-Security-Policy', self::FRAME_ANCESTORS);
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

        // H5233: intent=transfer — дописываем исходную группу в комментарий,
        // чтобы куратор видел её и в TG-уведомлении, и в админском списке
        // (схема не трогается; резолвим по активному членству в группе курса
        // того же семейства канвы, не равного курсу заявки).
        $comment = $validated['comment'] ?? null;
        if ($validated['intent'] === CourseInterestRequest::INTENT_TRANSFER) {
            $fromGroup = $this->studentKocherginaGroupName($request->user(), $courseModel);
            if ($fromGroup !== null) {
                $comment = trim('Переезд из группы «'.$fromGroup.'».'.($comment !== null && $comment !== '' ? ' '.$comment : ''));
            }
        }

        $interest = CourseInterestRequest::create([
            'course_id' => $courseModel?->id,
            'course_title' => $courseModel !== null ? '' : $courseTitle,
            'intent' => $validated['intent'],
            'name' => $validated['name'] ?? null,
            'email' => $validated['email'] ?? null,
            'telegram' => $validated['telegram'] ?? null,
            'comment' => $comment,
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

    /**
     * H5233: валидный интент из query string (?intent=...) или null.
     * Неизвестное значение — null (форма упадёт в дефолт join), не 4xx:
     * публичный GET, гадать вводу нечего валидировать.
     */
    private function queryIntent(Request $request): ?string
    {
        $intent = (string) $request->query('intent', '');

        return in_array($intent, CourseInterestRequest::INTENTS, true) ? $intent : null;
    }

    /**
     * H5233: группа курса семейства Кочергиной, где заявитель активен
     * (left_at IS NULL), не равная курсу заявки. null — гость или не сидит
     * ни в одной другой группе семейства. Пишется префиксом в comment.
     */
    private function studentKocherginaGroupName(?User $user, ?Course $targetCourse): ?string
    {
        if ($user === null || $targetCourse === null) {
            return null;
        }

        $group = Group::query()
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id)->whereNull('group_user.left_at'))
            ->whereHas('courses', fn ($q) => $q
                ->where('courses.id', '!=', $targetCourse->id)
                ->where('is_active', true)
                ->where('title', 'like', '%Кочерг%'))
            ->with('courses:id,title')
            ->first();
        if ($group === null) {
            return null;
        }

        // Группа может быть привязана к нескольким курсам; берём первый курс
        // семейства Кочергиной — его тайтл читаемее номера группы.
        $course = $group->courses->firstWhere(
            fn (Course $c): bool => $c->id !== $targetCourse->id
                && TextbookScale::courseFamilyPublic((string) $c->title) === 'kochergina',
        );

        return $course !== null ? (string) $group->name : null;
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
