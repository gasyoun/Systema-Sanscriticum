@extends('layouts.student')

@section('title', $course->title)
@section('header', 'Дом курса')

@section('content')
@php
    $completedLessonIds = $completedLessonIds ?? [];
    $suppressOffers = $suppressOffers ?? false;
    $landmarks = $landmarks ?? [];
    $total = $lessons->count();
    $completed = collect($completedLessonIds)->intersect($lessons->pluck('id'))->count();
    $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
@endphp

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito"
     x-data="hybridCourseTabs()"
     x-init="init()">

    <p class="text-sm text-gray-500 mb-4">
        <a href="{{ route('student.dashboard') }}" class="hover:text-brand font-bold">← Сегодня</a>
    </p>

    <header class="mb-6">
        <h1 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight">{{ $course->title }}</h1>
        <p class="text-sm text-gray-500 mt-2">
            {{ $completed }} из {{ $total }} уроков · {{ $percent }}%
        </p>
    </header>

    @include('student.partials.course-continuation-banner', [
        'continuationBanner' => $continuationBanner ?? null,
    ])

    {{-- R29.8 «место курса на пути» — orientation landmarks, never payment deadlines --}}
    @if (count($landmarks) > 0)
        <section class="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" data-course-landmarks aria-label="Место курса на пути">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-2">Место курса на пути</p>
            <ul class="space-y-2">
                @foreach ($landmarks as $lm)
                    <li class="flex flex-wrap items-baseline gap-2 text-sm">
                        <span class="font-bold text-[#101010]">{{ $lm->label }}</span>
                        @if ($lm->at)
                            <span class="text-gray-500">{{ $lm->at->timezone(config('app.timezone'))->translatedFormat('d F Y') }}</span>
                        @endif
                        <span class="text-[10px] uppercase tracking-widest text-gray-400">ориентир</span>
                    </li>
                @endforeach
            </ul>
            <p class="text-xs text-gray-400 mt-3">Это вехи контура, не сроки оплаты.</p>
        </section>
    @endif

    {{-- R9 workspace tabs, hash-addressable.
         data-track-*: baseline-телеметрия H962 (course.tab.view, спека §4).
         Имена атрибутов — ровно те, что слушает student.partials.telemetry;
         data-cabinet-event/data-kind ничего не шлют (инцидент H4134/H4185). --}}
    <nav class="ws-tabs flex flex-wrap gap-1 border-b border-gray-200 mb-6" data-tabs aria-label="Разделы курса">
        <template x-for="tab in tabs" :key="tab.id">
            <a :href="'#' + tab.id"
               :data-tab="tab.id"
               data-track-event="course.tab.view"
               data-track-surface="course"
               :data-track-tab="tab.id"
               @click.prevent="show(tab.id, true)"
               :class="active === tab.id
                    ? 'border-b-2 border-brand text-brand'
                    : 'text-gray-500 hover:text-gray-800'"
               class="px-4 py-3 text-sm font-bold transition-colors"
               :aria-current="active === tab.id ? 'page' : null"
               x-text="tab.label"></a>
        </template>
    </nav>

    {{-- Обзор --}}
    <section class="tab-panel" id="tab-obzor" x-show="active === 'obzor'" x-cloak>
        <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm mb-6">
            <div class="text-gray-600 prose prose-slate max-w-none">
                {!! $course->description_html !!}
            </div>
            <div class="mt-6 bg-gray-50 rounded-xl p-4 border border-gray-100">
                <div class="flex justify-between text-sm font-bold text-gray-700 mb-2">
                    <span>Прогресс</span>
                    <span class="text-brand">{{ $percent }}%</span>
                </div>
                <div class="h-2 bg-white rounded-full overflow-hidden border border-gray-100">
                    <div class="h-full bg-brand rounded-full" style="width: {{ $percent }}%"></div>
                </div>
            </div>
        </div>
        @unless ($suppressOffers)
            {{-- Master offer rule R29.7: Phase 3 lights ladder offers; empty in Phase 1. --}}
        @endunless
    </section>

    {{-- Уроки --}}
    <section class="tab-panel" id="tab-uroki" x-show="active === 'uroki'" x-cloak>
        <ol class="space-y-2 list-none">
            @foreach ($lessons as $lesson)
                @php
                    $unlocked = $lesson->is_free
                        || in_array($lesson->id, $grantedLessonIds ?? [], true)
                        || $lesson->isUnlockedBy($unlockedTariffs);
                    $done = in_array($lesson->id, $completedLessonIds, true);
                @endphp
                {{-- Закрытый урок = next-block offer (спека §4): те же offer.impression /
                     offer.click, что и в легаси-витрине student/course.blade.php.
                     Политика подавления не меняется — при recovery ($suppressOffers)
                     маячка нет, как и раньше. --}}
                <li class="flex items-center gap-3 rounded-xl border border-gray-100 bg-white px-4 py-3 {{ $unlocked ? '' : 'opacity-70' }}"
                    @if (! $unlocked && ! $suppressOffers)
                        data-track-event="offer.click"
                        data-track-impression="offer.impression"
                        data-track-kind="next-block"
                        data-track-block="{{ $lesson->block_number }}"
                        data-track-course="{{ $course->id }}"
                    @endif
                >
                    <span class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-xs font-extrabold
                        {{ $done ? 'bg-emerald-100 text-emerald-700' : ($unlocked ? 'bg-orange-50 text-brand' : 'bg-gray-100 text-gray-400') }}">
                        {{ $done ? '✓' : ($unlocked ? $loop->iteration : '🔒') }}
                    </span>
                    <div class="min-w-0 flex-1">
                        @if ($unlocked)
                            <a href="{{ route('student.lesson', [$course->slug, $lesson->id]) }}"
                               class="font-bold text-[#101010] hover:text-brand">
                                {{ $lesson->title }}
                            </a>
                        @else
                            <span class="font-bold text-gray-500">{{ $lesson->title }}</span>
                            @include('student.partials.access-why-locked', [
                                'findings' => ($accessFindingsByLessonId ?? [])[$lesson->id] ?? [],
                                'enabled' => $accessSelfService ?? false,
                            ])
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    {{-- Материалы --}}
    <section class="tab-panel" id="tab-materialy" x-show="active === 'materialy'" x-cloak>
        @php
            $hasAnyMaterials = $lessons->contains(function ($lesson) use ($unlockedTariffs, $grantedLessonIds) {
                return ($lesson->is_free
                        || in_array($lesson->id, $grantedLessonIds ?? [], true)
                        || $lesson->isUnlockedBy($unlockedTariffs))
                    && ! empty($lesson->attachments) && count($lesson->attachments) > 0;
            });
        @endphp
        @if ($hasAnyMaterials)
            <a href="{{ route('student.course.materials.download', $course->slug) }}"
               class="inline-flex items-center gap-2 px-5 py-3 bg-brand hover:bg-brand-hover text-white text-sm font-bold rounded-xl shadow-md">
                <i class="fas fa-file-archive"></i> Скачать все материалы
            </a>
        @else
            <p class="text-sm text-gray-500">Пока нет материалов для скачивания в доступных уроках.</p>
        @endif

        {{-- Библиотека курса — реестр ссылок на литературу (файлы лежат не у нас).
             За флагом features.course_library: выключен — ссылки нет, маршрут 404. --}}
        @if(config('features.course_library', false))
            <div class="mt-3 flex justify-end">
                <a href="{{ route('student.course.library', $course->slug) }}"
                   class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-gray-200 hover:border-brand/40 text-gray-700 hover:text-brand text-sm font-bold rounded-xl shadow-sm hover:shadow-md transition-all">
                    <i class="fas fa-book-open"></i>
                    <span>Библиотека курса</span>
                </a>
            </div>
        @endif
    </section>

    {{-- Доступ --}}
    <section class="tab-panel" id="tab-dostup" x-show="active === 'dostup'" x-cloak>
        <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <h3 class="font-extrabold text-lg mb-2">Доступ и продление</h3>
            <p class="text-sm text-gray-600 mb-4">
                Управление оплатой и доступом — на странице «Оплата и доступ».
            </p>
            <a href="{{ route('student.access') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-bold hover:border-brand hover:text-brand">
                Открыть оплату и доступ
            </a>
        </div>
    </section>

    {{-- Помощь --}}
    <section class="tab-panel" id="tab-pomosch" x-show="active === 'pomosch'" x-cloak>
        <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <h3 class="font-extrabold text-lg mb-2">Помощь по курсу</h3>
            <p class="text-sm text-gray-600 mb-4">Напишите куратору — тема подставится из контекста курса.</p>
            <a href="{{ route('student.messages') }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand text-white text-sm font-bold">
                Написать в помощь
            </a>
        </div>
    </section>
</div>

<script>
function hybridCourseTabs() {
    return {
        active: 'obzor',
        tabs: [
            { id: 'obzor', label: 'Обзор' },
            { id: 'uroki', label: 'Уроки' },
            { id: 'materialy', label: 'Материалы' },
            { id: 'dostup', label: 'Доступ и продление' },
            { id: 'pomosch', label: 'Помощь' },
        ],
        init() {
            const fromHash = (location.hash || '').replace('#', '');
            if (fromHash && this.tabs.some(t => t.id === fromHash)) {
                this.active = fromHash;
            }
            window.addEventListener('hashchange', () => {
                const id = (location.hash || '').replace('#', '');
                if (id && this.tabs.some(t => t.id === id)) this.active = id;
            });
        },
        show(id, push) {
            if (!this.tabs.some(t => t.id === id)) return;
            this.active = id;
            if (push) history.replaceState(null, '', '#' + id);
        },
    };
}
</script>
@endsection
