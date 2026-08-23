<x-filament-panels::page>
    @php
        $sw = $sw ?? [];
        $money2 = fn ($v): string => number_format((float) $v, 2, ',', ' ');
        $bal = $sw['balances'] ?? [];
        $obl = $sw['obligations'] ?? [];
        $tax = $sw['taxes'] ?? [];
        $opr = $sw['op_reserve'] ?? [];
    @endphp

    <div class="rounded-xl bg-primary-50 p-4 text-sm ring-1 ring-primary-500/20 dark:bg-primary-500/10">
        <p class="font-semibold text-primary-900 dark:text-primary-200">Только чтение</p>
        <p class="mt-1 text-primary-900/80 dark:text-primary-200/80">
            Практика «Нескучных финансов»: балансы − обязательства горизонта − налоги − резерв.
            Строки с допущениями помечены «предварительно». Компаньоны:
            <a href="{{ $cockpitUrl }}" class="underline">Штурвал</a> ·
            <a href="{{ $calendarUrl }}" class="underline">Календарь выплат</a> ·
            <a href="{{ $profitFundsUrl }}" class="underline">Фонды прибыли</a>.
        </p>
    </div>

    {{-- Итог --}}
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="text-sm font-semibold">Взносы за сотрудницу 30 % (консервативно)</p>
            <p class="mt-1 text-2xl font-bold">{{ $money2($sw['available_general'] ?? 0) }} ₽</p>
        </div>
        <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <p class="text-sm font-semibold">МСП: 30 % до МРОТ + 15 % свыше</p>
            <p class="mt-1 text-2xl font-bold">{{ $money2($sw['available_msp'] ?? 0) }} ₽</p>
            <p class="text-xs text-gray-500">разница схем: {{ $money2(($tax['insurance_general'] ?? 0) - ($tax['insurance_msp'] ?? 0)) }} ₽ на горизонте</p>
        </div>
    </div>

    {{-- Балансы --}}
    <h2 class="mt-6 text-sm font-semibold text-gray-500 dark:text-gray-400">1. Балансы</h2>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">Точка, к трате (ClosingAvailable, без исключённых счетов)</td>
                    <td class="px-3 py-2 text-right font-semibold">
                        @if ($bal['tochka_ok'] ?? false)
                            {{ $money2($bal['tochka_closing']) }} ₽
                            @if (!empty($bal['tochka_excluded']))
                                <span class="text-xs text-gray-500">(исключены накопительные: {{ implode(', ', array_map(fn ($a) => '…'.$a['tail'].' '.$money2($a['closing']).' ₽', $bal['tochka_excluded'])) }})</span>
                            @endif
                        @else
                            <span class="text-danger-600">нет данных банка</span>
                        @endif
                    </td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">PayPal / Xoom</td>
                    <td class="px-3 py-2 text-right text-gray-500">{{ $bal['paypal'] ?? '' }}</td>
                </tr>
                <tr class="border-t border-gray-100 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                    <td class="px-3 py-2">Итого балансы</td>
                    <td class="px-3 py-2 text-right">{{ $money2($sw['balance_total'] ?? 0) }} ₽</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Обязательства --}}
    <h2 class="mt-6 text-sm font-semibold text-gray-500 dark:text-gray-400">2. Обязательства {{ config('safe_withdrawal.horizon_days') }} дней</h2>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">Преподаватели — сетка Календаря выплат; на каждого: MIN(баланс регистра, начисление за 3 мес) — регистровые балансы завышены старыми «Расход»-пачками мимо регистра (₽-дорожка; EUR отдельно в PayPal)</td>
                    <td class="px-3 py-2 text-right">{{ $money2($obl['teachers_rub'] ?? 0) }} ₽ @if ($obl['teachers_eur_due'] ?? false)<span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-800">+ EUR</span>@endif</td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">
                        Персонал: {{ $money2($obl['staff_monthly'] ?? 0) }} ₽/мес × {{ $obl['staff_horizon_months'] ?? 0 }} мес
                        @if (($obl['staff_overrides_monthly'] ?? 0) > 0)
                            <span class="text-xs text-gray-500">(в т.ч. по ручному реестру: {{ $money2($obl['staff_overrides_monthly']) }} ₽/мес — Ильюшина, Кравченко, Кузнецова, Головченко)</span>
                        @endif
                        @if (!empty($obl['staff_quits']))<span class="text-xs text-gray-500">· уволены 15-08, расчёты произведены: {{ implode(', ', $obl['staff_quits']) }}</span>@endif
                        @if (!empty($obl['staff_stale_excluded']))<span class="text-xs text-gray-500">· без молчащих ≥2 мес: {{ implode(', ', $obl['staff_stale_excluded']) }}</span>@endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ $money2($obl['staff_total'] ?? 0) }} ₽</td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">
                        Прочие opex: {{ $money2($obl['opex_monthly'] ?? 0) }} ₽/мес × горизонт
                        @if ($obl['opex_assumption'] ?? false)
                            <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-800">предварительно: среднее LMS за 6 мес; ручной реестр полнее — задайте SAFE_WITHDRAWAL_OPEX_MONTHLY</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right">{{ $money2($obl['opex_total'] ?? 0) }} ₽</td>
                </tr>
                <tr class="border-t border-gray-100 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                    <td class="px-3 py-2">Итого обязательства</td>
                    <td class="px-3 py-2 text-right">{{ $money2($obl['total'] ?? 0) }} ₽</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Налоги --}}
    <h2 class="mt-6 text-sm font-semibold text-gray-500 dark:text-gray-400">3. Налоговый резерв</h2>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">
                        УСН {{ number_format((float) config('safe_withdrawal.usn_rate') * 100, 0, ',', ' ') }} % × доход квартала кассово ({{ $money2($tax['usn_qtd_revenue'] ?? 0) }} ₽).
                        <span class="text-xs text-gray-500">{{ $tax['usn_note'] ?? '' }}</span>
                    </td>
                    <td class="px-3 py-2 text-right">{{ $money2($tax['usn_reserve'] ?? 0) }} ₽</td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10"><td class="px-3 py-1 text-xs text-gray-500" colspan="2">{{ $tax['usn_offset_note'] ?? '' }}</td></tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">НДФЛ агента {{ number_format((float) config('safe_withdrawal.ndfl_rate') * 100, 0, ',', ' ') }} % × зарплата сотрудницы на горизонте</td>
                    <td class="px-3 py-2 text-right">{{ $money2($tax['ndfl'] ?? 0) }} ₽</td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">Страховые взносы за сотрудницу — схема 30 %</td>
                    <td class="px-3 py-2 text-right">{{ $money2($tax['insurance_general'] ?? 0) }} ₽</td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">Страховые взносы за сотрудницу — МСП (30 % до МРОТ {{ number_format((float) config('safe_withdrawal.mrot_monthly'), 0, ',', ' ') }} + 15 % сверх)</td>
                    <td class="px-3 py-2 text-right">{{ $money2($tax['insurance_msp'] ?? 0) }} ₽</td>
                </tr>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">Взносы ИП за себя: фикс {{ $money2(config('safe_withdrawal.ip_fixed_yearly')) }} ₽/год ÷ 12 × {{ min(12, (int) ceil((int) config('safe_withdrawal.horizon_days') / 30)) }} мес + 1 % сверх {{ number_format((float) config('safe_withdrawal.ip_extra_threshold'), 0, ',', ' ') }} ₽ (прогноз года по LMS: {{ $money2($tax['ip_extra_income_proxy_year'] ?? 0) }} ₽, предварительно)</td>
                    <td class="px-3 py-2 text-right">{{ $money2(($tax['ip_fixed'] ?? 0) + ($tax['ip_extra'] ?? 0)) }} ₽</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Резерв --}}
    <h2 class="mt-6 text-sm font-semibold text-gray-500 dark:text-gray-400">4. Операционный резерв</h2>
    <div class="mt-2 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <table class="w-full text-sm">
            <tbody>
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-3 py-2">Активные месячные оттоки (персонал + прочие opex) × {{ $opr['months'] ?? 1 }} мес; преподаватели — в обязательствах горизонта, здесь не дублируются</td>
                    <td class="px-3 py-2 text-right">{{ $money2($opr['total'] ?? 0) }} ₽</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-4 text-xs text-gray-500">
        Снято {{ $sw['as_of'] ?? now() }}. Read-only: строки teacher_payouts/payments не пишутся.
    </p>
</x-filament-panels::page>
