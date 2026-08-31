@extends('layouts.shop')
@section('title', $course->meta_title ?: $course->title)

@php
    // Ритм курса, выведенный из `schedules` (App\Support\CourseCadence).
    // Ручной бейдж формата отвечает «идёт / в записи», но не «когда» и не
    // «сколько осталось» — эти три строки закрывают именно это.
    $cadenceSlot = $cadence?->slotLabel();
    $cadenceNext = $cadence?->nextLabel();
    $cadenceProgress = $cadence?->progressLabel();
    // H3115: у курса может быть несколько потоков одной программы. Общий остаток
    // тогда молчит (он описывал бы студента, которого нет), а прогресс называется
    // по каждому потоку отдельно.
    $cadenceStreams = $cadence?->streamLines() ?? [];
    // Ручные часы приоритетны; пусто — считаем астрономические по календарю.
    $heroHours = $course->hours_count ?: $cadence?->hours();
@endphp

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.shopReachGoal === 'function') {
        window.shopReachGoal('course_page_view');
    }
});
</script>
@endpush

@push('head')
    <meta name="description" content="{{ $course->meta_description ?: \Illuminate\Support\Str::limit(trim(strip_tags($course->description)), 160) }}">

    {{-- H3807: канон программы — живой курс. Для записи прошедшего потока это
         ЧУЖОЙ адрес: страница остаётся покупаемой, но в выдаче программу
         представляет одна карточка, а не две конкурирующие. --}}
    <link rel="canonical" href="{{ $canonicalUrl ?? route('shop.course.show', $course->slug) }}">

    {{-- ═══════════════ SEO: Course + Offer (schema.org / JSON-LD) ═══════════════
         Помогает Яндексу и Google показать курс с ценой в выдаче. Цены берём из
         публичных (list) цен активных тарифов — без учёта персональных скидок. --}}
    @php
        $courseUrl = route('shop.course.show', $course->slug);
        $courseDescription = $course->meta_description
            ?: \Illuminate\Support\Str::limit(trim(strip_tags((string) $course->description)), 300);

        // Активные тарифы = покупаемые варианты участия. Каждый → отдельный Offer.
        $offerTariffs = $course->tariffs->where('is_active', true);
        if ($offerTariffs->isEmpty()) {
            $offerTariffs = $course->tariffs;
        }
        $offers = $offerTariffs
            ->filter(fn ($t) => (float) $t->price > 0)
            ->map(fn ($t) => [
                '@type' => 'Offer',
                'name' => $t->title,
                'price' => number_format((float) $t->price, 2, '.', ''),
                'priceCurrency' => 'RUB',
                'availability' => 'https://schema.org/InStock',
                'url' => $courseUrl.'#tariffs',
                'category' => 'Paid',
            ])
            ->values()
            ->all();

        // hasCourseInstance → включает курс в «Course Info» Google (карусель с ценой + режимом).
        // Обязательные для CourseInstance: courseMode + (courseWorkload ИЛИ courseSchedule).
        // courseWorkload берём из hours_count (ISO-8601 «PTnH»); если часов нет — instance
        // не добавляем (базовый Course + offers остаётся валидным сам по себе).
        $courseWorkload = $heroHours ? 'PT'.((int) $heroHours).'H' : null;
        $ciStart = $course->blocks->filter(fn ($b) => $b->starts_at)->min('starts_at');
        $ciEnd = $course->blocks->filter(fn ($b) => $b->ends_at)->max('ends_at');
        $courseInstance = $courseWorkload
            ? array_filter([
                '@type' => 'CourseInstance',
                'courseMode' => 'online', // платформа полностью онлайн (формат live | recorded)
                'courseWorkload' => $courseWorkload,
                'location' => [
                    '@type' => 'VirtualLocation',
                    'url' => $courseUrl,
                ],
                'startDate' => $ciStart ? $ciStart->toDateString() : null,
                'endDate' => $ciEnd ? $ciEnd->toDateString() : null,
                'instructor' => $course->teacher
                    ? ['@type' => 'Person', 'name' => $course->teacher->name]
                    : null,
            ], fn ($v) => $v !== null)
            : null;

        $schemaTeaches = collect($course->outcomes ?? [])->filter(fn ($x) => filled($x))->values();
        if ($schemaTeaches->isEmpty() && ! empty(($flagship ?? [])['outcomes'])) {
            $schemaTeaches = collect($flagship['outcomes']);
        }

        $courseSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Course',
            'name' => $course->title,
            'description' => $courseDescription,
            'url' => $courseUrl,
            'inLanguage' => 'ru-RU',
            'image' => $course->image_path ? \Illuminate\Support\Facades\Storage::url($course->image_path) : null,
            'provider' => [
                '@type' => 'Organization',
                '@id' => 'https://samskrte.ru/#org',
                'name' => 'Общество ревнителей санскрита',
            ],
            'teaches' => $schemaTeaches->isNotEmpty() ? $schemaTeaches->all() : null,
            'hasCourseInstance' => $courseInstance,
            'offers' => !empty($offers) ? $offers : null,
        ], fn ($v) => $v !== null);
    @endphp
    <script type="application/ld+json">
{!! json_encode($courseSchema) !!}
    </script>

    @include('partials.schema-breadcrumbs', ['crumbs' => [
        ['name' => 'Главная', 'url' => url('/')],
        ['name' => 'Курсы', 'url' => route('shop.index')],
        ['name' => $course->title],
    ]])
