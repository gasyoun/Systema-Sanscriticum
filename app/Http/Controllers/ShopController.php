<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\LessonAccessGrant;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Testimonial;
use App\Support\ProductLadderAnchors;
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

        // Общесайтовая полоса отзывов (H323, social proof): избранные отзывы
        // из библиотеки — независимо от привязки к курсам.
        $featuredTestimonials = Testimonial::query()
            ->featured()
            ->latest('id')
            ->limit(6)
            ->get();

        return view('shop.index', compact('courses', 'page', 'search', 'purchasedByCourse', 'deposit', 'featuredTestimonials'));
    }

    /**
     * МЕТОД 1b: «С чего начать» — вводная страница для новичка (H323):
     * лесенка продуктов (бесплатно → запись → живой курс), квиз подбора курса,
     * объяснение уровней. Все CTA ведут на живые маршруты магазина — курс под
     * рекомендацию подбирается по паттерну (config/onramp.php), не нашёлся →
     * фильтрованный каталог, битых ссылок не бывает.
     */
    public function start()
    {
        // Якоря лесенки (цены «от N ₽», бесплатная ступень) — общий хелпер
        // ProductLadderAnchors: те же числа видят конец статьи и «Материалы».
        [
            'freeUrl' => $freeUrl,
            'minRecordedPrice' => $minRecordedPrice,
            'minBlockPrice' => $minBlockPrice,
            'catalogUrl' => $catalogUrl,
            'freePreviewCourse' => $freePreviewCourse,
        ] = ProductLadderAnchors::resolve();

        $beginnerUrl = route('shop.index', ['level' => 'beginner']);
        $curatorUrl = config('onramp.curator_url');

        // Рекомендованные курсы под ветки квиза (первый видимый по паттерну).
        $recommended = [];
        foreach (config('onramp.recommendations', []) as $key => $pattern) {
            $recommended[$key] = Course::query()
                ->where('is_visible', true)
                ->where('title', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $pattern).'%')
                ->orderBy('id')
                ->first();
        }

        $courseUrl = fn (?Course $course, string $fallback): string => $course
            ? route('shop.course.show', $course->slug)
            : $fallback;

        // Данные квиза (порт мастер-квиза ORS-FAQ, переретаргетированный на
        // маршруты магазина). Рендерится Alpine-компонентом на странице.
        $quiz = [
            'first' => 'q1',
            'questions' => [
                'q1' => [
                    'prog' => 'Вопрос 1 из 2',
                    'text' => 'Вы уже учились у нас?',
                    'opts' => [
                        ['label' => 'Нет, я новичок', 'next' => 'q2'],
                        ['label' => 'Да, продолжаю обучение', 'next' => 'existing'],
                    ],
                ],
                'q2' => [
                    'prog' => 'Вопрос 2 из 2',
                    'text' => 'Что вас привлекает в санскрите?',
                    'opts' => [
                        ['label' => 'Хочу читать тексты и понимать язык', 'next' => 'grammar'],
                        ['label' => 'Йога, мантры, рецитация', 'next' => 'yoga'],
                        ['label' => 'Философия — Йога-сутры, Упанишады', 'next' => 'philo'],
                        ['label' => 'Хочу попробовать — пока не знаю', 'next' => 'try'],
                    ],
                ],
            ],
            'results' => [
                'grammar' => [
                    'title' => 'Грамматика санскрита',
                    'body' => 'Начните с грамматического курса с нуля: деванагари, чтение, базовые формы. После него открываются текстовые курсы — Бхагавадгита, Упанишады, синтаксис.',
                    'ctas' => [
                        ['label' => 'К курсу →', 'url' => $courseUrl($recommended['grammar'] ?? null, $beginnerUrl), 'primary' => true],
                        ['label' => 'Все курсы с нуля', 'url' => $beginnerUrl, 'primary' => false],
                    ],
                ],
                'yoga' => [
                    'title' => 'Йога и рецитация',
                    'body' => 'Рецитация и мантропение не требуют знания языка — начать можно сразу. Философию йоги (Йога-сутры) можно взять параллельно.',
                    'ctas' => [
                        ['label' => 'К курсу →', 'url' => $courseUrl($recommended['yoga'] ?? null, $catalogUrl), 'primary' => true],
                        ['label' => 'Смотреть каталог', 'url' => $catalogUrl, 'primary' => false],
                    ],
                ],
                'philo' => [
                    'title' => 'Философия и тексты',
                    'body' => 'Йога-сутры — базовый текст йогической философии на санскрите; знание языка не обязательно. Дальше — Бхагавадгита или Упанишады.',
                    'ctas' => [
                        ['label' => 'К курсу →', 'url' => $courseUrl($recommended['philo'] ?? null, $catalogUrl), 'primary' => true],
                        ['label' => 'Смотреть каталог', 'url' => $catalogUrl, 'primary' => false],
                    ],
                ],
                'try' => [
                    'title' => 'Сначала — попробуйте бесплатно',
                    'body' => 'Не нужно сразу решать: посмотрите бесплатный пример урока и пройдитесь по каталогу — у каждого курса есть страница с программой и отзывами.',
                    'ctas' => [
                        ['label' => 'Бесплатный пример урока →', 'url' => $freeUrl, 'primary' => true],
                        ['label' => 'Смотреть каталог', 'url' => $catalogUrl, 'primary' => false],
                    ],
                ],
                'existing' => [
                    'title' => 'Вы уже наш студент',
                    'body' => 'Выбирайте следующий курс в каталоге — купленные курсы и персональные скидки видны после входа. Куратор поможет подобрать курс под ваш прогресс.',
                    'ctas' => [
                        ['label' => 'Смотреть каталог →', 'url' => $catalogUrl, 'primary' => true],
                        ['label' => 'Написать куратору', 'url' => $curatorUrl, 'primary' => false],
                    ],
                ],
            ],
        ];

        $page = new LandingPage([
            'title' => 'С чего начать изучение санскрита',
            'description' => 'Уровни, форматы, бесплатный пробный урок и квиз подбора курса — короткий путь новичка.',
        ]);

        return view('shop.start', compact(
            'page', 'quiz', 'minBlockPrice', 'minRecordedPrice',
            'freePreviewCourse', 'freeUrl', 'catalogUrl', 'beginnerUrl'
        ));
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
            $alreadyHasTrial = LessonAccessGrant::query()
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
