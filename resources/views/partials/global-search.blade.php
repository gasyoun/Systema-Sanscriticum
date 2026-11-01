{{-- H7590 — глобальный поиск в шапке. Включается из public-header (все
     лейауты, кроме shop) и из инлайн-шапки layouts/shop.

     Alpine-компонент — inline x-data (без Alpine.data-регистрации): шапка
     живёт на страницах с Livewire-Alpine И с alpine-standalone — регистрация
     до старта в обоих путях не гарантируется. Fuse.js + индекс грузятся
     лениво из resources/js/global-search.js при первом фокусе/вводе.

     Клавиатура (GitHub-набор): Cmd/Ctrl+K и «/» фокусируют поле, ↑↓ ходят
     по топ-5, Enter — переход (выбранный результат или страница /poisk/{q}),
     Esc — закрыть. Мобайл: поле свёрнуто в иконку-лупу → полноэкранный оверлей.
     No-JS/нет индекса: форма GET /poisk?q= … → 301 на path-style /poisk/{q}. --}}

@php
    $gsDark = ($variant ?? 'dark') === 'dark';
    $gsManifest = \App\Support\SearchIndexBuilder::manifest();
    $gsIndexUrl = $gsManifest ? asset('search/'.$gsManifest['file']) : null;

    $gsFieldBg    = $gsDark ? 'bg-[#111622] border-[#1F2636] focus-within:border-brand' : 'bg-white border-brand/20 focus-within:border-brand';
    $gsInputText  = $gsDark ? 'text-white placeholder-slate-500' : 'text-[#1F1B16] placeholder-gray-400';
    $gsIconIdle   = $gsDark ? 'text-slate-400 hover:text-brand' : 'text-gray-500 hover:text-brand';
    $gsPanelBg    = $gsDark ? 'bg-[#111622] border-[#1F2636]' : 'bg-white border-brand/10';
    $gsItemActive = $gsDark ? 'bg-[#1F2636]' : 'bg-brand/5';
    $gsMetaColor  = $gsDark ? 'text-slate-500' : 'text-gray-400';
@endphp

