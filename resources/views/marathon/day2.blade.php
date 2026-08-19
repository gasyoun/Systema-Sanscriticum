@extends('layouts.shop')

@section('title', 'День 2 — Консультация по онлайн-курсам ОРС')

@section('content')
@php
    $alreadyDone = $enrollment->day2_engaged_at !== null;
    $durationLabel = $enrollment->formatQuizDuration($enrollment->day2_quiz_seconds);
@endphp
<div class="max-w-2xl mx-auto py-12 px-4">

    <div class="text-center mb-8">
        <p class="text-xs font-black uppercase tracking-widest text-brand mb-2">День 2 из 3</p>
        <h1 class="text-2xl md:text-3xl font-black text-[#1A1A1A]">{{ $mantra ? 'Прочитайте мантру на деванагари' : 'Как устроено санскритское слово' }}</h1>
    </div>

    @if ($alreadyDone)
        <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8">
            <div class="py-2">
                <p class="text-2xl text-center mb-2">🎉</p>
                <p class="font-bold text-[#1A1A1A] text-center mb-1">День 2 пройден!</p>
                @if ($durationLabel)
                    <p class="text-sm text-gray-600 text-center mb-2">Время на опрос: <span class="font-semibold text-[#1A1A1A]">{{ $durationLabel }}</span></p>
                @endif
                @if ($mantra && $enrollment->hasSubmittedDay2Voice())
                    <p class="text-sm text-gray-500 text-center mb-4">
                        Голосовое получено{{ $enrollment->isPaidTrack() ? ' — куратор прослушает и даст обратную связь.' : ' — сверьтесь с разбором выше, это самопроверка.' }}
                    </p>
                @elseif ($mantra)
                    <p class="text-sm text-gray-500 text-center mb-4">
                        Не забудьте отправить голосовое с чтением мантры в бота @samskrte, когда будете готовы.
                    </p>
                @endif
                @if ($enrollment->day2_question)
                    <p class="text-sm text-gray-500 text-center mb-4">Ваш вопрос к консультации уже сохранён.</p>
                    <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 mb-2">{{ $enrollment->day2_question }}</div>
                @else
                    <p class="text-sm text-gray-500 text-center mb-4">Опрос засчитан. День 3 — живая консультация.</p>
                @endif
            </div>
        </div>
    @elseif ($mantra)
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8">
        <div class="mb-6">
            <p class="text-sm font-bold text-gray-400 mb-3">Прочитайте вслух, по словам</p>
            <div class="rounded-xl bg-gray-50 p-5 text-center mb-4">
                <p class="text-xl font-bold text-[#1A1A1A] mb-2 leading-relaxed">{!! nl2br(e($mantra['devanagari'])) !!}</p>
                <p class="text-sm text-gray-500 italic leading-relaxed">{!! nl2br(e($mantra['iast'])) !!}</p>
            </div>

            <div class="grid grid-cols-1 gap-2 mb-4">
                @foreach ($mantra['words'] as $w)
                    <div class="flex items-baseline justify-between px-4 py-2 rounded-lg bg-gray-50 text-sm">
                        <span class="font-semibold text-[#1A1A1A]">{{ $w['word'] }}</span>
                        <span class="text-gray-500">{{ $w['gloss'] }}</span>
                    </div>
                @endforeach
            </div>

            <p class="text-sm text-gray-600 bg-gray-50 rounded-xl p-4 mb-3">{{ $mantra['translation'] }}</p>

            @if (! empty($mantra['pronunciation_note']))
                <p class="text-xs text-gray-500 mb-3">{{ $mantra['pronunciation_note'] }}</p>
            @endif

            <div class="rounded-xl bg-brand/5 border border-brand/20 p-4 text-sm text-[#1A1A1A] mb-2">
                <p class="font-bold mb-1">Задание</p>
                <p>Прочитайте строку вслух, по словам, и пришлите голосовое сообщение прямо сюда, в бота
                    <a href="https://t.me/samskrte" class="text-brand font-semibold" target="_blank" rel="noopener">@samskrte</a>.
                    {{ $enrollment->isPaidTrack() ? 'Куратор прослушает и даст обратную связь.' : 'Это самопроверка — сверьтесь с разбором выше, отдельного ответа не будет.' }}
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('marathon.day.complete', ['day' => 2, 'token' => $token]) }}">
            @csrf
            <input type="hidden" name="duration_seconds" value="0">
            <textarea name="question" rows="3" maxlength="2000"
                      placeholder="Вопрос к живой консультации Дня 3 (необязательно)"
                      class="w-full rounded-xl border-gray-200 focus:border-brand focus:ring-brand mb-4">{{ $enrollment->day2_question }}</textarea>
            <button type="submit" class="w-full px-6 py-3 bg-brand hover:bg-brand-hover text-white font-extrabold rounded-xl transition-colors">
                Завершить День 2
            </button>
        </form>
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
                                    : 'border-gray-200 hover:border-brand/60 hover:bg-brand/5 text-gray-700 cursor-pointer'"
                                x-text="opt"></button>
                    </template>
                </div>
                <template x-if="answered">
                    <div>
                        <p class="text-sm text-gray-600 bg-gray-50 rounded-xl p-4 mb-4" x-text="step.explain"></p>
                        <button type="button" @click="next()"
                                class="w-full px-6 py-3 bg-brand hover:bg-brand-hover text-white font-extrabold rounded-xl transition-colors">
                            <span x-text="isLast ? 'Дальше — вопрос к консультации' : 'Далее'"></span>
                        </button>
                    </div>
                </template>
            </div>
        </template>

        <template x-if="done">
            <form method="POST" action="{{ route('marathon.day.complete', ['day' => 2, 'token' => $token]) }}"
                  @submit="$el.querySelector('[name=duration_seconds]').value = durationSeconds()">
                @csrf
                <input type="hidden" name="duration_seconds" value="0">
                <div class="py-2">
                    <p class="text-2xl text-center mb-2">🎉</p>
                    <p class="font-bold text-[#1A1A1A] text-center mb-1">День 2 пройден!</p>
                    <p class="text-sm text-gray-600 text-center mb-2">
                        Время на опрос:
                        <span class="font-semibold text-[#1A1A1A]" x-text="Math.floor(durationSeconds() / 60) + ' мин ' + (durationSeconds() % 60) + ' сек'"></span>
                    </p>
                    <p class="text-sm text-gray-500 text-center mb-6">
                        День 3 — живая консультация. Если у вас уже есть вопрос — оставьте его здесь,
                        мы разберем его лично (необязательно).
                    </p>
                    <textarea name="question" rows="3" maxlength="2000"
                              placeholder="Например: с чего мне начать — грамматика или чтение текстов?"
                              class="w-full rounded-xl border-gray-200 focus:border-brand focus:ring-brand mb-4">{{ $enrollment->day2_question }}</textarea>
                    <button type="submit" class="w-full px-6 py-3 bg-brand hover:bg-brand-hover text-white font-extrabold rounded-xl transition-colors">
                        Завершить День 2
                    </button>
                </div>
            </form>
        </template>

    </div>
    @endif
</div>
@endsection
