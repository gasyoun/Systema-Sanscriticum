<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вы в партнерской программе — Общество ревнителей санскрита</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-white text-[#101010] antialiased" style="font-family: 'Montserrat', sans-serif;">

    <x-public-header variant="dark" />

    <main class="max-w-2xl mx-auto px-4 sm:px-6 py-12 md:py-16" x-data="{ copied: false }">

        <div class="text-center mb-8">
            <div class="w-16 h-16 mx-auto rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-4">
                <i class="fas fa-check text-2xl"></i>
            </div>
            @if($justRegistered)
                <h1 class="text-3xl font-extrabold mb-2">Заявка принята!</h1>
                <p class="text-gray-600">
                    Спасибо, {{ $partner->name }}. Ваш аккаунт создан и находится
                    <span class="font-bold">на модерации</span> — мы активируем его в ближайшее время
                    и подтвердим условия. Ссылку ниже можно сохранить уже сейчас.
                </p>
            @else
                <h1 class="text-3xl font-extrabold mb-2">Партнерский кабинет</h1>
                <p class="text-gray-600">Партнер: <span class="font-bold">{{ $partner->name }}</span></p>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm text-gray-500">Статус</span>
                <span class="px-3 py-1 rounded-full text-sm font-bold
                    @class([
                        'bg-amber-100 text-amber-700' => $partner->status === \App\Models\Partner::STATUS_PENDING,
                        'bg-emerald-100 text-emerald-700' => $partner->status === \App\Models\Partner::STATUS_ACTIVE,
                        'bg-gray-200 text-gray-600' => $partner->status === \App\Models\Partner::STATUS_SUSPENDED,
                    ])">
                    {{ \App\Models\Partner::STATUSES[$partner->status] ?? $partner->status }}
                </span>
            </div>

            <label class="block text-sm font-bold text-gray-700 mb-1">Ваша партнерская ссылка</label>
            <div class="flex flex-col sm:flex-row gap-2 mb-4">
                <input type="text" readonly value="{{ $partner->referralLink() }}" x-ref="link"
                       class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 bg-gray-50 text-gray-700 text-sm font-mono outline-none">
                <button type="button"
                        x-on:click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 2000)"
                        class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-colors">
                    <i class="fas" :class="copied ? 'fa-check' : 'fa-copy'"></i>
                    <span x-text="copied ? 'Скопировано' : 'Скопировать'"></span>
                </button>
            </div>

            <div class="grid grid-cols-2 gap-4 text-center">
                <div class="rounded-xl bg-gray-50 py-3">
                    <div class="text-xs text-gray-500 mb-1">Ваш код</div>
                    <div class="text-lg font-extrabold font-mono tracking-wider">{{ $partner->code }}</div>
                </div>
                <div class="rounded-xl bg-gray-50 py-3">
                    <div class="text-xs text-gray-500 mb-1">Вознаграждение за клиента</div>
                    <div class="text-lg font-extrabold text-brand">{{ number_format($reward, 0, '.', ' ') }} ₽</div>
                </div>
            </div>
        </div>

        <div class="text-center">
            <a href="{{ url('/partners') }}" class="text-sm text-gray-500 hover:text-brand">← К условиям программы</a>
        </div>
    </main>
</body>
</html>
