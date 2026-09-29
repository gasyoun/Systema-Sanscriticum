@extends('layouts.shop')

@section('title', 'Попробовать санскрит с нуля — первый шаг')

@section('content')
<main class="max-w-3xl mx-auto px-4 py-10 sm:py-16 text-stone-800 bg-stone-50 rounded-2xl my-6">
    <p class="text-sm font-bold text-brand mb-3">Санскрит для занятых взрослых</p>
    <h1 class="text-3xl sm:text-4xl font-extrabold mb-5">Первый шаг — попробовать, как вы учитесь</h1>
    <p class="text-lg leading-relaxed mb-8">Несколько понятных заданий помогут познакомиться с санскритом: узнать знакомые корни и увидеть, как устроено слово. Знание деванагари для этого не требуется.</p>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 sm:p-8 mb-8" aria-labelledby="preview-title">
        <h2 id="preview-title" class="text-2xl font-bold mb-3">Сначала посмотрите открытое занятие</h2>
        @if ($offer['clipUrl'])
            <p class="mb-4">Можно ли начать без деванагари? Объяснение преподавателя за 1 минуту 43 секунды — фрагмент открытого вебинара.</p>
            <iframe src="{{ $offer['clipUrl'] }}" title="Можно ли начать санскрит без деванагари — короткий фрагмент" class="w-full aspect-video min-h-[200px] rounded-xl border-0" loading="lazy" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>
            <p class="mt-4"><a href="{{ $offer['clipWatchUrl'] }}" target="_blank" rel="noopener noreferrer" class="underline">Открыть фрагмент на YouTube ↗</a> <span class="text-sm">(1:28:57–1:30:40)</span></p>
            <a href="{{ $offer['previewUrl'] }}" target="_blank" rel="noopener noreferrer" class="inline-block mt-3 underline">Полная запись на другом плеере ↗</a>
        @elseif ($offer['previewUrl'])
            <p class="mb-4">{{ $offer['previewTitle'] }}. Полная запись, бесплатно и без регистрации.</p>
            <a href="{{ $offer['previewUrl'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-xl border-2 border-brand px-5 py-3 font-bold text-stone-900">Смотреть видео <span class="sr-only">в новой вкладке</span> ↗</a>
        @else
            <p>Бесплатные открытые занятия можно выбрать в <a class="underline" href="{{ route('shop.index') }}">каталоге</a>.</p>
        @endif
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white p-5 sm:p-8 mb-8" aria-labelledby="practice-title">
        <h2 id="practice-title" class="text-2xl font-bold mb-4">Затем попробуйте сами</h2>
        <ol class="list-decimal pl-5 space-y-3 mb-6">
            <li>Узнайте санскритские слова по знакомым корням.</li>
            <li>Разберите устройство слова на простых примерах.</li>
            <li>Сформулируйте вопросы и выберите дальнейший маршрут обучения.</li>
        </ol>
        <p class="mb-3"><strong>Время:</strong> ориентир для вводных материалов — около 15 минут в день. Живая встреча занимает отдельное время и не входит в эти 15 минут.</p>
        <p class="mb-6">Пропустили день — вернитесь к материалам и продолжите со своего места. Нагрузка основного курса зависит от его программы: перед покупкой проверьте длительность занятий, время на практику и доступность записей.</p>
        <div class="border-t border-stone-200 pt-5 space-y-4">
            <div>
                <h3 class="font-bold">Самостоятельно — бесплатно</h3>
                <p>Вводные материалы и задания без личной проверки куратора. Этот вариант остается доступен.</p>
            </div>
            <div>
                <h3 class="font-bold">С проверкой — {{ $offer['price'] }} ₽</h3>
                <p>Проверка вводной практики куратором и разбор вопроса на групповой консультации. Это помощь в рамках вводных заданий, а не постоянное индивидуальное сопровождение.</p>
                @if ($offer['supportAvailable'])
                    <p class="font-semibold mt-2">Групповая консультация: {{ $offer['scheduleLabel'] }}.</p>
                    <a href="{{ route('marathon.show') }}#marathon-form" class="inline-flex mt-4 rounded-xl bg-brand px-5 py-3 text-white font-bold">Выбрать участие с проверкой →</a>
                @else
                    @if ($offer['sessionScheduled'])
                        <p class="mt-2 font-semibold" role="status">Запись на консультацию {{ $offer['scheduleLabel'] }} закрыта. Бесплатные материалы остаются доступны.</p>
                    @else
                        <p class="mt-2 font-semibold" role="status">Новая дата групповой консультации пока не подтверждена. До подтверждения даты начните с бесплатных материалов.</p>
                    @endif
                @endif
            </div>
        </div>
        <a href="{{ route('marathon.show') }}#marathon-form" class="inline-block mt-6 underline underline-offset-4">Открыть вводные материалы и условия участия</a>
    </section>

    <section aria-labelledby="next-title" class="mb-8">
        <h2 id="next-title" class="text-2xl font-bold mb-3">После знакомства — подходящий курс</h2>
        <p class="mb-4">Если захотите продолжить, сравните программу, расписание и стоимость. Вводное участие не обязывает покупать основной курс.</p>
        <a href="{{ route('shop.pathway') }}" class="underline underline-offset-4">Посмотреть маршрут обучения →</a>
    </section>
</main>
@endsection
