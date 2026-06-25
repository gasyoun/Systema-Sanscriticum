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
                class="w-full bg-[#111622]/80 backdrop-blur-md border border-[#1F2636] text-white pl-12 pr-12 py-4 rounded-2xl focus:outline-none focus:border-[#E85C24]/70 focus:ring-1 focus:ring-[#E85C24]/70 transition-all placeholder-slate-500 shadow-[0_4px_20px_rgba(0,0,0,0.3)]">

            @if($search !== '')
                <button
                    type="button"
                    wire:click="$set('search', '')"
                    class="absolute inset-y-0 right-4 flex items-center text-slate-500 hover:text-[#E85C24] transition-colors"
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
                            class="text-slate-300 hover:text-[#E85C24] underline-offset-4 hover:underline transition-colors cursor-pointer">
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
            <button type="button" wire:click="$set('format', '')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                        'bg-[#E85C24] text-white border-[#E85C24]' => $format === '',
                        'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-[#E85C24]/50 hover:text-white' => $format !== '',
                    ])>
                Все курсы
            </button>

            {{-- Идут сейчас --}}
            <button type="button" wire:click="$set('format', 'live')"
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
            </button>

            {{-- В записи --}}
            <button type="button" wire:click="$set('format', 'recorded')"
                    @class([
                        'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer',
                        'bg-indigo-500 text-white border-indigo-500' => $format === 'recorded',
                        'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-indigo-500/50 hover:text-white' => $format !== 'recorded',
                    ])>
                <i class="fas fa-play-circle text-[11px]"></i>
                В записи
            </button>

            {{-- Правый край: преподаватель + сброс всех --}}
            <div class="ml-auto flex items-center gap-2">
                @if($this->teachers->isNotEmpty())
                    <div class="relative">
                        <select wire:model.live="teacherId"
                                @class([
                                    'appearance-none text-sm font-semibold rounded-full py-2 pl-4 pr-9 border transition cursor-pointer focus:outline-none',
                                    'bg-[#E85C24]/15 text-white border-[#E85C24]/50' => $teacherId !== '',
                                    'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-[#E85C24]/50' => $teacherId === '',
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
                    <button type="button" wire:click="resetFilters"
                            class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-400 hover:text-[#E85C24] uppercase tracking-wider px-3 py-2 transition-colors">
                        <i class="fas fa-times-circle"></i>
                        Сбросить
                    </button>
                @endif
            </div>
        </div>

        {{-- ===== Строка B: категории (скролл по горизонтали на узких экранах) ===== --}}
        @if($this->categories->isNotEmpty())
            <div class="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {{-- Все темы --}}
                <button type="button" wire:click="resetCategories"
                        @class([
                            'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer shrink-0',
                            'bg-[#E85C24] text-white border-[#E85C24]' => empty($categoryIds),
                            'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-[#E85C24]/50 hover:text-white' => ! empty($categoryIds),
                        ])>
                    Все темы
                </button>

                @foreach($this->categories as $category)
                    @php $active = in_array($category->id, $categoryIds, true); @endphp
                    <button type="button"
                            wire:click="toggleCategory({{ $category->id }})"
                            wire:key="cat-{{ $category->id }}"
                            @class([
                                'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold border transition whitespace-nowrap cursor-pointer shrink-0',
                                'bg-[#E85C24] text-white border-[#E85C24]' => $active,
                                'bg-[#141A28] text-slate-300 border-[#1F2636] hover:border-[#E85C24]/50 hover:text-white' => ! $active,
                            ])>
                        @if($category->icon)<i class="fas {{ $category->icon }} text-[11px] opacity-80"></i>@endif
                        <span>{{ $category->name }}</span>
                        <span @class([
                            'text-[11px]',
                            'text-white/70' => $active,
                            'text-slate-500' => ! $active,
                        ])>{{ $category->courses_count }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============================================ --}}
    {{-- РЕЗУЛЬТАТЫ                                      --}}
    {{-- ============================================ --}}
    <div>
        {{-- Счётчик результатов + индикатор перерасчёта фильтров --}}
        <div class="flex items-center justify-between mb-6">
            <div class="text-sm text-slate-500">
                Показано: <span class="text-white font-bold">{{ $courses->count() }}</span>
                из <span class="text-white font-bold">{{ $totalCount }}</span>
            </div>
            <div wire:loading wire:target="search,toggleCategory,resetCategories,teacherId,format,resetFilters"
                 class="flex items-center gap-2 text-xs text-slate-400">
                <i class="fas fa-spinner fa-spin text-[#E85C24]"></i>
                Обновляем...
            </div>
        </div>

        <div wire:loading.class="opacity-50" wire:target="search,toggleCategory,resetCategories,teacherId,format,resetFilters"
             class="space-y-12 transition-opacity">

            @php
                // Заголовки секций по формату курса
                $sectionLabels = ['live' => 'Идут сейчас', 'recorded' => 'В записи', 'other' => 'Другие курсы'];
                // Группируем подгруженную порцию; порядок секций фиксирован
                $grouped = $courses->groupBy(fn ($c) => $c->format ?: 'other');
                $orderedKeys = collect(['live', 'recorded', 'other'])
                    ->merge($grouped->keys())
                    ->unique()
                    ->filter(fn ($k) => $grouped->has($k));
            @endphp

            @forelse($orderedKeys as $key)
                <section wire:key="section-{{ $key }}">
                    <div class="flex items-baseline gap-3 mb-6">
                        <h2 class="text-2xl font-extrabold text-white">
                            {{ $sectionLabels[$key] ?? 'Курсы' }}
                        </h2>
                        <span class="text-xl font-bold text-slate-500">{{ $sectionTotals[$key] ?? $grouped[$key]->count() }}</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6">
                        @foreach($grouped[$key] as $course)
                            <x-shop.course-card
                                :course="$course"
                                :purchasedByCourse="$purchasedByCourse"
                                :deposit="$deposit"
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
                                class="inline-flex items-center gap-2 bg-[#E85C24] hover:bg-[#E85C24]/90 text-white text-sm font-bold px-6 py-3 rounded-xl transition-all">
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

        {{-- ============================================ --}}
        {{-- INFINITE SCROLL: SENTINEL + КНОПКА FALLBACK    --}}
        {{-- ============================================ --}}
        @if($hasMore)
            <div
                x-data="{
                    observer: null,
                    loading: false,
                    init() {
                        // Если IntersectionObserver не поддерживается — оставляем только кнопку
                        if (!('IntersectionObserver' in window)) return;

                        this.observer = new IntersectionObserver((entries) => {
                            entries.forEach(entry => {
                                if (entry.isIntersecting && !this.loading) {
                                    this.loading = true;
                                    @this.call('loadMore').finally(() => {
                                        this.loading = false;
                                    });
                                }
                            });
                        }, {
                            // Подгружаем заранее, когда сентинель ещё за 200px от viewport
                            rootMargin: '200px',
                            threshold: 0
                        });

                        this.observer.observe(this.$refs.sentinel);
                    },
                    destroy() {
                        this.observer?.disconnect();
                    }
                }"
                x-init="init()"
                @destroy="destroy()"
                class="mt-12 pt-8 border-t border-[#1F2636] flex flex-col items-center gap-4">

                {{-- Лоадер --}}
                <div wire:loading wire:target="loadMore" class="flex items-center gap-3 text-slate-400">
                    <i class="fas fa-circle-notch fa-spin text-[#E85C24] text-lg"></i>
                    <span class="text-sm font-semibold">Загружаем ещё...</span>
                </div>

                {{-- Кнопка-fallback (видна когда НЕ идёт загрузка) --}}
                <button
                    type="button"
                    wire:click="loadMore"
                    wire:loading.remove
                    wire:target="loadMore"
                    class="inline-flex items-center gap-2 bg-[#1F2636] hover:bg-[#2A344A] text-white text-sm font-bold px-8 py-3.5 rounded-xl transition-all hover:-translate-y-0.5">
                    <i class="fas fa-chevron-down"></i>
                    Показать ещё
                </button>

                {{-- Сентинель для IntersectionObserver --}}
                <div x-ref="sentinel" class="h-1 w-full" aria-hidden="true"></div>
            </div>
        @elseif($courses->count() > $perPage)
            {{-- Когда показали всё и было больше одной порции — приятный финал --}}
            <div class="mt-12 pt-8 border-t border-[#1F2636] text-center">
                <p class="text-sm text-slate-500">
                    <i class="fas fa-check-circle text-emerald-500/70 mr-1.5"></i>
                    Это все курсы, которые удалось найти
                </p>
            </div>
        @endif
    </div>
</div>
