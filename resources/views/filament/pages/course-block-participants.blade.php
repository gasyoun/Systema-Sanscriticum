<x-filament-panels::page>
    {{-- Выбор курса --}}
    <div class="max-w-md">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Курс</label>
        <select
            wire:model.live="courseId"
            class="fi-select-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm"
        >
            @foreach ($courseOptions as $id => $title)
                <option value="{{ $id }}">{{ $title }}</option>
            @endforeach
        </select>
    </div>

    @if ($report)
        {{-- Сводка: участники групп + всего оплативших --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Участников в группах курса</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $report['participants_total'] }}</div>
                @if (count($report['groups']))
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($report['groups'] as $g)
                            <span class="inline-flex items-center gap-1 rounded-md bg-white px-2 py-0.5 text-xs ring-1 ring-gray-950/10 dark:bg-white/10 dark:ring-white/10">
                                {{ $g['name'] }}
                                <b class="text-primary-600 dark:text-primary-400">{{ $g['count'] }}</b>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Оплатили хотя бы один блок</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $report['paid_any'] }}</div>
            </div>
        </div>

        {{-- Разбивка по блокам --}}
        @if (count($report['blocks']))
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">Блок</th>
                            <th class="px-4 py-2 text-left font-semibold">Период</th>
                            <th class="px-4 py-2 text-center font-semibold">Оплатили целиком</th>
                            <th class="px-4 py-2 text-center font-semibold">1-я половина</th>
                            <th class="px-4 py-2 text-center font-semibold">2-я половина</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($report['blocks'] as $b)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">
                                    Блок {{ $b['number'] }}
                                    @if ($b['title'])
                                        <span class="text-gray-400">· {{ $b['title'] }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-500">
                                    {{ $b['starts_at']?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td class="px-4 py-2 text-center font-bold text-gray-900 dark:text-white">{{ $b['whole'] }}</td>
                                <td class="px-4 py-2 text-center text-gray-700 dark:text-gray-200">{{ $b['half1'] }}</td>
                                <td class="px-4 py-2 text-center text-gray-700 dark:text-gray-200">{{ $b['half2'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400">
                «Целиком» — студенты с доступом к обеим половинам блока (тариф full, весь блок, диапазон или обе половины).
                Половины — у кого открыта хотя бы соответствующая часть. Учтены только оплаченные платежи (без «обещанного» доступа).
            </p>

            {{-- Матрица «студент × блок»: кто какой блок оплатил --}}
            @php
                $cell = fn (?string $s) => match ($s) {
                    'whole' => ['✓', 'text-green-600 bg-green-50 dark:bg-green-500/10'],
                    'half1' => ['½¹', 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'],
                    'half2' => ['½²', 'text-amber-600 bg-amber-50 dark:bg-amber-500/10'],
                    default => ['—', 'text-gray-300'],
                };
            @endphp

            <div class="mt-2">
                <div class="flex flex-wrap gap-3 mb-2 text-xs text-gray-500">
                    <span><b class="text-green-600">✓</b> блок целиком</span>
                    <span><b class="text-amber-600">½¹ / ½²</b> только половина</span>
                    <span><b class="text-gray-300">—</b> не оплачен</span>
                    <span class="text-gray-400">Студентов с оплатой: {{ count($report['students']) }}</span>
                </div>

                @if (count($report['students']))
                    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                        <table class="text-sm" style="border-collapse: collapse; white-space: nowrap;">
                            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                                <tr>
                                    <th class="px-4 py-2 text-left font-semibold sticky left-0 bg-gray-50 dark:bg-gray-900 z-10">Студент</th>
                                    @foreach ($report['block_numbers'] as $n)
                                        <th class="px-3 py-2 text-center font-semibold">Б{{ $n }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($report['students'] as $st)
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-900 dark:text-white sticky left-0 bg-white dark:bg-gray-900 z-10">{{ $st['name'] }}</td>
                                        @foreach ($report['block_numbers'] as $n)
                                            @php([$mark, $cls] = $cell($st['blocks'][$n] ?? null))
                                            <td class="px-3 py-2 text-center font-bold {{ $cls }}">{{ $mark }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-gray-400 p-4">По этому курсу пока нет оплат блоков.</div>
                @endif
            </div>
        @else
            <div class="text-gray-400 p-4">У курса не заданы блоки.</div>
        @endif
    @else
        <div class="text-gray-400 p-4">Выберите курс.</div>
    @endif
</x-filament-panels::page>
