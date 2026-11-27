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

        {{-- Мини-рассылка: напоминание об оплате выбранного блока --}}
        @if (count($reminderBlockOptions))
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">Напоминание об оплате</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Должники выбранного блока. Повторно одному и тому же человеку — не раньше чем через 24 часа.
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        Должников: <b class="text-gray-900 dark:text-white">{{ count($reminderDebtors) }}</b>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Блок</label>
                        <select
                            wire:model.live="reminderBlock"
                            class="fi-select-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm"
                        >
                            @foreach ($reminderBlockOptions as $number => $label)
                                <option value="{{ $number }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Текст</label>
                        <textarea
                            wire:model.blur="reminderText"
                            rows="5"
                            class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm"
                        ></textarea>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Подстановки: {name}, {course}, {block}, {pay_link}, {paid_until}, {deadline}.
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-5 text-sm">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model.live="reminderAccount" class="rounded border-gray-300 dark:border-white/10">
                        <span class="text-gray-700 dark:text-gray-200">От имени аккаунта</span>
                        <span class="text-xs text-gray-400">({{ $reminderReach['account'] }})</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model.live="reminderBot" class="rounded border-gray-300 dark:border-white/10">
                        <span class="text-gray-700 dark:text-gray-200">От бота школы</span>
                        <span class="text-xs text-gray-400">({{ $reminderReach['bot'] }})</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model.live="reminderEmail" class="rounded border-gray-300 dark:border-white/10">
                        <span class="text-gray-700 dark:text-gray-200">На почту</span>
                        <span class="text-xs text-gray-400">({{ $reminderReach['email'] }})</span>
                    </label>
                </div>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    «От имени аккаунта» — канал только для ответа: Telegram не даёт бизнес-боту писать первым
                    (ошибка BUSINESS_PEER_USAGE_MISSING), поэтому по умолчанию он выключен. Для напоминаний используйте
                    бота школы и почту; числа в скобках — сколько должников этого блока достижимо каналом.
                </p>

                @if (count($reminderDebtors))
                    <div class="mt-4 max-h-96 overflow-y-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                        <table class="w-full text-sm">
                            <thead class="bg-white dark:bg-gray-900 text-gray-600 dark:text-gray-300 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 w-10"></th>
                                    <th class="px-3 py-2 text-left font-semibold">Студент</th>
                                    <th class="px-3 py-2 text-center font-semibold">Аккаунт</th>
                                    <th class="px-3 py-2 text-center font-semibold">Бот</th>
                                    <th class="px-3 py-2 text-center font-semibold">Почта</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($reminderDebtors as $d)
                                    <tr>
                                        <td class="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                wire:model.live="reminderIds"
                                                value="{{ $d['id'] }}"
                                                class="rounded border-gray-300 dark:border-white/10"
                                            >
                                        </td>
                                        <td class="px-3 py-2 text-gray-900 dark:text-white">{{ $d['name'] }}</td>
                                        <td class="px-3 py-2 text-center">{{ $d['account'] ? '✓' : '—' }}</td>
                                        <td class="px-3 py-2 text-center">{{ $d['bot'] ? '✓' : '—' }}</td>
                                        <td class="px-3 py-2 text-center">{{ $d['email'] ? '✓' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <x-filament::button wire:click="sendReminders" wire:loading.attr="disabled">
                            Отправить напоминание
                        </x-filament::button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Отмечено: {{ count($reminderIds) }}
                        </span>
                    </div>
                @else
                    <div class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        По этому блоку должников нет.
                    </div>
                @endif
            </div>
        @endif
    @else
        <div class="text-gray-400 p-4">Выберите курс.</div>
    @endif
</x-filament-panels::page>


