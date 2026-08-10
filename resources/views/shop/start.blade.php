@extends('layouts.shop')

@section('title', 'С чего начать изучение санскрита')

@section('content')
<div class="min-h-screen bg-[#0A0D14] text-white py-16 lg:py-24 relative overflow-hidden font-sans">

    <div class="absolute top-0 left-1/4 w-96 h-96 bg-brand/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-0 w-[500px] h-[500px] bg-indigo-500/10 rounded-full blur-[150px] pointer-events-none"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-12 relative z-10">

        {{-- HERO --}}
        <div class="text-center mb-14">
            <p class="text-[11px] font-black uppercase tracking-widest text-[#38BDF8] mb-4">Для новичков</p>
            <h1 class="text-4xl md:text-5xl font-extrabold text-white tracking-tight mb-6">
                С чего начать изучение санскрита
            </h1>
            <p class="text-lg md:text-xl text-slate-400 max-w-3xl mx-auto leading-relaxed">
                Подготовка не нужна: большинство курсов начинаются с нуля.
                Два вопроса — и мы подскажем, куда идти.
            </p>
        </div>

        {{-- КВИЗ ПОДБОРА КУРСА --}}
        <section class="mb-16 lg:mb-20" data-analytics="onramp-quiz">
            <div class="max-w-2xl mx-auto rounded-2xl bg-[#111622] border border-[#1F2636] p-6 sm:p-8"
                 x-data="{
                     quiz: @js($quiz),
                     step: @js($quiz['first']),
                     result: null,
                     answer(next) {
                         if (this.quiz.results[next]) { this.result = this.quiz.results[next]; }
                         else { this.step = next; }
                     },
                     restart() { this.result = null; this.step = this.quiz.first; }
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
                        <p class="text-[10px] font-black uppercase tracking-widest text-emerald-400 mb-3">Ваш план</p>
                        <h2 class="text-2xl font-bold text-white mb-4" x-text="result.title"></h2>
                        <p class="text-slate-400 leading-relaxed mb-6" x-text="result.body"></p>
                        <div class="flex flex-wrap gap-3 mb-5">
                            <template x-for="cta in result.ctas" :key="cta.label">
                                <a :href="cta.url"
                                   class="inline-flex items-center px-5 py-3 rounded-xl text-xs font-bold transition-all"
                                   :class="cta.primary
                                       ? 'bg-brand hover:bg-brand/85 text-white shadow-[0_0_15px_rgba(232,92,36,0.3)]'
                                       : 'bg-[#141A28] border border-[#1F2636] hover:border-brand/50 text-slate-300 hover:text-white'"
                                   x-text="cta.label"></a>
                            </template>
                        </div>
                        <button type="button" @click="restart()"
                                class="text-xs font-bold text-slate-500 hover:text-brand transition-colors cursor-pointer">
                            ← Пройти заново
                        </button>
                    </div>
                </template>
            </div>
        </section>

        {{-- ЛЕСЕНКА ПРОДУКТОВ --}}
        @include('shop.partials.ladder')

        {{-- УРОВНИ --}}
        <section class="mb-16 lg:mb-20" data-analytics="levels-explainer">
            <h2 class="text-3xl font-bold text-white mb-8">Какой у вас уровень?</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach([
                    'beginner' => ['icon' => 'fa-seedling', 'text' => 'Никогда не учили санскрит — или начинали и бросили. Деванагари и чтение объясняются с самого начала.'],
                    'continuing' => ['icon' => 'fa-book-open', 'text' => 'Читаете деванагари, знаете базовую грамматику. Курсы по текстам и продолжение грамматики.'],
                    'advanced' => ['icon' => 'fa-mountain', 'text' => 'Свободно разбираете тексты со словарем. Специальные курсы: синтаксис, комментарии, сложные тексты.'],
                ] as $levelKey => $levelInfo)
                    <a href="{{ route('shop.index', ['level' => $levelKey]) }}"
                       class="group flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 p-6 transition-all">
                        <div class="w-11 h-11 rounded-xl bg-[#1F2636] flex items-center justify-center mb-4">
                            <i class="fas {{ $levelInfo['icon'] }} text-[#38BDF8]"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white mb-2 group-hover:text-brand transition-colors">
                            {{ \App\Models\Course::LEVELS[$levelKey] }}
                        </h3>
                        <p class="text-sm text-slate-400 leading-relaxed flex-1 mb-4">{{ $levelInfo['text'] }}</p>
                        <span class="text-xs font-bold text-[#38BDF8]">Смотреть курсы →</span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ДОВЕРИЕ --}}
        @include('shop.partials.trust-strip')

        {{-- БЕСПЛАТНЫЕ МАТЕРИАЛЫ (H387): мостик в хаб «Материалы» --}}
        <section class="mb-16 lg:mb-20" data-analytics="onramp-materials">
            <a href="{{ route('shop.materials') }}"
               class="group flex items-center justify-between gap-4 rounded-2xl bg-[#111622] border border-[#1F2636] hover:border-brand/50 p-6 transition-all">
                <div class="flex items-center gap-4">
                    <div class="w-11 h-11 rounded-xl bg-[#1F2636] flex items-center justify-center shrink-0">
                        <i class="fas fa-book-open text-[#38BDF8]"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-white mb-1 group-hover:text-brand transition-colors">
                            Почитать и посмотреть бесплатно
                        </h2>
                        <p class="text-sm text-slate-400">Статьи, беседы и открытые уроки — в разделе «Материалы».</p>
                    </div>
                </div>
                <span class="shrink-0 text-xs font-bold text-[#38BDF8]">Открыть →</span>
            </a>
        </section>

        {{-- ЛИД-ФОРМА (самогейтится по фича-флагу newsletter_subscribe) --}}
        <section class="mb-16 lg:mb-20 flex justify-center" data-analytics="onramp-lead">
            <x-newsletter-subscribe
                title="Не готовы выбрать курс?"
                blurb="Оставьте email — пришлем бесплатные материалы для первого шага и заведем личный кабинет." />
        </section>

        {{-- ФИНАЛЬНЫЙ CTA --}}
        <section class="text-center">
            <a href="{{ $catalogUrl }}"
               class="inline-flex items-center gap-2 px-8 py-4 bg-brand hover:bg-brand/85 text-white text-sm font-bold rounded-xl transition-all shadow-[0_0_20px_rgba(232,92,36,0.35)]">
                Смотреть все курсы
                <i class="fas fa-arrow-right"></i>
            </a>
        </section>

    </div>
</div>
@endsection
