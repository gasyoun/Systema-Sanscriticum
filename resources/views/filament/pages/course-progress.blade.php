<x-filament-panels::page>
    @php
        $date = fn ($s) => $s?->start?->translatedFormat('j M, D H:i');
    @endphp

    @if ($rows->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">
            В расписании нет занятий в пределах {{ $windowDays }} дней от сегодня.
        </p>
    @else
        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Курс</th>
                        <th class="px-4 py-2 text-left font-semibold">Поток</th>
                        <th class="px-4 py-2 text-left font-semibold">Блок</th>
                        <th class="px-4 py-2 text-left font-semibold">Занятие в блоке</th>
                        <th class="px-4 py-2 text-left font-semibold">Занятие курса</th>
                        <th class="px-4 py-2 text-left font-semibold">Прошло последнее</th>
                        <th class="px-4 py-2 text-left font-semibold">Следующее</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">
                                @if ($row['course_url'])
                                    <a href="{{ $row['course_url'] }}" class="hover:underline">{{ $row['course_title'] }}</a>
                                @else
                                    {{ $row['course_title'] }}
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">
                                @if ($row['group_id'])
                                    <a href="{{ $scheduleUrl }}?tableFilters[group_id][value]={{ $row['group_id'] }}" class="hover:underline">
                                        {{ $row['group_name'] }}
                                    </a>
                                @else
                                    весь курс
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">Блок {{ $row['block'] }}</td>
                            <td class="px-4 py-2 font-semibold text-gray-900 dark:text-white">
                                @if ($row['next'] === null)
                                    <span class="text-gray-500 dark:text-gray-400">{{ $row['in_block'] }}-е прошло, дальше в календаре пусто</span>
                                @else
                                    {{ $row['in_block'] }}-е из {{ $blockSize }}
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">
                                {{ $row['number'] }}-е{{ $row['total'] ? ' из '.$row['total'] : '' }}
                                @if ($row['numbered_by'] === 'order')
                                    <div class="text-xs text-warning-600 dark:text-warning-400">по порядку: в названии нет «(#N, …)»</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ $date($row['last_past']) ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $date($row['next']) ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400">
            Всё по расписанию: номер занятия — из названия события «(#13, 08.09.26)», блок — по {{ $blockSize }} занятия
            подряд (так же считают напоминания об оплате блока). Номер — у ближайшего или идущего сейчас занятия.
            Обзорные занятия не считаются. Показаны потоки с занятиями в пределах {{ $windowDays }} дней от сегодня.
        </p>
    @endif
</x-filament-panels::page>
