{{-- Финальный CTA. Статичный, ведёт к тарифам. --}}
<section class="mb-16 lg:mb-20" data-analytics="final-cta">
    <div class="relative overflow-hidden rounded-3xl border border-[#E85C24]/30 bg-gradient-to-br from-[#1A2235] to-[#111622] p-8 lg:p-14 text-center">
        <div class="absolute top-[-30%] left-1/2 -translate-x-1/2 w-[500px] h-[500px] bg-[#E85C24]/10 rounded-full blur-[120px] pointer-events-none"></div>
        <div class="relative z-10">
            <h2 class="text-3xl lg:text-4xl font-extrabold text-white mb-4">Готовы начать изучать санскрит?</h2>
            <p class="text-slate-400 max-w-2xl mx-auto mb-8 leading-relaxed">
                Присоединяйтесь к курсу «{{ $course->title }}» и откройте для себя язык живой традиции.
            </p>
            <a href="#tariffs"
               class="inline-flex justify-center items-center px-10 py-4 text-base font-bold rounded-xl text-white bg-[#E85C24] hover:bg-[#d64e1c] transition-all hover:-translate-y-1 shadow-[0_0_30px_rgba(232,92,36,0.35)]">
                Выбрать тариф
            </a>
        </div>
    </div>
</section>
