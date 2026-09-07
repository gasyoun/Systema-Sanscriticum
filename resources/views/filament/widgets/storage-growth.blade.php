<x-filament-widgets::widget>
    {{-- H4298: ответ на вопрос MG «к какому месяцу исчерпается место?» --}}
    <div class="filament-widget overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 dark:text-gray-400 text-left">
                    <th class="py-2 pr-3 font-semibold">Каталог</th>
                    <th class="py-2 pr-3 font-semibold text-right">Занято</th>
                    <th class="py-2 pr-3 font-semibold text-right">Потолок</th>
                    <th class="py-2 pr-3 font-semibold text-right">Доля</th>
                    <th class="py-2 pr-3 font-semibold">Статус</th>
                    <th class="py-2 pr-3 font-semibold text-right">Рост / {{ $recentDays }} дн.</th>
                    <th class="py-2 font-semibold text-right">Прогноз до потолка</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-700/50">
                        <td class="py-2 pr-3 font-mono text-xs">{{ $row['path'] }}</td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $row['used'] }}</td>
                        <td class="py-2 pr-3 text-right text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $row['limit'] }}</td>
                        <td class="py-2 pr-3 text-right">{{ $row['ratio'] }}</td>
                        <td class="py-2 pr-3">
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap
                                {{ $row['level'] === 'red' ? 'text-danger-600' : ($row['level'] === 'yellow' ? 'text-warning-500' : 'text-success-600') }}">
                                <span class="inline-block w-2 h-2 rounded-full
                                    {{ $row['level'] === 'red' ? 'bg-danger-500' : ($row['level'] === 'yellow' ? 'bg-warning-400' : 'bg-success-500') }}"></span>
                                {{ $row['level'] === 'red' ? 'превышен' : ($row['level'] === 'yellow' ? 'внимание' : 'ок') }}
                            </span>
                        </td>
                        <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $row['growth'] }}</td>
                        <td class="py-2 text-right font-semibold whitespace-nowrap">{{ $row['eta'] }}</td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-gray-200 dark:border-gray-600 font-bold">
                    <td class="py-2 pr-3">ВСЕГО storage/app</td>
                    <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $total['used'] }}</td>
                    <td class="py-2 pr-3 text-right text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $total['limit'] }}</td>
                    <td class="py-2 pr-3 text-right">{{ $total['ratio'] }}</td>
                    <td class="py-2 pr-3"></td>
                    <td class="py-2 pr-3 text-right text-gray-500 dark:text-gray-400">по растущим каталогам</td>
                    <td class="py-2 text-right whitespace-nowrap">{{ $total['eta'] }}</td>
                </tr>
            </tbody>
        </table>

        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
            Прогноз — месяц достижения потолка при темпе последних {{ $recentDays }} дней (оценка по mtime файлов;
            перезаписи считает как рост, поэтому для бэкапных каталогов завышен — их ETA смотрите скептично).
            @if ($freeDisk)
                Свободно на диске: {{ $freeDisk }}. Данные кэшируются на 6 часов; ежедневная проверка — <code>storage:check</code> (04:20).
            @endif
        </p>
    </div>
</x-filament-widgets::widget>
