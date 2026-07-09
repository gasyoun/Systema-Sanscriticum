<x-filament-panels::page>
    @php($s = $snap)
    @php($ring = [
        'success' => 'ring-success-600/20 dark:ring-success-400/30 bg-success-50 dark:bg-success-500/10',
        'warning' => 'ring-warning-600/20 dark:ring-warning-400/30 bg-warning-50 dark:bg-warning-500/10',
        'danger' => 'ring-danger-600/20 dark:ring-danger-400/30 bg-danger-50 dark:bg-danger-500/10',
        'gray' => 'ring-gray-950/5 dark:ring-white/10 bg-gray-50 dark:bg-white/5',
    ])
    @php($dot = [
        'success' => 'bg-success-500',
        'warning' => 'bg-warning-500',
        'danger' => 'bg-danger-500',
        'gray' => 'bg-gray-300',
    ])

    @if ($s['goals']->isEmpty())
        <div class="rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4 text-sm text-gray-500 dark:text-gray-400">
            Активных целей нет.
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($s['goals'] as $card)
                @php($goal = $card['goal'])
                <div class="rounded-xl p-4 ring-1 {{ $ring[$card['level']] }}">
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <span class="inline-block h-2.5 w-2.5 rounded-full {{ $dot[$card['level']] }}"></span>
                        {{ $goal->title }}
                        <span class="text-xs text-gray-400">({{ $goal->owner?->name }})</span>
                    </div>
                    <div class="mt-1 text-lg font-bold">
                        {{ $card['current_value'] }} / {{ (float) $goal->target_value }} {{ $goal->target_metric }}
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Ожидаемый темп на сегодня: {{ round($card['expected_value'], 1) }} · прогресс {{ $card['progress_pct'] }}%
                    </div>
                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        Период: {{ $goal->period_start->toDateString() }} — {{ $goal->period_end->toDateString() }}
                    </div>

                    <div class="mt-3 flex items-center gap-2">
                        <input type="number" step="0.01" wire:model="checkinValue.{{ $goal->id }}"
                               placeholder="Текущее значение"
                               class="fi-input block w-40 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-gray-700" />
                        <button type="button" wire:click="recordCheckin({{ $goal->id }})"
                                class="fi-btn inline-flex items-center rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-500">
                            Check-in
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4 text-xs text-gray-400 dark:text-gray-500">
        Снимок на {{ $s['as_of'] }}. Еженедельный автоматический check-in — <code>goals:record-checkins</code>.
    </div>
</x-filament-panels::page>
