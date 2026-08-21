<x-filament-panels::page>
    @php
        $report = $this->report();
        $students = $report['students'];
        $groups = $report['groups'];
        $courses = $report['courses'];
        $weekly = $report['weekly'];
        $chronic = $report['chronic'];
        $maxWeekly = max(1, $weekly->max('rate') ?? 0);
    @endphp

    {{-- Тренд по неделям --}}
    <x-filament::section>
        <x-slot name="heading">Тренд посещаемости по неделям</x-slot>
        <x-slot name="description">Последние {{ (int) config('attendance.default_window_days') }} дней. Столбик — доля пришедших или перешедших по ссылке от ожидавшихся. Нулевой столбик значит: занятие в календаре было, отметок Zoom/клика нет.</x-slot>

        @if ($weekly->isEmpty())
            <p class="text-gray-400 text-sm">В календаре занятий (Zoom-расписание) за последние {{ (int) config('attendance.default_window_days') }} дней нет строк. Уроки с датой в карточке курса сюда не попадают.</p>
        @else
            <div class="flex items-end gap-1 h-40" style="min-height: 10rem;">
                @foreach ($weekly as $week => $row)
                    @php
                        $bar = $row['rate'] > 0
                            ? max(8, (int) round($row['rate'] / $maxWeekly * 100))
                            : 6;
                    @endphp
                    <div class="flex-1 flex flex-col items-center justify-end h-full min-w-0"
                         title="Неделя с {{ $week }}: {{ $row['rate'] }}% ({{ $row['attended'] }} из {{ $row['expected'] }})">
                        <div class="text-[10px] tabular-nums text-gray-500 mb-1">{{ $row['rate'] }}%</div>
                        <div class="w-full rounded-t {{ $row['rate'] > 0 ? 'bg-primary-500/80' : 'bg-gray-300 dark:bg-gray-600' }}"
                             style="height: {{ $bar }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex justify-between text-xs text-gray-400 mt-2">
                <span>{{ \Illuminate\Support\Carbon::parse($weekly->keys()->first())->format('d.m') }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse($weekly->keys()->last())->format('d.m') }}</span>
            </div>
        @endif
    </x-filament::section>

    {{-- Хронические неявки --}}
    <x-filament::section>
        <x-slot name="heading">Хронические неявки</x-slot>
        <x-slot name="description">Пропустили {{ config('attendance.chronic_absence_threshold') }} последних занятий подряд.</x-slot>

        @if ($chronic->isEmpty())
            <p class="text-gray-400 text-sm">Хронических неявок нет.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Студент</th>
                            <th class="py-2 pr-4">Ожидалось</th>
                            <th class="py-2">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($chronic as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['user']->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['expected'] }}</td>
                                <td class="py-2 tabular-nums text-danger-600">{{ $row['rate'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Rate по студенту --}}
    <x-filament::section>
        <x-slot name="heading">Посещаемость по студенту</x-slot>
        <x-slot name="description">Меньше всего посещавшие — сверху.</x-slot>

        @if ($students->isEmpty())
            <p class="text-gray-400 text-sm">Нет данных за выбранный период.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Студент</th>
                            <th class="py-2 pr-4">Ожидалось</th>
                            <th class="py-2 pr-4">Посетил</th>
                            <th class="py-2">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($students as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['user']->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['expected'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['attended'] }}</td>
                                <td class="py-2 tabular-nums {{ $row['rate'] < 50 ? 'text-danger-600' : 'text-gray-700 dark:text-gray-300' }}">{{ $row['rate'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Rate по группе --}}
    <x-filament::section>
        <x-slot name="heading">Посещаемость по группе</x-slot>

        @if ($groups->isEmpty())
            <p class="text-gray-400 text-sm">Нет данных за выбранный период.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Группа</th>
                            <th class="py-2 pr-4">Ожидалось</th>
                            <th class="py-2 pr-4">Посетил</th>
                            <th class="py-2">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($groups as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['group']->name }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['expected'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['attended'] }}</td>
                                <td class="py-2 tabular-nums">{{ $row['rate'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Rate по курсу --}}
    <x-filament::section>
        <x-slot name="heading">Посещаемость по курсу</x-slot>

        @if ($courses->isEmpty())
            <p class="text-gray-400 text-sm">Нет данных за выбранный период.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Курс</th>
                            <th class="py-2 pr-4">Ожидалось</th>
                            <th class="py-2 pr-4">Посетил</th>
                            <th class="py-2">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($courses as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['course']->title }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['expected'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['attended'] }}</td>
                                <td class="py-2 tabular-nums">{{ $row['rate'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
