@extends('layouts.shop')

@section('title', 'Уровень санскрита — Консультация по онлайн-курсам ОРС')

@section('content')
<div class="max-w-2xl mx-auto py-12 px-4">

    <div class="text-center mb-8">
        <p class="text-xs font-black uppercase tracking-widest text-[#E85C24] mb-2">Перед Днем 1</p>
        <h1 class="text-2xl md:text-3xl font-black text-[#1A1A1A]">Какой у вас уровень санскрита?</h1>
    </div>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8"
         x-data="{
             quiz: @js($quiz),
             idx: 0,
             answered: false,
             picked: null,
             picks: [],
             tap(i) {
                 if (this.answered) return;
                 this.picked = i;
                 this.answered = true;
             },
             next() {
                 this.picks.push(this.picked);
                 this.idx++;
                 this.answered = false;
                 this.picked = null;
             },
             get step() { return this.quiz.steps[this.idx]; },
             get isLast() { return this.idx === this.quiz.steps.length - 1; },
             get done() { return this.idx >= this.quiz.steps.length; }
         }">

        <template x-if="! done">
            <div>
                <p class="text-sm font-bold text-gray-400 mb-3" x-text="'Вопрос ' + (idx + 1) + ' из ' + quiz.steps.length"></p>
                <h2 class="text-xl font-bold text-[#1A1A1A] mb-6" x-text="step.text"></h2>
                <div class="grid grid-cols-1 gap-3 mb-4">
                    <template x-for="(opt, i) in step.opts" :key="i">
                        <button type="button" @click="tap(i)"
                                class="text-left px-5 py-4 rounded-xl border font-semibold transition-all"
                                :class="answered
                                    ? (i === step.correct ? 'border-green-400 bg-green-50 text-green-800' : (i === picked ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 text-gray-400'))
                                    : 'border-gray-200 hover:border-[#E85C24]/60 hover:bg-[#E85C24]/5 text-gray-700 cursor-pointer'"
                                x-text="opt"></button>
                    </template>
                </div>
                <template x-if="answered">
                    <button type="button" @click="next()"
                            class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                        <span x-text="isLast ? 'Готово' : 'Далее'"></span>
                    </button>
                </template>
            </div>
        </template>

        <template x-if="done">
            <form method="POST" action="{{ route('marathon.level-quiz.complete', ['token' => $token]) }}">
                @csrf
                <template x-for="(p, i) in picks" :key="i">
                    <input type="hidden" :name="'picks[' + i + ']'" :value="p">
                </template>
                <div class="text-center py-4">
                    <p class="text-2xl mb-2">📊</p>
                    <p class="font-bold text-[#1A1A1A] mb-1">Квиз пройден!</p>
                    <p class="text-sm text-gray-500 mb-6">Результат подскажет, с чего начать День 1.</p>
                    <button type="submit" class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                        Узнать результат
                    </button>
                </div>
            </form>
        </template>

    </div>
</div>
@endsection
