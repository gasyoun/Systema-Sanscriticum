<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\MarketingSetting;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    // МЕТОД 1: Витрина со всеми курсами
    public function index(Request $request)
    {
        $search = $request->input('search');

        $courses = Course::where('is_visible', true)
            ->when($search, fn ($q, $s) => $q->where('title', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%'))
            ->with(['tariffs' => function ($query) {
                $query->where('is_active', true)->orderBy('price', 'asc');
            }])
            ->paginate(9)
            ->withQueryString();

        // Предзагружаем купленные ключи по всем курсам на странице — одним запросом
        $purchasedByCourse = [];
        if (Auth::check()) {
            $courseIds = $courses->pluck('id')->all();

            $purchasedByCourse = Payment::query()
                ->real() // conditional-доступ «под обещание» — не покупка, блок должен остаться оплачиваемым
                ->where('user_id', Auth::id())
                ->whereIn('course_id', $courseIds)
                ->paid()
                ->get(['course_id', 'tariff'])
                ->groupBy('course_id')
                ->map(fn ($rows) => $rows->pluck('tariff')->filter()->unique()->values()->all())
                ->all();
        }

        $page = new LandingPage([
            'title' => 'Общество ревнителей санскрита',
            'description' => 'Выберите курс и начните обучение',
        ]);

        $deposit = MarketingSetting::cached();

        return view('shop.index', compact('courses', 'page', 'search', 'purchasedByCourse', 'deposit'));
    }

    // МЕТОД 2: Страница одного конкретного курса
    public function show(Course $course)
    {
        if (! $course->is_visible) {
            abort(404, 'Курс не найден');
        }

        $course->load([
            'tariffs' => function ($query) {
                $query->where('is_active', true)->orderBy('price', 'asc');
            },
            'tariffs.block',
            'blocks',
            'teacher', // подгружаем преподавателя одним запросом
            'teachers', // со-преподаватели (блок «Преподаватель(и)» на лендинге)
            'faqs', // блок «FAQ по курсу»
            'testimonials', // блок «Отзывы» (в порядке пивота)
            'previewLesson', // блок «Пример урока» + вторая CTA в hero
            // Для блока «Программа курса»: только опубликованные уроки, по порядку.
            'lessons' => fn ($query) => $query
                ->where('is_published', true)
                ->select(['id', 'course_id', 'title', 'block_number', 'sort_order'])
                ->orderBy('block_number')
                ->orderBy('sort_order')
                ->orderBy('id'),
        ]);

        // Блок «Программа курса»: уроки, сгруппированные по номеру блока (для
        // аккордеона). Пусто — секция не выводится.
        $lessonsByBlock = $course->lessons->groupBy('block_number');

        // Блок «Расписание»: ближайшие занятия курса, сгруппированные по месяцу
        // («Июнь 2026» → [занятия]). Пустая группировка — секция просто не выводится.
        $scheduleGroups = $course->upcomingSchedules()
            ->groupBy(fn ($s) => $s->start->translatedFormat('F Y'));

        $currentBlock = $course->currentBlock();
        $currentBlockNumber = $currentBlock?->number;

        // Собираем массив купленных тарифов ОДНИМ запросом (без N+1)
        $purchasedKeys = [];
        if (Auth::check()) {
            $purchasedKeys = Payment::query()
                ->real() // conditional-доступ «под обещание» — не покупка, блок должен остаться оплачиваемым
                ->where('user_id', Auth::id())
                ->where('course_id', $course->id)
                ->paid()
                ->pluck('tariff')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $page = new LandingPage(['title' => $course->title]);

        $deposit = MarketingSetting::cached();

        // Кнопка «Купить пробное»: задана цена и предстоящее живое занятие, курс ещё
        // не куплен, и (для залогиненного) нет активного гранта на урок-заготовку.
        $course->loadMissing('trialSchedule');
        $trialSession = $course->trialSchedule;
        $showTrialCta = (float) $course->trial_price > 0
            && $trialSession
            && $trialSession->start
            && $trialSession->start->isFuture()
            && empty($purchasedKeys);

        if ($showTrialCta && Auth::check() && $course->trial_lesson_id) {
            $alreadyHasTrial = \App\Models\LessonAccessGrant::query()
                ->where('user_id', Auth::id())
                ->where('lesson_id', $course->trial_lesson_id)
                ->active()
                ->exists();
            $showTrialCta = ! $alreadyHasTrial;
        }

        return view('shop.show', compact('course', 'page', 'purchasedKeys', 'currentBlock', 'currentBlockNumber', 'deposit', 'showTrialCta', 'scheduleGroups', 'lessonsByBlock'));
    }

    /**
     * МЕТОД 3: Публичный «Пример урока» (бесплатный preview).
     *
     * Единственная точка правды доступа к preview: отдаём РОВНО preview-урок
     * этого курса (lessons.is_preview = true, опубликованный). URL не содержит
     * lesson-id, поэтому гость физически не может запросить произвольный урок —
     * ни соседний платный, ни урок другого курса. Нет preview-урока → 404.
     */
    public function preview(Course $course)
    {
        if (! $course->is_visible) {
            abort(404, 'Курс не найден');
        }

        // Жёсткий гейт: урок принадлежит ЭТОМУ курсу И is_preview И опубликован.
        $lesson = $course->lessons()
            ->preview()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        abort_if($lesson === null, 404, 'У этого курса нет пробного урока');

        return view('shop.preview', compact('course', 'lesson'));
    }
}
