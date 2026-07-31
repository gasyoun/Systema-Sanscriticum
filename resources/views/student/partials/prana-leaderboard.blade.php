{{-- Multi-board gamification leaderboards (H2054): board select + Week/Month/All. --}}
@php
    $multiBoards = $gamificationBoards ?? [];
    // BC: H2051 single-board shape still accepted if multi not passed.
    if ($multiBoards === [] || $multiBoards === null) {
        $legacy = $pranaLeaderboards ?? [
            'week' => collect(),
            'month' => collect(),
            'all' => $pranaLeaderboard ?? collect(),
        ];
        $multiBoards = [[
            'key' => 'prana',
            'label' => 'Прана (суммарно)',
            'hint' => 'Кто больше всех набрал праны',
            'periods' => $legacy,
        ]];
    }
    $defaultBoard = $multiBoards[0]['key'] ?? 'prana';
    $hasAny = collect($multiBoards)->contains(function ($b) {
        $periods = $b['periods'] ?? [];
        return collect($periods)->contains(fn ($c) => $c instanceof \Illuminate\Support\Collection && $c->isNotEmpty());
    });
@endphp
@if($hasAny || \App\Services\Prana\PranaSettings::isActive() || count($multiBoards) > 0)
    <div class="mb-6 rounded-2xl border border-amber-100 bg-white p-5 md:p-6 shadow-sm"
         x-data="{ board: '{{ $defaultBoard }}', period: 'week' }">
        <div class="flex flex-col gap-3 mb-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-extrabold text-[#101010]">Таблицы лидеров</h3>
                        <p class="text-gray-500 text-sm">Прана, SRS, игры /lila, перенос очков Memrise — выберите топ.</p>
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

            {{-- Board chips (scroll on mobile) --}}
            @if(count($multiBoards) > 1)
                <div class="flex gap-1.5 overflow-x-auto pb-1 -mx-1 px-1 scrollbar-thin" role="tablist" aria-label="Тип рейтинга">
                    @foreach($multiBoards as $b)
                        <button type="button" role="tab"
                                @click="board = '{{ $b['key'] }}'"
                                :aria-selected="board === '{{ $b['key'] }}'"
                                :class="board === '{{ $b['key'] }}'
                                    ? 'bg-amber-600 text-white border-amber-600 shadow-sm'
                                    : 'bg-white text-gray-600 border-gray-200 hover:border-amber-300 hover:text-amber-800'"
                                class="shrink-0 px-3 py-1.5 rounded-full border text-xs font-bold transition-colors whitespace-nowrap"
                                title="{{ $b['hint'] ?? $b['label'] }}">
                            {{ $b['label'] }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @foreach($multiBoards as $b)
            @php $periods = $b['periods'] ?? []; @endphp
            <div x-show="board === '{{ $b['key'] }}'" x-cloak
                 @if($b['key'] !== $defaultBoard) style="display: none;" @endif>
                @if(!empty($b['hint']))
                    <p class="text-xs text-gray-400 mb-3" x-show="board === '{{ $b['key'] }}'">{{ $b['hint'] }}</p>
                @endif

                @foreach(['week' => 'эту неделю', 'month' => 'этот месяц', 'all' => 'всё время'] as $pkey => $plabel)
                    @php $rows = $periods[$pkey] ?? collect(); @endphp
                    <div x-show="period === '{{ $pkey }}'" x-cloak
                         @if($pkey !== 'week') style="display: none;" @endif>
                        @if(!($rows instanceof \Illuminate\Support\Collection) || $rows->isEmpty())
                            <div class="rounded-xl bg-amber-50/60 border border-amber-100 px-4 py-6 text-center">
                                <p class="text-sm text-gray-600 font-semibold">Пока пусто за {{ $plabel }}.</p>
                                <p class="text-xs text-gray-400 mt-1">
                                    @if(str_starts_with($b['key'], 'memrise_import'))
                                        Импортируйте CSV: <code class="text-[10px]">php artisan leaderboard:import-memrise …</code>
                                        и привяжите <code class="text-[10px]">users.memrise_username</code>.
                                    @elseif(str_starts_with($b['key'], 'lila'))
                                        Залогиньтесь и пройдите раунд на /lila — очки пишутся только авторизованным.
                                    @elseif(str_starts_with($b['key'], 'srs'))
                                        Повторяйте карточки в SRS — очки = Again…Easy (1…4).
                                    @else
                                        Заходите в уроки и SRS — станьте первыми в топе.
                                    @endif
                                </p>
                            </div>
                        @else
                            <ul class="space-y-1.5">
                                @foreach($rows as $row)
                                    @php
                                        $medal = match($row['position']) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => null };
                                        $points = $row['score'] ?? $row['lifetime'];
                                        $isPrana = ($b['key'] ?? '') === 'prana';
                                    @endphp
                                    <li class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ !empty($row['is_me']) ? 'bg-amber-50 border border-amber-200' : 'hover:bg-gray-50' }}">
                                        <span class="w-7 shrink-0 text-center font-extrabold tabular-nums {{ $row['position'] <= 3 ? 'text-base' : 'text-sm text-gray-400' }}">
                                            {{ $medal ?? $row['position'] }}
                                        </span>
                                        <div class="flex-1 min-w-0">
                                            <span class="font-bold text-gray-800 truncate">{{ $row['display'] }}</span>
                                            @if(!empty($row['is_me']))
                                                <span class="ml-1 text-[11px] font-bold text-amber-600 uppercase">вы</span>
                                            @endif
                                            @if(!empty($row['rank']))
                                                <span class="block text-[11px] text-gray-400">{{ $row['rank'] }}</span>
                                            @endif
                                        </div>
                                        <span class="shrink-0 text-sm font-extrabold text-amber-600 tabular-nums">
                                            @if($isPrana)<x-prana-lotus />@endif
                                            {{ number_format((int) $points, 0, '.', ' ') }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        <p class="mt-3 text-[11px] text-gray-400 leading-snug">
            Неделя — с понедельника; месяц — с 1-го. Прана: только начисления (не траты).
            SRS: 1–4 очка за рейтинг FSRS. /lila: завершённые раунды (только вошедшие).
            Memrise: снимок импорта; week/month совпадают, пока нет свежего CSV.
            Отключить ненужные топы: <code class="text-[10px]">config/leaderboards.php</code> → <code class="text-[10px]">enabled => false</code>.
        </p>
    </div>
@endif
