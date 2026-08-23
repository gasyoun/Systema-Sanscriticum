@extends('layouts.shop')

@section('title', 'Меценаты Института')

@section('content')
<div class="max-w-3xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold text-white mb-4">Меценаты Института</h1>
    <p class="text-slate-300 mb-4">
        Институт исследования санскрита — учебное крыло Общества ревнителей санскрита.
        Меценатская поддержка идёт на исследовательскую работу: корпуса и словари,
        издания, открытые разборы и бесплатные открытые занятия.
    </p>
    <p class="text-slate-300 mb-8">
        Пожертвование — добровольное и свободной суммы. Взамен мы ничего не продаём:
        все публикации Института остаются открытыми, а имена меценатов (по желанию)
        указываются в благодарностях изданий.
    </p>

    <h2 class="text-xl font-bold text-white mb-3">Как поддержать</h2>
    <div class="rounded-xl border border-slate-700 p-4 mb-6 text-slate-200 space-y-2">
        <p>Перевод по реквизитам ИП Гасунс М. Ю.:</p>
        <p class="whitespace-pre-line font-mono text-sm">{{ config('institute.donate_requisites') }}</p>
        <p class="text-sm text-slate-400">Назначение платежа: «Добровольное пожертвование».</p>
    </div>

    <p class="text-sm text-slate-500 mb-8">
        По вопросам меценатства — <a href="https://t.me/samskrte" class="underline">@samskrte</a>.
        Институт исследования санскрита — витрина учебного крыла ОРС; образовательную
        деятельность осуществляет ИП Гасунс М. Ю. на основании лицензии
        (<a href="https://samskrte.ru/sveden/education" class="underline">раскрытие информации</a>).
    </p>
</div>
@endsection
