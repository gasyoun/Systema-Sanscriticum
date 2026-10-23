<x-filament-panels::page>
    @php
        $date = fn ($s) => $s?->start?->translatedFormat('j M, D H:i');
    @endphp

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Сейчас ни у одного активного курса нет текущего блока (по датам блока или флагу «текущий»).
        </p>
    @else
        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Курс</th>
                        <th class="px-4 py-2 text-left font-semibold">Блок</th>
                        <th class="px-4 py-2 text-left font-semibold">Поток</th>
                        <th class="px-4 py-2 text-left font-semibold">Занятие в блоке</th>
                        <th class="px-4 py-2 text-left font-semibold">Прошло последнее</th>
                        <th class="px-4 py-2 text-left font-semibold">Следующее</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        @php
                            $block = $row['block'];
                            $streams = $row['streams'] ?: [null];
                        @endphp
                        @foreach ($streams as $i => $s)
                            <tr>
                                @if ($i === 0)
                                    <td class="px-4 py-2 align-top font-medium text-gray-900 dark:text-white" rowspan="{{ count($streams) }}">
                                        <a href="{{ $row['courseUrl'] }}" class="hover:underline">{{ $row['course']->title }}</a>
                                    </td>
                                    <td class="px-4 py-2 align-top text-gray-700 dark:text-gray-200" rowspan="{{ count($streams) }}">
                                        Блок {{ $block->number }}
                                        @if ($block->title)
                                            <span class="text-gray-400">· {{ $block->title }}</span>
                                        @endif
                                        <div class="text-xs text-gray-400">
                                            {{ $block->starts_at?->format('d.m.Y') ?? '…' }} — {{ $block->ends_at?->format('d.m.Y') ?? '…' }}
                                        </div>
                                    </td>
                                @endif

                                @if ($s === null)
                                    <td class="px-4 py-2 text-gray-400" colspan="4">в датах блока нет занятий в календаре</td>
                                @else
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-200">
                                        @if ($s['group_id'])
                                            <a href="{{ $scheduleUrl }}?tableFilters[group_id][value]={{ $s['group_id'] }}" class="hover:underline">
                                                {{ $groupNames[$s['group_id']] ?? 'группа #'.$s['group_id'] }}
                                            </a>
                                        @else
                                            весь курс
                                        @endif
                                        @if ($s['slot'])
                                            <div class="text-xs text-gray-400">{{ $s['slot'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-semibold text-gray-900 dark:text-white">
                                        @if ($s['past'] >= $s['total'])
                                            все {{ $s['total'] }} прошли
                                        @elseif ($s['past'] === 0)
                                            <span class="text-gray-500 dark:text-gray-400">ещё не начался · 1-е из {{ $s['total'] }}</span>
                                        @else
                                            {{ $s['past'] + 1 }}-е из {{ $s['total'] }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-gray-500">{{ $date($s['last_past']) ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $date($s['next']) ?? '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400">
            Счёт по календарю: занятия потока, попавшие в даты блока, без обзорных. «5-е из 12» — пятое занятие
            блока ближайшее или идёт сейчас, четыре уже прошли. Потоки одного курса считаются отдельно.
        </p>
    @endif

    @if ($upcoming->isNotEmpty())
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Между блоками</h2>
        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Курс</th>
                        <th class="px-4 py-2 text-left font-semibold">Следующий блок</th>
                        <th class="px-4 py-2 text-left font-semibold">Начало</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($upcoming as $block)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $block->course->title }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">
                                Блок {{ $block->number }}
                                @if ($block->title)
                                    <span class="text-gray-400">· {{ $block->title }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ $block->starts_at->format('d.m.Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
