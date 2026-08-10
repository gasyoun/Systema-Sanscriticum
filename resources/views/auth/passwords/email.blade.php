<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля | ОРС LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4 font-sans text-[#101010]">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden relative">
        {{-- Декоративная линия сверху --}}
        <div class="absolute top-0 left-0 w-full h-1.5 bg-brand"></div>

        <div class="p-8 pt-10 sm:p-10">
            <div class="text-center mb-8">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full flex items-center justify-center bg-brand/10 text-brand">
                    <i class="fas fa-key text-2xl"></i>
                </div>
                <h2 class="text-3xl font-extrabold mb-2 text-gray-900">Вход в кабинет</h2>
                <p class="text-gray-500 text-sm">Уже покупали курс? Введите email, на который оформляли
                    заказ — мы проверим ваш аккаунт и пришлем ссылку для входа.</p>
            </div>

            @if (session('email_found'))
                {{-- Аккаунт найден — ссылка для входа отправлена. --}}
                <div class="mb-6 bg-green-50 border border-green-200 text-green-800 text-sm rounded-xl px-4 py-4">
                    <p class="font-bold mb-1"><i class="fas fa-check-circle mr-1"></i> Нашли ваш аккаунт!</p>
                    <p>Мы отправили ссылку для входа на <span class="font-semibold">{{ session('email_found') }}</span>.
                        Откройте письмо и задайте пароль. <span class="font-semibold">Проверьте папку «Спам»</span> —
                        письмо может попасть туда.</p>
                    <p class="mt-2 text-green-700/80 text-xs">Не пришло за 5 минут? Отправьте запрос еще раз ниже
                        или <a href="https://t.me/rusamskrtam" class="underline font-semibold">напишите куратору</a> —
                        пришлём <span class="font-semibold">личную ссылку для входа в Telegram</span> (без пароля).</p>
                </div>
            @endif

            @if (session('email_not_found'))
                {{-- Email не найден — объясняем, что делать, без тупика. --}}
                <div class="mb-6 bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-xl px-4 py-4">
                    <p class="font-bold mb-1"><i class="fas fa-circle-question mr-1"></i> Не нашли этот email в базе</p>
                    <p>Адреса <span class="font-semibold">{{ session('email_not_found') }}</span> у нас нет. Скорее всего:</p>
                    <ul class="list-disc list-inside mt-1.5 space-y-0.5 text-amber-700">
                        <li>заказ был оформлен на <span class="font-semibold">другой email</span> — попробуйте его;</li>
                        <li>в адресе <span class="font-semibold">опечатка</span> — проверьте написание.</li>
                    </ul>
                    <p class="mt-2 text-amber-800 text-sm">Не помните email или пароль — это нормально.
                        <a href="https://t.me/rusamskrtam" class="underline font-semibold">Напишите куратору в Telegram</a>
                        (ФИО, курс, когда примерно платили) — найдём кабинет и пришлём
                        <span class="font-semibold">личную одноразовую ссылку</span>. Откроете — войдёте
                        <span class="font-semibold">без пароля</span>. Новый кабинет заводить не нужно.</p>
                </div>
            @endif

            <form action="{{ route('password.email') }}" method="POST" class="space-y-5">
                @csrf

                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1" for="email">
                        Email адрес
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input type="email" name="email" id="email" required autofocus value="{{ old('email') }}"
                            class="w-full pl-11 pr-4 py-3.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm"
                            placeholder="student@example.com">
                    </div>
                    @error('email')
                        <p class="text-red-500 text-xs mt-2 pl-1 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div class="pt-2">
                    <button type="submit"
                        class="w-full bg-brand hover:bg-brand-hover text-white font-extrabold py-3.5 px-4 rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl hover:-translate-y-0.5 text-sm uppercase tracking-wider">
                        Проверить email и войти
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-gray-50/80 px-8 py-5 border-t border-gray-100 text-center space-y-2">
            <p class="text-xs text-gray-600 leading-relaxed">
                Не помните ни email, ни пароль?
                <a href="https://t.me/rusamskrtam" target="_blank" rel="noopener" class="text-brand hover:underline font-semibold">Куратор</a>
                пришлёт личную ссылку для входа в Telegram — без пароля.
            </p>
            <p class="text-xs text-gray-500">
                Вспомнили пароль? <a href="{{ route('login') }}" class="text-brand hover:underline font-semibold transition-colors">Войти</a>
            </p>
        </div>
    </div>

</body>
</html>
