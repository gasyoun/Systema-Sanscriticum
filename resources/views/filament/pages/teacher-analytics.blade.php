<x-filament-panels::page>
    @php
        $funnel = $this->funnel();
        $daily = $this->dailyOpens();
        $progress = $this->studentProgress();
        $webinars = $this->webinarAttendance();
        $maxOpen = max(1, max($daily ?: [0]));
        $completionRate = $funnel['opened'] > 0
            ? round($funnel['completed_any'] / $funnel['opened'] * 100)
            : 0;
    @endphp

    {{-- Воронка --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <x-filament::section>
            <div class="text-sm text-gray-500">Студентов начали</div>
            <div class="text-3xl font-bold">{{ $funnel['opened'] }}</div>
            <div class="text-xs text-gray-400 mt-1">открыли хотя бы один урок</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Прошли ≥1 урок</div>
            <div class="text-3xl font-bold text-success-600">{{ $funnel['completed_any'] }}</div>
            <div class="text-xs text-gray-400 mt-1">{{ $completionRate }}% от начавших</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Уроков в курсах</div>
            <div class="text-3xl font-bold">{{ $funnel['lessons_total'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Прохождений уроков</div>
            <div class="text-3xl font-bold">{{ $funnel['views_completed'] }}</div>
            <div class="text-xs text-gray-400 mt-1">всего отметок «пройдено»</div>
        </x-filament::section>
    </div>

    {{-- Посуточная воронка просмотров (новые открытия уроков) --}}
    <x-filament::section>
        <x-slot name="heading">Открытий уроков по дням (30 дней)</x-slot>
        <x-slot name="description">Сколько уроков студенты открыли впервые в каждый день.</x-slot>

        <div class="flex items-end gap-1 h-40" style="min-height: 10rem;">
            @foreach ($daily as $date => $count)
                <div class="flex-1 flex flex-col items-center justify-end h-full" title="{{ $date }}: {{ $count }}">
                    <div class="w-full rounded-t bg-primary-500/80"
                         style="height: {{ $count > 0 ? max(4, round($count / $maxOpen * 100)) : 0 }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="flex justify-between text-xs text-gray-400 mt-2">
            <span>{{ array_key_first($daily) }}</span>
            <span>сегодня</span>
        </div>
    </x-filament::section>

    {{-- Прогресс студентов (меньше всего прошедшие — сверху) --}}
    <x-filament::section>
        <x-slot name="heading">Прогресс студентов</x-slot>
        <x-slot name="description">Кому нужна помощь — наверху списка.</x-slot>

        @if ($progress->isEmpty())
            <p class="text-gray-400 text-sm">Пока нет данных о просмотрах уроков.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Студент</th>
                            <th class="py-2 pr-4">Прогресс</th>
                            <th class="py-2 pr-4">Пройдено</th>
                            <th class="py-2">Последний визит</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($progress as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                                <td class="py-2 pr-4 w-48">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden">
                                            <div class="h-2 rounded-full {{ $row['percent'] >= 100 ? 'bg-success-500' : 'bg-primary-500' }}"
                                                 style="width: {{ $row['percent'] }}%"></div>
                                        </div>
                                        <span class="tabular-nums text-gray-500 w-10 text-right">{{ $row['percent'] }}%</span>
                                    </div>
                                </td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['completed'] }} / {{ $row['total'] }}</td>
                                <td class="py-2 text-gray-500">
                                    {{ $row['last_seen'] ? \Illuminate\Support\Carbon::parse($row['last_seen'])->diffForHumans() : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Посещаемость вебинаров (Zoom participant-вебхуки) --}}
    @if ($webinars->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Посещаемость вебинаров</x-slot>
            <x-slot name="description">Кто и сколько был на онлайн-занятиях (по данным Zoom).</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Вебинар</th>
                            <th class="py-2 pr-4">Дата</th>
                            <th class="py-2 pr-4">Участников</th>
                            <th class="py-2 pr-4">Из них студентов</th>
                            <th class="py-2 pr-4">Перешли по ссылке</th>
                            <th class="py-2">Ср. длительность</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($webinars as $w)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $w['title'] }}</td>
                                <td class="py-2 pr-4 text-gray-500">
                                    {{ $w['start'] ? \Illuminate\Support\Carbon::parse($w['start'])->format('d.m.Y H:i') : '—' }}
                                </td>
                                <td class="py-2 pr-4 tabular-nums font-semibold">{{ $w['attendees'] }}</td>
                                <td class="py-2 pr-4 tabular-nums text-gray-500">{{ $w['known'] }}</td>
                                <td class="py-2 pr-4 tabular-nums text-gray-500">{{ $w['clicked'] }}</td>
                                <td class="py-2 tabular-nums text-gray-500">
                                    {{ $w['avg_minutes'] !== null ? $w['avg_minutes'] . ' мин' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
