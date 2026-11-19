{{--
    H4718 (census A13) — sandhi rules ranked by how often a reader meets them in 41 DCS texts.
    Data: resources/data/corpus_sandhi/corpus_sandhi_top.json, baked by
    scripts/vendor_corpus_sandhi.py from kosha `corpus-sandhi` — never hand-edit.
    H6304 — teaching order: the same rules re-ordered by kosha `sandhi-curriculum`
    (priority = frequency × class × environment-generality, MG 14-07-2026).
--}}
@extends('layouts.slovar')

@section('title', 'Сандхи по частоте: какие правила встречаются в текстах | Общество ревнителей санскрита')
@section('meta_description', 'Правила сандхи, упорядоченные по частоте в 41 санскритском тексте: какие выучить первыми, чтобы читать половину всех стыков.')
@section('robots', 'noindex, follow')

@section('content')
    @php($stats = $layer['stats'])
    <header class="text-center mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-brand tracking-tight">Сандхи по частоте</h1>
        <p class="mt-3 text-gray-400 text-sm max-w-2xl mx-auto">
            Какие правила сандхи на самом деле встречаются в текстах — по
            <b class="text-gray-200">{{ number_format($stats['events'], 0, ',', ' ') }}</b> стыкам слов в
            <b class="text-gray-200">{{ $stats['texts'] }}</b> текстах (от «Хитопадеши» до «Махабхараты» и шастр).
        </p>
        <p class="mt-3 text-gray-500 text-xs max-w-2xl mx-auto">
            Выучите первые <b class="text-brand">{{ $stats['rules_for_50_pct'] }}</b> правил — и узнаете половину всех стыков;
            <b class="text-brand">{{ $stats['rules_for_80_pct'] }}</b> правил — 80%;
            <b class="text-brand">{{ $stats['rules_for_90_pct'] }}</b> — 90%.
            Всего в корпусе {{ number_format($stats['rules_total'], 0, ',', ' ') }} различных правил, остальные редки.
        </p>
    </header>

    <section class="mb-10 max-w-2xl mx-auto">
        <h2 class="text-sm font-semibold text-gray-300 mb-3">Доля классов среди этих {{ $stats['rules_baked'] }} правил</h2>
        <ul class="text-xs text-gray-400 space-y-1">
            @foreach($layer['categories'] as $cat)
                <li><b class="text-gray-200">{{ $cat['category'] }}</b> — {{ $cat['pct_of_baked'] }}%</li>
            @endforeach
        </ul>
    </section>

    @foreach($bands as $band)
        <section class="mb-12">
            <h2 class="text-lg font-bold text-gray-200 mb-1">
                @if($band['from'] === 0)
                    Первые {{ count($band['rules']) }} правил: половина всех стыков
                @else
                    Ещё {{ count($band['rules']) }} правил: от {{ $band['from'] }}% до {{ $band['cutoff'] }}%
                @endif
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-300">
                    <thead class="text-xs text-gray-500 border-b border-gray-700">
                        <tr>
                            <th class="py-2 pr-3">№</th>
                            <th class="py-2 pr-3">Правило</th>
                            <th class="py-2 pr-3">Класс</th>
                            <th class="py-2 pr-3 text-right">Доля</th>
                            <th class="py-2 pr-3 text-right">Нарастающим итогом</th>
                            <th class="py-2 pr-3">Пример</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($band['rules'] as $rule)
                            <tr class="border-b border-gray-800 align-top">
                                <td class="py-2 pr-3 text-gray-500">{{ $rule['rank'] }}</td>
                                <td class="py-2 pr-3 font-semibold text-gray-100 whitespace-nowrap">{{ $rule['rule'] }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-400">{{ $rule['category'] }}</td>
                                <td class="py-2 pr-3 text-right" title="{{ number_format($rule['count'], 0, ',', ' ') }} раз, в {{ $rule['n_texts'] }} текстах из {{ $stats['texts'] }}">{{ $rule['pct'] }}%</td>
                                <td class="py-2 pr-3 text-right text-gray-500">{{ $rule['cum_pct'] }}%</td>
                                <td class="py-2 pr-3 text-xs">
                                    @if($rule['example'])
                                        <span class="text-brand">{{ $rule['example']['split'] }}</span>
                                        <span class="block text-gray-500 italic">{{ $rule['example']['sentence'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @php($curriculum = $layer['curriculum'])
    <section class="mb-12">
        <h2 class="text-lg font-bold text-gray-200 mb-1" id="curriculum">Порядок изучения (учебная программа)</h2>
        <p class="text-xs text-gray-500 max-w-2xl mb-1">
            Те же {{ $stats['rules_baked'] }} правил, но в педагогическом порядке — из учебной программы kosha
            «<a class="underline" href="{{ $curriculum['source']['repo'] }}">{{ $curriculum['source']['dataset'] }}</a>»:
            приоритет = частота × класс × универсальность среды (решение MG 14-07-2026).
            Порядок по частоте выше не отменяется — это второй взгляд на тот же список.
        </p>
        <p class="text-xs text-gray-500 max-w-2xl mb-4">
            Покрытие остаётся частотным: доли и нарастающий итог — доля стыков в тех же
            {{ number_format($stats['events'], 0, ',', ' ') }} стыках корпуса, а не «процент программы».
        </p>
        @foreach($teachingGroups as $group)
            @php($pr = count($group['rules']))
            @php($prWord = $pr % 10 == 1 && $pr % 100 != 11 ? 'правило' : (in_array($pr % 10, [2, 3, 4]) && ($pr % 100 < 10 || $pr % 100 >= 20) ? 'правила' : 'правил'))
            <h3 class="text-sm font-semibold text-gray-300 mt-6 mb-2">
                @if($group['lesson'] !== null)
                    Урок {{ $group['lesson'] }} <span class="text-gray-500 font-normal">({{ $pr }} {{ $prWord }})</span>
                @else
                    Дальше — вне уроков <span class="text-gray-500 font-normal">({{ $pr }} {{ $prWord }} из хвоста программы: редкие для чтения, но нужны для 90% покрытия)</span>
                @endif
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-300">
                    <thead class="text-xs text-gray-500 border-b border-gray-700">
                        <tr>
                            <th class="py-2 pr-3">№ в программе</th>
                            <th class="py-2 pr-3">Правило</th>
                            <th class="py-2 pr-3">Класс</th>
                            <th class="py-2 pr-3 text-right">Доля в корпусе</th>
                            <th class="py-2 pr-3 text-right">№ по частоте</th>
                            <th class="py-2 pr-3">Пример</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($group['rules'] as $rule)
                            <tr class="border-b border-gray-800 align-top">
                                <td class="py-2 pr-3 text-gray-500">{{ $rule['curriculum_rank'] }}</td>
                                <td class="py-2 pr-3 font-semibold text-gray-100 whitespace-nowrap">{{ $rule['rule'] }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-400">{{ $rule['category'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $rule['pct'] }}%</td>
                                <td class="py-2 pr-3 text-right text-gray-500">{{ $rule['rank'] }}</td>
                                <td class="py-2 pr-3 text-xs">
                                    @if($rule['example'])
                                        <span class="text-brand">{{ $rule['example']['split'] }}</span>
                                        <span class="block text-gray-500 italic">{{ $rule['example']['sentence'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </section>

    <footer class="mt-10 text-xs text-gray-500 max-w-2xl mx-auto text-center">
        Данные: <a class="underline" href="{{ $layer['source']['repo'] }}">kosha</a>, набор
        «{{ $layer['source']['dataset'] }}» ({{ $layer['source']['license'] }}), срез
        {{ substr($layer['source']['commit'], 0, 9) }}. {{ $layer['source']['credit'] }}.<br>
        Порядок изучения: kosha «{{ $curriculum['source']['dataset'] }}», срез
        {{ substr($curriculum['source']['commit'], 0, 9) }} ({{ $curriculum['source']['license'] }}).
    </footer>
@endsection
