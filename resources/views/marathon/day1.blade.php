@extends('layouts.shop')

@section('title', 'День 1 — Консультация по онлайн-курсам ОРС')

@section('content')
@php
    $alreadyDone = $enrollment->day1_engaged_at !== null;
    $durationLabel = $enrollment->formatQuizDuration($enrollment->day1_quiz_seconds);
@endphp
<div class="max-w-2xl mx-auto py-12 px-4">

    <div class="text-center mb-8">
        <p class="text-xs font-black uppercase tracking-widest text-[#E85C24] mb-2">День 1 из 3</p>
        <h1 class="text-2xl md:text-3xl font-black text-[#1A1A1A]">Санскрит роднее, чем кажется</h1>
    </div>

    @if ($alreadyDone)
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8">
            <div class="text-center py-4">
                <p class="text-2xl mb-2">🎉</p>
                <p class="font-bold text-[#1A1A1A] mb-1">День 1 пройден!</p>
                @if ($durationLabel)
                    <p class="text-sm text-gray-600 mb-2">Время на опрос: <span class="font-semibold text-[#1A1A1A]">{{ $durationLabel }}</span></p>
                @endif
                <p class="text-sm text-gray-500 mb-2">Завтра — как устроено само слово: корень + аффикс.</p>
                <p class="text-xs text-gray-400">Опрос уже засчитан — повторно проходить не нужно.</p>
            </div>
        </div>
    @else
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8"
         x-data="{
             quiz: @js($quiz),
             idx: 0,
             answered: false,
             picked: null,
             startedAt: Date.now(),
             durationSeconds() {
                 return Math.max(0, Math.min(7200, Math.round((Date.now() - this.startedAt) / 1000)));
             },
             tap(i) {
                 if (this.answered) return;
                 this.picked = i;
                 this.answered = true;
             },
             next() {
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
                    <div>
                        <p class="text-sm text-gray-600 bg-gray-50 rounded-xl p-4 mb-3" x-text="step.explain"></p>
                        <template x-if="step.link && step.link.url">
                            <p class="mb-4">
                                <a :href="step.link.url" target="_blank" rel="noopener noreferrer"
                                   class="text-sm font-semibold text-[#E85C24] hover:underline"
                                   x-text="step.link.label || step.link.url"></a>
                            </p>
                        </template>
                        <button type="button" @click="next()"
                                class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                            <span x-text="isLast ? 'Готово' : 'Далее'"></span>
                        </button>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="done">
            <form method="POST" action="{{ route('marathon.day.complete', ['day' => 1, 'token' => $token]) }}"
                  @submit="$el.querySelector('[name=duration_seconds]').value = durationSeconds()">
                @csrf
                <input type="hidden" name="duration_seconds" value="0">
                <div class="text-center py-4">
                    <p class="text-2xl mb-2">🎉</p>
                    <p class="font-bold text-[#1A1A1A] mb-1">День 1 пройден!</p>
                    <p class="text-sm text-gray-600 mb-2">
                        Время на опрос:
                        <span class="font-semibold text-[#1A1A1A]" x-text="Math.floor(durationSeconds() / 60) + ' мин ' + (durationSeconds() % 60) + ' сек'"></span>
                    </p>
                    <p class="text-sm text-gray-500 mb-6">Завтра — как устроено само слово: корень + аффикс.</p>
                    <button type="submit" class="w-full px-6 py-3 bg-[#E85C24] hover:bg-[#d34f1c] text-white font-extrabold rounded-xl transition-colors">
                        Завершить День 1
                    </button>
                </div>
            </form>
        </template>

    </div>
    @endif
</div>
@endsection
