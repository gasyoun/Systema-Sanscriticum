<x-filament-panels::page>
    @php
        $rows = $this->stalledLemmas();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Топ застрявших лемм</x-slot>
        <x-slot name="description">
            Слова, которые студенты открывают снова и снова, но не добавляют в свою колоду.
        </x-slot>

        @if($rows->isEmpty())
            <p class="text-sm text-gray-500">Пока нет данных — ни один студент ещё не открывал слова в текстах курса.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-gray-700/60">
                        <th class="py-2 pr-4">Лемма</th>
                        <th class="py-2 pr-4">Пакет</th>
                        <th class="py-2 pr-4">Открытий</th>
                        <th class="py-2 pr-4">Студентов</th>
                        <th class="py-2 pr-4">В колоде у</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="border-b border-gray-800/60">
                            <td class="py-2 pr-4 font-semibold" lang="sa-Latn">{{ $row['surface'] }}</td>
                            <td class="py-2 pr-4 text-gray-400">{{ $row['pack'] }}</td>
                            <td class="py-2 pr-4">{{ $row['lookups'] }}</td>
                            <td class="py-2 pr-4">{{ $row['students'] }}</td>
                            <td class="py-2 pr-4">{{ $row['added'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
