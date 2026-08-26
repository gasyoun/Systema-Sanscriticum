@php
    $money = fn ($v): string => number_format((float) $v, 2, ',', ' ');
    $grid = $grid ?? ['weeks' => [], 'tochka' => ['ok' => false], 'paypal_note' => ''];
    // H3532: при флаге OFF ($yearView не задан) всё новое отсутствует из DOM байт-в-байт.
    $yearView = !empty($yearView);
    $tab = $yearView ? ($tab ?? 'week') : 'week';
    $showWeek = ! $yearView || $tab === 'week';
    $yearGrid = $yearGrid ?? null;
@endphp

<x-filament-panels::page>
    <div class="rounded-xl bg-primary-50 p-4 text-sm ring-1 ring-primary-500/20 dark:bg-primary-500/10">
        <p class="font-semibold text-primary-900 dark:text-primary-200">Только чтение</p>
        <p class="mt-1 text-primary-900/80 dark:text-primary-200/80">
            Эта страница не создаёт выплаты. Разметка легаси-«Расход» —
            <a href="{{ $attributionUrl }}" class="underline">Как размечать выплаты</a>.
            Начисление — <a href="{{ $salariesUrl }}" class="underline">Зарплаты</a>.
        </p>
    </div>

    @if ($yearView)
        <div class="mt-4 flex gap-2">
            <a href="{{ url()->current() }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 {{ $tab === 'week' ? 'bg-primary-600 text-white ring-primary-600' : 'bg-white text-gray-700 ring-gray-950/10 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' }}">
                Неделя
            </a>
            <a href="{{ url()->current().'?tab=year' }}"
               class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 {{ $tab === 'year' ? 'bg-primary-600 text-white ring-primary-600' : 'bg-white text-gray-700 ring-gray-950/10 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' }}">
                Год
            </a>
        </div>
    @endif

    @if ($showWeek)
    <div class="mt-4 rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <p class="font-semibold">Недельный ритуал владельца</p>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-700 dark:text-gray-300">
            <li>Ниже — кого платим на этой неделе (преподаватели) и кто молчит слишком долго (персонал).</li>
            <li>Проверить сборность должников по курсам получателя — раздел «Собранность должников».</li>
            <li>Заплатить: Точка ₽ / PayPal EUR (вручную).</li>
            <li>Записать: калькулятор «Записать выплату» на «Зарплатах» / занести opex в Финансы.</li>
            <li>Разметить новые «Расходы» в очереди разметки. НПД-чек самозанятым: зачёт до выплаты, −6 %.</li>
        </ol>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="font-semibold">Точка, к трате (ClosingAvailable)</p>
            @if (!empty($grid['tochka']['ok']))
                <p class="mt-1 text-lg">{{ $money($grid['tochka']['closing_total']) }} ₽</p>
            @else
                <p class="mt-1 text-danger-600">нет данных ({{ $grid['tochka']['error'] ?? 'банк' }})</p>
            @endif
        </div>
        <div class="rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="font-semibold">PayPal EUR</p>
            <p class="mt-1">{{ $grid['paypal_note'] ?? 'откройте PayPal' }}</p>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">Неделя ISO</th>
                    <th class="px-3 py-2 text-left">Даты</th>
                    <th class="px-3 py-2 text-left">Кто (id)</th>
                    <th class="px-3 py-2 text-left">₽ / EUR</th>
                    <th class="px-3 py-2 text-left">Триггер</th>
                    <th class="px-3 py-2 text-left">Точка</th>
                    <th class="px-3 py-2 text-left">PayPal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($grid['weeks'] as $week)
                    @if (count($week['due']) === 0)
                        @continue
                    @endif
                    @foreach ($week['due'] as $i => $due)
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            @if ($i === 0)
                                <td class="px-3 py-2 align-top" rowspan="{{ count($week['due']) }}">{{ $week['iso_week'] }}</td>
                                <td class="px-3 py-2 align-top whitespace-nowrap" rowspan="{{ count($week['due']) }}">
                                    {{ \Illuminate\Support\Carbon::parse($week['start'])->format('d.m') }}
                                    –
                                    {{ \Illuminate\Support\Carbon::parse($week['end'])->format('d.m.Y') }}
                                </td>
                            @endif
                            <td class="px-3 py-2">
                                #{{ $due['teacher_id'] }} {{ $due['name'] }}
                                @if (!empty($due['preliminary']))
                                    <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-800">предварительно</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $due['lane'] }}</td>
                            <td class="px-3 py-2">
                                {{ $due['trigger'] === 'block_4_end' ? 'конец блока 4' : 'годовщина прошлого месяца' }}
                                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($due['due_on'])->format('d.m.Y') }}</span>
                            </td>
                            @if ($i === 0)
                                <td class="px-3 py-2 align-top" rowspan="{{ count($week['due']) }}">
                                    @if ($week['tochka_cover'] === 'enough') хватит
                                    @elseif ($week['tochka_cover'] === 'short') не хватит
                                    @elseif ($week['tochka_cover'] === 'no_bank') нет банка
                                    @else —
                                    @endif
                                </td>
                                <td class="px-3 py-2 align-top" rowspan="{{ count($week['due']) }}">
                                    {{ $week['paypal_cover'] === 'open_the_bank' ? 'откройте PayPal' : '—' }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    @php
        $staff = $staff ?? ['payees' => [], 'totals' => ['monthly_estimate' => 0, 'owed_estimate' => 0]];
        $debts = $debts ?? [];
    @endphp

    <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Собранность должников по преподавателям</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        Студенты активных курсов, ещё не оплатившие текущий блок. Выплата без учёта несобранного — неполная картина.
    </p>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">Преподаватель</th>
                    <th class="px-3 py-2 text-left">Должников</th>
                    <th class="px-3 py-2 text-left">Не собрано, ₽</th>
                    <th class="px-3 py-2 text-left">Должники</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($debts as $d)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="px-3 py-2">#{{ $d['teacher_id'] }} {{ $d['name'] }}</td>
                        <td class="px-3 py-2">{{ $d['pairs'] }}</td>
                        <td class="px-3 py-2">{{ $money($d['amount']) }}</td>
                        <td class="px-3 py-2"><a href="{{ $debtorsUrl }}" class="underline">открыть раздел</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-2 text-gray-500">Должников нет.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @php
        $staff = $staff ?? ['payees' => [], 'totals' => ['monthly_estimate' => 0, 'owed_estimate' => 0]];
        $debts = $debts ?? [];
        $recentPayments = $recentPayments ?? [];
        $delayLabel = function ($d): string {
            if ($d === null) {
                return 'блоки без дат';
            }
            if ($d < 0) {
                return 'заранее на '.abs($d).' дн';
            }
            if ($d <= 3) {
                return 'вовремя';
            }

            return 'опоздал '.$d.' дн';
        };
    @endphp

    <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Должники платят: оплаты за последние 35 дней</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        По каждому преподавателю: кто оплатил, сколько, за какие блоки и с какой задержкой относительно старта самого раннего покрытого блока. Срез для ежемесячных выплат.
    </p>
    <div class="mt-2 space-y-4">
        @forelse ($recentPayments as $t)
            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <p class="bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    #{{ $t['teacher_id'] }} {{ $t['name'] }} — {{ count($t['rows']) }} опл.
                </p>
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-1 text-left">Студент</th>
                            <th class="px-3 py-1 text-left">Курс</th>
                            <th class="px-3 py-1 text-left">Блоки</th>
                            <th class="px-3 py-1 text-right">Сумма</th>
                            <th class="px-3 py-1 text-left">Дата</th>
                            <th class="px-3 py-1 text-left">Задержка</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($t['rows'] as $r)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                <td class="px-3 py-1">{{ $r['student'] }}</td>
                                <td class="px-3 py-1">{{ $r['course'] }}</td>
                                <td class="px-3 py-1">{{ $r['blocks'] }}</td>
                                <td class="px-3 py-1 text-right">{{ $money($r['amount']) }}</td>
                                <td class="px-3 py-1">{{ $r['paid_at'] }}</td>
                                <td class="px-3 py-1">
                                    @if ($r['delay_days'] !== null && $r['delay_days'] > 3)
                                        <span class="rounded bg-danger-100 px-1 text-xs text-danger-800">{{ $delayLabel($r['delay_days']) }}</span>
                                    @else
                                        <span class="text-gray-600 dark:text-gray-300">{{ $delayLabel($r['delay_days']) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-sm text-gray-500">За последние 35 дней оплат не было.</p>
        @endforelse
    </div>

    <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Весь контур: персонал и повторяемые получатели (не преподаватели)</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        Ставка — среднее последних ≤3 плативших месяцев; «оценка долга» — ставка × полные месяцы тишины.
        Это экстраполяция (предварительно), а не начисление: решение о сумме — за человеком.
    </p>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 text-left">Получатель</th>
                    <th class="px-3 py-2 text-left">Категория</th>
                    <th class="px-3 py-2 text-left">Ставка/мес</th>
                    <th class="px-3 py-2 text-left">Последняя проводка</th>
                    <th class="px-3 py-2 text-left">Мес. тишины</th>
                    <th class="px-3 py-2 text-left">Оценка долга</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($staff['payees'] as $p)
                    <tr class="border-t border-gray-100 dark:border-white/10">
                        <td class="px-3 py-2">{{ $p['name'] }}</td>
                        <td class="px-3 py-2">{{ $p['category'] }}</td>
                        <td class="px-3 py-2">{{ $p['monthly_rate'] !== null ? $money($p['monthly_rate']).' ₽' : '—' }}</td>
                        <td class="px-3 py-2">{{ $p['last_month'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $p['silent_months'] > 0 ? $p['silent_months'] : '—' }}</td>
                        <td class="px-3 py-2">
                            {{ $p['owed_estimate'] > 0 ? $money($p['owed_estimate']).' ₽' : '—' }}
                            @if ($p['assumption'])
                                <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-800">предварительно</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-2 text-gray-500">За 12 месяцев получателей не найдено.</td></tr>
                @endforelse
            </tbody>
            @if (count($staff['payees']) > 0)
                <tfoot class="bg-gray-50 text-xs dark:bg-white/5">
                    <tr>
                        <td class="px-3 py-2 font-semibold" colspan="2">Итого по персоналу (экстраполяция)</td>
                        <td class="px-3 py-2 font-semibold">{{ $money($staff['totals']['monthly_estimate']) }} ₽/мес</td>
                        <td class="px-3 py-2" colspan="2"></td>
                        <td class="px-3 py-2 font-semibold">{{ $money($staff['totals']['owed_estimate']) }} ₽</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    @endif

    {{-- ===== H3532: таб «Год» — 52 недели × все получатели + балансы ===== --}}
    @if ($yearView && $tab === 'year' && $yearGrid !== null)
        @php
            $fx = $yearGrid['fx_eur'];
            $paypal = $yearGrid['paypal'];
            $horizon = $yearGrid['horizon4'];
            $channelLabel = [
                'tochka_maria' => 'Точка · шлёт Мария',
                'tochka_ip_gasuns' => 'ИП Гасунса',
                'paypal_mg' => 'PayPal € · MG',
                'xoom_mg' => 'Xoom · MG',
                'self_ip' => 'своё ИП (Точка)',
            ];
        @endphp

        <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Балансы против ближайших 4 недель</h2>
        <div class="mt-2 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="font-semibold">₽: Точка ClosingAvailable</p>
                @if (!empty($yearGrid['tochka']['ok']))
                    <p class="mt-1 text-lg">{{ $money($yearGrid['tochka']['closing_total']) }} ₽
                        <span class="text-xs text-gray-500">против {{ $money($horizon['rub_need']) }} ₽ за 4 недели</span></p>
                    <p class="mt-1 text-xs">{{ (float) $yearGrid['tochka']['closing_total'] + 0.0001 >= $horizon['rub_need'] ? '✓ хватит' : '⚠ не хватит' }}</p>
                @else
                    <p class="mt-1 text-danger-600">нет данных банка</p>
                @endif
            </div>
            <div class="rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="font-semibold">€: баланс PayPal (ручной ввод)</p>
                @if ($paypal['balance_eur'] !== null)
                    <p class="mt-1 text-lg">{{ $money($paypal['balance_eur']) }} €
                        <span class="text-xs text-gray-500">против {{ $money($horizon['eur_need']) }} € за 4 недели · введён {{ $paypal['entered_at'] }}</span></p>
                    <p class="mt-1 text-xs">{{ (float) $paypal['balance_eur'] + 0.0001 >= $horizon['eur_need'] ? '✓ хватит' : '⚠ не хватит' }}</p>
                @else
                    <p class="mt-1 text-warning-600">ещё не введён — внесите ниже</p>
                @endif
                <p class="mt-1 text-xs text-gray-500">Курс ₽→€: {{ rtrim(rtrim((string) $fx['rate'], '0'), '.') }} ({{ $fx['source'] }})</p>
            </div>
        </div>

        <form class="mt-3 flex flex-wrap items-end gap-2 rounded-xl bg-white p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
              wire:submit.prevent="savePaypalBalance">
            <div>
                <label class="block text-xs font-medium text-gray-500" for="paypal-balance-input">Баланс PayPal, € (ручной ввод; пишется только в finance_snapshots с датой)</label>
                <input id="paypal-balance-input" type="number" min="0" step="0.01"
                       wire:model="paypalBalance"
                       class="mt-1 block w-48 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"
                       placeholder="например 1250.00" />
            </div>
            <button type="submit"
                    class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Записать баланс
            </button>
            @error('paypalBalance')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror
        </form>

        <h2 class="mt-8 text-sm font-semibold text-gray-500 dark:text-gray-400">Год {{ $yearGrid['year'] }}: 52 недели × все получатели</h2>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Даты твёрдые (конец блока 4 / годовщина прошлого месяца / штатный ритм), суммы предварительные — растут с оплатами студентов.
            Формула «на руки»: (поступления ₽ × 92 %) × ставка(t) − прямые вычеты ± перерасчёты; НПД −6 % — отдельный шаг выплаты.
        </p>
        <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 text-left">Неделя ISO</th>
                        <th class="px-3 py-2 text-left">Даты</th>
                        <th class="px-3 py-2 text-left">Получатель</th>
                        <th class="px-3 py-2 text-left">Канал</th>
                        <th class="px-3 py-2 text-right">Предв. сумма</th>
                        <th class="px-3 py-2 text-left">Триггер / дата</th>
                        <th class="px-3 py-2 text-left">Пометки</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($yearGrid['weeks'] as $week)
                        @if (count($week['due']) === 0)
                            @continue
                        @endif
                        @php $nDues = count($week['due']); @endphp
                        @foreach ($week['due'] as $i => $due)
                            <tr class="border-t border-gray-100 dark:border-white/10">
                                @if ($i === 0)
                                    <td class="px-3 py-2 align-top" rowspan="{{ $nDues }}">{{ $week['iso_week'] }}</td>
                                    <td class="px-3 py-2 align-top whitespace-nowrap" rowspan="{{ $nDues }}">
                                        {{ \Illuminate\Support\Carbon::parse($week['start'])->format('d.m') }}
                                        –
                                        {{ \Illuminate\Support\Carbon::parse($week['end'])->format('d.m.Y') }}
                                    </td>
                                @endif
                                <td class="px-3 py-2">
                                    {{ $due['name'] }}
                                    @if (($due['recipient_kind'] ?? '') === 'staff') <span class="ml-1 rounded bg-info-100 px-1 text-xs text-info-800">штат</span> @endif
                                    @if (($due['recipient_kind'] ?? '') === 'contractor') <span class="ml-1 rounded bg-gray-100 px-1 text-xs text-gray-700">подрядчик</span> @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $channelLabel[$due['channel']] ?? $due['channel'] }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    @if ($due['lane'] === 'EUR')
                                        {{ $money($due['amount_eur_prelim']) }} €
                                        <span class="block text-xs text-gray-400">{{ $money($due['amount_rub_prelim']) }} ₽</span>
                                    @else
                                        {{ $money($due['amount_rub_prelim']) }} ₽
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ ['block_4_end' => 'конец блока 4', 'last_month_anniversary' => 'годовщина', 'staff_schedule' => 'штатный ритм', 'contractor_fee' => 'ежемесячный fee'][$due['trigger']] ?? $due['trigger'] }}
                                    <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($due['due_on'])->format('d.m.Y') }}</span>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if (!empty($due['npd_note']))
                                        <span class="rounded bg-warning-100 px-1 text-warning-800">{{ $due['npd_note'] }}</span>
                                    @endif
                                    <span class="rounded bg-warning-100 px-1 text-warning-800">предварительно</span>
                                    @if (!empty($due['formula_note']))
                                        <span class="block text-gray-400">{{ \Illuminate\Support\Str::limit($due['formula_note'], 120) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr><th class="px-3 py-2 text-left">Неделя</th><th class="px-3 py-2 text-right">Нужно ₽</th><th class="px-3 py-2 text-left">Точка</th><th class="px-3 py-2 text-right">Нужно €</th><th class="px-3 py-2 text-left">PayPal</th></tr>
                </thead>
                <tbody>
                    @foreach ($yearGrid['weeks'] as $week)
                        @if ((float) $week['rub_need_forecast'] <= 0 && (float) $week['eur_need_forecast'] <= 0)
                            @continue
                        @endif
                        <tr class="border-t border-gray-100 dark:border-white/10">
                            <td class="px-3 py-1.5">№{{ $week['iso_week'] }}</td>
                            <td class="px-3 py-1.5 text-right">{{ $money($week['rub_need_forecast']) }} ₽</td>
                            <td class="px-3 py-1.5">
                                {{ ['enough' => 'хватит', 'short' => 'не хватит', 'no_bank' => 'нет банка', 'n/a' => '—'][$week['tochka_cover']] ?? '—' }}
                            </td>
                            <td class="px-3 py-1.5 text-right">{{ $money($week['eur_need_forecast']) }} €</td>
                            <td class="px-3 py-1.5">
                                {{ ['enough' => 'хватит', 'short' => 'не хватит', 'no_data' => 'нет ввода', 'n/a' => '—'][$week['paypal_cover_forecast']] ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
