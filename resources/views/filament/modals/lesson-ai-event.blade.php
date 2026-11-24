{{--
    Разбор одного запроса к ИИ-помощнику занятия: что спросили, что выделили,
    что ответил Сократ. Текст ответа рендерим через LessonAiService::render —
    тот же санитайзер, что на странице урока: сырой markdown модели в админку
    не попадает.
--}}
@php
    $event = $event;
    $answerHtml = $event->answer_md ? app(\App\Services\Knowledge\LessonAiService::class)->render($event->answer_md) : null;
    $hasText = filled($event->question) || filled($event->selection) || filled($event->answer_md);
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3 rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
        <div>
            <div class="font-semibold text-gray-900">{{ $event->user?->name ?? ('#' . $event->user_id) }}</div>
            <div>{{ $event->user?->email }}</div>
        </div>
        <div class="text-right">
            <div>{{ $event->created_at?->format('d.m.Y H:i') }}</div>
            <div>{{ $event->lesson?->title ?? ('урок #' . $event->lesson_id) }}</div>
        </div>
        <div>
            Задача: <strong>{{ \App\Filament\Resources\LessonAiEventResource::TASK_LABELS[$event->task] ?? $event->task }}</strong>
        </div>
        <div class="text-right">
            @if($event->status === \App\Models\LessonAiEvent::STATUS_DONE)
                Ответил
            @else
                Ошибка: {{ $event->error_code ?? '—' }}
            @endif
            @if($event->latency_ms !== null)
                · {{ $event->latency_ms }} мс
            @endif
            · {{ $event->source === \App\Models\LessonAiEvent::SOURCE_CACHED ? 'из кэша' : 'модель' }}
        </div>
    </div>

    @unless($hasText)
        <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
            Текста нет: у ученика нет действующего согласия на тест (или отметки тестировщика).
            Сохранены только счётчики: задача, статус, время.
        </p>
    @endunless

    @if(filled($event->question))
        <div>
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Вопрос ученика</div>
            <div class="rounded-lg border border-gray-200 bg-white p-3">{{ $event->question }}</div>
        </div>
    @endif

    @if(filled($event->selection))
        <div>
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Выделенный фрагмент</div>
            <div class="rounded-lg border border-gray-200 bg-white p-3 italic">{{ \Illuminate\Support\Str::limit($event->selection, 1000) }}</div>
        </div>
    @endif

    @if($answerHtml !== null)
        <div>
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Ответ «Спроси Сократа»</div>
            <div class="lesson-ai-result rounded-lg border border-gray-200 bg-white p-3">{!! $answerHtml !!}</div>
        </div>
    @endif

    @if($event->notes_chars)
        <p class="text-xs text-gray-500">
            Заметки к занятию: {{ $event->notes_chars }} знаков. Сам текст заметок не сохраняется —
            только длина.
        </p>
    @endif
</div>
