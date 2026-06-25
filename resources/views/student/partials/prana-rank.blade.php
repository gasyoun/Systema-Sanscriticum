{{-- Ранг студента по накопленной пране (lifetime). Геймификация поверх скидки. --}}
@php($rank = auth()->user()->pranaRank())

<div class="mb-6 rounded-2xl border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-5 md:p-6 shadow-sm">
    <div class="flex items-start gap-3 mb-3">
        <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
            <i class="fas fa-feather-pointed"></i>
        </div>
        <div>
            <p class="text-[11px] font-extrabold uppercase tracking-wider text-amber-600">Ваш ранг</p>
            <h3 class="text-lg font-extrabold text-[#101010]">{{ $rank['name'] }}</h3>
            <p class="text-gray-500 text-sm">Накоплено за всё время: <span class="font-bold">{{ number_format($rank['lifetime'], 0, '.', ' ') }}</span> праны</p>
        </div>
    </div>

    @if ($rank['next_name'])
        <div class="mt-2">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>до «{{ $rank['next_name'] }}»</span>
                <span class="tabular-nums">{{ $rank['progress'] }}%</span>
            </div>
            <div class="h-2 w-full rounded-full bg-amber-100 overflow-hidden">
                <div class="h-2 rounded-full bg-amber-500" style="width: {{ $rank['progress'] }}%"></div>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">
                Ещё {{ number_format(max(0, $rank['next_min'] - $rank['lifetime']), 0, '.', ' ') }} праны до следующего ранга.
                Ранг растёт от накопленной праны и не падает при тратах на скидки.
            </p>
        </div>
    @else
        <p class="text-sm text-amber-700 font-semibold mt-1">🏆 Максимальный ранг достигнут!</p>
    @endif
</div>
