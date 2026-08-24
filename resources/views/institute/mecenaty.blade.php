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

    @if(config('institute.donations_enabled'))
        {{-- Онлайн-приём (план института N2): свободная сумма; пресеты — только ратифицированные MG значения из конфига. --}}
        <form method="POST" action="{{ route('institute.donate') }}" class="rounded-xl border border-slate-700 p-4 mb-6 space-y-4">
            @csrf
            @if(session('error'))
                <p class="text-sm text-red-400">{{ session('error') }}</p>
            @endif

            @if(!empty(config('institute.donate_presets')))
                <div class="flex flex-wrap gap-2">
                    @foreach(config('institute.donate_presets') as $preset)
                        <button type="submit" name="amount" value="{{ $preset }}"
                                class="rounded-lg border border-slate-500 px-4 py-2 text-slate-200 hover:border-slate-300">
                            {{ number_format((int) $preset, 0, ',', ' ') }} ₽
                        </button>
                    @endforeach
                </div>
            @endif

            <div>
                <label for="amount" class="block text-sm font-bold text-slate-200 mb-1">Свободная сумма, ₽</label>
                <input type="number" id="amount" name="amount" required min="{{ config('institute.donate_min') }}"
                       max="{{ config('institute.donate_max') }}" step="1"
                       class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-slate-100">
                @error('amount')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
            </div>

            @guest
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-bold text-slate-200 mb-1">Имя</label>
                        <input type="text" id="name" name="name" required maxlength="255"
                               class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-slate-100">
                        @error('name')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-bold text-slate-200 mb-1">Email (для чека)</label>
                        <input type="email" id="email" name="email" required maxlength="255"
                               class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-slate-100">
                        @error('email')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
            @endguest

            <div class="rounded-lg border border-slate-700 p-3 space-y-2">
                <label class="flex items-start gap-2 text-sm text-slate-300">
                    <input type="checkbox" name="gratitude_consent" value="1"
                           class="mt-0.5 rounded border-slate-500 bg-slate-800">
                    <span>Указать моё имя в благодарностях меценатам (публичный список ниже; по желанию)</span>
                </label>
                <div>
                    <label for="gratitude_name" class="block text-sm font-bold text-slate-200 mb-1">Имя для благодарности</label>
                    <input type="text" id="gratitude_name" name="gratitude_name" maxlength="120"
                           placeholder="Как указать вас в списке"
                           class="w-full rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-slate-100">
                    @error('gratitude_name')<p class="text-sm text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-start gap-2 text-sm text-slate-300 pl-6">
                    <input type="checkbox" name="gratitude_amount" value="1"
                           class="mt-0.5 rounded border-slate-500 bg-slate-800">
                    <span>Указать рядом и сумму пожертвования (только по вашему отдельному желанию)</span>
                </label>
            </div>
            </div>

            <button type="submit"
                    class="rounded-lg bg-brand px-6 py-2 font-bold text-white hover:opacity-90">
                Поддержать
            </button>
        </form>
    @else
        <div class="rounded-xl border border-slate-700 p-4 mb-6 text-slate-200 space-y-2">
            <p>Перевод по реквизитам ИП Гасунс М. Ю.:</p>
            <p class="whitespace-pre-line font-mono text-sm">{{ config('institute.donate_requisites') }}</p>
            <p class="text-sm text-slate-400">Назначение платежа: «Добровольное пожертвование».</p>
        </div>
    @endif

    @if($gratitudes->isNotEmpty())
        <h2 class="text-xl font-bold text-white mb-3">Благодарности меценатам</h2>
        <div class="rounded-xl border border-slate-700 p-4 mb-6">
            <ul class="space-y-1 text-slate-200">
                @foreach($gratitudes as $gratitude)
                    <li>
                        {{ $gratitude->name_display }}@if($gratitude->show_amount && $gratitude->payment)
                            — {{ number_format((float) $gratitude->payment->amount, 0, '', ' ') }} ₽
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="text-sm text-slate-400 mt-3">Имена публикуются только с согласия каждого мецената.</p>
        </div>
    @endif

    <p class="text-sm text-slate-500 mb-8">
        По вопросам меценатства — <a href="https://t.me/samskrte" class="underline">@samskrte</a>.
        Институт исследования санскрита — витрина учебного крыла ОРС; образовательную
        деятельность осуществляет ИП Гасунс М. Ю. на основании лицензии
        (<a href="https://samskrte.ru/sveden/education" class="underline">раскрытие информации</a>).
    </p>
</div>
@endsection
