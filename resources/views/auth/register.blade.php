<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Регистрация | ОРС LMS</title>
    @include('partials.tailwind-cdn')
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 font-sans text-[#101010]">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden relative">
        <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

        <div class="p-8 pt-10 sm:p-10">
            <div class="text-center mb-8">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full flex items-center justify-center bg-brand/10 text-brand">
                    <i class="fas fa-user-plus text-2xl"></i>
                </div>
                <h2 class="text-3xl font-extrabold mb-2 text-gray-900">Создать кабинет</h2>
                <p class="text-gray-500 text-sm">Бесплатный уровень клуба — без оплаты</p>
            </div>

            @if ($errors->any())
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm font-medium">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('register.post') }}" method="POST" id="register-form" class="space-y-5">
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
                            value="{{ old('email') }}"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="student@example.com">
                    </div>
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
                            placeholder="не меньше 8 символов">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="password_confirmation">
                        Пароль ещё раз
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password_confirmation" id="password_confirmation" required
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="••••••••">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="birth_year">
                        Год рождения <span class="font-normal normal-case tracking-normal">(необязательно)</span>
                    </label>
                    <input type="number" name="birth_year" id="birth_year" min="1900" max="{{ now()->format('Y') }}"
                        placeholder="Например, 1990"
                        value="{{ old('birth_year') }}"
                        class="w-full px-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm">
                    @error('birth_year')<p class="mt-1.5 text-xs text-red-500"><i class="fas fa-exclamation-circle mr-1"></i>{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="signup_source">
                        Откуда вы о нас узнали? <span class="font-normal normal-case tracking-normal">(необязательно)</span>
                    </label>
                    @include('partials.signup-source-select')
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-3.5 px-4 rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 text-sm uppercase tracking-wider">
                        Зарегистрироваться
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-gray-50/80 px-8 py-5 border-t border-gray-100 text-center">
            <p class="text-sm text-gray-600">
                Уже есть кабинет?
                <a href="{{ route('login') }}" class="text-brand hover:underline font-bold">Войти</a>
            </p>
        </div>
    </div>

    @include('partials.csrf-token-refresh')
    <script>
        (function () {
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById('register-form');
                if (!form) return;
                const btn = form.querySelector('button[type="submit"]');
                let refreshed = false;

                form.addEventListener('submit', async function (e) {
                    if (refreshed) return;
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
        })();
    </script>

</body>
</html>
