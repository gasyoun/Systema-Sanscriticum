{{-- Таблица лидеров по накопленной пране (геймификация). Имена замаскированы. --}}
@if(($pranaLeaderboard ?? collect())->isNotEmpty())
    <div class="mb-6 rounded-2xl border border-amber-100 bg-white p-5 md:p-6 shadow-sm">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                <i class="fas fa-trophy"></i>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-[#101010]">Таблица лидеров</h3>
                <p class="text-gray-500 text-sm">Кто больше всех накопил праны за всё время.</p>
            </div>
        </div>

        <ul class="space-y-1.5">
            @foreach($pranaLeaderboard as $row)
                @php
                    $medal = match($row['position']) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => null };
                @endphp
                <li class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ $row['is_me'] ? 'bg-amber-50 border border-amber-200' : 'hover:bg-gray-50' }}">
                    <span class="w-7 shrink-0 text-center font-extrabold tabular-nums {{ $row['position'] <= 3 ? 'text-base' : 'text-sm text-gray-400' }}">
                        {{ $medal ?? $row['position'] }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <span class="font-bold text-gray-800 truncate">{{ $row['display'] }}</span>
                        @if($row['is_me'])
                            <span class="ml-1 text-[11px] font-bold text-amber-600 uppercase">вы</span>
                        @endif
                        <span class="block text-[11px] text-gray-400">{{ $row['rank'] }}</span>
                    </div>
                    <span class="shrink-0 text-sm font-extrabold text-amber-600 tabular-nums">
                        🪷 {{ number_format($row['lifetime'], 0, '.', ' ') }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
