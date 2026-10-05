<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Все оплаты по вашим курсам и прямые оплаты на ваше имя — читайте и сверяйте
        свои расчёты. Показаны только ваши данные ({{ $scopeName }}): чужие курсы
        и общая касса школы здесь недоступны. Страница read-only: правки платежей —
        у администрации в разделе «Финансы».
    </p>

    {{ $this->table }}

    <h2 class="mt-8 text-lg font-semibold text-gray-900 dark:text-gray-100">
        Мои выплаты
    </h2>
    <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
        История перечислений (последние 30): авансы зачитываются в следующей выплате.
    </p>
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-left dark:bg-gray-800">
                    <th class="px-3 py-2">Дата</th>
                    <th class="px-3 py-2">Тип</th>
                    <th class="px-3 py-2 text-right">Сумма, ₽</th>
                    <th class="px-3 py-2 text-right">В валюте</th>
                    <th class="px-3 py-2">Период</th>
                    <th class="px-3 py-2">Комментарий</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->payouts() as $payout)
                    <tr class="border-t border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2 whitespace-nowrap">{{ optional($payout->paid_at)->format('d.m.Y') }}</td>
                        <td class="px-3 py-2">
                            @if ($payout->isAdvance())
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">аванс</span>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">выплата</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $payout->amount, 2, '.', ' ') }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            @if ($payout->amount_foreign)
                                {{ rtrim(rtrim(number_format((float) $payout->amount_foreign, 2, '.', ' '), '0'), '.') }} {{ $payout->payout_currency }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $payout->period_month ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ Str::limit($payout->comment ?? '', 90) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-4 text-center text-gray-400">Выплат пока не было</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
