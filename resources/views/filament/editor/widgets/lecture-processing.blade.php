<div wire:poll.4s="refreshStatus">
    @if ($isProcessing)
        <div class="flex items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300">
            <x-filament::loading-indicator class="h-5 w-5 shrink-0" />
            <div class="text-sm leading-snug">
                <span class="font-semibold">Выполняется: {{ $label }}</span>
                @if ($startedAt)
                    <span class="text-amber-600/80 dark:text-amber-400/70">— с {{ \Illuminate\Support\Carbon::parse($startedAt)->format('H:i:s') }}</span>
                @endif
                <div class="text-xs text-amber-600/80 dark:text-amber-400/70">Кнопки заблокированы до завершения. Страница обновится сама.</div>
            </div>
        </div>
    @elseif (! empty($lastAi['summary']))
        <div class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 dark:border-emerald-500/40 dark:bg-emerald-500/10 dark:text-emerald-300">
            <x-filament::icon icon="heroicon-o-sparkles" class="mt-0.5 h-4 w-4 shrink-0" />
            <div class="text-sm leading-snug">
                <span class="font-semibold">Последний прогон ИИ:</span>
                <span>{{ $lastAi['summary'] }}</span>
            </div>
        </div>
    @endif
</div>
