@extends('layouts.slovar')

@section('title', 'Транслитерация санскрита: IAST → деванагари | Общество ревнителей санскрита')
@section('meta_description', 'Живой конвертер: введите слово латиницей (IAST) и получите деванагари и SLP1. На каноническом транскодере sanskrit-util.')
@section('robots', 'noindex, follow')

@push('head')
    @vite('resources/js/transliterate.js')
@endpush

@section('content')
    <div data-playground="transliterate" class="max-w-2xl mx-auto">
        <header class="text-center mb-8">
            <h1 class="text-3xl md:text-4xl font-extrabold text-[#E85C24] tracking-tight">Транслитерация санскрита</h1>
            <p class="mt-3 text-gray-400 text-sm max-w-xl mx-auto">
                Введите слово латиницей (IAST — с диакритикой или без) и сразу увидите деванагари и SLP1.
                Преобразование идёт в браузере на каноническом транскодере
                <span lang="en">sanskrit-util</span>.
            </p>
        </header>

        <label for="translit-input" class="block text-sm font-medium text-gray-300 mb-2">
            Введите слово (например, <span lang="sa-Latn">rāma</span>, <span lang="sa-Latn">kṛṣṇa</span>)
        </label>
        <input
            id="translit-input"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="например, rama, kṛṣṇa, rājā…"
            class="w-full rounded-xl bg-[#0f1420] border border-gray-700/60 px-4 py-3 text-lg text-white placeholder-gray-600 focus:border-[#E85C24] focus:outline-none"
        >

        <div class="mt-3">
            <p class="text-xs text-gray-500 mb-2">Добавьте диакритику (вставляется в позицию курсора):</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach(['ā','ī','ū','ṛ','ṝ','ḷ','ḹ','ṃ','ḥ','ṅ','ñ','ṭ','ḍ','ṇ','ś','ṣ'] as $mark)
                    <button
                        type="button"
                        data-diacritic="{{ $mark }}"
                        aria-label="вставить {{ $mark }}"
                        class="px-2.5 py-1 rounded-md bg-gray-800/70 text-gray-200 hover:bg-gray-700 hover:text-[#E85C24] transition-colors font-mono text-sm"
                    >{{ $mark }}</button>
                @endforeach
            </div>
        </div>

        <section class="mt-8 space-y-3">
            <div class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6 text-center">
                <span class="block text-xs font-black uppercase tracking-widest text-gray-500 mb-2">Деванагари</span>
                <div id="translit-deva" lang="sa" class="deva text-4xl md:text-5xl text-white min-h-[3rem] is-empty">Начните печатать, чтобы увидеть результат…</div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-4">
                    <span class="block text-xs font-black uppercase tracking-widest text-gray-500 mb-1">IAST</span>
                    <code id="translit-iast" lang="sa-Latn" class="text-lg text-gray-200 break-all">—</code>
                </div>
                <div class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-4">
                    <span class="block text-xs font-black uppercase tracking-widest text-gray-500 mb-1">SLP1</span>
                    <code id="translit-slp1" class="text-lg text-gray-200 break-all">—</code>
                </div>
            </div>
        </section>

        <p class="mt-6 text-xs text-gray-600 text-center max-w-xl mx-auto">
            Деванагари строится по абугида-корректному пути (согласная + матра/вирама), а не побуквенной
            заменой — поэтому <span lang="sa">राम</span>, а не <span lang="sa">रआमअ</span>.
        </p>
    </div>
@endsection
