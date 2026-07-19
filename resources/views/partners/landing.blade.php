<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $pageTitle = 'Партнерская программа — Общество ревнителей санскрита';
        $description = 'Рекомендуйте наши курсы и получайте вознаграждение за каждого приведенного клиента. Все условия и цифры — сразу при регистрации.';
    @endphp
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-white text-[#101010] antialiased" style="font-family: 'Montserrat', sans-serif;">

    <x-public-header variant="dark" />

    <main class="max-w-4xl mx-auto px-4 sm:px-6 py-10 md:py-16">

        {{-- Hero / оффер --}}
        <section class="text-center mb-12">
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#E85C24]/10 text-[#E85C24] text-sm font-bold mb-4">
                <i class="fas fa-handshake"></i> Партнерская программа
            </span>
            <h1 class="text-3xl md:text-5xl font-extrabold leading-tight mb-4">
                Рекомендуйте нас — <span class="text-[#E85C24]">и получайте вознаграждение</span>
            </h1>
            <p class="text-gray-600 text-lg max-w-2xl mx-auto">
                Мы ценим каждую связь, которая сложилась за время работы, — и предлагаем вывести ее
                на новый уровень. Вы рекомендуете наши курсы, а мы благодарим вас
                <span class="font-bold text-[#101010]">{{ number_format($reward, 0, '.', ' ') }} ₽</span>
                за каждого приведенного клиента. Всю остальную работу берем на себя.
            </p>
        </section>

        {{-- Как это работает --}}
        <section class="grid md:grid-cols-3 gap-4 mb-12">
            @foreach([
                ['fa-link', 'Делитесь ссылкой', 'После регистрации вы получите personal-ссылку и код. Отправляйте их тем, кому наши курсы могут быть полезны.'],
                ['fa-user-graduate', 'Клиент учится', 'Мы принимаем клиента, консультируем и ведем его сами — от вас нужна только рекомендация.'],
                ['fa-ruble-sign', 'Вы получаете выплату', 'Когда приведенный клиент впервые оплачивает курс, вам начисляется фиксированное вознаграждение к выплате.'],
            ] as [$icon, $title, $text])
                <div class="rounded-2xl border border-gray-100 bg-gray-50/60 p-5">
                    <div class="w-11 h-11 rounded-xl bg-[#E85C24]/10 text-[#E85C24] flex items-center justify-center mb-3">
                        <i class="fas {{ $icon }}"></i>
                    </div>
                    <h3 class="font-extrabold mb-1">{{ $title }}</h3>
                    <p class="text-sm text-gray-600">{{ $text }}</p>
                </div>
            @endforeach
        </section>

        {{-- Условия и цифры --}}
        <section class="mb-12 rounded-2xl border border-gray-200 overflow-hidden">
            <div class="bg-[#101010] text-white px-5 py-3 font-bold">Условия программы</div>
            <dl class="divide-y divide-gray-100">
                @foreach([
                    ['Вознаграждение', number_format($reward, 0, '.', ' ').' ₽ за каждого приведенного клиента'],
                    ['Когда начисляется', 'При первой реальной оплате курса приведенным клиентом'],
                    ['Учет', 'Прозрачно: в личном кабинете партнера и в Telegram-боте видно каждого клиента и статус выплаты'],
                    ['Как платим', 'Реальная выплата на согласованные реквизиты (карта / СБП). Не кредит на покупку.'],
                    ['Что от вас нужно', 'Только рекомендация. Продажу, поддержку и обучение ведем мы.'],
                ] as [$term, $val])
                    <div class="px-5 py-3 flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                        <dt class="sm:w-52 shrink-0 text-sm font-bold text-gray-500">{{ $term }}</dt>
                        <dd class="text-[#101010]">{{ $val }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Форма заявки --}}
        <section id="join" class="rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 to-white p-6 md:p-8">
            <h2 class="text-2xl font-extrabold mb-1">Присоединиться</h2>
            <p class="text-gray-600 mb-6">Заполните заявку — мы активируем ваш партнерский аккаунт и вышлем ссылку для рекомендаций.</p>

            @if($errors->any())
                <div class="mb-4 rounded-xl bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('partners.register') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Ваше имя <span class="text-[#E85C24]">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}" required
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white outline-none focus:border-[#E85C24]">
                </div>
                <div class="grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Telegram</label>
                        <input type="text" name="telegram_username" value="{{ old('telegram_username') }}" placeholder="@username"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white outline-none focus:border-[#E85C24]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white outline-none focus:border-[#E85C24]">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Телефон</label>
                        <input type="text" name="phone" value="{{ old('phone') }}"
                               class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white outline-none focus:border-[#E85C24]">
                    </div>
                </div>
                <p class="text-xs text-gray-500">Укажите хотя бы один контакт: Telegram, email или телефон.</p>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Реквизиты для выплат <span class="text-gray-400 font-normal">(необязательно)</span></label>
                    <input type="text" name="payout_details" value="{{ old('payout_details') }}" placeholder="Карта / СБП — можно уточнить позже"
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 bg-white outline-none focus:border-[#E85C24]">
                </div>
                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-[#E85C24] hover:bg-[#d04a15] text-white font-bold transition-colors">
                    <i class="fas fa-paper-plane"></i> Отправить заявку
                </button>
            </form>
        </section>
    </main>

    <footer class="border-t border-gray-100 mt-12 py-8 bg-gray-50">
        <div class="max-w-4xl mx-auto px-4">
            @include('partials.legal-requisites', ['class' => 'text-center text-[12px] text-gray-500'])
        </div>
    </footer>

    @includeIf('partials.cookie-consent')
</body>
</html>
