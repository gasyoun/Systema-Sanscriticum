<div>
    {{-- ============================================ --}}
    {{-- ПОИСК                                          --}}
    {{-- ============================================ --}}
    <div class="mb-6 max-w-2xl mx-auto relative z-20">
        <div class="relative flex items-center">
            <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                <i class="fas fa-search text-slate-500"></i>
            </div>

            <input
                type="text"
                wire:model.live.debounce.350ms="search"
                placeholder="Найти курс..."
                class="w-full bg-[#111622]/80 backdrop-blur-md border border-[#1F2636] text-white pl-12 pr-12 py-4 rounded-2xl focus:outline-none focus:border-brand/70 focus:ring-1 focus:ring-brand/70 transition-all placeholder-slate-500 shadow-[0_4px_20px_rgba(0,0,0,0.3)]">

            @if($search !== '')
                <button
                    type="button"
                    wire:click="$set('search', '')"
                    class="absolute inset-y-0 right-4 flex items-center text-slate-500 hover:text-brand transition-colors"
                    title="Сбросить">
                    <i class="fas fa-times text-lg"></i>
                </button>
            @endif
        </div>

        {{-- Подсказки «Часто ищут» --}}
        @if(! empty($popularSearches) && $search === '')
            <div class="mt-3 flex flex-wrap items-center justify-center gap-x-3 gap-y-1.5 text-sm">
                <span class="text-slate-500">Часто ищут:</span>
                @foreach($popularSearches as $term)
                    <button type="button"
                            wire:click="$set('search', '{{ $term }}')"
                            class="text-slate-300 hover:text-brand underline-offset-4 hover:underline transition-colors cursor-pointer">
                        {{ $term }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============================================ --}}
    {{-- ФИЛЬТРЫ-ЧИПЫ (горизонтальная навигация)        --}}
    {{-- ============================================ --}}
    <div class="mb-8 space-y-3">

        {{-- ===== Строка A: формат + преподаватель + сброс ===== --}}
        <div class="flex flex-wrap items-center gap-2">
            {{-- Все курсы --}}
            <a href="{{ route('shop.index') }}" wire:click.prevent="$set('format', '')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                        'bg-brand text-white border-brand' => $format === '',
                        'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-brand/50 hover:text-white' => $format !== '',
                    ])>
                Все курсы
            </a>

            {{-- Идут сейчас --}}
            <a href="{{ route('shop.index.facets', ['facets' => 'format/live']) }}" wire:click.prevent="$set('format', 'live')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                        'bg-rose-500 text-white border-rose-500' => $format === 'live',
                        'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-rose-500/50 hover:text-white' => $format !== 'live',
                    ])>
                <span @class([
                    'w-1.5 h-1.5 rounded-full',
                    'bg-white animate-pulse' => $format === 'live',
                    'bg-rose-400' => $format !== 'live',
                ])></span>
                Идут сейчас
            </a>

            {{-- В записи --}}
            <a href="{{ route('shop.index.facets', ['facets' => 'format/recorded']) }}" wire:click.prevent="$set('format', 'recorded')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                        'bg-indigo-500 text-white border-indigo-500' => $format === 'recorded',
                        'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-indigo-500/50 hover:text-white' => $format !== 'recorded',
                    ])>
                <i class="fas fa-play-circle text-[11px]"></i>
                В записи
            </a>

            {{-- H3834: рубрика «Список ожидания» — голосуй за будущую группу --}}
            @can('view', new \App\Models\CourseWaitlistItem)
            <a href="{{ route('shop.waitlist') }}"
                    @class([
                        'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                        'bg-brand text-white border-brand' => false,
                        'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-brand/50 hover:text-white' => true,
                    ])>
                <i class="fas fa-hand-raised text-[11px]"></i>
                Список ожидания
            </a>
            @endcan

            {{-- Правый край: преподаватель + сброс всех --}}
            <div class="ml-auto flex items-center gap-2">
                @if($this->teachers->isNotEmpty())
                    <div class="relative">
                        <select wire:model.live="teacherId"
                                @class([
                                    'appearance-none text-sm font-semibold rounded-full py-2 pl-4 pr-9 border transition cursor-pointer focus:outline-none',
                                    'bg-brand/15 text-white border-brand/50' => $teacherId !== '',
                                    'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-brand/50' => $teacherId === '',
                                ])>
                            <option value="">Все преподаватели</option>
                            @foreach($this->teachers as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}</option>
                            @endforeach
                        </select>
                        <i class="fas fa-chevron-down absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-[10px] pointer-events-none"></i>
                    </div>
                @endif

                @if($this->hasActiveFilters)
                    <a href="{{ route('shop.index') }}" wire:click.prevent="resetFilters"
                            class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 hover:text-brand uppercase tracking-wider px-3 py-2 transition-colors">
                        <i class="fas fa-times-circle"></i>
                        Сбросить
                    </a>
                @endif
            </div>
        </div>

        {{-- ===== Строка A2: уровень (появляется, когда владелец классифицировал курсы) ===== --}}
        @if(! empty($this->levelCounts))
            <div class="flex flex-wrap items-center gap-2" data-analytics="level-filter">
                <span class="text-xs font-bold uppercase tracking-widest text-slate-500 mr-1">Уровень:</span>
                @foreach(\App\Models\Course::LEVELS as $levelKey => $levelLabel)
                    @if(($this->levelCounts[$levelKey] ?? 0) > 0)
                        @php $levelActive = $level === $levelKey; @endphp
                        <a href="{{ $levelActive ? route('shop.index') : route('shop.index.facets', ['facets' => 'uroven/'.$levelKey]) }}"
                                wire:click.prevent="$set('level', '{{ $levelActive ? '' : $levelKey }}')"
                                wire:key="level-{{ $levelKey }}"
                                @class([
                                    'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                                    'bg-emerald-500 text-white border-emerald-500' => $levelActive,
                                    'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-emerald-500/50 hover:text-white' => ! $levelActive,
                                ])>
                            @if($levelKey === 'beginner')<i class="fas fa-seedling text-[11px] opacity-80"></i>@endif
                            {{ $levelLabel }}
                            <span @class([
                                'text-[11px]',
                                'text-white/70' => $levelActive,
                                'text-slate-500' => ! $levelActive,
                            ])>{{ $this->levelCounts[$levelKey] }}</span>
                        </a>
                    @endif
                @endforeach
                <a href="{{ route('shop.start') }}"
                   class="inline-flex items-center gap-1.5 text-xs font-bold text-[#38BDF8] hover:text-white underline-offset-4 hover:underline px-2 py-2 transition-colors">
                    <i class="fas fa-compass text-[11px]"></i>
                    С чего начать?
                </a>
            </div>
        @endif

        {{-- ===== Строка B: категории (скролл по горизонтали на узких экранах) ===== --}}
        @if($this->categories->isNotEmpty())
            {{-- H2379: fade + thin scrollbar so mobile discovers horizontal scroll --}}
            <div class="relative">
                <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1 [scrollbar-width:thin] [scrollbar-color:#1F2636_transparent] scroll-smooth">
                    {{-- Все темы --}}
                    <a href="{{ route('shop.index') }}" wire:click.prevent="resetCategories"
                            @class([
                                'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer shrink-0',
                                'bg-brand text-white border-brand' => empty($categoryIds),
                                'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-brand/50 hover:text-white' => ! empty($categoryIds),
                            ])>
                        Все темы
                    </a>

                    @foreach($this->categories as $category)
                        @php $active = in_array($category->id, $categoryIds, true); @endphp
                        <a href="{{ route('shop.index.facets', ['facets' => 'kategoriya/'.$category->slug]) }}"
                                wire:click.prevent="toggleCategory({{ $category->id }})"
                                wire:key="cat-{{ $category->id }}"
                                @class([
                                    'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer shrink-0',
                                    'bg-brand text-white border-brand' => $active,
                                    'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-brand/50 hover:text-white' => ! $active,
                                ])>
                            @if($category->icon)<i class="fas {{ $category->icon }} text-[11px] opacity-80"></i>@endif
                            <span>{{ $category->name }}</span>
                            <span @class([
                                'text-[11px]',
                                'text-white/70' => $active,
                                'text-slate-500' => ! $active,
                            ])>{{ $category->courses_count }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-[#0A0D14] via-[#0A0D14]/80 to-transparent sm:hidden"
                     aria-hidden="true"></div>
            </div>
            <p class="mt-1.5 text-[11px] text-slate-500 sm:hidden" aria-hidden="true">
                Листайте темы →
            </p>
        @endif
    </div>

    {{-- ============================================ --}}
    {{-- КУРАТОРСКИЕ ВХОДЫ (H2379) — над сеткой          --}}
    {{-- Синхронизация/Arzamas: сначала полки, потом grid --}}
    {{-- ============================================ --}}
    @if($search === '' && $teacherId === '')
        <section class="mb-10" data-analytics="editorial-entrances">
            <div class="flex items-end justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-lg font-extrabold text-white tracking-tight">Куда зайти</h2>
                    <p class="text-sm text-slate-500 mt-1">Короткие входы в каталог — без перелистывания всех карточек.</p>
                </div>
                <a href="{{ route('shop.start') }}"
                   class="hidden sm:inline-flex items-center gap-1.5 text-xs font-bold text-[#38BDF8] hover:text-white underline-offset-4 hover:underline shrink-0">
                    <i class="fas fa-compass text-[11px]"></i>
                    С чего начать?
                </a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                {{-- С нуля → on-ramp --}}
                <a href="{{ route('shop.start') }}"
                   class="group flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-[#38BDF8]/50 p-5 transition-all">
                    <div class="w-10 h-10 rounded-xl bg-[#1F2636] flex items-center justify-center mb-3 group-hover:bg-[#38BDF8]/15 transition-colors">
                        <i class="fas fa-seedling text-[#38BDF8]"></i>
                    </div>
                    <span class="text-base font-bold text-white group-hover:text-[#38BDF8] transition-colors mb-1">С нуля</span>
                    <span class="text-sm text-slate-400 leading-snug">Два вопроса — и план, с какого курса начать.</span>
                </a>

                {{-- Идут сейчас → format filter --}}
                <a href="{{ route('shop.index.facets', ['facets' => 'format/live']) }}"
                        wire:click.prevent="$set('format', 'live')"
                        @class([
                            'group flex flex-col text-left rounded-2xl border p-5 transition-all cursor-pointer',
                            'bg-rose-500/10 border-rose-500/50 ring-1 ring-rose-500/30' => $format === 'live',
                            'bg-[#111622] border-[#1F2636] hover:border-rose-500/50' => $format !== 'live',
                        ])>
                    <div @class([
                        'w-10 h-10 rounded-xl flex items-center justify-center mb-3 transition-colors',
                        'bg-rose-500/20' => $format === 'live',
                        'bg-[#1F2636] group-hover:bg-rose-500/15' => $format !== 'live',
                    ])>
                        <span class="w-2 h-2 rounded-full bg-rose-400 animate-pulse"></span>
                    </div>
                    <span class="text-base font-bold text-white group-hover:text-rose-300 transition-colors mb-1">Идут сейчас</span>
                    <span class="text-sm text-slate-400 leading-snug">Живые потоки с преподавателем — можно присоединиться.</span>
                </a>

                {{-- Библиотека записей → format filter (явный режим, не «остатки») --}}
                <a href="{{ route('shop.index.facets', ['facets' => 'format/recorded']) }}"
                        wire:click.prevent="$set('format', 'recorded')"
                        @class([
                            'group flex flex-col text-left rounded-2xl border p-5 transition-all cursor-pointer',
                            'bg-indigo-500/10 border-indigo-500/50 ring-1 ring-indigo-500/30' => $format === 'recorded',
                            'bg-[#111622] border-[#1F2636] hover:border-indigo-500/50' => $format !== 'recorded',
                        ])>
                    <div @class([
                        'w-10 h-10 rounded-xl flex items-center justify-center mb-3 transition-colors',
                        'bg-indigo-500/20' => $format === 'recorded',
                        'bg-[#1F2636] group-hover:bg-indigo-500/15' => $format !== 'recorded',
                    ])>
                        <i class="fas fa-play-circle text-indigo-400"></i>
                    </div>
                    <span class="text-base font-bold text-white group-hover:text-indigo-300 transition-colors mb-1">Библиотека записей</span>
                    <span class="text-sm text-slate-400 leading-snug">Смотрите в своём темпе — доступ к материалам бессрочный.</span>
                </a>

                {{-- Топ-категория (если есть) или fallback «Все курсы» --}}
                @php $topCategory = $this->categories->sortByDesc('courses_count')->first(); @endphp
                @if($topCategory)
                    <a href="{{ route('shop.index.facets', ['facets' => 'kategoriya/'.$topCategory->slug]) }}"
                            wire:click.prevent="toggleCategory({{ $topCategory->id }})"
                            @class([
                                'group flex flex-col text-left rounded-2xl border p-5 transition-all cursor-pointer',
                                'bg-brand/10 border-brand/50 ring-1 ring-brand/30' => in_array($topCategory->id, $categoryIds, true),
                                'bg-[#111622] border-[#1F2636] hover:border-brand/50' => ! in_array($topCategory->id, $categoryIds, true),
                            ])>
                        <div class="w-10 h-10 rounded-xl bg-[#1F2636] flex items-center justify-center mb-3 group-hover:bg-brand/15 transition-colors">
                            @if($topCategory->icon)
                                <i class="fas {{ $topCategory->icon }} text-brand"></i>
                            @else
                                <i class="fas fa-bookmark text-brand"></i>
                            @endif
                        </div>
                        <span class="text-base font-bold text-white group-hover:text-brand transition-colors mb-1">{{ $topCategory->name }}</span>
                        <span class="text-sm text-slate-400 leading-snug">Тема с наибольшим выбором — {{ $topCategory->courses_count }} {{ \App\Support\Plural::ru((int) $topCategory->courses_count, 'курс', 'курса', 'курсов') }}.</span>
                    </a>
                @else
                    <a href="{{ route('shop.index') }}"
                            wire:click.prevent="resetFilters"
                            class="group flex flex-col text-left rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 p-5 transition-all cursor-pointer">
                        <div class="w-10 h-10 rounded-xl bg-[#1F2636] flex items-center justify-center mb-3 group-hover:bg-brand/15 transition-colors">
                            <i class="fas fa-th-large text-brand"></i>
                        </div>
                        <span class="text-base font-bold text-white group-hover:text-brand transition-colors mb-1">Весь каталог</span>
                        <span class="text-sm text-slate-400 leading-snug">Сбросить фильтры и смотреть все курсы.</span>
                    </a>
                @endif
            </div>
        </section>
    @endif

    {{-- ============================================ --}}
    {{-- РЕЗУЛЬТАТЫ (сгруппированы по секциям)          --}}
    {{-- ============================================ --}}
    <div>
        {{-- Индикатор перерасчёта фильтров --}}
        <div wire:loading wire:target="search,toggleCategory,resetCategories,teacherId,format,level,resetFilters"
             class="flex items-center gap-2 text-xs text-slate-400 mb-6">
            <i class="fas fa-spinner fa-spin text-brand"></i>
            Обновляем...
        </div>

        <div wire:loading.class="opacity-50" wire:target="search,toggleCategory,resetCategories,teacherId,format,level,resetFilters"
             class="space-y-12 transition-opacity">

            @php
                // H2379: library vocabulary when browsing recorded-only; otherwise section pair.
                $sectionLabels = [
                    'live' => 'Идут сейчас',
                    'recorded' => $format === 'recorded' ? 'Библиотека записей' : 'В записи',
                    'other' => 'Другие курсы',
                ];
                $sectionHints = [
                    'live' => 'Живые потоки — можно присоединиться к идущему курсу.',
                    'recorded' => 'Открытая библиотека: смотрите в своём темпе, доступ бессрочный.',
                    'other' => null,
                ];
                // Группируем всю выдачу; порядок секций фиксирован
                $grouped = $courses->groupBy(fn ($c) => $c->format ?: 'other');
                $orderedKeys = collect(['live', 'recorded', 'other'])
                    ->merge($grouped->keys())
                    ->unique()
                    ->filter(fn ($k) => $grouped->has($k));
            @endphp

            @forelse($orderedKeys as $key)
                <section wire:key="section-{{ $key }}">
                    <div class="mb-6">
                        <div class="flex items-baseline gap-3">
                            <h2 class="text-2xl font-extrabold text-white">
                                {{ $sectionLabels[$key] ?? 'Курсы' }}
                            </h2>
                            <span class="text-xl font-bold text-slate-500">{{ $sectionTotals[$key] ?? $grouped[$key]->count() }}</span>
                        </div>
                        @if(! empty($sectionHints[$key]))
                            <p class="mt-1.5 text-sm text-slate-500 max-w-2xl">{{ $sectionHints[$key] }}</p>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                        @foreach($grouped[$key] as $course)
                            <x-shop.course-card
                                :course="$course"
                                :purchasedByCourse="$purchasedByCourse"
                                :deposit="$deposit"
                                :categoryIds="$categoryIds"
                                :nextStep="$nextStepByCourse[$course->id] ?? []"
                                :cadence="$cadenceByCourse[$course->id] ?? null"
                                :eager="$loop->index < 4"
                                wire:key="course-{{ $course->id }}" />
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="text-center py-20">
                    <i class="fas fa-moon text-5xl text-slate-700 mb-4"></i>
                    @if($this->hasActiveFilters)
                        <h3 class="text-2xl font-bold text-white mb-2">Ничего не найдено</h3>
                        <p class="text-slate-400 mb-6">Попробуйте изменить параметры фильтрации.</p>
                        <button wire:click="resetFilters"
                                class="inline-flex items-center gap-2 bg-brand hover:bg-brand/90 text-white text-sm font-bold px-6 py-3 rounded-xl transition-all">
                            <i class="fas fa-redo"></i>
                            Сбросить фильтры
                        </button>
                    @else
                        <h3 class="text-2xl font-bold text-white mb-2">Звезды пока не сошлись</h3>
                        <p class="text-slate-400">Курсы находятся в стадии подготовки.</p>
                    @endif
                </div>
            @endforelse
        </div>
    </div>
</div>
