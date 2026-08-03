<x-filament-panels::page>
    @php($s = $snap)
    @php($pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1).'%')
    @php($light = [
        'success' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
        'gray' => 'text-gray-900 dark:text-white',
    ])

    @if ($scoped)
        <div class="text-xs text-gray-500 dark:text-gray-400">
            Показаны только заказы, которые оформили вы — менеджерам видна своя воронка.
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <div class="px-4 pt-3 text-sm font-semibold text-gray-700 dark:text-gray-200">
            По менеджеру <span class="text-xs font-normal text-gray-400">· {{ $s['breakdown_window_days'] }} дн.</span>
        </div>
        <table class="w-full text-sm mt-2">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2 text-left font-semibold">Менеджер</th>
                    <th class="px-4 py-2 text-right font-semibold">Заказы</th>
                    <th class="px-4 py-2 text-right font-semibold">Оплачено</th>
                    <th class="px-4 py-2 text-right font-semibold">Конверсия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($s['by_manager'] as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row['manager'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['orders'] }}</td>
                        <td class="px-4 py-2 text-right">{{ $row['paid'] }}</td>
                        <td class="px-4 py-2 text-right font-semibold {{ $light[$row['level']] }}">
                            {{ $pct($row['conversion_pct']) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-3 text-center text-gray-400">Нет заказов за окно</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
        <div><b>Менеджер</b> = кто создал строку платежа (<code>payments.created_by_user_id</code>) — не ответственный за лид/сделку. Самостоятельные покупки и заказы, пришедшие вебхуком, попадают в «Без менеджера».</div>
        <div>Снимок на {{ $s['as_of'] }}.</div>
    </div>
</x-filament-panels::page>
