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
    // Бегущие отзывы слева — только если их хватает на три колонки;
    // иначе прежний вид: одна карточка по центру.
    $showTestimonials = isset($testimonials) && $testimonials->count() >= 3;
@endphp
<body class="bg-gray-50 min-h-screen font-sans text-[#101010] {{ $showTestimonials ? 'lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(460px,40%)]' : 'flex items-center justify-center p-4' }}">

    @if ($showTestimonials)
        @include('auth.partials.testimonials-marquee', ['testimonials' => $testimonials])
    @endif

    {{-- Без отзывов main «прозрачен» (contents): карточка остаётся flex-ребёнком body, как раньше. --}}
    <main class="{{ $showTestimonials ? 'min-h-screen flex items-center justify-center p-4 sm:p-8' : 'contents' }}">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden relative">
        {{-- Декоративная линия сверху --}}
        <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

        <div class="p-8 pt-10 sm:p-10">
            <div class="text-center mb-8">
                {{-- Иконка пользователя/студента --}}
                <div class="w-16 h-16 mx-auto mb-5 rounded-full flex items-center justify-center bg-brand/10 text-brand">
                    <i class="fas fa-user-graduate text-2xl"></i>
                </div>
                <h2 class="text-3xl font-extrabold mb-2 text-gray-900">С возвращением!</h2>
                <p class="text-gray-500 text-sm">Войдите в личный кабинет ОРС</p>
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

            <form action="{{ route('login.post') }}" method="POST" id="login-form" class="space-y-5">
                @csrf
                
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="email">
                        Email адрес
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input type="email" name="email" id="email" required autofocus
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="student@example.com">
                    </div>
                    @error('email')
                        <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="password">
                        Пароль
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password" id="password" required
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="••••••••">
                    </div>
                </div>

                {{-- H1949 — opt-in long-lived session; default off (unchecked).
                     Shop modal already had this; password /login was the gap. --}}
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none pl-1">
                    <input type="checkbox" name="remember" value="1"
                        @checked(old('remember'))
                        class="rounded border-gray-300 text-brand focus:ring-brand">
                    Запомнить меня
                </label>

                <div class="pt-2">
                    <button type="submit" 
                        class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-3.5 px-4 rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 text-sm uppercase tracking-wider">
                        Войти в кабинет
                    </button>
                </div>
            </form>

            @include('auth.partials.social-buttons')
        </div>

        <div class="bg-gray-50/80 px-8 py-5 border-t border-gray-100 text-center space-y-2">
            {{-- Главный путь для старых студентов: аккаунт уже создан при оплате,
                 «регистрации» нет — вход по email заказа. --}}
            <p class="text-sm text-gray-600">
                Первый раз входите или не помните пароль?<br>
                <a href="{{ route('password.request') }}" class="text-brand hover:underline font-bold transition-colors">Войдите по email заказа →</a>
            </p>
            @if (config('features.guest_registration'))
                @if (session()->has(\App\Http\Controllers\Api\PublicWaitlistController::PENDING_VOTE_SESSION_KEY))
                    {{-- Гость пришёл голосовать: регистрация — главный путь, кнопкой. --}}
                    <p class="text-sm text-gray-600">Нет кабинета?</p>
                    <a href="{{ route('register') }}"
                       class="block w-full border-2 border-brand text-brand hover:bg-brand hover:text-white font-extrabold py-3 px-4 rounded-xl transition text-sm uppercase tracking-wider">
                        Зарегистрироваться бесплатно
                    </a>
                @else
                    <p class="text-sm text-gray-600">
                        Нет кабинета?
                        <a href="{{ route('register') }}" class="text-brand hover:underline font-bold">Зарегистрироваться бесплатно</a>
                    </p>
                @endif
            @endif
            <p class="text-xs text-gray-500 leading-relaxed">
                Аккаунт создан при оплате — сначала
                <a href="{{ route('password.request') }}" class="text-brand hover:underline font-semibold">проверьте email заказа</a>.
                Не помните email или пароль?
                <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="text-brand hover:underline font-semibold">Куратор</a>
                найдет кабинет и пришлет <span class="font-semibold text-gray-600">личную ссылку для входа</span>
                в Telegram (без пароля). Новый кабинет заводить не нужно.
            </p>
        </div>
    </div>
    </main>

    @include('partials.csrf-token-refresh')
    <script>
        // H1774 — анти-419 на входе: та же вкладка-простояла-дольше-жизни-сессии
        // проблема, что и на чекауте (295ea8b8). Подтягиваем свежий токен перед
        // сабмитом и при возврате страницы из bfcache.
        (function () {
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById('login-form');
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

            window.addEventListener('pageshow', function (e) {
                if (!e.persisted) return;
                window.CsrfTokenRefresh.refresh();
            });
        })();
    </script>

</body>
</html>