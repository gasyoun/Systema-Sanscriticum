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

    @if ($donated)
        <div class="rounded-xl border border-emerald-700 bg-emerald-900/30 p-4 mb-6 text-emerald-100">
            Спасибо! Пожертвование принято. Если вы согласились на публикацию имени,
            оно появится в <a href="{{ route('institute.gratitude') }}" class="underline">реестре благодарностей</a>.
        </div>
    @elseif ($donateFailed)
        <div class="rounded-xl border border-amber-700 bg-amber-900/20 p-4 mb-6 text-amber-100">
            Оплата не была завершена. Можно попробовать ещё раз или поддержать переводом по реквизитам ниже.
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-red-800 bg-red-900/20 p-4 mb-6 text-red-100 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    @if ($donateOnline)
        <h2 class="text-xl font-bold text-white mb-3">Поддержать онлайн</h2>
        <form method="POST" action="{{ route('institute.donate') }}" class="rounded-xl border border-slate-700 p-4 mb-6 space-y-4">
            @csrf
            <input type="hidden" name="website" value="">
            <input type="hidden" name="utm_source" value="mecenaty">
            <input type="hidden" name="utm_medium" value="page">
            <div>
                <label class="block text-slate-200 mb-2 text-sm">Сумма, ₽ (свободная)</label>
                <div class="flex flex-wrap gap-2 mb-2">
                    @foreach ($presets as $preset)
                        <button type="button"
                                class="preset-btn px-4 py-2 rounded-lg border border-slate-600 text-slate-200 hover:border-sky-500"
                                data-amount="{{ $preset }}">{{ number_format($preset, 0, '', ' ') }} ₽</button>
                    @endforeach
                </div>
                <input type="number" name="amount" min="{{ $minAmount }}" step="50" required
                       class="w-full max-w-xs rounded-lg border border-slate-600 bg-slate-900 text-white px-3 py-2"
                       placeholder="от {{ $minAmount }} ₽">
            </div>
            <div>
                <label class="block text-slate-200 mb-1 text-sm">Электронная почта (для чека)</label>
                <input type="email" name="email" required
                       class="w-full max-w-xs rounded-lg border border-slate-600 bg-slate-900 text-white px-3 py-2">
            </div>
            <div class="text-sm text-slate-300 space-y-2">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="publish_name" value="1" class="mt-1" id="publish_name">
                    <span>Указать моё имя в <a href="{{ route('institute.gratitude') }}" class="underline">реестре благодарностей</a></span>
                </label>
                <div id="name-wrap" class="hidden pl-6">
                    <input type="text" name="name" maxlength="120"
                           class="w-full max-w-xs rounded-lg border border-slate-600 bg-slate-900 text-white px-3 py-2"
                           placeholder="Как указать имя">
                </div>
                <label class="flex items-start gap-2 pl-6 {{ '' }}">
                    <input type="checkbox" name="show_amount" value="1" class="mt-1">
                    <span>Указать рядом и сумму пожертвования</span>
                </label>
            </div>
            <button type="submit"
                    class="px-5 py-2.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white font-semibold">
                Перейти к оплате
            </button>
            <p class="text-xs text-slate-500">
                Оплата картой или СБП через Точку. Это добровольное пожертвование
                (ст. 582 ГК); возврату не подлежит, встречных благ не предусматривает.
            </p>
        </form>
    @endif

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

@if ($donateOnline)
    @push('scripts')
    <script>
        document.querySelectorAll('.preset-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.querySelector('input[name="amount"]');
                input.value = btn.dataset.amount;
            });
        });
        var publish = document.getElementById('publish_name');
        if (publish) {
            publish.addEventListener('change', function () {
                document.getElementById('name-wrap').classList.toggle('hidden', !publish.checked);
            });
        }
    </script>
    @endpush
@endif
@endsection
