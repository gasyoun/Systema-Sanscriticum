@php
    /** @var \App\Models\TelegramPoll $poll */
    /** @var array<int, array{label: string, count: int, answers: \Illuminate\Support\Collection}> $tally */
    /** @var \Illuminate\Support\Collection $retracted */
    $voterLabel = function (\App\Models\TelegramPollAnswer $a): string {
        return $a->displayName();
    };
@endphp

<div class="space-y-4 text-sm">
    <div class="text-gray-600 dark:text-gray-400">
        {{ $poll->group?->name ?? '—' }}
        · {{ \App\Models\TelegramPoll::STATUS_LABELS[$poll->status] ?? $poll->status }}
        @if($poll->sent_at) · отправлен {{ $poll->sent_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }} @endif
        @if($poll->allows_multiple) · можно несколько вариантов @endif
    </div>

    @if($poll->error)
        <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
            {{ $poll->error }}
        </div>
    @endif

    @foreach($tally as $row)
        <div class="rounded-lg p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="flex items-baseline justify-between gap-3">
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['label'] }}</span>
                <span class="text-gray-600 dark:text-gray-400">{{ $row['count'] }}</span>
            </div>
            @if($row['answers']->isNotEmpty())
                <ul class="mt-2 space-y-1">
                    @foreach($row['answers'] as $answer)
                        <li class="text-gray-700 dark:text-gray-300">
                            @if($answer->user)
                                <a href="{{ \App\Filament\Resources\UserResource::getUrl('view', ['record' => $answer->user_id]) }}"
                                   target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">{{ $voterLabel($answer) }}</a>
                            @else
                                {{ $voterLabel($answer) }}
                                <span class="text-gray-400">— не привязан к кабинету</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach

    @if($retracted->isNotEmpty())
        <div class="text-gray-500 dark:text-gray-400">
            Отозвали голос: {{ $retracted->map(fn ($a) => $voterLabel($a))->join(', ') }}
        </div>
    @endif
</div>
