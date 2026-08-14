@extends('layouts.student')

@section('title', 'Упражнения · '.$lesson->title)
@section('header', 'Упражнения')

@section('content')
<script>
function hindiTranscriptDrills() {
    return {
        checkUrl: @js(route('student.lesson.drills.check', [$course->slug, $lesson->id])),
        csrf: @js(csrf_token()),
        results: {},
        async submitItem(itemId, answer) {
            const body = new FormData();
            body.append('_token', this.csrf);
            body.append('item_id', itemId);
            body.append('answer', answer);
            const res = await fetch(this.checkUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                body,
            });
            if (!res.ok) {
                this.results[itemId] = { status: 'err', message: 'Не удалось проверить. Попробуйте ещё раз.' };
                return;
            }
            const data = await res.json();
            this.results[itemId] = data.ok
                ? { status: 'ok', message: 'Верно' }
                : { status: 'err', message: data.correct_answer ? ('Неверно. Правильно: ' + data.correct_answer) : 'Неверно' };
        },
    };
}
</script>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito"
     data-testid="hindi-transcript-drills"
     data-lesson-id="{{ $lesson->id }}"
     x-data="hindiTranscriptDrills()">

    <div class="relative bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8 md:p-10 mb-8 overflow-hidden">
        <div class="absolute top-0 right-0 -mt-10 -mr-10 w-40 h-40 bg-orange-50 rounded-full blur-3xl opacity-50 pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center px-4 py-1.5 rounded-full text-xs font-extrabold uppercase tracking-widest bg-orange-50 text-brand mb-5">
                <i class="fas fa-pen-fancy mr-2"></i> Упражнения
            </div>
            <h1 class="text-3xl md:text-4xl font-extrabold text-[#101010] mb-3 tracking-tight leading-tight">
                {{ $lesson->title }}
            </h1>
            <p class="text-gray-500 text-base leading-relaxed max-w-2xl">
                Задания собраны из расшифровки этого занятия: вставьте пропущенное слово хинди или переведите.
            </p>
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ $lessonUrl }}"
                   class="inline-flex items-center gap-2 text-sm font-bold text-brand hover:underline"
                   data-testid="hindi-drills-back-lesson">
                    <i class="fas fa-arrow-left text-xs"></i> К занятию
                </a>
                @if($playlistEnabled)
                    <a href="{{ route('student.programme.hindi') }}"
                       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand"
                       data-testid="hindi-drills-back-playlist">
                        Мой хинди
                    </a>
                @endif
            </div>
        </div>
    </div>

    @forelse($items as $item)
        <article class="bg-white rounded-2xl border border-gray-100 p-5 md:p-6 mb-4"
                 data-testid="hindi-drill-item"
                 data-item-id="{{ $item['id'] }}"
                 data-item-type="{{ $item['type'] }}">
            <div class="text-[10px] font-extrabold uppercase tracking-widest text-gray-400 mb-2">
                @if($item['type'] === 'translate')
                    Перевод
                @elseif($item['type'] === 'vocab_pick')
                    Выберите слово
                @else
                    Вставьте слово
                @endif
            </div>
            <p class="text-lg font-bold text-gray-900 mb-4 leading-snug">{{ $item['prompt'] }}</p>

            @if($item['type'] === 'vocab_pick' && !empty($item['choices']))
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach($item['choices'] as $choice)
                        <button type="button"
                                class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-bold hover:border-brand hover:text-brand transition-colors"
                                data-testid="hindi-drill-choice"
                                @click="submitItem('{{ $item['id'] }}', @js($choice))">
                            {{ $choice }}
                        </button>
                    @endforeach
                </div>
            @else
                <form class="flex flex-col sm:flex-row gap-3"
                      @submit.prevent="submitItem('{{ $item['id'] }}', $event.target.querySelector('[data-testid=hindi-drill-answer]').value)">
                    <input type="text"
                           autocomplete="off"
                           class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold focus:ring-2 focus:ring-brand outline-none"
                           data-testid="hindi-drill-answer"
                           placeholder="Ответ">
                    <button type="submit"
                            class="px-5 py-2.5 rounded-xl bg-brand text-white text-sm font-extrabold hover:bg-brand/90"
                            data-testid="hindi-drill-submit">
                        Проверить
                    </button>
                </form>
            @endif

            <p class="mt-3 text-sm font-bold"
               data-testid="hindi-drill-feedback"
               x-show="results['{{ $item['id'] }}']"
               :class="results['{{ $item['id'] }}']?.status === 'ok' ? 'text-green-700' : 'text-red-600'"
               x-text="results['{{ $item['id'] }}']?.message || ''"></p>
        </article>
    @empty
        <div class="bg-white rounded-2xl border border-gray-100 p-8 text-gray-500" data-testid="hindi-drills-empty">
            Из расшифровки этого занятия пока не получилось собрать упражнения. Откройте текст занятия и повторите слова оттуда.
        </div>
    @endforelse
</div>
@endsection
