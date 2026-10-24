@extends('layouts.student')

@section('title', 'Вопросы и ответы')
@section('header', 'Вопросы и ответы')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <a href="{{ route('student.dashboard') }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> В кабинет
    </a>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8 mb-6">
        <h1 class="text-2xl font-black text-gray-900 mb-2">Вопросы и ответы</h1>
        <p class="text-sm text-gray-600 leading-relaxed mb-4">
            База знаний Академии — та же, по которой отвечает ИИ-куратор в Telegram и чате
            поддержки: обучение, оплата, доступ, записи, организация.
            Цены и расписание — в каталоге курсов, а не здесь.
        </p>
        <label for="faq-filter" class="sr-only">Поиск по вопросам</label>
        <input id="faq-filter"
               type="search"
               autocomplete="off"
               placeholder="Начните печатать — например: запись, оплата, доступ…"
               class="w-full rounded-2xl border border-gray-200 focus:border-brand focus:ring-brand px-4 py-3 text-sm text-gray-800 placeholder-gray-400"
               oninput="window.__faqFilter(this.value)">
        <p data-faq-empty hidden class="text-sm text-gray-500 mt-3">
            Ничего не нашлось по запросу. Напишите в чат поддержки — живой куратор поможет:
            <a class="text-brand font-bold underline" href="{{ route('student.dashboard') }}#chat">Поддержка</a>.
        </p>
    </div>

    @forelse ($categories as $category => $chunks)
        <section class="mb-8" data-faq-category>
            <h2 class="text-lg font-extrabold text-gray-900 mb-3">{{ $category }}</h2>

            <div class="space-y-3">
                @foreach ($chunks as $chunk)
                    <details class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                             data-faq-item
                             data-faq-text="{{ $chunk->searchText() }}">
                        <summary class="cursor-pointer select-none px-5 py-4 text-sm font-bold text-gray-800 hover:bg-orange-50/60 transition-colors flex items-center gap-3 list-none">
                            <i class="fas fa-chevron-right text-xs text-gray-400 transition-transform" aria-hidden="true"></i>
                            <span>{{ $chunk->title }}</span>
                        </summary>
                        <div class="px-5 pb-5 pt-1 text-sm text-gray-700 leading-relaxed border-t border-gray-100">
                            @if (trim($chunk->body) !== '')
                                {!! \App\Support\FaqBodyHtml::render($chunk->body) !!}
                            @else
                                <p class="text-gray-500">
                                    Ответа в базе пока нет — напишите в чат поддержки,
                                    куратор ответит лично.
                                </p>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    @empty
        <p class="text-sm text-gray-600 bg-white rounded-3xl border border-gray-100 p-6">
            База знаний временно недоступна — напишите в
            <a class="text-brand font-bold underline" href="{{ route('student.dashboard') }}#chat">чат поддержки</a>.
        </p>
    @endforelse

    <div class="bg-orange-50/70 border border-orange-100 rounded-3xl p-6 text-sm text-gray-700 leading-relaxed">
        <p class="font-extrabold text-gray-900 mb-1">Не нашли ответ?</p>
        <p>
            Напишите в чат поддержки в кабинете — сначала ответит ИИ-куратор,
            фраза «позови куратора» передаст диалог живому человеку.
            <a class="text-brand font-bold underline whitespace-nowrap"
               href="{{ route('student.dashboard') }}#chat">Открыть поддержку</a>
        </p>
        <p class="mt-3 text-[13px] text-gray-500">
            Источник ответов: {{ implode(', ', $sourceNames) }} — единая база знаний
            Академии; если вопрос касается цен или расписания, актуальные данные
            даст куратор.
        </p>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        var items = [].slice.call(document.querySelectorAll('[data-faq-item]'));
        var categories = [].slice.call(document.querySelectorAll('[data-faq-category]'));
        var empty = document.querySelector('[data-faq-empty]');

        window.__faqFilter = function (query) {
            var q = (query || '').trim().toLowerCase();
            var anyVisible = false;

            items.forEach(function (item) {
                var text = (item.getAttribute('data-faq-text') || '').toLowerCase();
                var visible = q === '' || text.indexOf(q) !== -1;
                item.hidden = !visible;
                if (visible) anyVisible = true;
            });

            categories.forEach(function (cat) {
                var hasVisible = cat.querySelectorAll('[data-faq-item]:not([hidden])').length > 0;
                cat.hidden = q !== '' && !hasVisible;
            });

            if (empty) empty.hidden = q === '' || anyVisible;
        };
    })();
</script>
@endpush
@endsection
