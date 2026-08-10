{{--
    Финальный CTA (H1085/R7, H1068 audit §3.6): раньше жёстко писал «начать
    изучать санскрит», что неверно для курсов по философии/хинди/календарю.
    $course->cta_subject — готовая фраза в вин. падеже, куратор вписывает
    вручную под курс (CourseResource, «Продающая страница»); пусто = дефолт
    «санскрит» (прежнее поведение).
--}}
@php
    $ctaSubject = $course->cta_subject ?: 'санскрит';
@endphp
<section class="mb-16 lg:mb-20" data-analytics="final-cta">
    <div class="relative overflow-hidden rounded-3xl border border-brand/30 bg-gradient-to-br from-[#1A2235] to-[#111622] p-8 lg:p-14 text-center">
        <div class="absolute top-[-30%] left-1/2 -translate-x-1/2 w-[500px] h-[500px] bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
        <div class="relative z-10">
            <h2 class="text-3xl lg:text-4xl font-extrabold text-white mb-4">Готовы начать изучать {{ $ctaSubject }}?</h2>
            <p class="text-slate-400 max-w-2xl mx-auto mb-8 leading-relaxed">
                Присоединяйтесь к курсу «{{ $course->title }}» и откройте для себя язык живой традиции.
            </p>
            <a href="#tariffs"
               class="inline-flex justify-center items-center px-10 py-4 text-base font-bold rounded-xl text-white bg-brand hover:bg-brand-hover transition-all hover:-translate-y-1 shadow-[0_0_30px_rgba(232,92,36,0.35)]">
                Выбрать тариф
            </a>
        </div>
    </div>
</section>
