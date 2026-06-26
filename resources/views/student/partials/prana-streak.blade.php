{{-- Серия активных дней (streak) — геймификация. --}}
@php
    $streak = (int) (auth()->user()->streak_days ?? 0);
    $milestone = \App\Services\StreakService::nextMilestone($streak);
@endphp

@if($streak > 0)
    <div class="mb-6 rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 to-white p-5 md:p-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-orange-100 text-[#E85C24] flex items-center justify-center text-2xl shrink-0">🔥</div>
            <div class="flex-1 min-w-0">
                <h3 class="text-lg font-extrabold text-[#101010]">{{ $streak }} {{ trans_choice('день|дня|дней', $streak) }} подряд</h3>
                @if($milestone)
                    <p class="text-gray-500 text-sm">
                        Ещё {{ $milestone['remaining'] }} {{ trans_choice('день|дня|дней', $milestone['remaining']) }} до бонуса
                        <span class="font-bold text-[#E85C24]">+{{ number_format((int) (config('prana.streak_bonuses')[$milestone['next']] ?? 0), 0, '.', ' ') }} 🪷</span>.
                    </p>
                @else
                    <p class="text-amber-700 text-sm font-semibold">🏆 Все вехи серии пройдены — так держать!</p>
                @endif
            </div>
        </div>

        @if($milestone)
            @php($prevMin = collect(array_keys(config('prana.streak_bonuses', [])))->sort()->filter(fn ($m) => $m < $milestone['next'])->last() ?? 0)
            @php($span = max(1, $milestone['next'] - $prevMin))
            @php($progress = (int) round(min(100, max(0, ($streak - $prevMin) / $span * 100))))
            <div class="mt-3 h-2 w-full rounded-full bg-orange-100 overflow-hidden">
                <div class="h-2 rounded-full bg-[#E85C24]" style="width: {{ $progress }}%"></div>
            </div>
        @endif
    </div>
@endif
