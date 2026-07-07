<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Канал → оплата (текущий месяц)</x-slot>

        @php($rows = $this->getRows())

        @if (empty($rows))
            <p class="text-sm text-gray-500">Пока нет новых регистраций в этом месяце.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="text-gray-500 border-b">
                            <th class="py-2 pr-4">Канал</th>
                            <th class="py-2 pr-4 text-right">Регистраций</th>
                            <th class="py-2 pr-4 text-right">Сматчено с лидом</th>
                            <th class="py-2 pr-4 text-right">Оплатили</th>
                            <th class="py-2 pr-4 text-right">Конверсия</th>
                            <th class="py-2 pr-4 text-right">Выручка</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-4 font-medium">{{ $row['channel'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['users'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['matched_to_lead'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['paid_users'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ number_format($row['conversion'], 1, '.', ' ') }}%</td>
                                <td class="py-2 pr-4 text-right">{{ number_format($row['revenue'], 0, '.', ' ') }} ₽</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
