<div>
    {{-- ============================================ --}}
    {{-- ПОИСК                                          --}}
    {{-- ============================================ --}}
    <div class="mb-8 max-w-2xl mx-auto relative z-20">
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
    </div>

    {{-- ============================================ --}}
    {{-- ДВУХКОЛОНОЧНЫЙ LAYOUT                          --}}
    {{-- ============================================ --}}
    <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-8">

        {{-- ============================================ --}}
        {{-- SIDEBAR С ФИЛЬТРАМИ                           --}}
        {{-- ============================================ --}}
        <aside x-data="{ open: false }" class="lg:sticky lg:top-6 lg:self-start">
            <button
                type="button"
                @click="open = !open"
                class="lg:hidden w-full flex items-center justify-between bg-[#111622] border border-[#1F2636] text-white py-3 px-4 rounded-xl">
                <span class="flex items-center gap-2 text-sm font-bold">
                    <i class="fas fa-sliders-h text-[#E85C24]"></i>
                    Фильтры
                    @if($this->hasActiveFilters)
                        <span class="w-2 h-2 rounded-full bg-[#E85C24] animate-pulse"></span>
                    @endif
                </span>
                <i class="fas fa-chevron-down text-slate-500 text-xs transition-transform" :class="{ 'rotate-180': open }"></i>
            </button>

            <div
                x-show="open || window.innerWidth >= 1024"
                x-transition
                @resize.window="open = window.innerWidth >= 1024 ? true : open"
                x-cloak
                class="lg:!block bg-[#111622] border border-[#1F2636] rounded-2xl p-5 mt-3 lg:mt-0 space-y-6">

                <div class="hidden lg:flex items-center justify-between pb-4 border-b border-[#1F2636]">
                    <h3 class="text-white font-bold text-sm uppercase tracking-wider flex items-center gap-2">
                        <i class="fas fa-sliders-h text-[#E85C24]"></i>
                        Фильтры
                    </h3>
                    @if($this->hasActiveFilters)
                        <button
                            type="button"
                            wire:click="resetFilters"
                            class="text-[10px] font-bold text-slate-400 hover:text-[#E85C24] uppercase tracking-wider transition-colors">
                            Сбросить
                        </button>
                    @endif
                </div>

                {{-- ===== ФОРМАТ ===== --}}
                <div>
                    <h4 class="text-slate-400 text-[11px] font-bold uppercase tracking-widest mb-3">Формат</h4>
                    <div class="space-y-1.5">
                        <button type="button" wire:click="$set('format', '')"
                                class="w-full text-left text-xs font-semibold py-2.5 px-3 rounded-lg transition-all flex items-center justify-between
                                       {{ $format === '' ? 'bg-[#E85C24]/15 text-white border border-[#E85C24]/50' : 'text-slate-400 hover:text-white hover:bg-[#1F2636] border border-transparent' }}">
                            <span>Все курсы</span>
                            @if($format === '')<i class="fas fa-check text-[#E85C24] text-xs"></i>@endif
                        </button>
                        <button type="button" wire:click="$set('format', 'live')"
                                class="w-full text-left text-xs font-semibold py-2.5 px-3 rounded-lg transition-all flex items-center justify-between
                                       {{ $format === 'live' ? 'bg-rose-500/15 text-white border border-rose-500/50' : 'text-slate-400 hover:text-white hover:bg-[#1F2636] border border-transparent' }}">
                            <span class="flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400 {{ $format === 'live' ? 'animate-pulse' : '' }}"></span>
                                Идут сейчас
                            </span>
                            @if($format === 'live')<i class="fas fa-check text-rose-400 text-xs"></i>@endif
                        </button>
                        <button type="button" wire:click="$set('format', 'recorded')"
                                class="w-full text-left text-xs font-semibold py-2.5 px-3 rounded-lg transition-all flex items-center justify-between
                                       {{ $format === 'recorded' ? 'bg-indigo-500/15 text-white border border-indigo-500/50' : 'text-slate-400 hover:text-white hover:bg-[#1F2636] border border-transparent' }}">
                            <span class="flex items-center gap-2">
                                <i class="fas fa-play-circle text-[10px]"></i>
                                В записи
                            </span>
                            @if($format === 'recorded')<i class="fas fa-check text-indigo-400 text-xs"></i>@endif
                        </button>
                    </div>
                </div>

                {{-- ===== КАТЕГОРИИ ===== --}}
                @if($this->categories->isNotEmpty())
                    <div>
                        <h4 class="text-slate-400 text-[11px] font-bold uppercase tracking-widest mb-3">Категории</h4>
                        <div class="space-y-1.5">
                            @foreach($this->categories as $category)
                                @php $active = in_array($category->id, $categoryIds, true); @endphp
                                <button type="button"
                                        wire:click="toggleCategory({{ $category->id }})"
                                        wire:key="cat-{{ $category->id }}"
                                        class="w-full text-left text-xs font-semibold py-2.5 px-3 rounded-lg transition-all flex items-center justify-between
                                               {{ $active ? 'bg-[#E85C24]/15 text-white border border-[#E85C24]/50' : 'text-slate-400 hover:text-white hover:bg-[#1F2636] border border-transparent' }}">
                                    <span class="flex items-center gap-2 truncate">
                                        @if($category->icon)<i class="fas {{ $category->icon }} text-[10px] opacity-80"></i>@endif
                                        <span class="truncate">{{ $category->name }}</span>
                                    </span>
                                    <span class="flex items-center gap-1.5 shrink-0">
                                        <span class="text-[10px] {{ $active ? 'text-[#E85C24]' : 'text-slate-500' }}">{{ $category->courses_count }}</span>
                                        @if($active)<i class="fas fa-check text-[#E85C24] text-xs"></i>@endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- ===== ПРЕПОДАВАТЕЛЬ ===== --}}
                @if($this->teachers->isNotEmpty())
                    <div>
                        <h4 class="text-slate-400 text-[11px] font-bold uppercase tracking-widest mb-3">Преподаватель</h4>
                        <div class="relative">
                            <select wire:model.live="teacherId"
                                    class="w-full appearance-none bg-[#0A0D14] border border-[#1F2636] text-white text-xs py-2.5 pl-3 pr-9 rounded-lg focus:outline-none focus:border-[#E85C24]/70 cursor-pointer">
                                <option value="">Все преподаватели</option>
                                @foreach($this->teachers as $t)
                                    <option value="{{ $t->id }}">{{ $t->name }}</option>
                                @endforeach
                            </select>
                            <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 text-[10px] pointer-events-none"></i>
                        </div>
                    </div>
                @endif

                @if($this->hasActiveFilters)
                    <button type="button" wire:click="resetFilters"
                            class="lg:hidden w-full flex items-center justify-center gap-2 text-xs font-bold text-slate-400 hover:text-[#E85C24] uppercase tracking-wider py-3 border-t border-[#1F2636] transition-colors">
                        <i class="fas fa-times-circle"></i>
                        Сбросить все фильтры
                    </button>
                @endif
            </div>
        </aside>

        {{-- ============================================ --}}
        {{-- ПРАВАЯ КОЛОНКА: СЕТКА КУРСОВ                  --}}
        {{-- ============================================ --}}
        <div>
            {{-- Счётчик результатов + индикатор перерасчёта фильтров --}}
            <div class="flex items-center justify-between mb-6">
                <div class="text-sm text-slate-500">
                    Показано: <span class="text-white font-bold">{{ $courses->count() }}</span>
                    из <span class="text-white font-bold">{{ $totalCount }}</span>
                </div>
                <div wire:loading wire:target="search,toggleCategory,teacherId,format,resetFilters"
                     class="flex items-center gap-2 text-xs text-slate-400">
                    <i class="fas fa-spinner fa-spin text-[#E85C24]"></i>
                    Обновляем...
                </div>
            </div>

            <div wire:loading.class="opacity-50" wire:target="search,toggleCategory,teacherId,format,resetFilters"
                 class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6 transition-opacity">

                @forelse($courses as $course)
                    <x-shop.course-card
                        :course="$course"
                        :purchasedByCourse="$purchasedByCourse"
                        wire:key="course-{{ $course->id }}" />
                @empty
                    <div class="col-span-full text-center py-20">
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
</div>