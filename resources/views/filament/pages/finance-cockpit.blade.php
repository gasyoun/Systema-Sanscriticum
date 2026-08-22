<x-filament-panels::page>
    @php
        // NB: все `use`-алиасы - внутри @php вычисляются в родительской функции
        // представления, это PHP-файл, а не ParseError. Enum - не единственный такой случай.
        $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' ?';
        $pct = fn ($v) => $v === null ? '-' : number_format((float) $v, 1, ',', ' ') . ' %';
        $signClass = fn ($v) => (float) $v < 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400';

        $opiu = $this->getOpiu();
        $opiuAccrual = $this->getOpiuAccrual();
        $deferred = $this->getDeferredRevenue();
        $dds = $this->getDds();
        $balance = $this->getBalance();
        $calendar = $this->getCalendar();

        // «Сколько можно взять себе» — практика «Нескучных финансов», read-only.
        $sw = app(\App\Services\SafeWithdrawalService::class)->snapshot();
    @endphp

    {{-- Карточка доступного к выводу --}}
    <div class="rounded-xl bg-warning-50 p-4 ring-1 ring-warning-500/20 dark:bg-warning-500/10">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-warning-900 dark:text-warning-200">Сколько можно взять себе сейчас</p>
                <p class="mt-1 text-xs text-warning-900/70 dark:text-warning-200/70">
                    балансы − обязательства {{ config('safe_withdrawal.horizon_days') }} дн − УСН, НДФЛ, взносы − резерв {{ config('safe_withdrawal.op_reserve_months') }} мес.
                    Детализация и допущения:
                </p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-lg font-bold text-warning-900 dark:text-warning-200">{{ number_format((float) $sw['available_general'], 2, ',', ' ') }} ₽</p>
                    <p class="text-xs text-warning-900/60 dark:text-warning-200/60">взносы сотрудницы 30 %</p>
                </div>
                <div class="text-right">
                    <p class="text-lg font-bold text-warning-900 dark:text-warning-200">{{ number_format((float) $sw['available_msp'], 2, ',', ' ') }} ₽</p>
                    <p class="text-xs text-warning-900/60 dark:text-warning-200/60">МСП: 15 % свыше МРОТ</p>
                </div>
                <a href="{{ \App\Filament\Pages\SafeWithdrawal::getUrl() }}" class="rounded-lg bg-warning-600 px-3 py-2 text-sm font-semibold text-white hover:bg-warning-700">Разбивка</a>
            </div>
        </div>
    </div>

    {{-- Селектор периода --}}
    <div class="flex flex-wrap items-end gap-4">
        <label class="flex flex-col gap-1 text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-300">Период</span>
            <select
                wire:model.live="period"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
            >
                @foreach ($this->periodOptions() as $value => $label)
                    <option value="{{ $value }}">{{ \Illuminate\Support\Str::ucfirst($label) }}</option>
                @endforeach
            </select>
        </label>
        <p class="text-xs text-gray-500 dark:text-gray-400 max-w-md">
            Отчеты считаются из живых данных LMS (платежи, зарплаты, реклама, opex).
            Структура — «Нескучные финансы».
        </p>
    </div>

    {{-- ОПиУ (P&L) — первый живой отчёт --}}
    <x-filament::section>
        <x-slot name="heading">ОПиУ — отчет о прибылях и убытках · {{ \Illuminate\Support\Str::ucfirst($this->getPeriodLabel()) }}</x-slot>
        <x-slot name="description">Выручка − переменные − коммерческие − административные = операционная прибыль (EBITDA).</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr class="font-semibold">
                        <td class="py-2">Выручка</td>
                        <td class="py-2 text-right tabular-nums">{{ $money($opiu['revenue']) }}</td>
                    </tr>

                    <tr>
                        <td class="py-1 pl-6 text-gray-500 dark:text-gray-400" colspan="2">Переменные расходы</td>
                    </tr>
                    <tr>
                        <td class="py-1 pl-10">ЗП преподавателей (COGS, начисление)</td>
                        <td class="py-1 text-right tabular-nums">−{{ $money($opiu['salaryCogs']) }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 pl-10">{{ \App\Enums\ExpenseCategory::Acquiring->label() }}</td>
                        <td class="py-1 text-right tabular-nums">−{{ $money($opiu['acquiring']) }}</td>
                    </tr>
                    <tr class="border-t border-gray-200 dark:border-gray-600">
                        <td class="py-1 pl-6 font-medium">Итого переменные</td>
                        <td class="py-1 text-right tabular-nums font-medium">−{{ $money($opiu['variableTotal']) }}</td>
                    </tr>

                    <tr class="bg-gray-50 dark:bg-gray-800/40 font-semibold">
                        <td class="py-2">Валовая прибыль <span class="text-xs text-gray-400">(маржа {{ $pct($opiu['grossMargin']) }})</span></td>
                        <td class="py-2 text-right tabular-nums {{ $signClass($opiu['grossProfit']) }}">{{ $money($opiu['grossProfit']) }}</td>
                    </tr>

                    <tr>
                        <td class="py-1 pl-6 text-gray-500 dark:text-gray-400" colspan="2">Коммерческие расходы</td>
                    </tr>
                    <tr>
                        <td class="py-1 pl-10">Маркетинг (реклама)</td>
                        <td class="py-1 text-right tabular-nums">−{{ $money($opiu['marketing']) }}</td>
                    </tr>

                    <tr>
                        <td class="py-1 pl-6 text-gray-500 dark:text-gray-400" colspan="2">Административные расходы</td>
                    </tr>
                    @foreach ($opiu['admin'] as $catValue => $sum)
                        <tr>
                            <td class="py-1 pl-10">{{ \App\Enums\ExpenseCategory::from($catValue)->label() }}</td>
                            <td class="py-1 text-right tabular-nums">−{{ $money($sum) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-200 dark:border-gray-600">
                        <td class="py-1 pl-6 font-medium">Итого административные</td>
                        <td class="py-1 text-right tabular-nums font-medium">−{{ $money($opiu['adminTotal']) }}</td>
                    </tr>

                    <tr class="bg-primary-50 dark:bg-primary-900/20 text-base font-bold">
                        <td class="py-3">Операционная прибыль (EBITDA) <span class="text-xs font-normal text-gray-400">(рентабельность {{ $pct($opiu['ebitdaMargin']) }})</span></td>
                        <td class="py-3 text-right tabular-nums {{ $signClass($opiu['ebitda']) }}">{{ $money($opiu['ebitda']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-400">
            Кассовый метод: вся сумма платежа признается в месяц оплаты. Ниже EBITDA
            (проценты, амортизация, налог на прибыль) LMS не моделирует — чистая прибыль ≈ EBITDA.
        </p>
    </x-filament::section>

    {{-- ОПиУ по методу начисления (accrual) — выручка признаётся по месяцам блоков --}}
    <x-filament::section>
        <x-slot name="heading">ОПиУ (начисление) · {{ \Illuminate\Support\Str::ucfirst($this->getPeriodLabel()) }}</x-slot>
        <x-slot name="description">Метод начисления: годовой курс, оплаченный сразу, признается долями по месяцам занятий, а не целиком в месяц оплаты. Лекарство от «прибыль есть, а денег нет».</x-slot>

        {{-- Отложенная выручка: оплачено, но ещё не отработано — обязательство --}}
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <div class="text-sm font-semibold text-amber-800 dark:text-amber-300">Отложенная выручка (на конец периода)</div>
                    <div class="text-xs text-amber-700/80 dark:text-amber-400/80">Оплачено кассой, но еще не признано как выручка — обязательство перед студентами.</div>
                </div>
                <div class="text-right">
                    <div class="text-lg font-bold tabular-nums text-amber-900 dark:text-amber-200">{{ $money($deferred['deferred']) }}</div>
                    <div class="text-xs text-amber-700/80 dark:text-amber-400/80 tabular-nums">
                        оплачено {{ $money($deferred['cashReceived']) }} − признано {{ $money($deferred['recognized']) }}
                    </div>
                </div>
            </div>
            @if ($deferred['deferred'] < 0)
                <p class="mt-2 text-xs text-amber-700/80 dark:text-amber-400/80">
                    Значение отрицательное: выручка признана раньше кассы (поздние оплаты уже прошедших блоков) — начисленная, но еще не полученная выручка.
                </p>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr class="font-semibold">
                        <td class="py-2">Выручка (признанная)</td>
                        <td class="py-2 text-right tabular-nums">{{ $money($opiuAccrual['revenue']) }}</td>
                    </tr>

                    <tr>
                        <td class="py-1 pl-6 text-gray-500 dark:text-gray-400" colspan="2">Переменные расходы</td>
                    </tr>
                    <tr>
                        <td class="py-1 pl-10">ЗП преподавателей (COGS, начисление)</td>
                        <td class="py-1 text-right tabular-nums">−{{ $money($opiuAccrual['salaryCogs']) }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 pl-10">{{ \App\Enums\ExpenseCategory::Acquiring->label() }}</td>
                        <td class="py-1 text-right tabular-nums">−{{ $money($opiuAccrual['acquiring']) }}</td>
                    </tr>
                    <tr class="border-t border-gray-200 dark:border-gray-600">
                        <td class="py-1 pl-6 font-medium">Итого переменные</td>
                        <td class="py-1 text-right tabular-nums font-medium">−{{ $money($opiuAccrual['variableTotal']) }}</td>
                    </tr>

                    <tr class="bg-gray-50 dark:bg-gray-800/40 font-semibold">
                        <td class="py-2">Валовая прибыль <span class="text-xs text-gray-400">(маржа {{ $pct($opiuAccrual['grossMargin']) }})</span></td>
                        <td class="py-2 text-right tabular-nums {{ $signClass($opiuAccrual['grossProfit']) }}">{{ $money($opiuAccrual['grossProfit']) }}</td>
                    </tr>

                    <tr>
                        <td class="py-1 pl-6 text-gray-500 dark:text-gray-400" colspan="2">Коммерческие расходы</td>
                    </tr>
                    <tr>
                        <td class="py-1 pl-10">Маркетинг (реклама)</td>
                        <td class="py-1 text-right tabular-nums">−{{ $money($opiuAccrual['marketing']) }}</td>
                    </tr>

                    <tr>
                        <td class="py-1 pl-6 text-gray-500 dark:text-gray-400" colspan="2">Административные расходы</td>
                    </tr>
                    @foreach ($opiuAccrual['admin'] as $catValue => $sum)
                        <tr>
                            <td class="py-1 pl-10">{{ \App\Enums\ExpenseCategory::from($catValue)->label() }}</td>
                            <td class="py-1 text-right tabular-nums">−{{ $money($sum) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-200 dark:border-gray-600">
                        <td class="py-1 pl-6 font-medium">Итого административные</td>
                        <td class="py-1 text-right tabular-nums font-medium">−{{ $money($opiuAccrual['adminTotal']) }}</td>
                    </tr>

                    <tr class="bg-primary-50 dark:bg-primary-900/20 text-base font-bold">
                        <td class="py-3">Операционная прибыль (EBITDA) <span class="text-xs font-normal text-gray-400">(рентабельность {{ $pct($opiuAccrual['ebitdaMargin']) }})</span></td>
                        <td class="py-3 text-right tabular-nums {{ $signClass($opiuAccrual['ebitda']) }}">{{ $money($opiuAccrual['ebitda']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-400">
            Сумма признанной выручки за всё время равна кассовой — метод начисления лишь перераспределяет ее между месяцами занятий. Расходная часть у обеих вкладок одинакова.
        </p>
    </x-filament::section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- ДДС (Cash flow) --}}
        <x-filament::section>
            <x-slot name="heading">ДДС — движение денежных средств</x-slot>
            <x-slot name="description">Кассовый метод: приход − выплаты ЗП − возвраты − opex.</x-slot>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr><td class="py-1">Приток (выручка)</td><td class="py-1 text-right tabular-nums">{{ $money($dds['inflow']) }}</td></tr>
                    <tr><td class="py-1 pl-4">Выплаты преподавателям</td><td class="py-1 text-right tabular-nums">−{{ $money($dds['salaryOut']) }}</td></tr>
                    <tr><td class="py-1 pl-4">Возвраты</td><td class="py-1 text-right tabular-nums">−{{ $money($dds['refundOut']) }}</td></tr>
                    <tr><td class="py-1 pl-4">Операционные расходы (opex)</td><td class="py-1 text-right tabular-nums">−{{ $money($dds['opexOut']) }}</td></tr>
                    <tr class="border-t border-gray-200 dark:border-gray-600 font-bold">
                        <td class="py-2">Чистый денежный поток</td>
                        <td class="py-2 text-right tabular-nums {{ $signClass($dds['net']) }}">{{ $money($dds['net']) }}</td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>

        {{-- Платёжный календарь --}}
        <x-filament::section>
            <x-slot name="heading">Платежный календарь · {{ $calendar['days'] }} дн.</x-slot>
            <x-slot name="description">Ожидаемые приходы (обещания) минус ближайшие выплаты.</x-slot>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr><td class="py-1">Ожидаемые приходы ({{ $calendar['expectedCount'] }})</td><td class="py-1 text-right tabular-nums">{{ $money($calendar['expectedInflow']) }}</td></tr>
                    <tr><td class="py-1">Просроченные обещания ({{ $calendar['overdueCount'] }})</td><td class="py-1 text-right tabular-nums">{{ $money($calendar['overdue']) }}</td></tr>
                    <tr><td class="py-1 pl-4">К выплате преподавателям</td><td class="py-1 text-right tabular-nums">−{{ $money($calendar['teacherPayable']) }}</td></tr>
                    <tr class="border-t border-gray-200 dark:border-gray-600 font-bold">
                        <td class="py-2">Кассовый разрыв</td>
                        <td class="py-2 text-right tabular-nums {{ $signClass($calendar['gap']) }}">{{ $money($calendar['gap']) }}</td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>
    </div>

    {{-- Баланс (на сейчас) --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Баланс — активы и обязательства (на сейчас)</x-slot>
        <x-slot name="description">Собран из денежного ядра; полный баланс (осн. средства и т.п.) ведется вручную в Excel.</x-slot>
        <div class="grid grid-cols-2 gap-4 text-sm md:grid-cols-3">
            <div><div class="text-gray-500 dark:text-gray-400">Дебиторка (долг)</div><div class="font-semibold tabular-nums">{{ $money($balance['debt']) }}</div></div>
            <div><div class="text-gray-500 dark:text-gray-400">Conditional-доступы</div><div class="font-semibold tabular-nums">{{ $balance['conditionalCount'] }} шт.</div></div>
            <div><div class="text-gray-500 dark:text-gray-400">Авансы преподавателям</div><div class="font-semibold tabular-nums">{{ $money($balance['advances']) }}</div></div>
            <div><div class="text-gray-500 dark:text-gray-400">К выплате преподавателям</div><div class="font-semibold tabular-nums">{{ $money($balance['teacherPayable']) }}</div></div>
            <div><div class="text-gray-500 dark:text-gray-400">Непотраченные депозиты</div><div class="font-semibold tabular-nums">{{ $money($balance['deposits']) }}</div></div>
            <div><div class="text-gray-500 dark:text-gray-400">Обязательство по пране</div><div class="font-semibold tabular-nums">{{ $money($balance['pranaLiability']) }}</div></div>
            <div><div class="text-gray-500 dark:text-gray-400">Реферальный кредит</div><div class="font-semibold tabular-nums">{{ $money($balance['referral']) }}</div></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
