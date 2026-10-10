<?php
/**
 * Секция «Проверь себя» на странице курса: квизы этапов со статусом студента.
 * Ожидает $course и $courseQuizzes (коллекция CourseQuiz с option bestAttempt).
 * Подключается из student.course и student.hybrid.course.
 */
$courseQuizzes = $courseQuizzes ?? collect();
?>
@if ($courseQuizzes->isNotEmpty())
    <section class="mt-10" aria-label="Квизы этапов курса">
        <div class="flex items-baseline justify-between mb-4 flex-wrap gap-2">
            <h2 class="text-2xl font-extrabold text-gray-900 flex items-center">
                <i class="fas fa-clipboard-check text-brand mr-3 text-xl"></i>
                Проверь себя
            </h2>
            <span class="text-sm text-gray-500 font-medium">квиз после каждого этапа</span>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($courseQuizzes as $quiz)
                @php($best = $quiz->bestAttempt)
                <a href="{{ route('student.course.quiz', [$course->slug, $quiz->block_number]) }}"
                   class="group flex items-start gap-4 bg-white rounded-2xl border p-5 transition-all duration-300 hover:-translate-y-0.5 {{ $best && $best->passed ? 'border-green-200 hover:shadow-lg' : 'border-gray-100 hover:border-brand/30 hover:shadow-lg' }}">
                    <span class="flex-shrink-0 w-12 h-12 rounded-2xl flex items-center justify-center border text-xl
                        {{ $best && $best->passed
                            ? 'bg-green-50 text-green-500 border-green-100'
                            : 'bg-orange-50 text-brand border-orange-100 group-hover:bg-brand group-hover:text-white transition-colors' }}">
                        @if ($best && $best->passed)
                            <i class="fas fa-check"></i>
                        @else
                            {{ $quiz->block_number }}
                        @endif
                    </span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-[10px] font-bold uppercase tracking-widest {{ $best && $best->passed ? 'text-green-600' : 'text-brand' }}">
                            Этап {{ $quiz->block_number }}
                        </span>
                        <span class="block font-bold text-gray-900 group-hover:text-brand leading-tight mt-0.5">
                            {{ $quiz->title }}
                        </span>
                        <span class="block text-xs text-gray-500 mt-1 font-medium">
                            @if ($best && $best->passed)
                                Пройден: {{ $best->score }}/{{ $best->total }} — повторить?
                            @elseif ($best)
                                Попытка: {{ $best->score }}/{{ $best->total }} — попробовать снова
                            @else
                                Квиз этапа — проверить знания
                            @endif
                        </span>
                    </span>
                    <i class="fas fa-chevron-right text-gray-300 group-hover:text-brand transition-colors self-center"></i>
                </a>
            @endforeach
        </div>
    </section>
@endif
