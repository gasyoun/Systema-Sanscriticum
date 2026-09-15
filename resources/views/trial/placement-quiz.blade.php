@extends('layouts.shop')

@section('title', 'Определить свой уровень санскрита')

{{--
    H4818 (R2609-01) — F2 rung-placement квиз. Механика — копия онлайн-квиза
    подбора курса (resources/views/shop/start.blade.php): те же
    answer(next)/restart(), тот же quiz.results[next] терминал. Разница —
    терминал несёт rung-контент вместо CTA на курс, и по достижении
    терминала тихо POST'ится в /rung-placement (сохраняет в сессию, откуда
    его читает TrialController при оформлении пробного).
--}}
@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10">

        <div class="text-center mb-10">
            <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-4">Диагностика уровня</p>
            <h1 class="text-3xl md:text-4xl font-extrabold text-white tracking-tight mb-4">
                Определим ваш уровень санскрита
            </h1>
            <p class="text-lg text-slate-400 max-w-2xl mx-auto leading-relaxed">
                Несколько вопросов — и мы точнее подберём, с чего начать пробное занятие.
            </p>
        </div>

        <section data-analytics="placement-quiz">
            <div class="max-w-2xl mx-auto rounded-2xl bg-[#111622] border border-[#1F2636] p-6 sm:p-8"
                 x-data="{
                     quiz: @js($quiz),
                     step: @js($quiz['first']),
                     result: null,
                     saving: false,
                     saved: false,
                     answer(next) {
                         if (this.quiz.results[next]) {
                             this.result = this.quiz.results[next];
                             this.save(this.result.rung);
                         } else {
                             this.step = next;
                         }
                     },
                     restart() { this.result = null; this.step = this.quiz.first; this.saved = false; },
                     async save(rung) {
                         this.saving = true;
                         try {
                             const r = await fetch(@json(route('placement.quiz.store')), {
                                 method: 'POST',
                                 headers: {
                                     'Content-Type': 'application/json',
                                     'Accept': 'application/json',
                                     'X-Requested-With': 'XMLHttpRequest',
                                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                                 },
                                 body: JSON.stringify({ rung }),
                             });
                             this.saved = r.ok;
                         } finally {
                             this.saving = false;
                         }
                     }
                 }">
                <template x-if="! result">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-3" x-text="quiz.questions[step].prog"></p>
                        <h2 class="text-2xl font-bold text-white mb-6" x-text="quiz.questions[step].text"></h2>
                        <div class="grid grid-cols-1 gap-3">
                            <template x-for="opt in quiz.questions[step].opts" :key="opt.label">
                                <button type="button"
                                        @click="answer(opt.next)"
                                        class="text-left px-5 py-4 rounded-xl bg-[#141A28] border border-[#1F2636] hover:border-brand/60 hover:bg-brand/5 text-slate-200 font-semibold transition-all cursor-pointer"
                                        x-text="opt.label"></button>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="result">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-emerald-400 mb-3" x-text="result.title"></p>
                        <h2 class="text-xl font-bold text-white mb-3">На пробном занятии</h2>
                        <p class="text-slate-400 leading-relaxed mb-5" x-text="result.trial_focus"></p>
                        <h2 class="text-xl font-bold text-white mb-3">Дальше</h2>
                        <p class="text-slate-400 leading-relaxed mb-6" x-text="result.followup_offer"></p>
                        <div class="flex flex-wrap items-center gap-3 mb-5">
                            <a href="{{ route('shop.index') }}"
                               class="inline-flex items-center px-5 py-3 rounded-xl text-xs font-bold bg-brand hover:bg-brand/85 text-white shadow-[0_0_15px_rgba(232,92,36,0.3)] transition-all">
                                К пробному занятию →
                            </a>
                            <span class="text-[11px] text-slate-500" x-show="saving">Сохраняем…</span>
                            <span class="text-[11px] text-emerald-400" x-show="saved && !saving">Уровень сохранён</span>
                        </div>
                        <button type="button" @click="restart()"
                                class="text-xs font-bold text-slate-500 hover:text-brand transition-colors cursor-pointer">
                            ← Пройти заново
                        </button>
                    </div>
                </template>
            </div>
        </section>

    </div>
</div>
@endsection