@endpush

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white font-sans relative overflow-hidden">
    
    {{-- Декоративные блюры на фоне --}}
    <div class="absolute top-[-10%] left-[-10%] w-[800px] h-[800px] bg-indigo-900/10 rounded-full blur-[150px] pointer-events-none"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[600px] h-[600px] bg-brand/10 rounded-full blur-[150px] pointer-events-none"></div>

    {{-- ═════════════════ HERO ═════════════════ --}}
    <div class="relative pt-24 pb-16 lg:pt-32 lg:pb-24 border-b border-[#1F2636]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex flex-col lg:flex-row gap-12 lg:gap-20 items-center">
                
                <div class="w-full lg:w-1/2">
                    {{-- H2379: format vocabulary matches catalogue card (Идет сейчас / В записи) --}}
                    <div class="flex flex-wrap items-center gap-2 mb-6">
                        @if($course->isLive())
                            <span class="inline-flex items-center gap-1.5 bg-rose-500 text-white text-[11px] font-black uppercase px-3 py-1.5 rounded-full tracking-wider shadow-[0_4px_12px_rgba(244,63,94,0.35)]">
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                Идет сейчас
                            </span>
                        @elseif($course->format === 'recorded')
                            <span class="inline-flex items-center gap-1.5 bg-indigo-500/90 text-white text-[11px] font-black uppercase px-3 py-1.5 rounded-full tracking-wider">
                                <i class="fas fa-play-circle text-[10px]"></i>
                                В записи
                            </span>
                        @else
                            <span class="bg-brand/20 text-brand text-xs font-black uppercase px-3 py-1.5 rounded-full tracking-widest border border-brand/30">
                                Онлайн-программа
                            </span>
                        @endif

                        @if($course->levelLabel())
                            <span class="inline-flex items-center gap-1.5 bg-emerald-500/90 text-white text-[11px] font-black uppercase px-3 py-1.5 rounded-full tracking-wider">
                                @if($course->level === 'beginner')<i class="fas fa-seedling text-[10px]"></i>@endif
                                {{ $course->levelLabel() }}
                            </span>
                        @endif

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm font-bold text-slate-400 sm:ml-1">
                            @if($course->lessons_count)
                                <span class="flex items-center"><i class="fas fa-play-circle mr-2 text-indigo-400"></i> {{ $course->lessons_count }} {{ \App\Support\Plural::ru((int) $course->lessons_count, 'онлайн-занятие', 'онлайн-занятия', 'онлайн-занятий') }}</span>
                            @endif
                            @if($heroHours)
                                <span class="flex items-center"><i class="far fa-clock mr-2 text-indigo-400"></i> {{ $heroHours }} ч</span>
                            @endif
                        </div>
                    </div>

                    <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-8 leading-tight">
                        {{ $course->title }}
                    </h1>

                    {{-- Когда именно «идет сейчас»: день, время, ближайшее занятие,
                         сколько осталось. Всё выведено из `schedules` — бейдж
                         формата ручной и об этом ничего не знает. --}}
                    @if($cadenceSlot || $cadenceNext || $cadenceProgress || $cadenceStreams)
                        <p class="-mt-4 mb-8 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-slate-300"
                           data-testid="course-hero-cadence">
                            @if($cadenceSlot)
                                <span class="inline-flex items-center gap-2 bg-[#111622] border border-[#1F2636] rounded-lg px-3 py-1.5 font-bold">
                                    <i class="far fa-calendar-alt text-[#38BDF8]"></i>{{ $cadenceSlot }}
                                </span>
                            @endif
                            @if($cadenceNext)
                                <span class="text-slate-400">Ближайшее занятие — <span class="text-white font-semibold">{{ $cadenceNext }}</span></span>
                            @endif
                            @if($cadenceProgress)
                                <span class="inline-flex items-center gap-2 text-amber-400 font-bold">
                                    <i class="fas fa-hourglass-half text-[11px]"></i>{{ $cadenceProgress }}
                                </span>
                            @endif
                            @foreach($cadenceStreams as $streamLine)
                                <span class="inline-flex items-center gap-2 text-amber-400 font-bold" data-testid="course-stream-line">
                                    <i class="fas fa-hourglass-half text-[11px]"></i>{{ $streamLine }}
                                </span>
                            @endforeach
                            @if($cadenceSlot || $cadenceNext)
                                <a href="#schedule" class="text-[#38BDF8] hover:text-white underline-offset-2 hover:underline">все даты</a>
                            @endif
                        </p>
                    @endif
                    
                    {{-- One primary CTA (tariff) + optional secondary (sample) --}}
                    <div class="flex flex-wrap gap-3">
                        <a href="#tariffs" class="inline-flex justify-center items-center px-8 py-4 text-sm md:text-base font-bold rounded-xl text-white bg-brand hover:bg-brand-hover transition-all hover:-translate-y-1 shadow-[0_0_20px_rgba(232,92,36,0.3)]">
                            Выбрать тариф
                        </a>
                        @if($course->previewLesson)
                            <a href="#sample"
                               class="inline-flex justify-center items-center gap-2 px-8 py-4 text-sm md:text-base font-bold rounded-xl text-white bg-[#38BDF8]/15 border border-[#38BDF8]/30 hover:bg-[#38BDF8]/25 transition-all"
                               @if(! empty($ctaAb))
                                   data-cta-ab="{{ $ctaAb['variant'] }}"
                                   data-analytics="flagship-cta"
                               @endif
                               onclick="if (typeof window.shopReachGoal === 'function') window.shopReachGoal('sample_play');">
                                <i class="fas fa-play text-xs"></i> {{ $ctaAb['label'] ?? 'Смотреть пробный урок' }}
                            </a>
                        @else
                            <a href="{{ route('shop.index', $course->isLive() ? ['format' => 'live'] : ($course->format === 'recorded' ? ['format' => 'recorded'] : [])) }}"
                               class="inline-flex justify-center items-center px-8 py-4 text-sm md:text-base font-bold rounded-xl text-white bg-[#1F2636] hover:bg-[#2A344A] transition-all">
                                @if($course->format === 'recorded')
                                    Библиотека записей
                                @elseif($course->isLive())
                                    Другие живые курсы
                                @else
                                    Все курсы
                                @endif
                            </a>
                        @endif
                    </div>

                    {{-- Кликабельный бейдж преподавателя — visual continuity with card teacher line --}}
                    @if($course->teacher)
                        <a href="{{ route('shop.index', ['teacher' => $course->teacher->id]) }}"
                           class="group/teacher mt-6 inline-flex items-center gap-3 px-4 py-3 rounded-xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 hover:bg-[#1A2235] transition-all duration-300 max-w-fit">
                            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-brand to-brand-hover flex items-center justify-center shrink-0 shadow-md shadow-brand/20 overflow-hidden">
                                @if(! empty($course->teacher->photo_path))
                                    <img src="{{ Storage::url($course->teacher->photo_path) }}"
                                         alt="{{ $course->teacher->name }}"
                                         class="w-full h-full object-cover">
                                @else
                                    <i class="fas fa-user text-white text-sm"></i>
                                @endif
                            </div>
                            <div class="flex flex-col leading-tight">
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500 group-hover/teacher:text-brand transition-colors">
                                    Преподаватель
                                </span>
                                <span class="text-sm font-bold text-white">
                                    {{ $course->teacher->name }}
                                </span>
                            </div>
                            <i class="fas fa-arrow-right text-xs text-slate-500 group-hover/teacher:text-brand group-hover/teacher:translate-x-1 transition-all ml-2"></i>
                        </a>
                    @endif

                </div>

                <div class="w-full lg:w-1/2">
                    {{-- Cover: real photo OR typographic fallback matching catalogue card --}}
                    <div class="relative w-full aspect-video md:aspect-[4/3] rounded-3xl overflow-hidden bg-gradient-to-br from-[#111622] to-[#0A0D14] border border-[#1F2636] shadow-2xl shadow-indigo-900/20 flex items-center justify-center group">
                        @if($course->image_path)
                            <img src="{{ Storage::url($course->image_path) }}" alt="{{ $course->title }}" class="absolute inset-0 w-full h-full object-cover opacity-60 mix-blend-luminosity group-hover:mix-blend-normal group-hover:opacity-100 transition-all duration-700">
                            <div class="absolute inset-0 bg-gradient-to-t from-[#0A0D14]/80 via-transparent to-transparent"></div>
                        @else
                            @php $heroCoverColor = $course->categories->first()?->color ?: '#E85C24'; @endphp
                            <div class="absolute inset-0 p-8 flex flex-col group-hover:scale-[1.02] transition-transform duration-500"
                                 @style(['background-image: linear-gradient(135deg, ' . $heroCoverColor . 'E6 0%, #0A0D14 92%)'])>
                                <i class="fas fa-om absolute -right-6 -bottom-8 text-[10rem] text-white/5 pointer-events-none"></i>
                                <span class="relative text-[11px] font-black uppercase tracking-widest text-white/70 line-clamp-1 mb-4">
                                    {{ $course->teacher?->name ?? 'Онлайн-программа' }}
                                </span>
                                <div class="relative flex-grow flex items-center">
                                    <span class="text-2xl md:text-3xl font-extrabold text-white leading-tight line-clamp-4">{{ $course->title }}</span>
                                </div>
                            </div>
                        @endif
                        @if($course->isLive())
                            <div class="absolute top-5 right-5 z-10">
                                <span class="inline-flex items-center gap-1.5 bg-rose-500 text-white text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md tracking-wider">
                                    <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                    Идет сейчас
                                </span>
                            </div>
                        @elseif($course->format === 'recorded')
                            <div class="absolute top-5 right-5 z-10">
                                <span class="inline-flex items-center gap-1.5 bg-indigo-500/90 text-white text-[10px] font-black uppercase px-2.5 py-1.5 rounded-md tracking-wider">
                                    <i class="fas fa-play-circle text-[9px]"></i>
                                    В записи
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═════════════════ ОСНОВНОЙ КОНТЕНТ (одна широкая колонка) ═════════════════ --}}
    <div class="py-16 lg:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">

        {{-- ───── Микродоверие / Для кого / Чему научитесь (продающие блоки) ───── --}}
        @include('shop.partials.trust-strip')
        @include('shop.partials.audience')
        @include('shop.partials.outcomes')
        @include('shop.partials.flagship-free-step')

        {{-- ───── 1. О КУРСЕ (парная раскладка: текст + панель фактов) ───── --}}
        @php
            // Даты проведения курса — диапазон по блокам (если у них проставлены сроки).
            $datedBlocks = $course->blocks->filter(fn ($b) => $b->starts_at);
            $courseStart = $datedBlocks->min('starts_at');
            $courseEnd = $course->blocks->filter(fn ($b) => $b->ends_at)->max('ends_at');

            // Строки панели «Коротко о курсе» — собираем только заполненные.
            $facts = collect([
                $course->teacher
                    ? ['icon' => 'fas fa-user', 'label' => 'Преподаватель', 'value' => $course->teacher->name]
                    : null,
                $course->formatLabel()
                    ? ['icon' => 'fas fa-broadcast-tower', 'label' => 'Формат', 'value' => $course->formatLabel()]
                    : null,
                $course->lessons_count
                    ? ['icon' => 'fas fa-play-circle', 'label' => 'Занятий', 'value' => $course->lessons_count.' '.\App\Support\Plural::ru((int) $course->lessons_count, 'онлайн-занятие', 'онлайн-занятия', 'онлайн-занятий')]
                    : null,
                $heroHours
                    ? ['icon' => 'far fa-clock', 'label' => 'Длительность', 'value' => $heroHours.' '.\App\Support\Plural::ru((int) $heroHours, 'час', 'часа', 'часов')]
                    : null,
                $cadenceSlot
                    ? ['icon' => 'fas fa-calendar-day', 'label' => 'День и время', 'value' => $cadenceSlot.' МСК']
                    : null,
                $courseStart
                    ? ['icon' => 'far fa-calendar-alt', 'label' => 'Даты проведения', 'value' => $courseStart->translatedFormat('F Y').($courseEnd && $courseEnd->format('Y-m') !== $courseStart->format('Y-m') ? ' – '.$courseEnd->translatedFormat('F Y') : '')]
                    : null,
                $cadenceNext
                    ? ['icon' => 'fas fa-hourglass-half', 'label' => 'Ближайшее занятие', 'value' => $cadenceNext.($cadenceProgress ? ', '.$cadenceProgress : '')]
                    : null,
            ])->filter()->values();
        @endphp
        <section class="mb-16 lg:mb-20">
            <h2 class="text-3xl font-bold text-white mb-8">О курсе</h2>

            <div class="flex flex-col lg:flex-row gap-8 lg:gap-12 items-start">
                {{-- Левая колонка: текстовое описание --}}
                <div class="prose prose-invert prose-lg prose-slate max-w-none lg:flex-1">
                    @if($course->description)
                        <div class="text-slate-300 leading-relaxed space-y-6 [&_a]:text-indigo-400 [&_a:hover]:text-indigo-300 [&_a]:underline">
                            {!! $course->description_html !!}
                        </div>
                    @else
                        <p class="text-slate-500 italic">Подробное описание курса скоро появится.</p>
                    @endif
                </div>

                {{-- Правая колонка: структурированная панель фактов --}}
                @if($facts->isNotEmpty())
                    <aside class="w-full lg:w-80 shrink-0 lg:sticky lg:top-28">
                        <div class="rounded-2xl bg-[#111622] border border-[#1F2636] p-6">
                            <h3 class="text-xs font-black uppercase tracking-widest text-slate-500 mb-5">Коротко о курсе</h3>
                            <dl class="space-y-4">
                                @foreach($facts as $fact)
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-[#1F2636] flex items-center justify-center shrink-0">
                                            <i class="{{ $fact['icon'] }} text-indigo-400 text-xs"></i>
                                        </div>
                                        <div class="flex flex-col min-w-0">
                                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $fact['label'] }}</dt>
                                            <dd class="text-sm font-bold text-white">{{ $fact['value'] }}</dd>
                                        </div>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </aside>
                @endif
            </div>
        </section>

        @include('partials.samskrtam-related', ['samskrtamKey' => $course->slug])

        {{-- ───── 1.2 ПРОГРАММА КУРСА (аккордеон по блокам) ───── --}}
        @php
            // Русская плюрализация «занятий».
            $pluralLessons = function (int $n): string {
                $mod10 = $n % 10;
                $mod100 = $n % 100;
                if ($mod10 === 1 && $mod100 !== 11) {
                    return 'занятие';
                }
                if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
                    return 'занятия';
                }

                return 'занятий';
            };

            // Секция нужна только если у блоков есть содержимое (название, даты или уроки),
            // иначе это дублировало бы список тарифов «БЛОК N».
            $hasProgram = $course->blocks->contains(fn ($b) => filled($b->title)
                || $b->starts_at
                || ($lessonsByBlock[$b->number] ?? collect())->isNotEmpty());
        @endphp
        @if($hasProgram)
        <section id="program" class="mb-16 lg:mb-20" x-data="{ open: null }">
            <h2 class="text-3xl font-bold text-white mb-8">Программа курса</h2>

            <div class="space-y-3">
                @foreach($course->blocks as $block)
                    @php $blockLessons = $lessonsByBlock[$block->number] ?? collect(); @endphp
                    <div class="rounded-2xl bg-[#111622] border border-[#1F2636] overflow-hidden">
                        <button type="button"
                                @click="open === {{ $block->number }} ? open = null : open = {{ $block->number }}"
                                class="w-full flex items-center gap-4 p-5 text-left hover:bg-[#1A2235] transition-colors">
                            <span class="flex items-center justify-center shrink-0 w-10 h-10 rounded-xl bg-gradient-to-br from-[#1F2636] to-[#0A0D14] border border-[#1F2636] text-base font-extrabold text-white">
                                {{ $block->number }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-base font-bold text-white">{{ $block->title ?: 'Блок '.$block->number }}</span>
                                    @if($block->is_current)
                                        <span class="inline-flex items-center gap-1 bg-brand text-white text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full">
                                            <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> Сейчас идет
                                        </span>
                                    @endif
                                </div>
                                <div class="text-xs font-bold text-slate-500 mt-1">
                                    @if($block->starts_at)
                                        {{ $block->starts_at->translatedFormat('F Y') }}@if($block->ends_at && $block->ends_at->format('Y-m') !== $block->starts_at->format('Y-m')) – {{ $block->ends_at->translatedFormat('F Y') }}@endif
                                        @if($blockLessons->isNotEmpty()) · @endif
                                    @endif
                                    @if($blockLessons->isNotEmpty()){{ $blockLessons->count() }} {{ $pluralLessons($blockLessons->count()) }}@endif
                                </div>
                            </div>
                            @if($blockLessons->isNotEmpty())
                                <i class="fas fa-chevron-down text-slate-500 text-sm transition-transform"
                                   :class="open === {{ $block->number }} ? 'rotate-180' : ''"></i>
                            @endif
                        </button>

                        @if($blockLessons->isNotEmpty())
                            <div x-show="open === {{ $block->number }}" x-transition style="display:none"
                                 class="border-t border-[#1F2636]">
                                <ol class="px-5 py-4 space-y-2">
                                    @foreach($blockLessons as $i => $lesson)
                                        <li class="flex gap-3 text-sm text-slate-300">
                                            <span class="text-slate-600 font-bold shrink-0">{{ $i + 1 }}.</span>
                                            <span>{{ $lesson->title }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        {{-- ───── Пример урока + Преподаватель(и) ───── --}}
        @include('shop.partials.sample')
        @include('shop.partials.teachers')

        {{-- ───── 1.5 РАСПИСАНИЕ ───── --}}
        @if(!empty($scheduleGroups) && $scheduleGroups->isNotEmpty())
        <section id="schedule" class="mb-16 lg:mb-20">
            <div class="flex items-center gap-4 mb-8">
                <h2 class="text-3xl font-bold text-white">{{ ! empty($flagship) ? 'Ближайшие занятия' : 'Расписание' }}</h2>
                @unless(! empty($flagship))
                    <span class="text-sm font-bold text-slate-500">ближайшие занятия</span>
                @endunless
            </div>

            <div class="space-y-10">
                @foreach($scheduleGroups as $month => $sessions)
                    <div>
                        {{-- Пилюля месяца --}}
                        <div class="mb-5">
                            <span class="inline-block bg-[#111622] text-brand text-xs font-black uppercase tracking-widest px-4 py-2 rounded-full border border-brand/30">
                                {{ $month }}
                            </span>
                        </div>

                        {{-- Карусель: горизонтальная прокрутка со snap; H2379 mobile scroll affordance --}}
                        <div class="relative">
                        <div class="flex gap-4 overflow-x-auto snap-x snap-mandatory pb-4 -mx-1 px-1 [scrollbar-color:#1F2636_transparent] [scrollbar-width:thin] scroll-smooth">
                            @foreach($sessions as $session)
                                <div class="relative snap-start shrink-0 w-[340px] max-w-[85vw] flex items-center gap-5 p-5 rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 hover:bg-[#1A2235] transition-all duration-300">
                                    {{-- Дата-бейдж: число + месяц --}}
                                    <div class="flex flex-col items-center justify-center shrink-0 w-16 h-16 rounded-xl bg-gradient-to-br from-[#1F2636] to-[#0A0D14] border border-[#1F2636]">
                                        <span class="text-2xl font-extrabold text-white leading-none">{{ $session->start->translatedFormat('j') }}</span>
                                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1">{{ $session->start->translatedFormat('M') }}</span>
                                    </div>

                                    {{-- Описание сеанса --}}
                                    <div class="flex flex-col min-w-0 flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                                {{ $session->start->translatedFormat('l') }}
                                            </span>
                                            @if($session->isLive())
                                                <span class="inline-flex items-center gap-1 bg-brand text-white text-[10px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> Идет сейчас
                                                </span>
                                            @endif
                                        </div>
                                        <span class="text-base font-bold text-white truncate">
                                            {{ $session->title ?: $course->title }}
                                        </span>
                                        <span class="text-sm font-bold text-indigo-400 mt-1">
                                            <i class="far fa-clock mr-1"></i>{{ $session->start->format('H:i') }}@if($session->end)–{{ $session->end->format('H:i') }}@endif
                                        </span>
                                    </div>

                                    {{-- CTA: билет на сеанс --}}
                                    @php
                                        // Пробное — единственный «билет на один сеанс» в этой модели:
                                        // покупается отдельно через trial.create. Для записавшихся со
                                        // ссылкой — прямой вход; для остальных — переход к тарифам.
                                        $isTrialSession = $course->trial_schedule_id
                                            && (int) $course->trial_schedule_id === (int) $session->id;
                                        $enrolled = ! empty($purchasedKeys);
                                    @endphp
                                    @if($isTrialSession && ! empty($showTrialCta))
                                        <button type="button"
                                                onclick="window.dispatchEvent(new CustomEvent('open-trial-modal', { detail: { action: @js(route('trial.create', $course->slug)), title: @js($course->title), amount: {{ (float) $course->trial_price }}, isRecording: {{ ! empty($trialIsRecording) ? 'true' : 'false' }}, date: @js($session->start->translatedFormat('d F, H:i')) } }))"
                                                class="shrink-0 inline-flex items-center gap-1.5 py-2.5 px-4 rounded-xl bg-[#38BDF8] hover:bg-[#2da4dd] text-white text-xs font-bold transition-all whitespace-nowrap">
                                            <i class="fas fa-graduation-cap text-[11px]"></i>
                                            Купить пробное
                                        </button>
                                    @elseif($enrolled && $session->link)
                                        <a href="{{ $session->link }}" target="_blank" rel="noopener"
                                           class="shrink-0 inline-flex items-center gap-1.5 py-2.5 px-4 rounded-xl bg-brand hover:bg-brand-hover text-white text-xs font-bold transition-all whitespace-nowrap">
                                            <i class="fas fa-video text-[11px]"></i>
                                            Подключиться
                                        </a>
                                    @else
                                        <a href="#tariffs"
                                           class="shrink-0 inline-flex items-center gap-1.5 py-2.5 px-4 rounded-xl bg-[#1F2636] hover:bg-[#2A344A] text-white text-xs font-bold transition-all whitespace-nowrap border border-[#2A344A]">
                                            Записаться
                                            <i class="fas fa-arrow-right text-[10px]"></i>
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-[#0A0D14] via-[#0A0D14]/70 to-transparent sm:hidden"
                             aria-hidden="true"></div>
                        </div>
                        <p class="text-[11px] text-slate-500 sm:hidden -mt-2 mb-2" aria-hidden="true">Листайте расписание →</p>
                    </div>
                @endforeach
            </div>

            {{-- H1291: возражение «время/расписание» — снято у самого расписания, а не в FAQ --}}
            <p class="mt-6 text-sm text-slate-500 max-w-3xl" data-analytics="objection-time-microcopy">
                Не попадаете по времени? Занятие останется в записи — вернетесь к нему, когда будет тишина. Пропуск не выбивает из курса.
            </p>
        </section>
        @endif

        {{-- ───── 2. ТАРИФЫ ───── --}}
        @php
            $hasCurrentBlock = !empty($currentBlockNumber);
            $defaultTab = $hasCurrentBlock ? 'blocks' : 'full';

            // H266 (M1): «режим записи» — завершённый курс с активным тарифом-записью
            // при включённом флаге. Меняет ТОЛЬКО текст CTA (доступ/цена/чекаут те же).
            $sellsRecordings = $course->sellsRecordings();

            // H3100: покупателю, пришедшему в середине потока, надо сказать, что он
            // получит за уже прошедшие блоки. Раньше «Полный курс 22 000 ₽» и «БЛОК 1»
            // стояли рядом с «СЕЙЧАС ИДЕТ БЛОК 4» без единого слова про записи.
            // Всё считаем по факту: `full` открывает уроки ЛЮБОГО блока
            // (Lesson::unlockingKeys), а запись у урока есть, когда есть ссылка.
            $courseUnderway = $cadence?->isUnderway() ?? false;
            $recordedLessons = (int) ($course->recorded_lessons_count ?? 0);

            // Блок считаем прошедшим по его же `ends_at`; без даты — не считаем
            // (лучше промолчать, чем назвать прошедшим идущий блок).
            $finishedBlockNumbers = $course->blocks
                ->filter(fn ($b) => $b->ends_at && $b->ends_at->isPast())
                ->pluck('number')
                ->map(fn ($n) => (int) $n)
                ->all();
        @endphp
        <section id="tariffs" class="mb-16 lg:mb-20"
                 x-data="{ tab: '{{ $defaultTab }}' }"
                 x-init="if({{ $course->tariffs->where('type', '!=', 'block')->count() }} === 0) tab = 'blocks'">

            <h2 class="text-3xl font-bold text-white mb-8">Выберите вариант участия</h2>

            {{-- ───── H3807: запись прошедшего потока как вариант покупки ─────
                 У программы одна карточка (рулинг MG 31-08-2026), поэтому
                 запись больше не стоит в каталоге отдельным товаром. Но она
                 продаётся и покупается — молчать о ней значит спрятать товар,
                 у которого есть своя выручка. Ссылка ведёт на её собственную
                 страницу с её тарифами. --}}
            @if(!empty($recordingOffers) && count($recordingOffers) > 0)
                <div class="mb-8 max-w-3xl rounded-xl border border-[#38BDF8]/30 bg-[#38BDF8]/5 p-5"
                     data-testid="recording-offers">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-play-circle text-[#38BDF8] mt-1"></i>
                        <div class="min-w-0">
                            <p class="text-white font-bold mb-1">Прошедший поток — в записи</p>
                            <p class="text-slate-300 text-sm mb-3">
                                Живого набора ждать не нужно: занятия прошлого потока продаются записью, со своими тарифами.
                            </p>
                            <ul class="space-y-2">
                                @foreach($recordingOffers as $recording)
                                    <li>
                                        <a href="{{ route('shop.course.show', $recording->slug) }}"
                                           class="inline-flex items-center gap-2 text-[#38BDF8] hover:text-white font-semibold text-sm transition-colors">
                                            {{ $recording->title }}
                                            <i class="fas fa-arrow-right text-[10px]"></i>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Предупреждение для гостей --}}
            <div class="mb-6 max-w-3xl">
                @include('partials.guest-purchase-warning', ['variant' => 'dark'])
            </div>

            {{-- ───── CTA: Забронировать курс (предоплата зачтётся в тариф) ───── --}}
            @php
                $courseDepositAmount = (float) ($course->deposit_amount ?? 0);
                $showDepositCta = ($deposit ?? null)?->deposit_enabled
                    && $courseDepositAmount > 0
                    && empty($purchasedKeys);
            @endphp
            @if($showDepositCta)
                @php
                    $depositAmountLabel = number_format($courseDepositAmount, 0, '.', ' ');
                @endphp
                <div class="mb-8 max-w-3xl rounded-2xl border border-brand/30 bg-gradient-to-r from-brand/10 to-transparent p-5 lg:p-6 flex flex-col md:flex-row md:items-center gap-4">
                    <div class="flex-1">
                        <div class="text-[10px] font-black uppercase tracking-widest text-brand mb-1.5">
                            <i class="fas fa-bookmark mr-1"></i> Начните погружение не дожидаясь старта
                        </div>
                        <h3 class="text-lg lg:text-xl font-bold text-white mb-1">
                            Забронируйте место за {{ $depositAmountLabel }} ₽
                        </h3>
                        <p class="text-sm text-slate-400 leading-relaxed">
                            После предоплаты вы сразу получаете доступ к <span class="text-white font-semibold">открытым занятиям всей школы</span> —
                            начните погружение прямо сейчас. Сумма <span class="text-brand font-bold">{{ $depositAmountLabel }} ₽</span>
                            будет зачтена в стоимость тарифа этого курса при последующей оплате.
                        </p>
                    </div>
                    <button type="button"
                            onclick="window.dispatchEvent(new CustomEvent('open-deposit-modal', { detail: { action: @js(route('deposit.create', $course->slug)), title: @js($course->title), amount: {{ $courseDepositAmount }} } }))"
                            class="md:flex-shrink-0 flex justify-center items-center py-3 px-5 bg-brand hover:bg-brand-hover text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-brand/20">
                        <i class="fas fa-bookmark mr-2 text-xs"></i>
                        Забронировать
                    </button>
                </div>
            @endif

            {{-- ───── CTA: Купить пробное занятие (сумма зачтётся в тариф) ───── --}}
            @if(! empty($showTrialCta))
                @php
                    $trialAmount = (float) $course->trial_price;
                    $trialAmountLabel = number_format($trialAmount, 0, '.', ' ');
                @endphp
                <div class="mb-8 max-w-3xl rounded-2xl border border-[#38BDF8]/30 bg-gradient-to-r from-[#38BDF8]/10 to-transparent p-5 lg:p-6 flex flex-col md:flex-row md:items-center gap-4">
                    <div class="flex-1">
                        <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-1.5">
                            <i class="fas fa-graduation-cap mr-1"></i>
                            {{ ! empty($trialIsRecording) ? 'Посмотрите запись перед покупкой' : 'Попробуйте вживую перед покупкой' }}
                        </div>
                        <h3 class="text-lg lg:text-xl font-bold text-white mb-1">
                            Пробное занятие за {{ $trialAmountLabel }} ₽
                        </h3>
                        <p class="text-sm text-slate-400 leading-relaxed">
                            @if($course->trialSchedule?->start)
                                @if(! empty($trialIsRecording))
                                    Запись занятия от <span class="text-white font-semibold">{{ $course->trialSchedule->start->translatedFormat('d F') }}</span> — доступ откроем в личном кабинете сразу после оплаты.
                                @else
                                    Живое занятие <span class="text-white font-semibold">{{ $course->trialSchedule->start->translatedFormat('d F, H:i') }}</span> — ссылку на Zoom пришлем на email.
                                @endif
                            @else
                                Оплатите одно занятие, чтобы оценить подачу и формат.
                            @endif
                            Сумма <span class="text-[#38BDF8] font-bold">{{ $trialAmountLabel }} ₽</span>
                            будет зачтена в стоимость тарифа при последующей оплате.
                        </p>
                    </div>
                    <button type="button"
                            onclick="window.dispatchEvent(new CustomEvent('open-trial-modal', { detail: { action: @js(route('trial.create', $course->slug)), title: @js($course->title), amount: {{ $trialAmount }}, isRecording: {{ ! empty($trialIsRecording) ? 'true' : 'false' }}, date: @js($course->trialSchedule?->start?->translatedFormat('d F, H:i')) } }))"
                            class="md:flex-shrink-0 flex justify-center items-center py-3 px-5 bg-[#38BDF8] hover:bg-[#2da4dd] text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-[#38BDF8]/20">
                        <i class="fas fa-graduation-cap mr-2 text-xs"></i>
                        Купить пробное
                    </button>
                </div>
            @endif

            @php
                $fullTariffs = $course->tariffs->where('type', '!=', 'block');

                // Группируем блочные тарифы по номеру блока: у одного блока может быть
                // тариф на ВЕСЬ блок и/или тарифы-половины (block_half = 1/2).
                $blockGroups = $course->tariffs->where('type', 'block')
                    ->groupBy('block_number')
                    ->map(fn ($items, $number) => [
                        'number' => (int) $number,
                        'whole'  => $items->whereNull('block_half')->first(),
                        'halves' => $items->whereNotNull('block_half')->sortBy('block_half')->values(),
                    ])
                    ->sortBy('number')
                    ->values();

                // Поднимаем актуальный блок в начало, чтобы он был сразу виден
                if (!empty($currentBlockNumber)) {
                    $blockGroups = $blockGroups
                        ->sortByDesc(fn ($g) => $g['number'] === $currentBlockNumber)
                        ->values();
                }

                // H1291: строка «оплачиваете ближайший блок» честна, только если
                // целый блок правда можно купить (не одни половины) и курс не в
                // режиме продажи записей — там «ближайшего» блока не существует.
                $hasWholeBlockTariff = $blockGroups->contains(fn ($g) => $g['whole'] !== null);
            @endphp

            @if($course->tariffs->count() > 0)

                {{-- Переключатель вкладок (если есть оба типа) --}}
                @if($fullTariffs->count() > 0 && $blockGroups->count() > 0)
                    <div class="inline-flex bg-[#111622] border border-[#1F2636] rounded-xl p-1 mb-8">
                        <button @click="tab = 'full'"
                                :class="tab === 'full' ? 'bg-[#1F2636] text-white shadow-md' : 'text-slate-500 hover:text-slate-300'"
                                class="px-6 py-2.5 text-sm font-bold rounded-lg transition-all duration-200">
                            Весь курс
                        </button>
                        <button @click="tab = 'blocks'"
                                :class="tab === 'blocks' ? 'bg-[#1F2636] text-white shadow-md' : 'text-slate-500 hover:text-slate-300'"
                                class="px-6 py-2.5 text-sm font-bold rounded-lg transition-all duration-200">
                            По модулям
                        </button>
                    </div>
                @endif

                {{-- ВКЛАДКА 1: ВЕСЬ КУРС --}}
                <div x-show="tab === 'full'"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="grid grid-cols-1 gap-6 {{ $fullTariffs->count() > 1 ? 'md:grid-cols-2' : 'md:max-w-2xl md:mx-auto' }}" x-cloak>

                    {{-- H3100: что получает опоздавший. Показываем только когда поток
                         реально начат и не закончен — на ещё не стартовавшем курсе
                         эта врезка была бы шумом. --}}
                    @if($courseUnderway)
                        <div class="{{ $fullTariffs->count() > 1 ? 'md:col-span-2' : '' }} rounded-2xl border border-[#38BDF8]/25 bg-[#38BDF8]/5 p-5"
                             data-testid="tariffs-underway-notice">
                            <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-1.5">
                                <i class="fas fa-circle-info mr-1"></i> Курс уже идёт — что вы получите
                            </div>
                            <p class="text-sm text-slate-300 leading-relaxed">
                                {{-- H3115: при нескольких потоках общего остатка нет — называем каждый поток отдельно. --}}
                                @if($cadenceStreams)
                                    Курс идёт в {{ count($cadenceStreams) }} {{ \App\Support\Plural::ru(count($cadenceStreams), 'потоке', 'потоках', 'потоках') }}:
                                    {{ implode('; ', $cadenceStreams) }}. Вы занимаетесь в одном из них.
                                @else
                                    {{ $cadence->progressLabel() }}@if($cadence->slotLabel()), они пройдут {{ $cadence->slotLabel() }}@endif.
                                @endif
                                Покупая весь курс, вы получаете и <span class="text-white font-semibold">записи всех прошедших занятий</span> —
                                они открываются в личном кабинете сразу после оплаты@if($recordedLessons > 0), сейчас их {{ $recordedLessons }}@endif.
                                Остальные занятия вы проходите вживую вместе с группой.
                            </p>
                        </div>
                    @endif

                    @foreach($fullTariffs as $tariff)
                        @php
                            $tariffKey = $tariff->type === 'block' ? 'block_' . $tariff->block_number : 'full';
                            $isPurchased = in_array($tariffKey, $purchasedKeys, true);
                            $finalPrice = auth()->check() ? $tariff->calculateFinalPriceForUser(auth()->user()) : $tariff->price;
                            $discount = auth()->check() ? $tariff->discountInfoForUser(auth()->user()) : ['label' => ''];
                        @endphp

                        <div class="bg-gradient-to-b from-[#1A2235] to-[#111622] rounded-2xl p-6 border {{ $isPurchased ? 'border-emerald-500/50' : 'border-brand/30 hover:border-brand hover:-translate-y-1 hover:shadow-[0_12px_40px_-12px_rgba(232,92,36,0.35)]' }} transition-all duration-300 relative overflow-hidden group">

                            @if($isPurchased)
                                <div class="absolute top-0 right-0 bg-emerald-500 text-white text-[10px] font-black px-4 py-1.5 rounded-bl-xl tracking-wider">
                                    <i class="fas fa-check-circle mr-1"></i> КУПЛЕНО
                                </div>
                            @else
                                <div class="absolute top-0 right-0 bg-brand text-white text-[10px] font-black px-4 py-1.5 rounded-bl-xl tracking-wider">
                                    ВЫГОДНО
                                </div>
                            @endif

                            <h4 class="text-xl font-bold text-white mb-2 pr-20">{{ $tariff->title }}</h4>

                            <div class="mb-4">
    @if($isPurchased)
        <div class="text-2xl font-black text-emerald-400">Доступ открыт</div>
        <div class="text-sm text-slate-500 mt-1">
            Оплачено: {{ number_format($tariff->price, 0, '.', ' ') }} ₽
        </div>
    @elseif($finalPrice < $tariff->price)
        <div class="flex items-end gap-3 mb-1">
            <div class="text-4xl font-black text-[#38BDF8]">
                {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-xl text-[#38BDF8]/70 font-medium">₽</span>
            </div>
            @if($discount['label'] !== '')
                <span class="bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-black uppercase px-2 py-1 rounded mb-1.5 tracking-wider">
                    Скидка {{ $discount['label'] }}
                </span>
            @endif
        </div>
        <div class="text-slate-500 line-through text-lg font-medium decoration-slate-600/50">
            {{ number_format($tariff->price, 0, '.', ' ') }} ₽
        </div>
        <div class="text-[11px] text-emerald-400/80 font-bold uppercase tracking-wide mt-1">
            {{ $tariff->priceReductionNoteForUser(auth()->user()) }}
        </div>
    @else
        <div class="text-4xl font-black text-white">
            {{ number_format($tariff->price, 0, '.', ' ') }} <span class="text-xl text-slate-500 font-medium">₽</span>
        </div>
    @endif
</div>

                            @if($tariff->description)
                                <p class="text-sm text-slate-400 mb-6 leading-relaxed">{{ $tariff->description }}</p>
                            @endif

                            @if($isPurchased)
                                <a href="{{ route('student.course', $course->slug) }}"
                                   class="w-full flex justify-center items-center py-4 px-4 bg-emerald-500 text-white text-base font-bold rounded-xl hover:bg-emerald-600 transition-all">
                                    <i class="fas fa-arrow-right mr-2"></i> Перейти к обучению
                                </a>
                            @else
                                <a href="{{ route('checkout.show', $tariff->id) }}"
                                   class="w-full flex justify-center items-center py-4 px-4 bg-brand text-white text-base font-bold rounded-xl hover:bg-brand-hover hover:shadow-[0_0_20px_rgba(232,92,36,0.4)] transition-all">
                                    {{ $sellsRecordings ? 'Купить запись курса' : 'Записаться на курс' }}
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- ВКЛАДКА 2: ПО МОДУЛЯМ (сетка 1/2/3 колонки) --}}
                <div x-show="tab === 'blocks'"
                     x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 auto-rows-fr" x-cloak>

                    @foreach($blockGroups as $group)
                        @php
                            $number = $group['number'];
                            $whole = $group['whole'];
                            $halves = $group['halves'];

                            $wholeKey = 'block_' . $number;
                            $wholePurchased = in_array($wholeKey, $purchasedKeys, true);
                            $anyHalfPurchased = $halves->contains(fn ($h) => in_array($h->accessKey(), $purchasedKeys, true));
                            // «Перейти к блоку» доступно, если оплачен весь блок ИЛИ хотя бы одна половина.
                            $blockAccessible = $wholePurchased || $anyHalfPurchased;

                            $finalPrice = ($whole && auth()->check()) ? $whole->calculateFinalPriceForUser(auth()->user()) : ($whole->price ?? 0);
                            $discount = ($whole && auth()->check()) ? $whole->discountInfoForUser(auth()->user()) : ['label' => ''];

                            $defaultBlockTitle = 'Блок ' . $number;
                            $hasCustomTitle = $whole && $whole->title && trim($whole->title) !== $defaultBlockTitle;
                            $isCurrent = !$wholePurchased && $number === ($currentBlockNumber ?? null);
                            // H3100: блок уже прошёл — покупка остаётся, но это доступ
                            // к записям, а не к живым занятиям. Молчать об этом значит
                            // продавать «БЛОК 1» так же, как идущий «БЛОК 4».
                            $isFinishedBlock = !$wholePurchased && !$isCurrent
                                && in_array($number, $finishedBlockNumbers, true);

                            if ($wholePurchased) {
                                $borderClasses = 'border-emerald-500/50';
                            } elseif ($isCurrent) {
                                $borderClasses = 'border-brand shadow-[0_0_0_1px_rgba(232,92,36,0.4),0_12px_40px_-12px_rgba(232,92,36,0.45)] hover:-translate-y-1';
                            } else {
                                $borderClasses = 'border-[#1F2636] hover:border-[#38BDF8]/60 hover:-translate-y-1 hover:shadow-[0_12px_40px_-12px_rgba(56,189,248,0.3)]';
                            }
                        @endphp

                        <div class="bg-gradient-to-b from-[#1A2235] to-[#111622] rounded-xl p-5 border {{ $borderClasses }} transition-all duration-300 group flex flex-col relative">

                            @if($isCurrent)
                                <div class="absolute -top-2.5 left-4 inline-flex items-center gap-1.5 bg-brand text-white text-[10px] font-black uppercase px-2.5 py-1 rounded-md tracking-wider shadow-md shadow-brand/30">
                                    <span class="relative flex h-1.5 w-1.5">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-white"></span>
                                    </span>
                                    СЕЙЧАС ИДЕТ
                                </div>
                            @elseif($isFinishedBlock)
                                <div class="absolute -top-2.5 left-4 inline-flex items-center gap-1.5 bg-[#1F2636] border border-[#38BDF8]/30 text-[#38BDF8] text-[10px] font-black uppercase px-2.5 py-1 rounded-md tracking-wider"
                                     data-testid="tariffs-finished-block">
                                    <i class="fas fa-play-circle text-[9px]"></i>
                                    УЖЕ ПРОШЁЛ — В ЗАПИСИ
                                </div>
                            @endif

                            <div class="flex justify-between items-start mb-3 gap-3">
                                <div class="min-w-0 flex-1">
                                    <span class="inline-block text-[10px] font-black {{ $isCurrent ? 'text-brand bg-brand/10 border-brand/30' : 'text-[#38BDF8] bg-[#38BDF8]/10 border-[#38BDF8]/20' }} px-2 py-1 rounded border {{ $hasCustomTitle ? 'mb-2' : '' }} tracking-widest uppercase">
                                        БЛОК {{ $number }}
                                    </span>
                                    @if($hasCustomTitle)
                                        <h4 class="text-base font-bold text-white leading-tight">{{ $whole->title }}</h4>
                                    @endif
                                </div>

                                @if($whole)
                                    <div class="text-right whitespace-nowrap shrink-0">
                                        @if($wholePurchased)
                                            <div class="inline-flex items-center gap-1 bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-black uppercase px-2.5 py-1.5 rounded tracking-wider">
                                                <i class="fas fa-check-circle"></i> Оплачено
                                            </div>
                                        @elseif($discount['label'] !== '' || $finalPrice < $whole->price)
                                            <div class="text-slate-500 line-through text-xs font-medium mb-0.5 decoration-slate-600/50">
                                                {{ number_format($whole->price, 0, '.', ' ') }} ₽
                                            </div>
                                            <div class="text-xl font-black text-[#38BDF8]">
                                                {{ number_format($finalPrice, 0, '.', ' ') }} <span class="text-sm text-[#38BDF8]/70 font-medium">₽</span>
                                            </div>
                                            <div class="text-[10px] text-emerald-400 font-bold mt-1 tracking-wide uppercase">
                                                {{ $whole->priceReductionNoteForUser(auth()->user()) }}@if($discount['label'] !== '') · {{ $discount['label'] }}@endif
                                            </div>
                                        @else
                                            <div class="text-xl font-black text-white">
                                                {{ number_format($whole->price, 0, '.', ' ') }} <span class="text-sm text-slate-500 font-medium">₽</span>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if($whole && $whole->description)
                                <p class="text-xs text-slate-400 mb-4">{{ $whole->description }}</p>
                            @endif

                            {{-- Покупка половин блока (если такие тарифы заведены) --}}
                            @if($halves->isNotEmpty())
                                <div class="mb-4 pt-3 border-t border-[#1F2636]/80">
                                    <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-2">
                                        {{ $whole ? 'Или по половинам' : 'Доступно по половинам' }}
                                    </div>
                                    <div class="space-y-2">
                                        @foreach($halves as $half)
                                            @php
                                                $halfPurchased = in_array($half->accessKey(), $purchasedKeys, true);
                                                $halfFinal = auth()->check() ? $half->calculateFinalPriceForUser(auth()->user()) : $half->price;
                                                $halfLabel = ($half->title && trim($half->title) !== $defaultBlockTitle)
                                                    ? $half->title
                                                    : $half->block_half . '-я половина';
                                            @endphp
                                            <div class="flex items-center justify-between gap-2 bg-[#0F1420]/60 rounded-lg px-3 py-2">
                                                <div class="min-w-0">
                                                    <div class="text-xs font-semibold text-slate-200 truncate">{{ $halfLabel }}</div>
                                                    @if($half->description)
                                                        <div class="text-[11px] text-slate-500 truncate">{{ $half->description }}</div>
                                                    @endif
                                                </div>
                                                <div class="shrink-0">
                                                    @if($halfPurchased)
                                                        <span class="inline-flex items-center gap-1 text-emerald-400 text-[11px] font-bold whitespace-nowrap">
                                                            <i class="fas fa-check-circle"></i> Оплачено
                                                        </span>
                                                    @else
                                                        <a href="{{ route('checkout.show', $half->id) }}"
                                                           class="inline-flex items-center gap-2 bg-[#1F2636] hover:bg-[#38BDF8] hover:text-[#0A0D14] text-white text-xs font-bold px-3 py-1.5 rounded-lg transition-colors whitespace-nowrap">
                                                            {{ number_format($halfFinal, 0, '.', ' ') }} ₽
                                                        </a>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- mt-auto прижимает кнопку к низу карточки в сетке --}}
                            <div class="mt-auto">
                                @if($blockAccessible)
                                    <a href="{{ route('student.course', $course->slug) }}"
                                       class="w-full flex justify-center items-center py-3 px-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm font-bold rounded-lg hover:bg-emerald-500 hover:text-white transition-colors">
                                        <i class="fas fa-arrow-right mr-2"></i> Перейти к блоку
                                    </a>
                                @elseif($whole)
                                    <a href="{{ route('checkout.show', $whole->id) }}"
                                       class="w-full flex justify-center items-center py-3 px-4 {{ $isCurrent ? 'bg-brand hover:bg-brand-hover text-white shadow-md shadow-brand/20' : 'bg-[#1F2636] text-white hover:bg-[#38BDF8] hover:text-[#0A0D14]' }} text-sm font-bold rounded-lg transition-colors">
                                        {{-- H3100: у прошедшего блока «Оплатить модуль» обещает живые занятия, которых уже не будет. --}}
                                        {{ $sellsRecordings ? 'Купить запись блока' : ($isFinishedBlock ? 'Купить записи блока' : ($halves->isNotEmpty() ? 'Оплатить блок целиком' : 'Оплатить модуль')) }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- H1291: возражение «цена» — ответ рядом с ценой, микрокопией, не блоком.
                     Каждая строка условна: блочная — только при покупаемом целом блоке и
                     не в режиме записей, запрос «по частям» — только при настроенном
                     кураторском чате (та же калитка, что у самого запроса H1290 на
                     чекауте); купившему весь курс уговоры не показываются.
                     Формулировки: docs/copy/money-objection-corpus-pos-microcopy.md --}}
                @unless(in_array('full', $purchasedKeys, true))
                <div class="mt-8 max-w-3xl space-y-2.5" data-analytics="objection-price-microcopy">
                    @if($hasWholeBlockTariff && ! $sellsRecordings)
                        <p class="text-sm text-slate-400 leading-relaxed">
                            Платить за весь курс сразу не нужно: оплачиваете ближайший блок — обычно это 4 занятия — и решаете о продолжении после него.
                        </p>
                    @endif
                    @if((string) (config('services.telegram.curators_chat_id') ?? '') !== '')
                        <p class="text-sm text-slate-400 leading-relaxed">
                            Нужно разбить оплату на части? Спросите на шаге оплаты — запрос куратору ни к чему не обязывает.
                        </p>
                    @endif
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Пенсионерам, студентам и многодетным на многих курсах действует льготная цена — <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="text-slate-300 underline decoration-slate-600 underline-offset-2 hover:text-white transition-colors">напишите куратору в Telegram</a>.
                    </p>
                    <div class="pt-1 text-xs text-slate-500">
                        <a href="{{ route('refund.show') }}" target="_blank" class="inline-flex items-center gap-1.5 hover:text-slate-300 transition-colors">
                            <i class="fas fa-rotate text-[10px]"></i> Возврат: до начала — 100%
                        </a>
                    </div>
                </div>
                @endunless

            @else
                <div class="bg-[#111622] rounded-2xl p-8 border border-[#1F2636] text-center max-w-md mx-auto">
                    <div class="w-16 h-16 bg-[#1F2636] rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock text-2xl text-slate-500"></i>
                    </div>
                    <h4 class="text-lg font-bold text-white mb-2">Набор закрыт</h4>
                    <p class="text-sm text-slate-400">В данный момент запись на этот курс не ведется.</p>
                </div>
            @endif
        </section>

        {{-- ───── Отзывы / FAQ / Техтребования+оплата / Финальный CTA ───── --}}
        @include('shop.partials.testimonials')
        @include('shop.partials.faq')
        @include('shop.partials.tech-payment')
        @include('shop.partials.final-cta')

        {{-- ───── 3. ПРЕИМУЩЕСТВА ───── --}}
        <section class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-[#111622] p-6 rounded-2xl border border-[#1F2636]">
                <i class="fas fa-infinity text-2xl text-[#38BDF8] mb-4"></i>
                <h3 class="text-lg font-bold text-white mb-2">Вечный доступ</h3>
                <p class="text-sm text-slate-400">Материалы курса остаются с вами навсегда. Пересматривайте лекции в любое время.</p>
            </div>
            <div class="bg-[#111622] p-6 rounded-2xl border border-[#1F2636]">
                <i class="fas fa-laptop-house text-2xl text-[#38BDF8] mb-4"></i>
                <h3 class="text-lg font-bold text-white mb-2">Онлайн формат</h3>
                <p class="text-sm text-slate-400">Учитесь из любой точки мира в своем собственном темпе, без привязки к жесткому расписанию.</p>
            </div>
        </section>

    </div>
</div>

@if(! empty($showDepositCta))
    @include('partials.deposit-modal', ['deposit' => $deposit])
@endif
@if(! empty($showTrialCta))
    @include('partials.trial-modal')
@endif
@endsection