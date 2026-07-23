@extends('layouts.slovar')

@section('title', 'Транслитерация санскрита (IAST → деванагари + SLP1) | Общество ревнителей санскрита')
@section('meta_description', 'Живой конвертер IAST → деванагари и SLP1 на vendored sanskrit-util (CDSL).')
@section('robots', 'noindex, follow')

@section('content')
    <header class="text-center mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-[#E85C24] tracking-tight">Транслитерация</h1>
        <p class="mt-2 text-gray-400 text-sm max-w-xl mx-auto">
            IAST → деванагари + SLP1. Клиентский конвертер на
            <span class="text-gray-300">sanskrit-util</span> (CDSL) — без ручных таблиц.
        </p>
        <p class="mt-3 text-gray-500 text-xs">Демо · флаг <code class="text-gray-400">hub_transliterate</code> · noindex</p>
    </header>

    <div class="max-w-3xl mx-auto space-y-6">
        <section class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-6">
            <label for="hub-transliterate-input" class="block text-sm font-semibold text-gray-300 mb-2">
                Введите слово или имя (IAST)
            </label>
            <input
                id="hub-transliterate-input"
                type="text"
                autocomplete="off"
                spellcheck="false"
                placeholder="например: rāma, kṛṣṇa, Maria"
                class="w-full rounded-xl bg-[#0f1420] border border-gray-700 text-gray-100 px-4 py-3 focus:outline-none focus:border-[#E85C24]"
            />
            <p class="mt-3 text-xs text-gray-500">Диакритика (вставка в позицию курсора):</p>
            <div id="hub-diacritics" class="mt-2 flex flex-wrap gap-2"></div>
        </section>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <section class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-5">
                <h2 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-2">Деванагари</h2>
                <p id="hub-result-deva" class="deva text-2xl text-white min-h-[2rem]" lang="sa">—</p>
            </section>
            <section class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-5">
                <h2 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-2">IAST</h2>
                <p id="hub-result-iast" class="text-lg text-gray-200 min-h-[2rem] break-all" lang="sa-Latn">—</p>
            </section>
            <section class="bg-[#161b28] border border-gray-700/60 rounded-2xl p-5">
                <h2 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-2">SLP1</h2>
                <p id="hub-result-slp1" class="text-lg text-gray-200 font-mono min-h-[2rem] break-all">—</p>
            </section>
        </div>

        <p class="text-xs text-gray-500 text-center max-w-xl mx-auto">
            Правильный путь: <code class="text-gray-400">to_slp1</code> →
            <code class="text-gray-400">slp1_to_devanagari</code> (абугида). Не
            <code class="text-gray-400">iast_to_devanagari</code>.
        </p>
    </div>

    @vite('resources/js/transliterate.js')
@endsection
