@extends('layouts.student')

@section('title', 'Личный кабинет')
@section('header', 'Личный кабинет')

@section('content')

{{-- activeTab: #prana / #debts с fail-redirect оплаты долга (DebtPaymentController → #debts) --}}
<div x-data="{ activeTab: (window.location.hash === '#prana' ? 'prana' : (window.location.hash === '#debts' ? 'debts' : 'courses')) }" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <div class="mb-6 mt-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h2 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">Добро пожаловать, {{ auth()->user()->name }}!</h2>
            <p class="text-gray-500 text-lg">Управляйте своим обучением, материалами и оплатами.</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 shrink-0">
            <a href="{{ route('student.help') }}"
               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-bold hover:border-brand hover:text-brand transition-colors shadow-sm">
                <i class="fas fa-book-open"></i> Как пользоваться
            </a>
            <a href="{{ route('student.cabinet-mastery') }}"
               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-bold hover:border-brand hover:text-brand transition-colors shadow-sm">
                <i class="fas fa-clipboard-check"></i> Проверить кабинет
            </a>
            <button type="button" x-on:click="$dispatch('open-change-password')"
                    class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-700 text-sm font-bold hover:border-brand hover:text-brand transition-colors shadow-sm">
                <i class="fas fa-key"></i> Сменить пароль
            </button>
        </div>
    </div>

    @include('student.partials.onboarding-checklist')

    @include('student.partials.homework-alerts')

    {{-- session('error'|'success') — self-service долг, CSRF 419, перенос даты.
         До фикса flash терялся: DebtPaymentController писал error, а кабинет
         показывал только password_status / bot_status → «кнопка ничего не делает». --}}
    @if (session('error'))
        <div x-data="{ show: true }" x-show="show" role="alert"
             class="mb-6 flex items-center justify-between gap-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
            <span><i class="fas fa-triangle-exclamation mr-1.5"></i>{{ session('error') }}</span>
            <button type="button" x-on:click="show = false" class="text-red-500 hover:text-red-700" aria-label="Закрыть"><i class="fas fa-times"></i></button>
        </div>
    @endif

    @if (session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)" role="status"
             class="mb-6 flex items-center justify-between gap-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3">
            <span><i class="fas fa-check-circle mr-1.5"></i>{{ session('success') }}</span>
            <button type="button" x-on:click="show = false" class="text-green-500 hover:text-green-700" aria-label="Закрыть"><i class="fas fa-times"></i></button>
        </div>
    @endif

    @if (session('password_status'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
             class="mb-6 flex items-center justify-between gap-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3">
            <span><i class="fas fa-check-circle mr-1.5"></i>{{ session('password_status') }}</span>
            <button type="button" x-on:click="show = false" class="text-green-500 hover:text-green-700"><i class="fas fa-times"></i></button>
        </div>
    @endif

    @if (session('bot_status'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
             class="mb-6 flex items-center justify-between gap-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3">
            <span><i class="fas fa-check-circle mr-1.5"></i>{{ session('bot_status') }}</span>
            <button type="button" x-on:click="show = false" class="text-green-500 hover:text-green-700"><i class="fas fa-times"></i></button>
        </div>
    @endif

    {{-- H1680 — Wave 2: onboarding-from-games SRS import. Fires once per
         browser (localStorage dedupe key below) on first dashboard load
         after login/registration; no-op server-side while srs.enabled is
         OFF or this guest never played /lila. --}}
    @if (config('srs.enabled'))
        <div x-data="{ show: false, count: 0 }" x-show="show" x-cloak
             x-init="setTimeout(() => show = false, 6000)"
             x-on:srs-onboarding-imported.window="count = $event.detail.count; show = true"
             class="mb-6 flex items-center justify-between gap-3 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3">
            <span><i class="fas fa-check-circle mr-1.5"></i>Добавили <span x-text="count"></span> слов в повторения</span>
            <button type="button" x-on:click="show = false" class="text-green-500 hover:text-green-700"><i class="fas fa-times"></i></button>
        </div>
        <script>
        (function () {
            try {
                var ANON_KEY = 'sgx_anon_v1';
                var DONE_KEY = 'sgx_srs_onboarding_done_v1';
                var anonId = localStorage.getItem(ANON_KEY);
                if (!anonId || localStorage.getItem(DONE_KEY)) return;

                fetch('{{ route('games.srs-onboarding-import') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({ anon_id: anonId }),
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (data) {
                    localStorage.setItem(DONE_KEY, '1');
                    if (data && data.imported > 0) {
                        window.dispatchEvent(new CustomEvent('srs-onboarding-imported', { detail: { count: data.imported } }));
                    }
                }).catch(function () {});
            } catch (e) {}
        })();
        </script>
    @endif

    @include('student.partials.continue-learning-card')

    @include('student.partials.waitlist-card')

    @include('student.partials.subscriber-shelf')

    {{-- ========================================== --}}
    {{-- МОДАЛКА СМЕНЫ ПАРОЛЯ                        --}}
    {{-- ========================================== --}}
    <div x-data="{ open: false }"
         x-on:open-change-password.window="open = true"
         x-on:keydown.escape.window="open = false"
         x-show="open" x-cloak
         class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
         x-on:click.self="open = false">

        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden relative"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">
            <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

            <div class="flex items-center justify-between px-6 pt-6 pb-4">
                <h3 class="text-lg font-extrabold text-gray-900">Смена пароля</h3>
                <button type="button" x-on:click="open = false"
                        class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 transition-colors text-gray-400">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form action="{{ route('student.password.update') }}" method="POST" class="px-6 pb-6 space-y-4">
                @csrf

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="current_password">Текущий пароль</label>
                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                           placeholder="••••••••">
                    @error('current_password')
                        <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="new_password">Новый пароль</label>
                    <input type="password" name="password" id="new_password" required autocomplete="new-password"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                           placeholder="Минимум 8 символов">
                    @error('password')
                        <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="password_confirmation">Повторите новый пароль</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                           placeholder="••••••••">
                </div>

                <button type="submit"
                        class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-3 px-4 rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl text-sm uppercase tracking-wider">
                    Сохранить
                </button>
            </form>
        </div>
    </div>

    @if ($errors->has('current_password') || $errors->has('password'))
        <div x-init="$dispatch('open-change-password')"></div>
    @endif
    
    {{-- ========================================== --}}
{{-- УВЕДОМЛЕНИЕ О НАПОЛНЕНИИ КАБИНЕТА          --}}
{{-- ========================================== --}}
<div x-data="{
        show: localStorage.getItem('cabinet_notice_v1') !== 'dismissed',
        expanded: false,
        dismiss() {
            this.show = false;
            localStorage.setItem('cabinet_notice_v1', 'dismissed');
        }
     }"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 -translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 -translate-y-2"
     class="mb-8 relative bg-gradient-to-r from-orange-50 via-amber-50 to-orange-50 border border-brand/20 rounded-2xl p-5 md:p-6 shadow-[0_4px_20px_rgba(232,92,36,0.06)] overflow-hidden">
    
    {{-- Декоративное свечение --}}
    <div class="absolute top-0 right-0 w-40 h-40 bg-brand blur-[60px] opacity-10 rounded-full -mr-10 -mt-10 pointer-events-none"></div>
    
    <div class="flex items-start gap-4 relative z-10">
        {{-- Иконка --}}
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-brand to-orange-500 text-white flex items-center justify-center shrink-0 shadow-[0_4px_14px_rgba(232,92,36,0.3)]">
            <i class="fas fa-tools text-lg"></i>
        </div>
        
        {{-- Контент --}}
        <div class="flex-1 min-w-0 pr-8">
            <h3 class="text-base md:text-lg font-extrabold text-gray-900 mb-1.5 leading-tight">
                Кабинет находится на стадии наполнения
            </h3>
            {{-- На мобиле текст свёрнут (line-clamp-2) с тумблером «Подробнее»;
                 на sm+ всегда показан целиком, экономит вертикаль на телефоне. --}}
            <p class="text-sm text-gray-600 leading-relaxed"
               :class="expanded ? '' : 'line-clamp-2 sm:line-clamp-none'">
                Уважаемые студенты! В данный момент мы активно работаем над загрузкой всех материалов.
                Некоторые ваши курсы могут быть временно недоступны, а в открытых курсах часть уроков
                еще может загружаться. Приносим искренние извинения за временные неудобства —
                все материалы появятся в ближайшее время.
            </p>
            <button type="button" @click="expanded = !expanded"
                    class="sm:hidden mt-1 text-xs font-bold text-brand hover:underline"
                    x-text="expanded ? 'Свернуть' : 'Подробнее'"></button>
        </div>
        
        {{-- Кнопка закрытия --}}
        <button @click="dismiss()" 
                type="button"
                class="absolute top-0 right-0 w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-white/60 transition-all"
                aria-label="Закрыть уведомление">
            <i class="fas fa-times text-sm"></i>
        </button>
    </div>
</div>
    
    {{-- ========================================== --}}
    {{-- БЛОКИ БОТОВ (В ОДИН РЯД)                   --}}
    {{-- Видимость каждого блока — отдельный тумблер  --}}
    {{-- в админке (MarketingSetting → «Боты-кураторы»)--}}
    {{-- ========================================== --}}
    @include('student.partials.access-profile-summary', [
        'summary' => $accessProfileSummary ?? null,
        'enabled' => $accessSelfService ?? false,
    ])

    @php
        $marketingSettings = \App\Models\MarketingSetting::cached();
        $showTelegramBot = (bool) $marketingSettings?->student_telegram_bot_enabled;
        $showVkBot = (bool) $marketingSettings?->student_vk_bot_enabled;
    @endphp
    @if($showTelegramBot || $showVkBot)
    <div class="grid grid-cols-1 {{ ($showTelegramBot && $showVkBot) ? 'lg:grid-cols-2' : '' }} gap-6 mb-8">

        @if($showTelegramBot)
        @php $tgConnected = auth()->user() && auth()->user()->telegram_id; @endphp
        {{-- УМНЫЙ БЛОК TELEGRAM --}}
        <div class="h-full bg-white rounded-2xl p-5 md:p-6 border border-blue-50 shadow-[0_4px_20px_rgba(2,132,199,0.06)] flex flex-col xl:flex-row items-start xl:items-center justify-between gap-5 relative overflow-hidden">
            {{-- Декоративный фон --}}
            <div class="absolute top-0 right-0 w-40 h-40 bg-blue-400 blur-[60px] opacity-10 rounded-full -mr-10 -mt-10 pointer-events-none"></div>

            <div class="flex items-start gap-4 relative z-10 min-w-0">
                <div class="w-14 h-14 rounded-[1rem] bg-gradient-to-br from-blue-50 to-blue-100 text-[#0088cc] flex items-center justify-center shrink-0 border border-blue-200 shadow-inner">
                    {{-- Иконка Telegram (SVG) --}}
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.07-.19-.08-.05-.19-.02-.27 0-.12.03-1.96 1.25-5.54 3.67-.52.36-.99.54-1.41.53-.46-.01-1.35-.26-2.01-.48-.81-.27-1.46-.42-1.4-.88.03-.22.35-.45.96-.69 3.75-1.64 6.25-2.72 7.5-3.24 3.56-1.49 4.3-1.74 4.78-1.75.11 0 .35.03.48.14.11.08.15.22.14.36z"/></svg>
                </div>
                <div class="min-w-0">
                    @if($tgConnected)
                        <h3 class="text-lg font-bold text-gray-900 leading-tight mb-1">Telegram-бот ОРС</h3>
                        <p class="text-sm text-emerald-600 font-bold flex items-center">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Бот подключен — уведомления по учебе приходят
                        </p>
                        <form method="POST" action="{{ route('student.messenger.disconnect', 'telegram') }}"
                              onsubmit="return confirm('Отвязать Telegram-бота? Уведомления по учебе перестанут приходить. Подключить заново можно в любой момент.');"
                              class="mt-1.5">
                            @csrf
                            <button type="submit" class="text-xs font-semibold text-gray-400 hover:text-red-500 transition-colors">
                                Отвязать
                            </button>
                        </form>
                    @else
                        <h3 class="text-lg font-bold text-gray-900 leading-tight mb-1">Включите учебные уведомления</h3>
                        <p class="text-sm text-gray-500 font-medium mb-3">Бот пишет <span class="font-bold text-gray-700">только по делу</span> — никаких рекламных рассылок.</p>
                        <ul class="space-y-1.5 text-sm text-gray-600">
                            <li class="flex items-start gap-2"><i class="fas fa-bolt text-[#0088cc] mt-0.5 w-4 text-center shrink-0"></i><span>Доступ к урокам — сразу после оплаты, без ожидания</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-robot text-[#0088cc] mt-0.5 w-4 text-center shrink-0"></i><span>ИИ-куратор отвечает на вопросы об учебе круглосуточно</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-bell text-[#0088cc] mt-0.5 w-4 text-center shrink-0"></i><span>Напоминания о занятиях и сроках оплат</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-comments text-[#0088cc] mt-0.5 w-4 text-center shrink-0"></i><span>Ответы живого куратора прямо в Telegram</span></li>
                        </ul>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-2.5 py-1.5">
                            <i class="fas fa-shield-halved"></i>
                            Никакой рекламы и спама. Отписаться можно в один клик.
                        </p>
                    @endif
                </div>
            </div>

            @unless($tgConnected)
                {{-- H3313: привязка через CSRF-защищённый POST --}}
                <form method="POST" action="{{ route('telegram.connect.start') }}" target="_blank"
                      class="relative z-10 shrink-0 flex items-center w-full xl:w-auto justify-center">
                    @csrf
                    <button type="submit" class="px-6 py-3.5 bg-[#0088cc] hover:bg-[#0077b5] text-white text-sm font-extrabold rounded-xl transition-all duration-300 shadow-[0_4px_14px_rgba(0,136,204,0.3)] hover:shadow-[0_6px_20px_rgba(0,136,204,0.4)] hover:-translate-y-0.5 w-full xl:w-auto">
                        Подключить за 10 секунд
                    </button>
                </form>
            @endunless
        </div>
        @endif

        @if($showVkBot)
        @php $vkConnected = auth()->user() && auth()->user()->vk_id; @endphp
        {{-- УМНЫЙ БЛОК ВК --}}
        <div class="h-full bg-white rounded-2xl p-5 md:p-6 border border-blue-50 shadow-[0_4px_20px_rgba(0,119,255,0.06)] flex flex-col xl:flex-row items-start xl:items-center justify-between gap-5 relative overflow-hidden">
            {{-- Декоративный фон (VK Blue) --}}
            <div class="absolute top-0 right-0 w-40 h-40 bg-[#0077FF] blur-[60px] opacity-10 rounded-full -mr-10 -mt-10 pointer-events-none"></div>

            <div class="flex items-start gap-4 relative z-10 min-w-0">
                <div class="w-14 h-14 rounded-[1rem] bg-gradient-to-br from-blue-50 to-blue-100 text-[#0077FF] flex items-center justify-center shrink-0 border border-blue-200 shadow-inner">
                    {{-- Иконка VK (SVG) --}}
                    <svg class="w-7 h-7" fill="currentColor" viewBox="0 0 24 24"><path d="M15.07 2H8.93C3.33 2 2 3.33 2 8.93v6.14C2 20.67 3.33 22 8.93 22h6.14c5.6 0 6.93-1.33 6.93-6.93V8.93C22 3.33 20.67 2 15.07 2zm3.33 13.91c.21.22.44.43.64.65.34.36.67.73.91 1.15.19.33.02.66-.35.66h-1.92c-.39 0-.7-.14-.95-.44-.35-.41-.7-.81-1.04-1.22-.19-.23-.39-.46-.62-.65-.24-.21-.49-.18-.68.08-.24.34-.31.73-.31 1.13 0 .4-.18.57-.59.57h-1.36c-1.63-.03-2.99-.59-4.14-1.74-1.63-1.63-2.65-3.66-3.41-5.83-.09-.25 0-.41.27-.41h1.96c.26 0 .42.14.51.39.54 1.53 1.25 2.94 2.37 4.1.18.18.35.18.47-.03.14-.26.21-.55.21-.85v-2.31c-.02-.4.18-.58.55-.58h1.07c.3 0 .43.12.48.42.04.18.04.38.04.57v1.85c0 .32.13.43.37.28.23-.15.42-.35.6-.55.45-.52.82-1.09 1.14-1.69.11-.2.25-.32.48-.32h1.9c.35 0 .47.16.38.48-.15.54-.42 1.03-.71 1.5-.39.63-.82 1.23-1.28 1.8-.26.33-.27.6 0 .93z"/></svg>
                </div>
                <div class="min-w-0">
                    @if($vkConnected)
                        <h3 class="text-lg font-bold text-gray-900 leading-tight mb-1">ВКонтакте-бот ОРС</h3>
                        <p class="text-sm text-emerald-600 font-bold flex items-center">
                            <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Бот подключен — уведомления по учебе приходят
                        </p>
                        <form method="POST" action="{{ route('student.messenger.disconnect', 'vk') }}"
                              onsubmit="return confirm('Отвязать ВК-бота? Уведомления по учебе перестанут приходить. Подключить заново можно в любой момент.');"
                              class="mt-1.5">
                            @csrf
                            <button type="submit" class="text-xs font-semibold text-gray-400 hover:text-red-500 transition-colors">
                                Отвязать
                            </button>
                        </form>
                    @else
                        <h3 class="text-lg font-bold text-gray-900 leading-tight mb-1">Включите учебные уведомления</h3>
                        <p class="text-sm text-gray-500 font-medium mb-3">Бот пишет <span class="font-bold text-gray-700">только по делу</span> — никаких рекламных рассылок.</p>
                        <ul class="space-y-1.5 text-sm text-gray-600">
                            <li class="flex items-start gap-2"><i class="fas fa-bolt text-[#0077FF] mt-0.5 w-4 text-center shrink-0"></i><span>Доступ к урокам — сразу после оплаты, без ожидания</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-robot text-[#0077FF] mt-0.5 w-4 text-center shrink-0"></i><span>ИИ-куратор отвечает на вопросы об учебе круглосуточно</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-bell text-[#0077FF] mt-0.5 w-4 text-center shrink-0"></i><span>Напоминания о занятиях и сроках оплат</span></li>
                            <li class="flex items-start gap-2"><i class="fas fa-comments text-[#0077FF] mt-0.5 w-4 text-center shrink-0"></i><span>Ответы живого куратора прямо во ВКонтакте</span></li>
                        </ul>
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-2.5 py-1.5">
                            <i class="fas fa-shield-halved"></i>
                            Никакой рекламы и спама. Отписаться можно в один клик.
                        </p>
                    @endif
                </div>
            </div>

            @unless($vkConnected)
                {{-- H3313: привязка через CSRF-защищённый POST --}}
                <form method="POST" action="{{ route('vk.connect.start') }}" target="_blank"
                      class="relative z-10 shrink-0 flex items-center w-full xl:w-auto justify-center">
                    @csrf
                    <button type="submit" class="px-6 py-3.5 bg-[#0077FF] hover:bg-[#005ce6] text-white text-sm font-extrabold rounded-xl transition-all duration-300 shadow-[0_4px_14px_rgba(0,119,255,0.3)] hover:shadow-[0_6px_20px_rgba(0,119,255,0.4)] hover:-translate-y-0.5 w-full xl:w-auto">
                        Подключить ВК-бота
                    </button>
                </form>
            @endunless
        </div>
        @endif

    </div>
    @endif

    {{-- НАВИГАЦИЯ ПО ВКЛАДКАМ (Премиум стиль) --}}
    {{-- relative + right-edge fade: на узких экранах подсказывает, что вкладки
         (Прана, Поддержка) скроллятся по горизонтали. --}}
    <div class="relative mb-10">
    {{-- data-track-*: baseline-телеметрия H962. Имя course.tab.view — из спеки §4
         (в гибриде это вкладки дома курса); surface=dashboard отличает нынешнюю
         поверхность — вкладки главной кабинета. --}}
    <div class="flex space-x-6 border-b border-gray-200 overflow-x-auto custom-scrollbar">
        <button @click="activeTab = 'courses'" data-track-event="course.tab.view" data-track-tab="courses" data-track-surface="dashboard"
                :class="activeTab === 'courses' ? 'text-brand border-b-2 border-brand font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'" 
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-graduation-cap mr-2"></i>Мои курсы
        </button>

        <button @click="activeTab = 'dictionaries'" data-track-event="course.tab.view" data-track-tab="dictionaries" data-track-surface="dashboard"
                :class="activeTab === 'dictionaries' ? 'text-brand border-b-2 border-brand font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'" 
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-book mr-2"></i>Словари
        </button>

        <button @click="activeTab = 'payments'" data-track-event="course.tab.view" data-track-tab="payments" data-track-surface="dashboard"
                :class="activeTab === 'payments' ? 'text-brand border-b-2 border-brand font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'"
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-wallet mr-2"></i>Мои оплаты
        </button>

        @if($debts->isNotEmpty())
            <button @click="activeTab = 'debts'" data-track-event="course.tab.view" data-track-tab="debts" data-track-surface="dashboard"
                    :class="activeTab === 'debts' ? 'text-brand border-b-2 border-brand font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'"
                    class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
                <i class="fas fa-exclamation-triangle mr-2"></i>Мои долги
                <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-bold text-white bg-brand rounded-full">{{ $debts->count() }}</span>
            </button>
        @endif

        @if(\App\Services\Prana\PranaSettings::isActive())
            <button @click="activeTab = 'prana'" data-track-event="course.tab.view" data-track-tab="prana" data-track-surface="dashboard"
                    :class="activeTab === 'prana' ? 'text-brand border-b-2 border-brand font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'"
                    class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
                <x-prana-lotus class="mr-2" />Прана
            </button>
        @endif

        <button @click="activeTab = 'chat'" data-track-event="course.tab.view" data-track-tab="chat" data-track-surface="dashboard"
                :class="activeTab === 'chat' ? 'text-brand border-b-2 border-brand font-bold' : 'text-gray-500 hover:text-gray-800 hover:border-gray-300'"
                class="pb-3 px-1 text-base md:text-lg whitespace-nowrap transition-all outline-none">
            <i class="fas fa-headset mr-2"></i>Поддержка
        </button>
    </div>
        <div class="pointer-events-none absolute top-0 right-0 bottom-px w-12 bg-gradient-to-l from-[#F4F1EA] to-transparent sm:hidden"></div>
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 1: МОИ КУРСЫ (Премиум карточки)    --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'courses'" 
         x-transition:enter="transition ease-out duration-300" 
         x-transition:enter-start="opacity-0 translate-y-4" 
         x-transition:enter-end="opacity-100 translate-y-0">

        @include('student.partials.hindi-programme-playlist-card', ['hindiPlaylist' => $hindiPlaylist ?? null])
         
        {{-- Сетка 1-2-3-4 колонки со сдвигом на шаг вверх: фиксированный сайдбар (280px)
             съедает ширину, и при lg:3/xl:4 карточки ужимались до ~200px — кнопки переносились. --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-6 mb-16">
            @php $loyaltyCtaCourseComplete = false; @endphp
            @forelse($courses as $course)
                <div class="bg-white rounded-2xl shadow-[0_2px_12px_rgba(0,0,0,0.04)] border border-gray-100 hover:shadow-[0_15px_35px_rgba(232,92,36,0.08)] hover:border-brand/30 hover:-translate-y-1 transition-all duration-300 flex flex-col h-full group overflow-hidden">
                    
                    {{-- Обложка курса --}}
                    <div class="h-44 bg-[#101010] relative overflow-hidden shrink-0">
                        {{-- Темный абстрактный фон с оранжевым свечением, если нет картинки --}}
                        <div class="absolute inset-0 bg-[#101010] group-hover:scale-105 transition-transform duration-700">
                            <div class="absolute top-0 right-0 w-32 h-32 bg-brand blur-[50px] opacity-40 rounded-full -mr-10 -mt-10"></div>
                            <div class="absolute bottom-0 left-0 w-24 h-24 bg-purple-500 blur-[40px] opacity-20 rounded-full -ml-10 -mb-10"></div>
                        </div>
                        
                        {{-- Мягкое затемнение снизу для читаемости бейджа --}}
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                        
                        <div class="absolute bottom-4 left-5">
                            <span class="px-2.5 py-1 bg-white/20 backdrop-blur-md text-white text-[10px] font-bold uppercase tracking-widest rounded border border-white/20">
                                Курс
                            </span>
                        </div>
                    </div>

                    {{-- Тело карточки --}}
                    <div class="p-6 flex-1 flex flex-col bg-white relative z-10">
                        <h3 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-brand transition-colors leading-snug line-clamp-2">
                            {{ $course->title }}
                        </h3>

                        {{-- H2333: continuation shells show “from lesson N” on the card --}}
                        @if (! empty($course->predecessor_course_id) && ! empty($course->continues_from_lesson))
                            <p class="text-xs text-sky-800/90 font-semibold mb-2" data-testid="dashboard-continuation-hint">
                                Записи с {{ (int) $course->continues_from_lesson }}-го · начало — в связанном курсе
                            </p>
                        @elseif (! empty($course->predecessor_course_id))
                            <p class="text-xs text-sky-800/90 font-semibold mb-2" data-testid="dashboard-continuation-hint">
                                Продолжение · начало в связанном курсе
                            </p>
                        @endif
                        
                        {{-- Если описание есть, выводим его. Иначе - не создаем пустую дыру --}}
                        @if(!empty($course->description))
                            <p class="text-gray-500 text-sm line-clamp-2 mb-4">
                                {{ $course->description }}
                            </p>
                        @else
                            <div class="mb-4"></div>
                        @endif

                        @php
                            $totalLessons = $course->lessons->count();
                            $completedLessons = auth()->user()->completedLessons->whereIn('id', $course->lessons->pluck('id'))->count();
                            $percent = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;
                            $nextLesson = ($nextLessonByCourseId ?? collect())->get($course->id);
                        @endphp

                        {{-- Блок прогресса прижат к низу карточки благодаря mt-auto --}}
                        <div class="mt-auto pt-4 border-t border-gray-50">
                            {{-- Прогресс-бар --}}
                            <div class="flex justify-between items-end mb-2">
                                <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Прогресс</span>
                                <span class="text-sm font-extrabold text-gray-800">{{ $percent }}%</span>
                            </div>
                            
                            <div class="bg-gray-100 rounded-full h-1.5 w-full overflow-hidden mb-3">
                                <div class="bg-brand h-full rounded-full transition-all duration-1000 relative" style="width: {{ $percent }}%"></div>
                            </div>

                            {{-- Подсказка «следующий урок»: куда ведёт кнопка. При 100% — курс пройден. --}}
                            @if($nextLesson)
                                <div class="flex items-start gap-1.5 mb-4 text-xs text-gray-500 leading-snug">
                                    <i class="fas fa-play-circle text-brand/70 mt-0.5 shrink-0"></i>
                                    <span class="line-clamp-1">
                                        <span class="text-gray-400">{{ $percent > 0 ? 'Следующий урок:' : 'Первый урок:' }}</span>
                                        <span class="font-semibold text-gray-700">{{ $nextLesson->title }}</span>
                                    </span>
                                </div>
                            @elseif($totalLessons > 0)
                                @php $loyaltyCtaCourseComplete = true; @endphp
                                <div class="flex items-center gap-1.5 mb-4 text-xs text-emerald-600 font-semibold">
                                    <i class="fas fa-check-circle shrink-0"></i>
                                    <span>Все уроки пройдены</span>
                                </div>
                            @else
                                <div class="mb-4"></div>
                            @endif

                            {{-- Баннер: при активном обещании показываем нейтральный, иначе оранжевый «долг» --}}
                            @if($debt = $debtsByCourseId->get($course->id))
                                @if($debt->promise_active)
                                    <div class="block w-full mb-3 px-3 py-2 bg-emerald-50 border border-emerald-200/70 rounded-lg">
                                        <div class="flex items-start gap-2">
                                            <i class="fas fa-handshake text-emerald-600 text-xs mt-0.5 shrink-0"></i>
                                            <div class="text-xs leading-snug">
                                                <span class="font-bold text-emerald-700">Договоренность:</span>
                                                <span class="text-gray-700">оплата до {{ $debt->promise->promised_at->format('d.m.Y') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <button @click.prevent="activeTab = 'debts'"
                                            class="block w-full mb-3 px-3 py-2 {{ $debt->promise_overdue ? 'bg-red-50 hover:bg-red-100 border-red-300' : 'bg-orange-50 hover:bg-orange-100 border-brand/30' }} border rounded-lg text-left transition-colors"
                                            title="Перейти в раздел «Мои долги»">
                                        <div class="flex items-start gap-2">
                                            <i class="fas {{ $debt->promise_overdue ? 'fa-clock text-red-600' : 'fa-exclamation-triangle text-brand' }} text-xs mt-0.5 shrink-0"></i>
                                            <div class="text-xs leading-snug flex-1">
                                                <div>
                                                    <span class="font-bold {{ $debt->promise_overdue ? 'text-red-700' : 'text-brand' }}">Не оплачено:</span>
                                                    <span class="text-gray-700">{{ $debt->debt_label }}</span>
                                                </div>
                                                @if($debt->debt_amount)
                                                    <div class="text-gray-500 mt-0.5">
                                                        {{ $debt->debt_amount_approximate ? '≈ ' : '' }}{{ number_format($debt->debt_amount, 0, '.', ' ') }} ₽
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </button>
                                @endif
                            @elseif($paidUntil = $paidUntilByCourseId->get($course->id))
                                {{-- Долга нет: позитивная сводка «оплачено до» + дедлайн следующего
                                     платежа (старт следующего блока, 00:00 МСК), а не только его отсутствие. --}}
                                <div class="block w-full mb-3 px-3 py-2 bg-emerald-50 border border-emerald-200/70 rounded-lg">
                                    <div class="flex items-start gap-2">
                                        <i class="fas fa-check-circle text-emerald-600 text-xs mt-0.5 shrink-0"></i>
                                        <div class="text-xs leading-snug">
                                            <span class="font-bold text-emerald-700">Оплачено до:</span>
                                            <span class="text-gray-700">блок №{{ $paidUntil->block->number }}</span>
                                            @if($paidUntil->block->ends_at)
                                                <span class="text-gray-500">(до {{ $paidUntil->block->ends_at->format('d.m.Y') }})</span>
                                            @endif
                                            @if(! empty($paidUntil->extra_paid_blocks))
                                                <span class="text-gray-700">, плюс блоки {{ $paidUntil->extra_paid_blocks_label }}</span>
                                            @endif
                                            @if($paidUntil->next_payment_deadline)
                                                <div class="mt-1">
                                                    <span class="font-bold text-brand">Следующий платеж до:</span>
                                                    <span class="text-gray-700">{{ $paidUntil->next_payment_deadline->format('d.m.Y') }}, 00:00 (МСК)</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Кнопка: при наличии следующего урока ведём прямо в него,
                                 иначе (всё пройдено / нет уроков) — на страницу курса. --}}
                            <a href="{{ $nextLesson ? route('student.lesson', [$course->slug, $nextLesson->id]) : route('student.course', $course->slug) }}" data-track-event="cabinet.continue.click" data-track-kind="{{ $nextLesson ? 'lesson' : 'course' }}" data-track-surface="course-row" class="flex items-center justify-center w-full px-4 py-2.5 bg-gray-50 text-gray-900 text-sm font-bold rounded-xl group-hover:bg-brand group-hover:text-white transition-all duration-300">
                                <span>@if($nextLesson)@if($percent > 0) Продолжить @else Начать обучение @endif @else К курсу @endif</span>
                                <i class="fas fa-arrow-right ml-2 text-xs opacity-50 group-hover:opacity-100 group-hover:translate-x-1 transition-all"></i>
                            </a>

                            {{-- Кнопка: Чат курса (если URL задан в админке) --}}
                            @if(!empty($course->chat_url))
                                <x-course-chat-button :url="$course->chat_url" class="w-full justify-center mt-2" />
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-16 bg-white rounded-[2rem] border border-dashed border-gray-200 shadow-sm">
                    <div class="w-20 h-20 mx-auto bg-gray-50 rounded-full flex items-center justify-center mb-4">
                        <i class="fas fa-books text-3xl text-gray-300"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Нет доступных курсов</h3>
                    <p class="text-gray-500 max-w-md mx-auto">Вам пока не назначены курсы. Перейдите в каталог, чтобы выбрать программу обучения.</p>
                </div>
            @endforelse
        </div>

        @if(config('features.referral_loyalty_cta') && $loyaltyCtaCourseComplete)
            <div class="mb-12" data-testid="referral-loyalty-cta-course-complete">
                @include('student.partials.referral')
            </div>
        @endif

        {{-- ПРОБНЫЕ ЗАНЯТИЯ (оплачено пробное / разовый доступ к уроку) --}}
        @if(!empty($trialLessons) && $trialLessons->isNotEmpty())
        <div class="mb-16">
            <h3 class="text-2xl font-extrabold text-gray-900 mb-6 flex items-center">
                <i class="fas fa-graduation-cap text-[#38BDF8] mr-3"></i>Пробные занятия
                <div class="ml-3 px-3 py-1 bg-sky-50 text-sky-600 text-sm font-bold rounded-full border border-sky-100">{{ $trialLessons->count() }}</div>
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($trialLessons as $grant)
                    <div class="bg-white rounded-2xl shadow-[0_2px_12px_rgba(0,0,0,0.04)] border border-gray-100 hover:border-[#38BDF8]/40 hover:shadow-[0_15px_35px_rgba(56,189,248,0.08)] hover:-translate-y-1 transition-all duration-300 flex flex-col p-6">
                        <span class="self-start px-2.5 py-1 bg-sky-50 text-sky-600 text-[10px] font-bold uppercase tracking-widest rounded border border-sky-100 mb-3">
                            Пробное · {{ $grant->course->title }}
                        </span>
                        <h4 class="text-lg font-bold text-gray-900 mb-2 leading-snug line-clamp-2">
                            {{ $grant->lesson->title }}
                        </h4>
                        @if($grant->lesson->lesson_date)
                            <p class="text-gray-400 text-sm mb-4">{{ $grant->lesson->lesson_date->translatedFormat('d F Y') }}</p>
                        @else
                            <div class="mb-4"></div>
                        @endif
                        <a href="{{ route('student.lesson', [$grant->course->slug, $grant->lesson->id]) }}"
                           class="mt-auto flex items-center justify-center w-full px-4 py-2.5 bg-gray-50 text-gray-900 text-sm font-bold rounded-xl hover:bg-[#38BDF8] hover:text-white transition-all duration-300">
                            <span>Смотреть занятие</span>
                            <i class="fas fa-arrow-right ml-2 text-xs opacity-50"></i>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- КЛУБ (H2644): карточка членства + полка записей. Партиал сам решает,
             рисовать ли себя: при выключенном флаге clubMembership = null и
             clubShelf пуста, и кабинет остаётся прежним. --}}
        @include('student.partials.club-membership')

        {{-- ДОСТИЖЕНИЯ (Сертификаты) --}}
        @if($certificates->isNotEmpty())
        <div class="mb-12">
            <h3 class="text-2xl font-extrabold text-gray-900 mb-6 flex items-center">
                Мои достижения
                <div class="ml-3 px-3 py-1 bg-yellow-50 text-yellow-600 text-sm font-bold rounded-full border border-yellow-100">{{ $certificates->count() }}</div>
            </h3>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($certificates as $cert)
                    <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex items-center gap-4 hover:border-yellow-400 hover:shadow-md transition-all duration-300 group">
                        
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-yellow-100 to-yellow-50 text-yellow-600 flex items-center justify-center shrink-0 border border-yellow-200 group-hover:scale-110 group-hover:shadow-[0_0_15px_rgba(234,179,8,0.3)] transition-all">
                            <i class="fas fa-certificate text-xl"></i>
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-800 truncate group-hover:text-brand transition-colors">{{ str_replace('|', ' ', $cert->displayCourseTitle()) }}</p>
                            <p class="text-xs font-medium text-gray-400 mt-0.5">{{ $cert->isSpravka() ? 'Справка' : 'Сертификат' }} · {{ $cert->created_at->format('d.m.Y') }}</p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <a href="{{ route('student.certificate.download', $cert->id) }}" class="w-10 h-10 rounded-full bg-gray-50 hover:bg-brand hover:text-white flex items-center justify-center text-gray-400 transition-colors" title="Скачать PDF">
                                <i class="fas fa-download text-sm"></i>
                            </a>
                            @if(\App\Services\CertificateService::jpegSupported())
                                <a href="{{ route('student.certificate.download.jpg', $cert->id) }}" class="w-10 h-10 rounded-full bg-gray-50 hover:bg-brand hover:text-white flex items-center justify-center text-gray-400 transition-colors" title="Скачать JPG">
                                    <i class="fas fa-image text-sm"></i>
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if(config('features.referral_loyalty_cta'))
                <div class="mt-6" data-testid="referral-loyalty-cta-certificate">
                    @include('student.partials.referral')
                </div>
            @endif
        </div>
        @endif
        
        {{-- Рекомендация школы (H1294) — в основном потоке кабинета, вне
             геймификации: раньше блок жил во вкладке «Прана» и исчезал вместе
             с ней при выключенной пране. --}}
        @include('student.partials.referral')

    </div> {{-- Конец вкладки 1 (Мои курсы) --}}

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 2: СЛОВАРИ --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'dictionaries'" 
         style="display: none;"
         x-transition:enter="transition ease-out duration-300" 
         x-transition:enter-start="opacity-0 translate-y-4" 
         x-transition:enter-end="opacity-100 translate-y-0">
         
         @livewire('student-dictionary')
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА: ПОДДЕРЖКА (веб-чат с ИИ-куратором и человеком) --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'chat'"
         style="display: none;"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0">

         @livewire('student-chat')
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 3: МОИ ОПЛАТЫ --}}
    {{-- ========================================== --}}
    <div x-show="activeTab === 'payments'"
         style="display: none;"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0">

         @livewire('student-payments')
    </div>

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 4: МОИ ДОЛГИ                        --}}
    {{-- ========================================== --}}
    @if($debts->isNotEmpty())
    <div x-show="activeTab === 'debts'"
         style="display: none;"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0">

        @php
            $debtsWithPlan = $debts->contains(fn ($d) => $d->promise_active || $d->promise_overdue);
            $debtsWithoutPlan = $debts->contains(fn ($d) => ! $d->promise_active && ! $d->promise_overdue);
        @endphp
        <div class="mb-5 bg-gradient-to-r from-orange-50 via-amber-50 to-orange-50 border border-brand/20 rounded-xl px-4 py-3 flex items-center gap-3">
            <i class="fas fa-exclamation-triangle text-brand shrink-0"></i>
            <p class="text-sm text-gray-700 leading-snug">
                @if($debtsWithPlan && ! $debtsWithoutPlan)
                    <span class="font-bold text-gray-900">У вас согласована рассрочка.</span>
                    Вносите платежи по графику или погасите остаток сразу — как вам удобнее.
                @elseif($debtsWithPlan && $debtsWithoutPlan)
                    <span class="font-bold text-gray-900">Есть неоплаченные блоки и рассрочка.</span>
                    По рассрочке платите по графику, остальные блоки можно оплатить сразу — доступ откроется автоматически.
                @else
                    <span class="font-bold text-gray-900">Есть неоплаченные блоки.</span>
                    Оплатите блок — доступ откроется автоматически.
                @endif
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($debts as $debt)
                @php
                    $cardBorder = $debt->promise_active
                        ? 'border-emerald-200 hover:border-emerald-400'
                        : ($debt->promise_overdue ? 'border-red-200 hover:border-red-400' : 'border-gray-100 hover:border-brand/40');
                    $iconBg = $debt->promise_active
                        ? 'bg-emerald-50 text-emerald-600'
                        : ($debt->promise_overdue ? 'bg-red-50 text-red-600' : 'bg-orange-50 text-brand');
                    $accentLabel = $debt->promise_active
                        ? 'text-emerald-700'
                        : ($debt->promise_overdue ? 'text-red-700' : 'text-brand');
                    $accentBg = $debt->promise_active
                        ? 'bg-emerald-50/70 border-emerald-200/60'
                        : ($debt->promise_overdue ? 'bg-red-50/70 border-red-200/60' : 'bg-orange-50/60 border-brand/15');
                @endphp

                <div class="bg-white rounded-xl border {{ $cardBorder }} hover:shadow-sm transition-all duration-200 p-4 flex flex-col">
                    <div class="flex items-start gap-2.5 mb-3">
                        <div class="w-7 h-7 rounded-md {{ $iconBg }} flex items-center justify-center shrink-0 text-xs">
                            <i class="fas fa-book"></i>
                        </div>
                        <h4 class="flex-1 text-sm font-bold text-gray-900 leading-snug line-clamp-2">
                            {{ $debt->course->title }}
                        </h4>
                    </div>

                    @if($debt->ref_block && $debt->ref_block->starts_at && $debt->ref_block->ends_at)
                        <p class="text-[11px] text-gray-500 mb-2.5 leading-snug">
                            Текущий блок №{{ $debt->ref_block->number }} ·
                            {{ $debt->ref_block->starts_at->format('d.m') }}–{{ $debt->ref_block->ends_at->format('d.m.Y') }}
                        </p>
                    @endif

                    @if($debt->has_arrangement && $debt->is_installment)
                        {{-- Рассрочка: полный график платежей с отметками статуса --}}
                        <div class="{{ $accentBg }} border rounded-lg px-3 py-2 mb-3">
                            <div class="text-[9px] font-bold {{ $accentLabel }} uppercase tracking-wider mb-1.5 flex items-center justify-between">
                                <span><i class="fas fa-list-ol mr-1"></i>Рассрочка</span>
                                @if($debt->overdue_count > 0)
                                    <span class="text-red-600 normal-case">{{ $debt->overdue_count }} просроч.</span>
                                @endif
                            </div>
                            <div class="space-y-1">
                                @foreach($debt->promises as $p)
                                    @php $paid = $p->isPaid(); $late = $p->isOverdueOrExpired(); @endphp
                                    <div class="flex items-center justify-between text-[11px] leading-snug">
                                        <span class="flex items-center gap-1.5">
                                            @if($paid)
                                                <i class="fas fa-check-circle text-emerald-500 text-[10px]"></i>
                                            @elseif($late)
                                                <i class="fas fa-exclamation-circle text-red-500 text-[10px]"></i>
                                            @else
                                                <i class="far fa-circle text-gray-400 text-[10px]"></i>
                                            @endif
                                            <span class="{{ $paid ? 'text-gray-400 line-through' : ($late ? 'text-red-700 font-semibold' : 'text-gray-700') }}">
                                                {{ $p->promised_at?->format('d.m.Y') }}
                                            </span>
                                        </span>
                                        @if($p->amount)
                                            <span class="{{ $paid ? 'text-gray-400 line-through' : ($late ? 'text-red-700 font-bold' : 'text-gray-800 font-semibold') }}">
                                                {{ number_format((float) $p->amount, 0, '.', ' ') }} ₽
                                            </span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if($debt->plan_remaining)
                                <div class="mt-1.5 pt-1.5 border-t border-gray-200/70 flex items-center justify-between text-[11px]">
                                    <span class="text-gray-500">Осталось внести</span>
                                    <span class="font-extrabold text-gray-900">{{ number_format($debt->plan_remaining, 0, '.', ' ') }} ₽</span>
                                </div>
                            @endif
                        </div>
                    @elseif($debt->has_arrangement)
                        {{-- Одиночное обещание оплаты --}}
                        <div class="{{ $accentBg }} border rounded-lg px-3 py-2 mb-3">
                            <div class="text-[9px] font-bold {{ $accentLabel }} uppercase tracking-wider mb-0.5">
                                <i class="fas {{ $debt->promise_overdue ? 'fa-clock' : 'fa-handshake' }} mr-1"></i>{{ $debt->promise_overdue ? 'Срок оплаты прошел' : 'Договоренность' }}
                            </div>
                            <div class="text-xs font-semibold text-gray-800 leading-snug">
                                Оплата до {{ $debt->promise->promised_at?->format('d.m.Y') }}
                                @if($debt->promise->amount)
                                    · {{ number_format((float) $debt->promise->amount, 0, '.', ' ') }} ₽
                                @endif
                            </div>
                            @if($debt->promise->note)
                                <div class="text-[11px] text-gray-500 mt-1 leading-snug">{{ $debt->promise->note }}</div>
                            @endif
                        </div>
                    @else
                        {{-- Долг без договорённости (не продлил) --}}
                        <div class="{{ $accentBg }} border rounded-lg px-3 py-2 mb-3">
                            <div class="text-[9px] font-bold {{ $accentLabel }} uppercase tracking-wider mb-0.5">
                                Не оплачено · {{ count($debt->debt_block_numbers) }} бл.
                            </div>
                            <div class="text-xs font-semibold text-gray-800 leading-snug">
                                {{ $debt->debt_label }}
                            </div>
                            @if($debt->debt_amount)
                                <div class="text-sm font-extrabold text-gray-900 mt-1">
                                    {{ $debt->debt_amount_approximate ? '≈ ' : '' }}{{ number_format($debt->debt_amount, 0, '.', ' ') }} ₽
                                </div>
                            @endif
                        </div>
                    @endif

                    @php
                        $opts = $debtPayOptions[$debt->course_id] ?? null;
                    @endphp
                    <div class="mt-auto space-y-2">
                        {{-- Самостоятельная оплата долга (self-service).
                             data-track-*: baseline-телеметрия H962 — старт продления
                             (access.renewal.start, спека §4). --}}
                        @if($opts && $opts['type'] === 'arrangement')
                            @if($opts['next'])
                                <form method="POST" action="{{ $opts['next']['url'] }}">
                                    @csrf
                                    <button type="submit" data-track-event="access.renewal.start" data-track-kind="promise-next" class="w-full flex items-center justify-center px-3 py-2 bg-brand hover:bg-brand-hover text-white text-xs font-bold rounded-lg transition-colors">
                                        <i class="fas fa-credit-card mr-1.5 text-[10px]"></i>
                                        <span>Оплатить{{ $opts['next']['amount'] ? ' '.number_format($opts['next']['amount'], 0, '.', ' ').' ₽' : '' }}</span>
                                    </button>
                                </form>
                            @endif
                            @if($opts['whole'])
                                <form method="POST" action="{{ $opts['whole']['url'] }}">
                                    @csrf
                                    <button type="submit" data-track-event="access.renewal.start" data-track-kind="promise-whole" class="w-full flex items-center justify-center px-3 py-1.5 border border-brand/40 text-brand hover:bg-orange-50 text-xs font-bold rounded-lg transition-colors">
                                        <span>Погасить всё{{ $opts['whole']['amount'] ? ' ('.number_format($opts['whole']['amount'], 0, '.', ' ').' ₽)' : '' }}</span>
                                    </button>
                                </form>
                            @endif

                            {{-- Частичная оплата / прана (self-service Phase 2) --}}
                            @if(($opts['remaining'] ?? null) && \App\Services\Prana\PranaSettings::isActive() || ($opts['next_amount'] ?? null) !== ($opts['remaining'] ?? null))
                                <details class="text-[11px] text-gray-500">
                                    <summary class="cursor-pointer select-none hover:text-gray-700">Другая сумма{{ \App\Services\Prana\PranaSettings::isActive() ? ' / прана' : '' }}</summary>
                                    <form method="POST" action="{{ $opts['pay_all_url'] }}" class="mt-2 space-y-2">
                                        @csrf
                                        <label class="block">
                                            <span class="text-gray-500">Сумма, ₽ (от {{ number_format($opts['next_amount'] ?? 0, 0, '.', ' ') }} до {{ number_format($opts['remaining'] ?? 0, 0, '.', ' ') }})</span>
                                            <input type="number" name="amount" step="1" min="{{ (int) ($opts['next_amount'] ?? 0) }}" max="{{ (int) ($opts['remaining'] ?? 0) }}" value="{{ (int) ($opts['next_amount'] ?? 0) }}" class="mt-0.5 w-full rounded border-gray-300 text-xs">
                                        </label>
                                        @if(\App\Services\Prana\PranaSettings::isActive())
                                            <label class="block">
                                                <span class="text-gray-500">Списать прану (баланс {{ (int) (auth()->user()->prana_balance ?? 0) }})</span>
                                                <input type="number" name="prana_amount" step="{{ \App\Services\Prana\PranaSettings::rate() }}" min="0" max="{{ (int) (auth()->user()->prana_balance ?? 0) }}" value="0" class="mt-0.5 w-full rounded border-gray-300 text-xs">
                                            </label>
                                        @endif
                                        <button type="submit" class="w-full px-3 py-1.5 bg-brand hover:bg-brand-hover text-white text-xs font-bold rounded-lg transition-colors">Оплатить</button>
                                    </form>
                                </details>
                            @endif

                            {{-- Перенос даты ближайшего обещания (один раз) --}}
                            @if($opts['reschedule'] ?? null)
                                <details class="text-[11px] text-gray-500">
                                    <summary class="cursor-pointer select-none hover:text-gray-700">Перенести дату</summary>
                                    <form method="POST" action="{{ $opts['reschedule']['url'] }}" class="mt-2 flex gap-1.5">
                                        @csrf
                                        <input type="date" name="new_date" value="{{ $opts['reschedule']['current_date'] }}" class="flex-1 rounded border-gray-300 text-xs">
                                        <button type="submit" class="px-2.5 py-1 border border-gray-300 hover:bg-gray-50 rounded-lg text-xs font-semibold">ОК</button>
                                    </form>
                                    <p class="mt-1 text-gray-400">Не более чем на 2 недели вперед, один раз.</p>
                                </details>
                            @endif
                        @elseif($opts && $opts['type'] === 'tariff')
                            {{-- data-track-*: baseline-телеметрия H962 — старт продления
                                 (access.renewal.start, спека §4); complete пишет
                                 PaymentTelemetryObserver по is_self_service+paid. --}}
                            @if($opts['full'])
                                <a href="{{ $opts['full']['url'] }}" data-track-event="access.renewal.start" data-track-kind="full" class="flex items-center justify-center px-3 py-2 bg-brand hover:bg-brand-hover text-white text-xs font-bold rounded-lg transition-colors">
                                    <i class="fas fa-credit-card mr-1.5 text-[10px]"></i><span>Оплатить курс</span>
                                </a>
                            @else
                                @if($opts['bundle'] ?? null)
                                    {{-- Бандл: весь многоблочный долг одним платежом. GET —
                                         штатный чекаут по bundle-тарифу; POST — fallback pay-bundle. --}}
                                    @if(($opts['bundle']['method'] ?? 'POST') === 'GET')
                                        <a href="{{ $opts['bundle']['url'] }}" data-track-event="access.renewal.start" data-track-kind="bundle" class="flex items-center justify-center px-3 py-2 bg-brand hover:bg-brand-hover text-white text-xs font-bold rounded-lg transition-colors">
                                            <i class="fas fa-credit-card mr-1.5 text-[10px]"></i><span>Оплатить всё ({{ number_format($opts['bundle']['amount'], 0, '.', ' ') }} ₽)</span>
                                        </a>
                                    @else
                                        <form method="POST" action="{{ $opts['bundle']['url'] }}">
                                            @csrf
                                            <button type="submit" data-track-event="access.renewal.start" data-track-kind="bundle" class="w-full flex items-center justify-center px-3 py-2 bg-brand hover:bg-brand-hover text-white text-xs font-bold rounded-lg transition-colors">
                                                <i class="fas fa-credit-card mr-1.5 text-[10px]"></i><span>Оплатить всё ({{ number_format($opts['bundle']['amount'], 0, '.', ' ') }} ₽)</span>
                                            </button>
                                        </form>
                                    @endif
                                @endif
                                @foreach($opts['blocks'] as $b)
                                    <a href="{{ $b['url'] }}" data-track-event="access.renewal.start" data-track-kind="block" class="flex items-center justify-center px-3 py-{{ ($opts['bundle'] ?? null) ? '1.5' : '2' }} {{ ($opts['bundle'] ?? null) ? 'border border-brand/40 text-brand hover:bg-orange-50' : 'bg-brand hover:bg-brand-hover text-white' }} text-xs font-bold rounded-lg transition-colors">
                                        <i class="fas fa-credit-card mr-1.5 text-[10px]"></i><span>{{ ($opts['bundle'] ?? null) ? 'Блок №'.$b['number'].' отдельно' : 'Оплатить блок №'.$b['number'] }}</span>
                                    </a>
                                @endforeach
                                @if(!empty($opts['unpriced_blocks']))
                                    <p class="text-[11px] text-gray-400 text-center leading-snug">Блок{{ count($opts['unpriced_blocks']) > 1 ? 'и' : '' }} №{{ implode(', №', $opts['unpriced_blocks']) }} — оплата через куратора</p>
                                @endif
                            @endif
                        @endif

                        <a href="{{ route('student.course', $debt->course->slug) }}"
                           class="flex items-center justify-center px-3 py-1.5 text-gray-500 hover:text-gray-800 text-xs font-semibold transition-colors">
                            <span>К курсу</span>
                            <i class="fas fa-arrow-right ml-1.5 text-[10px]"></i>
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ========================================== --}}
    {{-- ВКЛАДКА 4: ПРАНА                            --}}
    {{-- ========================================== --}}
    @if(\App\Services\Prana\PranaSettings::isActive())
    <div x-show="activeTab === 'prana'"
         style="display: none;"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0">

        @include('student.partials.prana-rank')

        @include('student.partials.prana-streak')

        @include('student.partials.prana-badges')

        @include('student.partials.prana-shop')

        @include('student.partials.prana-leaderboard')

        @include('student.partials.prana-transfer')

        @php
            $balance = (int) (auth()->user()->prana_balance ?? 0);
            $rate    = \App\Services\Prana\PranaSettings::rate();
            $maxPct  = (int) round(\App\Services\Prana\PranaSettings::maxShare() * 100);
        @endphp

        {{-- Баланс --}}
        <div class="bg-gradient-to-br from-[#19191C] via-[#252529] to-[#19191C] text-white rounded-3xl p-8 md:p-10 mb-8 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-64 h-64 bg-brand blur-[80px] opacity-30 rounded-full -mr-20 -mt-20 pointer-events-none"></div>
            <div class="relative z-10 flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                <div>
                    <p class="text-xs font-bold text-orange-300 uppercase tracking-widest mb-3">Ваш баланс праны</p>
                    <div class="flex items-baseline gap-3">
                        <x-prana-lotus class="w-16 h-16 md:w-20 md:h-20" />
                        <span class="text-5xl md:text-6xl font-black tracking-tight tabular-nums">{{ number_format($balance, 0, '.', ' ') }}</span>
                    </div>
                    <p class="text-sm text-gray-400 mt-3 max-w-md leading-relaxed">
                        {{ $rate }} праны = 1 ₽ скидки. Списать можно до {{ $maxPct }}% стоимости любого курса.
                    </p>
                    <a href="{{ route('help.prana-balance') }}"
                       class="inline-flex items-center gap-1.5 text-xs font-bold text-orange-300 hover:text-white transition-colors mt-2">
                        <i class="fas fa-circle-question"></i> Почему баланс уменьшился?
                    </a>
                </div>
                <a href="{{ route('shop.index') }}"
                   class="inline-flex items-center justify-center px-6 py-3.5 bg-brand hover:bg-brand-hover text-white text-sm font-extrabold rounded-xl transition-all shadow-lg shadow-orange-900/30 hover:-translate-y-0.5">
                    Выбрать курс
                    <i class="fas fa-arrow-right ml-2 text-xs"></i>
                </a>
            </div>
        </div>

        {{-- Как заработать --}}
        <div class="mb-8">
            <h3 class="text-2xl font-extrabold text-gray-900 mb-5">Как заработать прану</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @php
                    $earnIcons = [
                        'lesson_complete'  => ['fa-check-circle', 'text-emerald-500', 'bg-emerald-50'],
                        'course_complete'  => ['fa-graduation-cap', 'text-purple-500', 'bg-purple-50'],
                        'open_lesson_view' => ['fa-video', 'text-blue-500', 'bg-blue-50'],
                        'daily_login'      => ['fa-calendar-day', 'text-amber-500', 'bg-amber-50'],
                        'payment_success'  => ['fa-shopping-bag', 'text-rose-500', 'bg-rose-50'],
                    ];
                @endphp
                @foreach($pranaRewards as $reason => $amount)
                    @if($amount > 0)
                        @php
                            [$icon, $iconColor, $iconBg] = $earnIcons[$reason] ?? ['fa-star', 'text-gray-500', 'bg-gray-50'];
                            $label = $pranaReasons[$reason] ?? $reason;
                        @endphp
                        <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-[0_2px_12px_rgba(0,0,0,0.04)] flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl {{ $iconBg }} {{ $iconColor }} flex items-center justify-center shrink-0 text-lg">
                                <i class="fas {{ $icon }}"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 truncate">{{ $label }}</p>
                                <p class="text-xs text-brand font-extrabold mt-0.5">+{{ $amount }} <x-prana-lotus /></p>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        {{-- История --}}
        <div>
            <h3 class="text-2xl font-extrabold text-gray-900 mb-5">История</h3>
            @if($pranaTransactions->isEmpty())
                <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-gray-200">
                    <div class="w-16 h-16 mx-auto bg-orange-50 rounded-full flex items-center justify-center mb-3"><x-prana-lotus class="w-8 h-8" /></div>
                    <p class="text-gray-500">Пока нет начислений. Пройдите первый урок — и тут появится запись.</p>
                </div>
            @else
                <div class="bg-white rounded-2xl border border-gray-100 shadow-[0_2px_12px_rgba(0,0,0,0.04)] divide-y divide-gray-50 overflow-hidden">
                    @foreach($pranaTransactions as $tx)
                        @php
                            $isPositive = $tx->amount >= 0;
                            $label = $tx->reasonLabel();
                        @endphp
                        <div class="flex items-center gap-4 px-5 py-4">
                            <div class="w-10 h-10 rounded-xl {{ $isPositive ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' }} flex items-center justify-center shrink-0">
                                <i class="fas {{ $isPositive ? 'fa-arrow-up' : 'fa-arrow-down' }} text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 truncate">{{ $label }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $tx->created_at->translatedFormat('d F Y, H:i') }}</p>
                            </div>
                            <div class="text-base font-extrabold tabular-nums {{ $isPositive ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ $isPositive ? '+' : '' }}{{ $tx->amount }}
                                <x-prana-lotus class="w-3.5 h-3.5" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    @endif

</div> {{-- Конец главного x-data контейнера --}}

@endsection
