{{-- Разбор твоих ошибок — CTA-блок публичных SRS-страниц (H5184 N06).
     Гостю — регистрация, вошедшему — кабинетный разбор ошибок
     (/dvaram/koloda/stats). Стиль — карточки /koloda (тёмные rounded-2xl). --}}
@php
    $isGuest = ! auth()->check();
    $ctaUrl = $isGuest ? route('register') : url('/dvaram/koloda/stats');
    $ctaLabel = $isGuest ? 'Создать кабинет и разобрать ошибки' : 'Открыть разбор ошибок';
@endphp
<section class="rounded-2xl border border-[#1F2636] bg-[#111622] p-6 sm:p-8" data-analytics="error-analysis-cta">
    <h2 class="text-xl font-extrabold text-white mb-3">Разбор твоих ошибок</h2>
    <p class="text-slate-400 leading-relaxed mb-5">
        Каждый ответ в повторении пишется в журнал. В кабинете собирается разбор последних ошибок и колода, которая закрывает слабые места — бесплатно вместе с любой колодой.
    </p>
    <a href="{{ $ctaUrl }}"
       class="inline-flex items-center px-5 py-3 rounded-xl text-xs font-bold bg-brand hover:bg-brand-hover text-white shadow-[0_0_15px_rgba(232,92,36,0.3)] transition-all">
        {{ $ctaLabel }}
        <i class="fas fa-arrow-right ml-2 text-[10px]"></i>
    </a>
</section>