<div data-testid="global-search"
     data-index-url="{{ $gsIndexUrl }}"
     x-data="{
         q: '', open: false, results: [], active: -1,
         engine: null, engineFailed: false, engineLoading: false, mobileOpen: false,
         indexUrl: null,
         init() {
             this.indexUrl = this.$el.dataset.indexUrl || null;
         },
         async ensureEngine() {
             if (this.engine || this.engineFailed || !this.indexUrl || !window.GlobalSearch || !window.GlobalSearch.load) return;
             if (this.engineLoading) return;
             this.engineLoading = true;
             try { this.engine = await window.GlobalSearch.load().then((m) => m.boot(this.indexUrl)); }
             catch (e) { this.engineFailed = true; }
             finally { this.engineLoading = false; }
         },
         async lookup() {
             const query = this.q.trim();
             if (query.length < 2) { this.results = []; this.open = false; this.active = -1; return; }
             await this.ensureEngine();
             if (!this.engine) return;
             this.results = this.engine.search(query, 5);
             this.open = this.results.length > 0;
             this.active = this.open ? 0 : -1;
         },
         fullUrl() { return '/poisk/' + encodeURIComponent(this.q.trim()); },
         go(item) { if (item && item.url) { window.location.href = item.url; } },
         goFull() { if (this.q.trim()) { window.location.href = this.fullUrl(); } },
         focusInput() {
             const el = window.matchMedia('(min-width: 768px)').matches ? this.$refs.inputDesktop : this.$refs.inputMobile;
             if (el) { el.focus(); el.select(); }
         },
         openFromHotkey() {
             if (window.matchMedia('(min-width: 768px)').matches) { this.focusInput(); return; }
             this.mobileOpen = true;
             this.$nextTick(() => { this.$refs.inputMobile && this.$refs.inputMobile.focus(); });
         },
         onKeydown(e) {
             if (e.key === 'ArrowDown') { e.preventDefault(); if (this.results.length) { this.active = (this.active + 1) % this.results.length; } }
             else if (e.key === 'ArrowUp') { e.preventDefault(); if (this.results.length) { this.active = this.active <= 0 ? this.results.length - 1 : this.active - 1; } }
             else if (e.key === 'Enter') { e.preventDefault(); if (this.open && this.active >= 0 && this.results[this.active]) { this.go(this.results[this.active]); } else { this.goFull(); } }
             else if (e.key === 'Escape') { this.open = false; this.active = -1; this.mobileOpen = false; }
         },
         onWindowKeydown(e) {
             const k = (e.key || '').toLowerCase();
             if ((e.metaKey || e.ctrlKey) && k === 'k') { e.preventDefault(); this.openFromHotkey(); return; }
             if (e.key === '/' && !/^(input|textarea|select)$/i.test(e.target.tagName) && !e.target.isContentEditable) { e.preventDefault(); this.openFromHotkey(); }
         },
     }"
     @keydown.window="onWindowKeydown($event)"
     class="relative flex items-center">

    <style>[x-cloak] { display: none !important; }</style>

    {{-- Мобильная иконка-лупа (поле свёрнуто до неё на <md); без x-cloak:
         до старта Alpine кнопка уже должна быть видна — это вход в поиск --}}
    <button type="button"
            x-show="!mobileOpen"
            class="md:hidden w-10 h-10 flex items-center justify-center rounded-lg {{ $gsIconIdle }} transition-colors"
            @click="openFromHotkey()"
            aria-label="Поиск по сайту">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
        </svg>
    </button>

    {{-- Десктопное поле (md+): компактный бокс, ширина растёт в фокусе --}}
    <form action="/poisk" method="GET"
          class="hidden md:flex items-center gap-2 px-3 py-2 rounded-xl border transition-all {{ $gsFieldBg }}"
          @submit.prevent="goFull()">
        <svg class="w-4 h-4 shrink-0 {{ $gsMetaColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
        </svg>
        <input type="search" name="q" x-ref="inputDesktop" x-model="q"
               autocomplete="off" spellcheck="false"
               placeholder="Поиск по сайту"
               aria-label="Поиск по сайту"
               @focus="lookup()"
               @input.debounce.150ms="lookup()"
               @keydown="onKeydown($event)"
               class="bg-transparent text-sm outline-none w-36 lg:w-44 focus:w-56 transition-all {{ $gsInputText }}">
        <kbd class="hidden lg:inline-flex items-center px-1.5 py-0.5 rounded border text-[10px] font-semibold {{ $gsDark ? 'border-[#1F2636] text-slate-600' : 'border-brand/20 text-gray-400' }}">⌘K</kbd>
    </form>

    {{-- Живой дропдаун топ-5 (десктоп) --}}
    <div x-show="open" x-cloak
         @click.outside="open = false"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="hidden md:block absolute right-0 top-full mt-2 w-96 max-h-[70vh] overflow-auto rounded-xl border shadow-2xl z-50 py-2 {{ $gsPanelBg }}">
        <template x-for="(item, idx) in results" :key="idx">
            <a :href="item.url"
               @click="open = false"
               @mouseenter="active = idx"
               :class="active === idx ? '{{ $gsItemActive }}' : ''"
               class="flex items-baseline justify-between gap-3 px-4 py-2.5 text-sm">
                <span class="font-semibold truncate {{ $gsDark ? 'text-white' : 'text-[#1F1B16]' }}" x-text="item.title"></span>
                <span class="shrink-0 text-xs {{ $gsMetaColor }}">
                    <span class="uppercase tracking-wide text-[10px] font-bold" x-text="item.type"></span>
                    <span x-show="item.hint" x-text="item.hint ? '· ' + item.hint : ''"></span>
                </span>
            </a>
        </template>
        <a :href="fullUrl()" @click="open = false"
           class="block px-4 py-2.5 text-sm font-semibold text-brand border-t mt-2 {{ $gsDark ? 'border-[#1F2636]' : 'border-brand/10' }}">
            Все результаты по запросу «<span x-text="q.trim()"></span>» →
        </a>
    </div>

    {{-- Мобильный полноэкранный оверлей поиска (открывается иконкой-лупой) --}}
    <div x-show="mobileOpen" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="md:hidden fixed inset-0 z-50 flex flex-col {{ $gsDark ? 'bg-[#0A0D14]/98' : 'bg-[#FAF8F5]' }}"
         @click.self="mobileOpen = false">
        <div class="flex items-center gap-2 px-4 py-3 border-b {{ $gsPanelBg }}">
            <form action="/poisk" method="GET" class="flex-1 flex items-center gap-2 px-3 py-2 rounded-xl border {{ $gsFieldBg }}" @submit.prevent="goFull()">
                <svg class="w-4 h-4 shrink-0 {{ $gsMetaColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="search" name="q" x-ref="inputMobile" x-model="q"
                       autocomplete="off" spellcheck="false"
                       placeholder="Курсы, статьи, преподаватели…"
                       aria-label="Поиск по сайту"
                       @focus="lookup()"
                       @input.debounce.150ms="lookup()"
                       @keydown="onKeydown($event)"
                       class="flex-1 bg-transparent text-base outline-none {{ $gsInputText }}">
            </form>
            <button type="button" @click="mobileOpen = false" aria-label="Закрыть поиск"
                    class="w-10 h-10 flex items-center justify-center rounded-lg {{ $gsIconIdle }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="flex-1 overflow-auto py-2">
            <template x-for="(item, idx) in results" :key="'m' + idx">
                <a :href="item.url" @click="mobileOpen = false"
                   :class="active === idx ? '{{ $gsItemActive }}' : ''"
                   class="flex items-baseline justify-between gap-3 px-4 py-3 text-sm">
                    <span class="font-semibold {{ $gsDark ? 'text-white' : 'text-[#1F1B16]' }}" x-text="item.title"></span>
                    <span class="shrink-0 text-xs {{ $gsMetaColor }}">
                        <span class="uppercase tracking-wide text-[10px] font-bold" x-text="item.type"></span>
                        <span x-show="item.hint" x-text="item.hint ? '· ' + item.hint : ''"></span>
                    </span>
                </a>
            </template>
            <button type="button" x-show="q.trim().length >= 2" x-cloak
                    @click="goFull()"
                    class="w-full text-left px-4 py-3 text-sm font-semibold text-brand">
                Все результаты по запросу «<span x-text="q.trim()"></span>» →
            </button>
        </div>
    </div>
</div>
