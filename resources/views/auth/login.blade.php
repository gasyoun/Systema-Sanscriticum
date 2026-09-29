<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Вход в кабинет | ОРС LMS</title>
    @include('partials.tailwind-cdn')
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
@php
    // Бегущие отзывы фоном на весь экран — только если их хватает на три колонки;
    // иначе прежний вид: карточка по центру на сером фоне.
    $showTestimonials = isset($testimonials) && $testimonials->count() >= 3;
    // Вкладки «Войти / Регистрация» — только в тёмной карточке и только при включённой
    // гостевой регистрации (флаг OFF → одна карточка входа, как раньше).
    $withTabs = $showTestimonials && config('features.guest_registration');
    $regOld = $withTabs && old('form') === 'register';   // вернулись с ошибкой регистрации
@endphp
<body class="bg-gray-50 min-h-screen font-sans text-[#101010] {{ $showTestimonials ? '' : 'flex items-center justify-center p-4' }}">

    @if ($showTestimonials)
        @include('auth.partials.testimonials-marquee', ['testimonials' => $testimonials])
    @endif

    {{-- Карточка «парит» над отзывами: слой поверх, прозрачный для мыши —
         наведение по-прежнему доходит до карточек-отзывов вокруг формы.
         Без отзывов main «прозрачен» (contents): карточка — flex-ребёнок body, как раньше. --}}
    <main class="{{ $showTestimonials ? 'relative z-10 min-h-screen flex flex-col items-center justify-center gap-6 p-4 sm:pb-[16vh] pointer-events-none' : 'contents' }}">
    <div class="lt-login-card pointer-events-auto max-w-xs w-full bg-white rounded-2xl overflow-hidden relative {{ $showTestimonials ? 'lt-dark' : 'shadow-2xl' }}">
        {{-- Декоративная линия сверху --}}
        <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

        <div class="p-5 pt-6">
            <div class="text-center mb-4">
                {{-- Иконка пользователя/студента — только в светлом варианте; в тёмном её место занимает नमो नमः. --}}
                @unless ($showTestimonials)
                    <div class="w-10 h-10 mx-auto mb-2 rounded-full flex items-center justify-center bg-brand/10 text-brand">
                        <i class="fas fa-user-graduate text-base"></i>
                    </div>
                @endunless
                @if ($showTestimonials)
                    {{-- Приветствие на санскрите с тем же эффектом, что у молитвы под формой
                         (lt-verse: блик, слово приподнимается, разбор в подписи). --}}
                    <div class="lt-verse">
                        <h2 lang="sa" class="lt-shloka lt-greet mb-0.5" aria-label="Намо намах — добро пожаловать">
                            <span class="lt-word" tabindex="0" data-iast="namo" data-ru="поклон">नमो</span>
                            <span class="lt-word" tabindex="0" data-iast="namaḥ" data-ru="поклон (повтор — знак почтения)">नमः</span>
                        </h2>
                        @if ($withTabs)
                            {{-- Разбор слова всплывает над вкладками и не двигает вёрстку. --}}
                            <p class="lt-gloss lt-gloss-float text-xs" aria-live="polite" data-default=""></p>
                        @else
                            <p class="lt-gloss text-gray-500 text-xs" aria-live="polite"
                               data-default="Войдите в личный кабинет ОРС">Войдите в личный кабинет ОРС</p>
                        @endif
                    </div>
                    @if ($withTabs)
                        <div class="lt-tabs mt-9" role="tablist" data-active="{{ $regOld ? 'register' : 'login' }}">
                            <span class="lt-tab-ind" aria-hidden="true"></span>
                            <button type="button" role="tab" id="lt-tab-login" data-tab="login"
                                    aria-controls="lt-pane-login" aria-selected="{{ $regOld ? 'false' : 'true' }}">Войти</button>
                            <button type="button" role="tab" id="lt-tab-register" data-tab="register"
                                    aria-controls="lt-pane-register" aria-selected="{{ $regOld ? 'true' : 'false' }}">Регистрация</button>
                        </div>
                    @endif
                @else
                    <h2 class="text-xl font-extrabold mb-0.5 text-gray-900">С возвращением!</h2>
                    <p class="text-gray-500 text-xs">Войдите в личный кабинет ОРС</p>
                @endif
            </div>

            @include('auth.partials.pending-waitlist-vote')

            @if (session('status'))
                <div class="mb-6 bg-green-50 border border-green-200 text-green-700 text-sm rounded-xl px-4 py-3">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm font-medium flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            <div class="lt-panes">
            <div id="lt-pane-login" data-pane="login" role="tabpanel" aria-labelledby="lt-tab-login" @if ($regOld) hidden @endif>
            <form action="{{ route('login.post') }}" method="POST" id="login-form" class="space-y-3">
                @csrf
                
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 pl-1" for="email">
                        Email адрес
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input type="email" name="email" id="email" required autofocus
                            class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="student@example.com">
                    </div>
                    @if (! $regOld)
                        @error('email')
                            <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                        @enderror
                    @endif
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 pl-1" for="password">
                        Пароль
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password" id="password" required
                            class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="••••••••">
                    </div>
                </div>

                {{-- H1949 — opt-in long-lived session; default off (unchecked).
                     Shop modal already had this; password /login was the gap. --}}
                <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer select-none pl-1">
                    <input type="checkbox" name="remember" value="1"
                        @checked(old('remember'))
                        class="rounded border-gray-300 text-brand focus:ring-brand">
                    Запомнить меня
                </label>

                <div class="pt-1">
                    <button type="submit" 
                        class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-2.5 px-4 rounded-lg shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 text-xs uppercase tracking-wider">
                        Войти в кабинет
                    </button>
                </div>
            </form>

            @include('auth.partials.social-buttons')
            </div>

            @if ($withTabs)
                {{-- Регистрация в том же исполнении: POST на тот же GuestRegisterController,
                     ошибки возвращают сюда же (hidden form=register открывает вкладку). --}}
                <div id="lt-pane-register" data-pane="register" role="tabpanel" aria-labelledby="lt-tab-register" @unless ($regOld) hidden @endunless>
                <form action="{{ route('register.post') }}" method="POST" id="lt-register-form" class="space-y-3">
                    @csrf
                    <input type="hidden" name="form" value="register">
                    @php
                        $regFields = [
                            ['first_name', 'Имя', 'text', 'fa-user', 'given-name', 'Анна'],
                            ['last_name', 'Фамилия', 'text', 'fa-user', 'family-name', 'Иванова'],
                            ['phone', 'Телефон', 'tel', 'fa-phone', 'tel', '+7 900 000-00-00'],
                            ['email', 'Email', 'email', 'fa-envelope', 'email', 'student@example.com'],
                            ['city', 'Город', 'text', 'fa-location-dot', 'address-level2', 'Москва'],
                            ['password', 'Пароль', 'password', 'fa-lock', 'new-password', 'не короче 8 символов'],
                        ];
                    @endphp
                    <div class="grid grid-cols-2 gap-3">
                        @foreach ($regFields as [$name, $label, $type, $icon, $ac, $ph])
                            <div class="{{ in_array($name, ['first_name', 'last_name'], true) ? '' : 'col-span-2' }}">
                                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 pl-1" for="reg_{{ $name }}">{{ $label }}</label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                        <i class="fas {{ $icon }} lt-ico text-gray-400"></i>
                                    </div>
                                    <input type="{{ $type }}" name="{{ $name }}" id="reg_{{ $name }}" autocomplete="{{ $ac }}" required
                                        @if ($name === 'password') minlength="8" @endif
                                        @if ($regOld && $name !== 'password') value="{{ old($name) }}" @endif
                                        class="w-full pl-10 pr-3 py-2.5 rounded-lg border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                                        placeholder="{{ $ph }}">
                                </div>
                                @if ($regOld)
                                    @error($name)
                                        <p class="text-red-500 text-xs mt-1.5 pl-1 font-medium">{{ $message }}</p>
                                    @enderror
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="pt-1">
                        <button type="submit"
                            class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-2.5 px-4 rounded-lg shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 text-xs uppercase tracking-wider">
                            Создать кабинет
                        </button>
                    </div>
                </form>
                </div>
            @endif
            </div>
        </div>

        <div class="bg-gray-50/80 px-5 py-3 border-t border-gray-100 text-center space-y-1.5" data-pane="login" @if ($regOld) hidden @endif>
            {{-- Главный путь для старых студентов: аккаунт уже создан при оплате,
                 «регистрации» нет — вход по email заказа. --}}
            <p class="text-xs text-gray-600">
                Первый раз входите или не помните пароль?<br>
                <a href="{{ route('password.request') }}" class="text-brand hover:underline font-bold transition-colors">Войдите по email заказа →</a>
            </p>
            @if ($withTabs)
                <p class="text-xs text-gray-600">
                    Нет кабинета?
                    <button type="button" data-tab-go="register" class="text-brand hover:underline font-bold">Зарегистрироваться бесплатно</button>
                </p>
            @elseif (config('features.guest_registration'))
                @if (session()->has(\App\Http\Controllers\Api\PublicWaitlistController::PENDING_VOTE_SESSION_KEY))
                    {{-- Гость пришёл голосовать: регистрация — главный путь, кнопкой. --}}
                    <p class="text-sm text-gray-600">Нет кабинета?</p>
                    <a href="{{ route('register') }}"
                       class="block w-full border-2 border-brand text-brand hover:bg-brand hover:text-white font-extrabold py-3 px-4 rounded-xl transition text-xs uppercase tracking-wider">
                        Зарегистрироваться бесплатно
                    </a>
                @else
                    <p class="text-xs text-gray-600">
                        Нет кабинета?
                        <a href="{{ route('register') }}" class="text-brand hover:underline font-bold">Зарегистрироваться бесплатно</a>
                    </p>
                @endif
            @endif
            <p class="text-[10px] text-gray-500 leading-snug">
                Аккаунт создан при оплате — сначала
                <a href="{{ route('password.request') }}" class="text-brand hover:underline font-semibold">проверьте email заказа</a>.
                Не помните email или пароль?
                <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="text-brand hover:underline font-semibold">Куратор</a>
                найдет кабинет и пришлет <span class="font-semibold text-gray-600">личную ссылку для входа</span>
                в Telegram (без пароля). Новый кабинет заводить не нужно.
            </p>
        </div>
        @if ($withTabs)
            <div class="bg-gray-50/80 px-5 py-3 border-t border-gray-100 text-center space-y-1.5" data-pane="register" @unless ($regOld) hidden @endunless>
                <p class="text-xs text-gray-600">
                    Уже есть кабинет?
                    <button type="button" data-tab-go="login" class="text-brand hover:underline font-bold">Войти</button>
                </p>
                <p class="text-[10px] text-gray-500 leading-snug">
                    Покупали курс? Кабинет уже создан при оплате — не регистрируйтесь заново,
                    <a href="{{ route('password.request') }}" class="text-brand hover:underline font-semibold">войдите по email заказа</a>.
                </p>
            </div>
        @endif
    </div>
    @if ($showTestimonials)
        {{-- Санскрит — одна строка золотом под формой: молитва Сарасвати перед началом учёбы.
             Интерактив: блик идёт за курсором; наведение/фокус/тап по слову — в подписи
             транслитерация и перевод слова. «सिद्धिर्भवतु» не режем: разрыв ломает лигатуру र्भ. --}}
        @php
            $shloka = [
                ['विद्यारम्भं', 'vidyārambhaṃ', 'начало учения'],
                ['करिष्यामि', 'kariṣyāmi', 'приступлю, совершу'],
                ['सिद्धिर्भवतु', 'siddhir bhavatu', 'да будет успех'],
                ['मे', 'me', 'мне'],
                ['सदा', 'sadā', 'всегда'],
            ];
        @endphp
        <div class="lt-verse pointer-events-auto text-center select-none">
            <div class="flex items-center justify-center gap-3">
                <span class="lt-hair h-px w-10"></span>
                <p lang="sa" class="lt-shloka text-lg leading-none" title="Сарасвати-вандана">
                    @foreach ($shloka as [$deva, $iast, $ru])
                        <span class="lt-word" tabindex="0" data-iast="{{ $iast }}" data-ru="{{ $ru }}">{{ $deva }}</span>@if (! $loop->last) @endif
                    @endforeach
                </p>
                <span class="lt-hair h-px w-10"></span>
            </div>
            <p class="lt-gloss mt-2 text-[10px] uppercase tracking-[0.25em] text-white/35" aria-live="polite"
               data-default="приступаю к учению — да будет успех">приступаю к учению — да будет успех</p>
        </div>
    @endif
    </main>

    @include('partials.csrf-token-refresh')
    <script>
        // H1774 — анти-419 на входе: та же вкладка-простояла-дольше-жизни-сессии
        // проблема, что и на чекауте (295ea8b8). Подтягиваем свежий токен перед
        // сабмитом и при возврате страницы из bfcache.
        (function () {
            document.addEventListener('DOMContentLoaded', function () {
                // Та же защита и для карточки регистрации (вкладка на тёмном /login).
                ['login-form', 'lt-register-form'].forEach(function (id) {
                const form = document.getElementById(id);
                if (!form) return;
                const btn = form.querySelector('button[type="submit"]');
                let refreshed = false;

                form.addEventListener('submit', async function (e) {
                    if (refreshed) return; // второй проход — отправляем по-настоящему
                    e.preventDefault();

                    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }

                    if (btn) btn.disabled = true;
                    await window.CsrfTokenRefresh.refresh();
                    refreshed = true;
                    form.submit();
                });
                });
            });

            window.addEventListener('pageshow', function (e) {
                if (!e.persisted) return;
                window.CsrfTokenRefresh.refresh();
            });
        })();
    </script>

</body>
</html>