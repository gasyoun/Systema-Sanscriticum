{{-- Таблица лидеров: Week / Month / All Time (Memrise-аналог, H2051). --}}
@php
    $boards = $pranaLeaderboards ?? [
        'week' => collect(),
        'month' => collect(),
        'all' => $pranaLeaderboard ?? collect(),
    ];
    $hasAny = collect($boards)->contains(fn ($c) => $c instanceof \Illuminate\Support\Collection && $c->isNotEmpty())
        || ($pranaLeaderboard ?? collect())->isNotEmpty();
@endphp
@if($hasAny || \App\Services\Prana\PranaSettings::isActive())
    <div class="mb-6 rounded-2xl border border-amber-100 bg-white p-5 md:p-6 shadow-sm"
         x-data="{ period: 'week' }">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                    <i class="fas fa-trophy"></i>
                </div>
                <div>
                    <h3 class="text-lg font-extrabold text-[#101010]">Таблица лидеров</h3>
                    <p class="text-gray-500 text-sm">Кто больше всех набрал праны — как очки в Memrise.</p>
                </div>
            </div>

            {{-- Week | Month | All Time --}}
            <div class="inline-flex rounded-xl bg-gray-100 p-1 text-sm font-bold shrink-0" role="tablist" aria-label="Период рейтинга">
                <button type="button" role="tab"
                        @click="period = 'week'"
                        :aria-selected="period === 'week'"
                        :class="period === 'week' ? 'bg-white text-amber-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                        class="px-3 py-1.5 rounded-lg transition-colors">
                    Неделя
                </button>
                <button type="button" role="tab"
                        @click="period = 'month'"
                        :aria-selected="period === 'month'"
                        :class="period === 'month' ? 'bg-white text-amber-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                        class="px-3 py-1.5 rounded-lg transition-colors">
                    Месяц
                </button>
                <button type="button" role="tab"
                        @click="period = 'all'"
                        :aria-selected="period === 'all'"
                        :class="period === 'all' ? 'bg-white text-amber-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                        class="px-3 py-1.5 rounded-lg transition-colors">
                    Всё время
                </button>
            </div>
        </div>

        @foreach(['week' => 'эту неделю', 'month' => 'этот месяц', 'all' => 'всё время'] as $key => $label)
            @php $rows = $boards[$key] ?? collect(); @endphp
            <div x-show="period === '{{ $key }}'" x-cloak
                 @if($key !== 'week') style="display: none;" @endif>
                @if($rows->isEmpty())
                    <div class="rounded-xl bg-amber-50/60 border border-amber-100 px-4 py-6 text-center">
                        <p class="text-sm text-gray-600 font-semibold">Пока никто не набрал прану за {{ $label }}.</p>
                        <p class="text-xs text-gray-400 mt-1">Заходите в уроки и SRS — станьте первыми в топе.</p>
                    </div>
                @else
                    <ul class="space-y-1.5">
                        @foreach($rows as $row)
                            @php
                                $medal = match($row['position']) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => null };
                                $points = $row['score'] ?? $row['lifetime'];
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
                                    @if(!empty($row['rank']))
                                        <span class="block text-[11px] text-gray-400">{{ $row['rank'] }}</span>
                                    @endif
                                </div>
                                <span class="shrink-0 text-sm font-extrabold text-amber-600 tabular-nums">
                                    <x-prana-lotus /> {{ number_format($points, 0, '.', ' ') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        <p class="mt-3 text-[11px] text-gray-400 leading-snug">
            Неделя — с понедельника; месяц — с 1-го числа. Считаются только <strong>начисления</strong> праны
            (уроки, стрик, SRS…), не траты в магазине. «Всё время» — накопленный lifetime-ранг.
        </p>
    </div>
@endif
