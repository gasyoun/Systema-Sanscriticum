@extends('layouts.shop')

@section('title', 'Институт исследования санскрита — повышение квалификации «Санскрит» 72 ч')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-12">
    <p class="text-sm text-slate-400 mb-2">Учебное крыло Общества ревнителей санскрита</p>
    <h1 class="text-3xl font-bold text-white mb-4">Институт исследования санскрита</h1>
    <p class="text-slate-300 mb-6">
        Программа повышения квалификации «Санскрит» — 72 академических часа,
        полностью дистанционно. Удостоверение о повышении квалификации.
        Оператор образовательной деятельности — ИП Гасунс М. Ю.
        (<a href="https://samskrte.ru/sveden/education" class="underline text-slate-300">раскрытие информации</a>).
    </p>

    <div class="grid gap-4 sm:grid-cols-2 mb-8">
        <div class="rounded-xl border border-slate-700 p-4">
            <p class="text-slate-400 text-sm">Объём</p>
            <p class="text-white font-bold">72 академических часа</p>
        </div>
        <div class="rounded-xl border border-slate-700 p-4">
            <p class="text-slate-400 text-sm">Формат</p>
            <p class="text-white font-bold">Дистанционно (ЭО и ДОТ)</p>
        </div>
        <div class="rounded-xl border border-slate-700 p-4">
            <p class="text-slate-400 text-sm">Преподаватель</p>
            <p class="text-white font-bold">к. ф. н. М. Ю. Гасунс</p>
        </div>
        <div class="rounded-xl border border-slate-700 p-4">
            <p class="text-slate-400 text-sm">Стоимость пилотной когорты</p>
            <p class="text-white font-bold">30 000 ₽ за полную программу</p>
        </div>
    </div>

    <h2 class="text-xl font-bold text-white mb-3">Для кого</h2>
    <p class="text-slate-300 mb-2">
        Для тех, кому санскрит нужен профессионально: преподаватели йоги и восточных
        практик, филологи и индологи, гиды, переводчики, редакторы.
    </p>
    <ul class="list-disc list-inside text-slate-300 mb-8 space-y-1">
        <li>система языка: фонетика, санндхи, основы морфологии и синтаксиса;</li>
        <li>чтение адаптированных текстов с разбором;</li>
        <li>терминология и произношение для профессионального применения;</li>
        <li>итоговая аттестация и удостоверение о повышении квалификации.</li>
    </ul>

    <h2 class="text-xl font-bold text-white mb-3">Заявка на первую когорту</h2>

    @if(session('institute_applied'))
        <div class="rounded-xl border border-green-600 bg-green-900/30 p-4 text-slate-200 mb-6">
            Заявка принята. Мы свяжемся с вами, чтобы обсудить программу и детали зачисления.
        </div>
    @else
        <form method="POST" action="{{ route('institute.apply') }}" class="space-y-3 mb-8">
            @csrf
            <input type="hidden" name="utm_source" value="{{ request()->query('utm_source', 'institut') }}">
            <input type="hidden" name="utm_campaign" value="{{ request()->query('utm_campaign', 'a1_pk72_pilot') }}">
            <input type="hidden" name="website" value="">
            <input type="text" name="name" required maxlength="120" placeholder="Имя"
                   class="w-full rounded-lg bg-slate-800 border border-slate-600 text-white p-2">
            <input type="text" name="contact" required maxlength="255" placeholder="Email или Telegram"
                   class="w-full rounded-lg bg-slate-800 border border-slate-600 text-white p-2">
            <textarea name="experience" rows="3" maxlength="2000"
                      placeholder="Роль и опыт (необязательно): чем занимаетесь, какой у вас санскрит"
                      class="w-full rounded-lg bg-slate-800 border border-slate-600 text-slate-200 p-2"></textarea>
            <label class="flex items-start gap-2 text-sm text-slate-400">
                <input type="checkbox" name="is_promo_agreed" value="1" required
                       class="mt-1 rounded bg-slate-800 border-slate-600">
                <span>Согласен(на) на обработку персональных данных для связи по заявке.</span>
            </label>
            @error('is_promo_agreed')<p class="text-red-400 text-sm">{{ $message }}</p>@enderror
            <button type="submit"
                    class="px-5 py-2 rounded-xl bg-brand text-white font-bold focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-brand">
                Подать заявку
            </button>
        </form>
    @endif

    <p class="text-sm text-slate-500">
        Институт исследования санскрита — учебное крыло Общества ревнителей санскрита.
        Образовательную деятельность осуществляет ИП Гасунс М. Ю. на основании лицензии.
    </p>
</div>
@endsection
