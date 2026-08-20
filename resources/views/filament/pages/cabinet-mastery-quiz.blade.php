<x-filament-panels::page>
    @php($bank = $this->bank())
    @php($questions = $this->questions())

    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $bank['intro'] }}</p>
    <p class="mt-1 text-sm text-gray-500">Зачёт — {{ $bank['pass'] }} из {{ count($questions) }}.</p>

    @if ($result)
        <div @class([
            'mt-4 rounded-xl p-4 text-sm ring-1',
            'bg-green-50 ring-green-500/20 text-green-900 dark:bg-green-500/10 dark:text-green-200' => $result['passed'],
            'bg-amber-50 ring-amber-500/20 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200' => ! $result['passed'],
        ])>
            <p class="font-semibold">
                {{ $result['score'] }} из {{ $result['total'] }}
                @if ($result['passed'])
                    — зачёт.
                @else
                    — порог {{ $result['pass'] }}. Ниже разбор ошибок.
                @endif
            </p>
        </div>
    @endif

    <form wire:submit="submit" class="mt-6 space-y-6">
        @foreach ($questions as $index => $question)
            @php($qid = $question['id'])
            <fieldset class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <legend class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ $index + 1 }}. {{ $question['prompt'] }}
                </legend>
                <div class="mt-3 space-y-2">
                    @foreach ($question['options'] as $key => $label)
                        <label class="flex cursor-pointer items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" wire:model="answers.{{ $qid }}" value="{{ $key }}" class="mt-1">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('answers.'.$qid)
                    <p class="mt-2 text-sm text-danger-600">{{ $message }}</p>
                @enderror

                @if ($result)
                    @foreach ($result['details'] as $row)
                        @if ($row['id'] === $qid)
                            <p @class([
                                'mt-3 text-sm',
                                'text-green-700 dark:text-green-300' => $row['ok'],
                                'text-danger-600 dark:text-danger-400' => ! $row['ok'],
                            ])>
                                @if ($row['ok'])
                                    Верно.
                                @else
                                    Не то. {{ $row['why'] }}
                                @endif
                            </p>
                        @endif
                    @endforeach
                @endif
            </fieldset>
        @endforeach

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit">
                Проверить
            </x-filament::button>
            @if ($result)
                <x-filament::button color="gray" type="button" wire:click="resetQuiz">
                    Пройти ещё раз
                </x-filament::button>
            @endif
        </div>
    </form>
</x-filament-panels::page>
