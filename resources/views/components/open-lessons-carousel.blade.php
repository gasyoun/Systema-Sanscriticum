@props([
    'lessons' => null,
    'title' => 'Бесплатные беседы вокруг санскрита',
    'subtitle' => 'Бесплатные уроки и записи вебинаров. Посмотрите прямо здесь — без перехода на YouTube.',
])

@php
    $lessons = $lessons ?? collect();

    // Форматирует duration_seconds в "h:mm:ss" или "m:ss". Null/0 → null (бейдж не показывается).
    $fmtDuration = static function (?int $s): ?string {
        if ($s === null || $s <= 0) {
            return null;
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $sec)
            : sprintf('%d:%02d', $m, $sec);
    };
@endphp

@if($lessons && $lessons->count() > 0)
    @php
        // Готовим карточки. На каждый урок собираем список доступных плееров
        // (YouTube и/или RuTube). Если оба — модалка сначала покажет выбор
        // (или, если ранее уже выбирали — сразу открывает запомненный).
        $cards = $lessons->values()->map(function ($lesson) use ($fmtDuration) {
            $options = [];

            if ($lesson->youtube_url && ($e = \App\Support\VideoEmbed::embed($lesson->youtube_url))) {
                $options[] = ['platform' => 'youtube', 'label' => 'YouTube', 'embed' => $e];
            }
            if ($lesson->rutube_url && ($e = \App\Support\VideoEmbed::embed($lesson->rutube_url))) {
                $options[] = ['platform' => 'rutube', 'label' => 'RuTube', 'embed' => $e];
            }
            // Generic fallback — старое video_url, если ни YT, ни RT не заданы.
            if (empty($options) && $lesson->video_url && ($e = \App\Support\VideoEmbed::embed($lesson->video_url))) {
                $options[] = ['platform' => 'video', 'label' => 'Смотреть', 'embed' => $e];
            }

            if (empty($options)) {
                return null;
            }

            // Постер — приоритет YouTube; иначе попробуем video_url.
            $posterSrc = $lesson->youtube_url ?: $lesson->video_url ?: $lesson->rutube_url;

            return [
                'id'       => $lesson->id,
                'title'    => $lesson->title,
                'course'   => $lesson->course?->title,
                'options'  => $options,
                'poster'   => \App\Support\VideoEmbed::poster($posterSrc),
                'duration' => $fmtDuration($lesson->duration_seconds),
            ];
        })->filter()->values()
          // Нумерация считается ПОСЛЕ фильтрации — чтобы карточки без распознанных ссылок не оставляли «дыры».
          ->map(fn ($card, $i) => $card + ['number' => $i + 1]);

        if ($cards->isEmpty()) {
            // Все ролики без распознанной ссылки — лучше не рендерить пустую секцию.
            return;
        }

        $perPage   = 3;
        $chunks    = $cards->chunk($perPage)->values();
        $pageCount = $chunks->count();
        $hasSlider = $pageCount > 1;

        // «Умный» слайдер: гостю доступны только первые $guestFreePages страниц
        // карусели. Попытка листнуть дальше открывает модалку с приглашением войти.
        $isAuthed       = auth()->check();
        $guestFreePages = 2;
    @endphp

    <section class="py-12 md:py-16"
             x-data="{
                videoOpen: false,
                embedUrl: '',
                chooserOptions: [],
                slide: 0,
                pages: {{ $pageCount }},
                /* Авторизация и лимит страниц для гостя. */
                authed: {{ $isAuthed ? 'true' : 'false' }},
                freePages: {{ $guestFreePages }},
                authPromptOpen: false,
                /* Сколько страниц реально доступно текущему пользователю. */
                get maxSlide() { return this.authed ? this.pages : Math.min(this.freePages, this.pages) },
                /* Есть ли заблокированные за авторизацией страницы. */
                get hasLocked() { return !this.authed && this.pages > this.maxSlide },
                requireAuth() {
                    this.authPromptOpen = true;
                    this.$nextTick(() => { if (this.$refs.authEmail) this.$refs.authEmail.focus() });
                },
                /* Встроенная форма входа (POST shop.login, как в shop-login-modal).
                   CSRF-токен вшит в компонент — на главной нет meta[csrf-token]. */
                authForm: { email: '', password: '', remember: false },
                authLoading: false,
                authError: null,
                csrf: '{{ csrf_token() }}',
                async submitLogin() {
                    this.authLoading = true;
                    this.authError = null;
                    try {
                        const res = await fetch(@js(route('shop.login')), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify(this.authForm),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok || !data.success) {
                            this.authError = data.message
                                || (data.errors && Object.values(data.errors)[0]?.[0])
                                || 'Не удалось войти. Проверьте данные.';
                            this.authLoading = false;
                            return;
                        }
                        window.location.reload();
                    } catch (e) {
                        this.authError = 'Ошибка сети. Попробуйте еще раз.';
                        this.authLoading = false;
                    }
                },
                /* Запоминаем выбор плеера на год — на повторное открытие сразу запускаем нужный без диалога. */
                cookieName: 'preferredVideoPlatform',
                getPref() {
                    /* split вместо regex — чтобы не плодить экранирование \\\\s в Blade-атрибуте. */
                    const prefix = this.cookieName + '=';
                    const pair = document.cookie.split(';').map(s => s.trim()).find(s => s.startsWith(prefix));
                    return pair ? decodeURIComponent(pair.substring(prefix.length)) : null;
                },
                savePref(platform) {
                    const d = new Date();
                    d.setTime(d.getTime() + 365 * 24 * 60 * 60 * 1000);
                    document.cookie = this.cookieName + '=' + encodeURIComponent(platform) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
                },
                open(options) {
                    if (!options || options.length === 0) return;
                    if (options.length === 1) {
                        this.embedUrl = options[0].embed;
                        this.chooserOptions = [];
                    } else {
                        const pref = this.getPref();
                        const match = pref ? options.find(o => o.platform === pref) : null;
                        if (match) {
                            this.embedUrl = match.embed;
                            this.chooserOptions = [];
                        } else {
                            this.chooserOptions = options;
                            this.embedUrl = '';
                        }
                    }
                    this.videoOpen = true;
                },
                pick(opt) {
                    this.savePref(opt.platform);
                    this.embedUrl = opt.embed;
                    this.chooserOptions = [];
                },
                close() {
                    this.videoOpen = false;
                    this.embedUrl = '';
                    this.chooserOptions = [];
                },
                /* Листание остаётся в пределах доступных страниц; на границе
                   заблокированной зоны — модалка авторизации вместо перехода. */
                prev() { this.slide = (this.slide - 1 + this.maxSlide) % this.maxSlide },
                next() {
                    if (this.slide + 1 < this.maxSlide) { this.slide++; return }
                    if (this.hasLocked) { this.requireAuth(); return }
                    this.slide = (this.slide + 1) % this.maxSlide;
                },
                goTo(i) {
                    if (i < this.maxSlide) { this.slide = i; return }
                    this.requireAuth();
                }
             }"
             @keydown.escape.window="close(); authPromptOpen = false">

        <div class="container mx-auto px-4 max-w-7xl">

            <div class="flex items-end justify-between mb-8 flex-wrap gap-4">
                <div>
                    <h2 class="text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                        {{ $title }}
                    </h2>
                    <div class="w-12 h-1 bg-[#E85C24] mt-3 mb-3 rounded-full"></div>
                    <p class="text-gray-300 text-sm md:text-base max-w-xl">
                        {{ $subtitle }}
                    </p>
                </div>
                @if($hasSlider)
                    <div class="flex items-center gap-2">
                        <button type="button"
                                @click="prev()"
                                aria-label="Предыдущие занятия"
                                class="w-9 h-9 rounded-full border bg-[#161b28] border-gray-700/60 text-white hover:border-[#E85C24]/60 hover:text-[#E85C24] flex items-center justify-center transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <button type="button"
                                @click="next()"
                                aria-label="Следующие занятия"
                                class="w-9 h-9 rounded-full border bg-[#161b28] border-gray-700/60 text-white hover:border-[#E85C24]/60 hover:text-[#E85C24] flex items-center justify-center transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </div>
                @endif
            </div>

            <div @if($hasSlider) class="overflow-hidden" @endif>
                <div @if($hasSlider) class="flex transition-transform duration-500 ease-out" x-bind:style="`transform: translateX(-${slide * 100}%)`" @endif>
                    @foreach($chunks as $chunk)
                        <div @if($hasSlider) class="w-full shrink-0 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6" @else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6" @endif>
                            @foreach($chunk as $card)
                                <button type="button"
                                        @click="open(@js($card['options']))"
                                        class="group flex flex-col text-left bg-[#161b28] border border-gray-700/60 hover:border-[#E85C24]/60 rounded-xl overflow-hidden transition-all duration-300 hover:-translate-y-0.5 group-hover:shadow-[0_0_25px_rgba(232,92,36,0.15)]">

                                    <div class="relative aspect-video bg-gray-800 overflow-hidden">
                                        @if($card['poster'])
                                            <img src="{{ $card['poster'] }}"
                                                 alt="{{ $card['title'] }}"
                                                 loading="lazy"
                                                 class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 ease-in-out">
                                        @else
                                            <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-[#1a2133] to-[#0d1119] text-gray-500">
                                                <svg class="w-10 h-10 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                                </svg>
                                            </div>
                                        @endif

                                        {{-- Play-кнопка по центру; затемнение только на hover, чтобы постер был ярким. --}}
                                        <div class="absolute inset-0 flex items-center justify-center bg-transparent group-hover:bg-black/30 transition-colors">
                                            <span class="w-14 h-14 rounded-full bg-[#E85C24] flex items-center justify-center shadow-[0_0_25px_rgba(232,92,36,0.55)] transform transition-transform group-hover:scale-110">
                                                <svg class="w-6 h-6 text-white ml-1" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M8 5v14l11-7z"/>
                                                </svg>
                                            </span>
                                        </div>

                                        {{-- Бейдж порядкового номера (#1 — самая свежая беседа). --}}
                                        <span class="absolute top-2 left-2 px-2 py-0.5 text-xs font-bold text-white bg-black/70 backdrop-blur-sm rounded-md">
                                            #{{ $card['number'] }}
                                        </span>

                                        {{-- Бейдж длительности — только если админ её заполнил. --}}
                                        @if($card['duration'])
                                            <span class="absolute bottom-2 right-2 px-2 py-0.5 text-xs font-semibold text-white bg-black/70 backdrop-blur-sm rounded-md tabular-nums">
                                                {{ $card['duration'] }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="p-4 md:p-5 flex flex-col flex-grow">
                                        @if($card['course'])
                                            <p class="text-[#2AABEE] text-xs font-bold uppercase tracking-wider mb-1.5">
                                                {{ $card['course'] }}
                                            </p>
                                        @endif
                                        <h3 class="font-bold text-white text-base leading-tight line-clamp-2 group-hover:text-[#E85C24] transition-colors">
                                            {{ $card['title'] }}
                                        </h3>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            @if($hasSlider)
                <div class="flex items-center justify-center gap-2 mt-6">
                    @for($i = 0; $i < $pageCount; $i++)
                        <button type="button"
                                @click="goTo({{ $i }})"
                                x-bind:aria-label="{{ $i }} >= maxSlide ? 'Страница {{ $i + 1 }} — нужна авторизация' : 'Страница {{ $i + 1 }}'"
                                class="h-2 rounded-full transition-all duration-300"
                                x-bind:class="slide === {{ $i }}
                                    ? 'w-6 bg-[#E85C24]'
                                    : ({{ $i }} >= maxSlide ? 'w-2 bg-gray-600/40 ring-1 ring-[#E85C24]/40' : 'w-2 bg-gray-600/70')"></button>
                    @endfor
                </div>
            @endif

        </div>

        {{-- Модалка: единый flex-контейнер, бэкдроп ловим через @click.self. --}}
        {{-- Внутри либо chooser, либо iframe; крестик абсолютно поверх. --}}
        <template x-teleport="body">
            <div x-show="videoOpen"
                 style="display: none;"
                 @click.self="close()"
                 class="fixed inset-0 z-[9999] flex items-center justify-center bg-[#101010]/90 backdrop-blur-md p-4 sm:p-6"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0">

                {{-- Крестик закрытия — фиксирован в углу viewport, выше плеера, всегда виден --}}
                <button type="button"
                        @click="close()"
                        aria-label="Закрыть"
                        class="fixed top-4 right-4 md:top-6 md:right-6 w-11 h-11 bg-white/15 hover:bg-[#E85C24] text-white rounded-full flex items-center justify-center backdrop-blur-sm transition-colors z-[10000]">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                {{-- 1. Chooser — компактная карточка с выбором плеера --}}
                <template x-if="!embedUrl && chooserOptions.length > 0">
                    <div class="relative w-full max-w-sm bg-[#161b28] border border-gray-700/60 rounded-2xl p-5 shadow-2xl">
                        <h3 class="text-white text-base font-bold text-center mb-1">
                            В каком плеере смотреть?
                        </h3>
                        <p class="text-gray-400 text-xs text-center mb-4">
                            Доступно на двух платформах
                        </p>

                        <div class="flex flex-wrap items-center justify-center gap-2">
                            <template x-for="opt in chooserOptions" :key="opt.platform">
                                <button type="button"
                                        x-on:click="pick(opt)"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-white rounded-lg transition-all duration-150 hover:brightness-110 active:scale-95"
                                        :style="{
                                            backgroundColor: opt.platform === 'youtube' ? '#FF0000' : (opt.platform === 'rutube' ? '#000000' : '#E85C24'),
                                            boxShadow: opt.platform === 'youtube' ? '0 4px 14px rgba(255,0,0,0.4)' : (opt.platform === 'rutube' ? '0 4px 14px rgba(0,0,0,0.6)' : 'none'),
                                            border: opt.platform === 'rutube' ? '1px solid rgba(255,255,255,0.15)' : 'none'
                                        }">
                                    <template x-if="opt.platform === 'youtube'">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                                        </svg>
                                    </template>
                                    <template x-if="opt.platform === 'rutube'">
                                        <svg class="w-4 h-4 text-[#fc233f]" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </template>
                                    <template x-if="opt.platform !== 'youtube' && opt.platform !== 'rutube'">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z"/>
                                        </svg>
                                    </template>
                                    <span x-text="opt.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- 2. iframe плеера — только когда embedUrl выставлен --}}
                <template x-if="!!embedUrl">
                    <div class="relative w-full max-w-5xl bg-black rounded-2xl md:rounded-[2rem] overflow-hidden shadow-2xl aspect-video">
                        <iframe :src="embedUrl"
                                class="w-full h-full border-0 absolute inset-0"
                                allow="autoplay; encrypted-media; fullscreen; picture-in-picture"
                                allowfullscreen></iframe>
                    </div>
                </template>

            </div>
        </template>

        {{-- Модалка «нужна авторизация» — для гостей при попытке листать дальше. --}}
        <template x-teleport="body">
            <div x-show="authPromptOpen"
                 style="display: none;"
                 @click.self="authPromptOpen = false"
                 class="fixed inset-0 z-[9999] flex items-center justify-center bg-[#101010]/90 backdrop-blur-md p-4 sm:p-6"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0">

                <div class="relative w-full max-w-md bg-[#161b28] border border-gray-700/60 rounded-2xl p-6 sm:p-7 shadow-2xl"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100">

                    <button type="button"
                            @click="authPromptOpen = false"
                            aria-label="Закрыть"
                            class="absolute top-3 right-3 w-9 h-9 bg-white/10 hover:bg-[#E85C24] text-white rounded-full flex items-center justify-center transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    <div class="text-center">
                        <span class="mx-auto mb-4 flex w-14 h-14 items-center justify-center rounded-full bg-[#E85C24]/15 text-[#E85C24]">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </span>
                        <h3 class="text-white text-lg font-extrabold mb-1.5">
                            Войдите, чтобы смотреть дальше
                        </h3>
                        <p class="text-gray-400 text-xs leading-relaxed mb-5">
                            Первые беседы открыты всем. Остальные записи — после входа в кабинет.<br>
                            <span class="text-gray-500">Уже покупали наши курсы? Напишите куратору — он свяжет прежние доступы с вашим аккаунтом.</span>
                        </p>
                    </div>

                    <form @submit.prevent="submitLogin" class="space-y-5 text-left">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2 uppercase tracking-wider">Email</label>
                            <input type="email" x-ref="authEmail" x-model="authForm.email" required autocomplete="email"
                                   placeholder="you@example.com"
                                   class="block w-full rounded-xl bg-[#0A0D14] border border-gray-700/60 text-white placeholder-slate-600 focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] py-3 px-4 transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2 uppercase tracking-wider">Пароль</label>
                            <input type="password" x-model="authForm.password" required autocomplete="current-password"
                                   placeholder="••••••••"
                                   class="block w-full rounded-xl bg-[#0A0D14] border border-gray-700/60 text-white placeholder-slate-600 focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] py-3 px-4 transition">
                        </div>

                        <template x-if="authError">
                            <div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm rounded-xl px-4 py-3"
                                 x-text="authError"></div>
                        </template>

                        <label class="flex items-center gap-2 text-sm text-slate-400 cursor-pointer select-none">
                            <input type="checkbox" x-model="authForm.remember"
                                   class="rounded border-gray-700/60 bg-[#0A0D14] text-[#E85C24] focus:ring-[#E85C24]">
                            Запомнить меня
                        </label>

                        <button type="submit" x-bind:disabled="authLoading"
                                class="w-full flex justify-center items-center py-3.5 px-4 bg-[#E85C24] hover:bg-[#d04a15] disabled:opacity-60 disabled:cursor-not-allowed text-white text-base font-bold rounded-xl transition-all shadow-lg shadow-[#E85C24]/20">
                            <span x-show="!authLoading">Войти</span>
                            <span x-show="authLoading" x-cloak>
                                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                            </span>
                        </button>

                        <div class="text-center text-xs text-slate-500 pt-1">
                            Забыли пароль?
                            <a href="{{ route('password.request') }}" class="text-[#38BDF8] hover:underline font-semibold">Сбросить по email</a>
                        </div>
                    </form>
                </div>
            </div>
        </template>

    </section>
@endif
