<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\LandingPage;
use App\Models\LessonAccessGrant;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\StorefrontAnalyticsEvent;
use App\Models\Teacher;
use App\Models\Testimonial;
use App\Models\WaitlistVote;
use App\Services\Activity\FunnelTelemetry;
use App\Services\Activity\StorefrontAnalytics;
use App\Services\Membership\PrivateArchiveEligibility;
use App\Support\CourseCadence;
use App\Support\FlagshipExperiments;
use App\Support\FlagshipLanding;
use App\Support\ProductLadderAnchors;
use App\Support\ShopCatalogUrl;
use App\Support\TrajectoryPathway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ShopController extends Controller
{
    /**
     * МЕТОД 1: Витрина со всеми курсами. Фильтры каталога живут в
     * /online/{facets} словами (kategoriya/format/uroven/prepodavatel/poisk),
     * без query string — см. App\Support\ShopCatalogUrl. Старые
     * ?cat[]=/?q=/?teacher=/?format=/?level= 301-редиректят на эквивалент.
     */
    public function index(Request $request, ?string $facets = null)
    {
        if ($request->hasAny(['cat', 'q', 'search', 'teacher', 'format', 'level'])) {
            return redirect()->to($this->legacyQueryToPrettyPath($request), 301);
        }

        $initial = [
            'initialCategoryIds' => [],
            'initialTeacherId' => '',
            'initialFormat' => '',
            'initialLevel' => '',
            'initialSearch' => '',
        ];
        $categorySlugs = [];
        $indexable = true;

        if ($facets !== null) {
            $parsed = ShopCatalogUrl::parse($facets);
            abort_if($parsed === null, 404);

            if (isset($parsed['kategoriya'])) {
                $categorySlugs = array_values(array_unique(explode(',', $parsed['kategoriya'])));
                $categories = Category::whereIn('slug', $categorySlugs)->get(['id']);
                abort_if($categories->count() !== count($categorySlugs), 404);
                $initial['initialCategoryIds'] = $categories->pluck('id')->all();
            }

            if (isset($parsed['format'])) {
                abort_unless(in_array($parsed['format'], ['live', 'recorded'], true), 404);
                $initial['initialFormat'] = $parsed['format'];
            }

            if (isset($parsed['uroven'])) {
                abort_unless(array_key_exists($parsed['uroven'], Course::LEVELS), 404);
                $initial['initialLevel'] = $parsed['uroven'];
            }

            if (isset($parsed['prepodavatel'])) {
                // Толерантный резолв (MG 02-09-2026): «Екатерина-Костина» и
                // «Костина-Екатерина-Александровна» ведут на одного преподавателя.
                $teacher = Teacher::resolveByName(ShopCatalogUrl::decodeWords($parsed['prepodavatel']));
                abort_if($teacher === null, 404);
                $initial['initialTeacherId'] = (string) $teacher->id;
            }

            if (isset($parsed['poisk'])) {
                $initial['initialSearch'] = ShopCatalogUrl::decodeWords($parsed['poisk']);
            }

            // Индексируем только «пусто» и «одна категория» — остальные комбинации
            // canonical-складываются вниз, иначе комбинаторика facet'ов даёт Google
            // бесконечный набор тонких дублей (тот же риск, что и у ?cat[]=, только хуже).
            $indexable = array_keys($parsed) === ['kategoriya'] && count($categorySlugs) === 1;
        }

        $canonicalPath = ShopCatalogUrl::build($indexable ? $categorySlugs : array_slice($categorySlugs, 0, 1));

        $deposit = MarketingSetting::cached();

        // Общесайтовая полоса отзывов (H323, social proof): избранные отзывы
        // из библиотеки — независимо от привязки к курсам.
        $featuredTestimonials = Testimonial::query()
            ->featured()
            ->latest('id')
            ->limit(6)
            ->get();

        // Якоря лесенки цен (H1293) — те же честные «от N ₽», что видят
        // «С чего начать», конец статьи и хаб «Материалы».
        $ladder = ProductLadderAnchors::resolve();

        return view('shop.index', array_merge($initial, compact(
            'deposit', 'featuredTestimonials', 'ladder', 'canonicalPath', 'indexable'
        )));
    }

    /** Старые query-параметры каталога -> эквивалентный словесный путь (301). */
    private function legacyQueryToPrettyPath(Request $request): string
    {
        $categoryIds = array_map('intval', (array) $request->input('cat', []));
        $categorySlugs = $categoryIds === []
            ? []
            : Category::whereIn('id', $categoryIds)->pluck('slug')->all();

        $teacherName = null;
        if ($request->filled('teacher')) {
            $teacherName = optional(Teacher::find($request->input('teacher')))->name;
        }

        return ShopCatalogUrl::build(
            categorySlugs: $categorySlugs,
            format: (string) $request->input('format', ''),
            level: (string) $request->input('level', ''),
            teacherName: $teacherName,
            search: (string) ($request->input('q') ?? $request->input('search') ?? ''),
        );
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
            $recommended[$key] = PrivateArchiveEligibility::scopePublic(Course::query())
                ->withOwnCatalogCard()
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

    /**
     * H2764 / R18 — catalogue pathway: three tracks through the shop,
     * not a second warehouse listing and not a why-us clone.
     */
    public function pathway()
    {
        $tracks = TrajectoryPathway::resolve();

        $page = new LandingPage([
            'title' => 'Путь через курсы санскрита',
            'description' => 'Три направления каталога — письмо и чтение, грамматика, тексты. Ближайший старт и цена, как в магазине.',
        ]);

        return view('shop.put', compact('page', 'tracks'));
    }

    /**
     * H3834 — рубрика «Список ожидания» на витрине (/online/zhdun): голосуй за
     * будущую группу — наберётся кворум голосов, откроется оплата; нужное
     * число оплат к сроку — группа стартует. Данные — те же строки
     * `course_waitlist_items` (is_listed), что в кабинете (H3815) и фиде
     * /api/public/waitlist (H3811). Флаг waitlist_voting OFF — 404, страница
     * не живёт раньше механизма голосования.
     */
    public function waitlist()
    {
        abort_unless((bool) config('features.waitlist_voting', false), 404);

        $items = CourseWaitlistItem::query()
            ->where('is_listed', true)
            ->whereNotIn('status', [
                CourseWaitlistItem::STATUS_CLOSED,
                CourseWaitlistItem::STATUS_SCHEDULED,
            ])
            ->withCount('votes')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // «Я уже голосовал» — для отметки на кнопках (как в кабинете H3815).
        $votedSlugs = Auth::check()
            ? WaitlistVote::query()
                ->whereIn('course_waitlist_item_id', $items->modelKeys())
                ->where('user_id', Auth::id())
                ->pluck('course_waitlist_item_id')
                ->all()
            : [];

        // Ссылки на преподавателей (MG 02-09-2026): естественный порядок имени —
        // «Екатерина Костина», как в waitlist-строке. Фильтр каталога резолвит
        // его толерантно (Teacher::resolveByName), полное ФИО тоже работает.
        $itemTeacherUrls = [];
        foreach ($items as $item) {
            $itemTeacherUrls[$item->getKey()] = $item->teacher_name
                ? '/online/prepodavatel/'.ShopCatalogUrl::encodeWords($item->teacher_name)
                : null;
        }

        $page = new LandingPage([
            'title' => 'Список ожидания — набор в новые группы',
            'description' => 'Голосуйте за будущие курсы: наберётся минимум голосов — откроется оплата; нужное число оплат к сроку — группа стартует.',
        ]);

        // Сезонные секции (MG 01-09-2026): ОСЕНЬ 2026 / НАЧАЛО 2027 / …;
        // строки без даты — «дата уточняется» в конце. Пустые секции не рисуем.
        $sections = $items
            ->groupBy(fn (CourseWaitlistItem $item) => implode('|', $item->seasonSortKey()))
            ->sortKeys()
            ->map(fn ($group) => [
                'label' => $group->first()->seasonLabel(),
                'undated' => $group->first()->seasonSection()[0] === null,
                'items' => $group->values(),
            ])
            ->values();

        return view('shop.zhdun', [
            'page' => $page,
            'sections' => $sections,
            'items' => $items,
            'votedItemIds' => $votedSlugs,
            'itemTeacherUrls' => $itemTeacherUrls,
        ]);
    }

    // МЕТОД 2: Страница одного конкретного курса
    public function show(Course $course, Request $request, FunnelTelemetry $funnel)
    {
        if ((bool) config('features.membership_private_archives', false)
            && in_array($course->slug, PrivateArchiveEligibility::privateOfferSlugs(), true)) {
            abort(404, 'Курс не найден');
        }

        if (! $course->is_visible) {
            abort(404, 'Курс не найден');
        }

        if (Auth::check()) {
            $funnel->emitCoursePageView(Auth::user(), (int) $course->id, $request);
        }

        $course->load([
            'tariffs' => function ($query) {
                $query->where('is_active', true)->orderBy('price', 'asc');
            },
            'tariffs.block',
            'blocks',
            'teacher', // подгружаем преподавателя одним запросом
            'teachers', // со-преподаватели (блок «Преподаватель(и)» на лендинге)
            'categories', // H2379: cover fallback colour + continuity with card
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

        // Ритм курса из календаря: день/время, ближайшее занятие, сколько
        // осталось. Раньше шапка показывала только ручное «Идет сейчас», и
        // покупатель не видел ни дня, ни того, что поток на 14-м из 16.
        $cadence = CourseCadence::for($course);

        // H3100: сколько прошедших занятий уже лежит записями. Отдельным
        // агрегатом, а не по $course->lessons — та коллекция грузится урезанным
        // select без ссылок на видео, и hasVideo() по ней всегда дал бы false.
        $course->loadCount([
            'lessons as recorded_lessons_count' => fn ($q) => $q->where('is_published', true)->withRecording(),
        ]);

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

        // Кнопка «Купить пробное»: задана цена и выбрано событие расписания
        // (предстоящее — живьём, ИЛИ прошедшее — его запись), курс ещё не куплен,
        // и (для залогиненного) нет активного гранта на урок-заготовку.
        $course->loadMissing('trialSchedule');
        $trialSession = $course->trialSchedule;
        $showTrialCta = (float) $course->trial_price > 0
            && $trialSession
            && $trialSession->start
            && empty($purchasedKeys);

        // Прошедшее занятие → пробное открывает запись (иначе — живое по Zoom).
        $trialIsRecording = (bool) ($trialSession && $trialSession->start && $trialSession->start->isPast());

        if ($showTrialCta && Auth::check() && $course->trial_lesson_id) {
            $alreadyHasTrial = LessonAccessGrant::query()
                ->where('user_id', Auth::id())
                ->where('lesson_id', $course->trial_lesson_id)
                ->active()
                ->exists();
            $showTrialCta = ! $alreadyHasTrial;
        }

        $flagship = FlagshipLanding::for($course);
        $ctaAb = FlagshipExperiments::ctaFor($course, request());

        // H3807 «одна карточка на программу» (рулинг MG 31-08-2026). Запись
        // прошедшего потока остаётся живой покупаемой страницей — у неё свои
        // оплаты и на неё ведёт реклама, — но канон у программы один: живой
        // курс. Иначе поисковик считает две страницы одной программы двумя
        // товарами и они конкурируют между собой в выдаче.
        $canonicalUrl = route('shop.course.show', $course->catalogCardCourse()->slug);

        // Обратная сторона: живой курс называет свои записи вариантом покупки,
        // чтобы «запись» не пропала из виду вместе со второй карточкой.
        $recordingOffers = $course->recordings()
            ->where('is_visible', true)
            ->orderBy('id')
            ->get(['id', 'title', 'slug']);

        return view('shop.show', compact('course', 'page', 'purchasedKeys', 'currentBlock', 'currentBlockNumber', 'deposit', 'showTrialCta', 'trialIsRecording', 'scheduleGroups', 'cadence', 'lessonsByBlock', 'flagship', 'ctaAb', 'canonicalUrl', 'recordingOffers'));
    }

    /**
     * МЕТОД 3: Публичный «Пример урока» (бесплатный preview).
     *
     * Единственная точка правды доступа к preview: отдаём РОВНО preview-урок
     * этого курса (lessons.is_preview = true, опубликованный). URL не содержит
     * lesson-id, поэтому гость физически не может запросить произвольный урок —
     * ни соседний платный, ни урок другого курса. Нет preview-урока → 404.
     */
    public function preview(Course $course, Request $request, StorefrontAnalytics $storefront)
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

        if (FlagshipExperiments::ctaAbEnabled() && FlagshipExperiments::isFlagship($course)) {
            $storefront->record(
                event: StorefrontAnalyticsEvent::SAMPLE_PLAY,
                experiment: StorefrontAnalyticsEvent::EXPERIMENT_CTA_AB,
                request: $request,
                course: $course,
                variant: FlagshipExperiments::ctaVariantFromRequest($request),
            );
        }

        return view('shop.preview', compact('course', 'lesson'));
    }
}
