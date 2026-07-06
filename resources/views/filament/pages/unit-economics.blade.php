<x-filament-panels::page>
    {{-- Выбор курса / блока / CAC --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Курс</label>
            <select wire:model.live="courseId"
                class="fi-select-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm">
                @foreach ($courseOptions as $id => $title)
                    <option value="{{ $id }}">{{ $title }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Блок</label>
            <select wire:model.live="blockNumber"
                class="fi-select-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm">
                @foreach ($blockOptions as $n => $label)
                    <option value="{{ $n }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">CAC на ученика, ₽ (пусто = авто)</label>
            <input type="number" step="0.01" wire:model.live.debounce.600ms="cacOverride"
                placeholder="авто из рекламы"
                class="fi-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm" />
        </div>
    </div>

    @php($e = $econ)
    @if (empty($e['found']))
        <div class="rounded-xl bg-gray-50 p-6 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            Выберите курс и блок.
        </div>
    @else
        {{-- Шапка блока --}}
        <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10 text-sm">
            <b>{{ $e['course_title'] }}</b> · Блок {{ $e['block_number'] }} ·
            цена блока <b>{{ number_format($e['price'], 0, '.', ' ') }} ₽</b> ·
            уроков <b>{{ $e['lessons'] }}</b> ·
            оплатили <b>{{ $e['payers'] }}</b> · бесплатники {{ $e['free_students'] }} ·
            выручка блока <b>{{ number_format($e['revenue'], 0, '.', ' ') }} ₽</b>
            <div class="mt-1 text-gray-500 dark:text-gray-400">
                Преподаватель: {{ $e['teacher_name'] ?? '—' }} · схема {{ $e['salary_type'] ?? '—' }} ({{ $e['salary_value'] }})
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- 1. Ученик --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Ученик — один урок блока</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($e['student_per_lesson'], 2, '.', ' ') }} ₽</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">цена блока ÷ {{ $e['lessons'] }} (по прайсу; у каждого своя из-за скидок/зачётов)</div>
            </div>

            {{-- 2. Преподаватель --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Преподаватель — один урок блока</div>
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($e['teacher_per_lesson_fact'], 2, '.', ' ') }} ₽</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">факт (по реально оплаченному)</div>
                @if ($e['is_percent'])
                    <div class="mt-2 text-sm text-gray-700 dark:text-gray-200">
                        теория: <b>{{ number_format($e['teacher_per_lesson_theor'], 2, '.', ' ') }} ₽</b>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">если оплатившие заплатили бы полный прайс; должники в факт не входят, пока не заплатят</div>
                @else
                    <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">фикс — от должников не зависит (теория = факт)</div>
                @endif
            </div>

            {{-- 3. Прибыль ИП --}}
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">Прибыль ИП — один урок блока</div>
                @if ($e['has_unknown_method'])
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ number_format($e['profit_per_lesson_min'], 2, '.', ' ') }} … {{ number_format($e['profit_per_lesson_max'], 2, '.', ' ') }} ₽
                    </div>
                    <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">вилка: у части платежей способ оплаты не сохранён (карта 2.9% … СБП 0.4%)</div>
                @else
                    <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ number_format($e['profit_per_lesson_max'], 2, '.', ' ') }} ₽</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">эквайринг по факту способа оплаты</div>
                @endif
            </div>
        </div>

        {{-- Разбивка затрат по блоку --}}
        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Статья (за блок)</th>
                        <th class="px-4 py-2 text-right font-semibold">₽</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    <tr><td class="px-4 py-2">Выручка блока (оплачено)</td><td class="px-4 py-2 text-right">{{ number_format($e['revenue'], 2, '.', ' ') }}</td></tr>
                    <tr><td class="px-4 py-2">− ЗП преподавателя (факт)</td><td class="px-4 py-2 text-right">{{ number_format($e['teacher_block_fact'], 2, '.', ' ') }}</td></tr>
                    <tr>
                        <td class="px-4 py-2">− Эквайринг</td>
                        <td class="px-4 py-2 text-right">
                            @if ($e['has_unknown_method'])
                                {{ number_format($e['acquiring_known'] + $e['acquiring_unknown_min'], 2, '.', ' ') }} … {{ number_format($e['acquiring_known'] + $e['acquiring_unknown_max'], 2, '.', ' ') }}
                            @else
                                {{ number_format($e['acquiring_known'], 2, '.', ' ') }}
                            @endif
                        </td>
                    </tr>
                    <tr><td class="px-4 py-2">− Налог УСН {{ $e['tax_pct'] }}%</td><td class="px-4 py-2 text-right">{{ number_format($e['tax'], 2, '.', ' ') }}</td></tr>
                    <tr>
                        <td class="px-4 py-2">
                            − CAC ({{ $e['cac_auto'] !== null && ($e['cac_per_student'] == $e['cac_auto']) ? 'авто' : 'ручной' }},
                            {{ number_format($e['cac_per_student'], 2, '.', ' ') }} ₽/ученик × {{ $e['payers'] }})
                        </td>
                        <td class="px-4 py-2 text-right">{{ number_format($e['cac_total'], 2, '.', ' ') }}</td>
                    </tr>
                    <tr class="font-bold bg-gray-50 dark:bg-white/5">
                        <td class="px-4 py-2">= Прибыль ИП за блок</td>
                        <td class="px-4 py-2 text-right">
                            @if ($e['has_unknown_method'])
                                {{ number_format($e['profit_block_min'], 2, '.', ' ') }} … {{ number_format($e['profit_block_max'], 2, '.', ' ') }}
                            @else
                                {{ number_format($e['profit_block_max'], 2, '.', ' ') }}
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        @if (! $e['block_has_dates'])
            <div class="text-xs text-amber-600 dark:text-amber-400">У блока не заданы даты (starts_at/ends_at) — авто-CAC из рекламы за окно блока недоступен, задайте CAC вручную.</div>
        @endif
        <div class="text-xs text-gray-500 dark:text-gray-400">
            ⚠ Если преподаватель — Гасунс с percent 100%, ЗП = вся выручка, маржа ИП до затрат = 0, после — минус: «зарплата» и «прибыль ИП» один карман.
        </div>
    @endif
</x-filament-panels::page>
