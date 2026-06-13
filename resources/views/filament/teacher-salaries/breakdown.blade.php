@php
    use App\Services\TeacherSalaryService;
    $rows = $breakdown['rows'] ?? [];
    $total = $breakdown['total'] ?? 0;
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ') . ' ₽';
@endphp

<div class="space-y-4 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        Период: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $periodLabel }}</span>
    </p>
    <p class="text-xs text-gray-400">
        Суммы признаны по периоду блока: оплата раскладывается на оплаченные блоки и
        попадает в ЗП в месяц соответствующего блока (override месяца у платежа — приоритетнее).
    </p>

    @if (empty($rows))
        <p class="text-gray-500 dark:text-gray-400">За выбранный период начислений по курсам нет.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Курс</th>
                        <th class="px-3 py-2">Схема</th>
                        <th class="px-3 py-2 text-center">Студентов</th>
                        <th class="px-3 py-2 text-right">Выручка</th>
                        <th class="px-3 py-2 text-right">Возвраты</th>
                        <th class="px-3 py-2 text-right">Начислено</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-200">{{ $row['title'] }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ TeacherSalaryService::salaryTypeLabel($row['salary_type']) }}
                                @if ($row['salary_value'])
                                    <span class="text-gray-400">· {{ $money($row['salary_value']) }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">{{ $row['students'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $money($row['revenue']) }}</td>
                            <td class="px-3 py-2 text-right text-danger-500">
                                {{ $row['returns'] < 0 ? $money($row['returns']) : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold text-gray-800 dark:text-gray-100">
                                {{ $money($row['accrued']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-white/5 font-semibold">
                    <tr>
                        <td class="px-3 py-2" colspan="5">Итого начислено за период</td>
                        <td class="px-3 py-2 text-right">{{ $money($total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Drill-down: из каких платежей сложилось начисление (B4) --}}
        @foreach ($payments as $courseId => $coursePayments)
            @php
                $row = collect($rows)->firstWhere('course_id', $courseId);
            @endphp
            <details class="rounded-lg border border-gray-200 dark:border-white/10">
                <summary class="cursor-pointer px-3 py-2 text-gray-600 dark:text-gray-300">
                    Платежи: {{ $row['title'] ?? ('Курс #' . $courseId) }}
                    <span class="text-gray-400">({{ $coursePayments->count() }})</span>
                </summary>
                <div class="overflow-x-auto border-t border-gray-100 dark:border-white/10">
                    <table class="w-full text-left">
                        <thead class="text-xs uppercase text-gray-400">
                            <tr>
                                <th class="px-3 py-1.5">Дата</th>
                                <th class="px-3 py-1.5">Студент</th>
                                <th class="px-3 py-1.5">Тариф</th>
                                <th class="px-3 py-1.5 text-right">Сумма</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($coursePayments as $p)
                                <tr>
                                    <td class="px-3 py-1.5 text-gray-500">{{ $p->created_at?->format('d.m.Y') }}</td>
                                    <td class="px-3 py-1.5 text-gray-500">{{ $p->user?->name ?? ('#' . $p->user_id) }}</td>
                                    <td class="px-3 py-1.5 text-gray-500">
                                        {{ $p->operationLabel() }}
                                        @if ($p->salary_recognition_month)
                                            <span class="ml-1 rounded bg-warning-100 px-1 text-xs text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">
                                                override {{ $p->salary_recognition_month }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 text-right">{{ $money($p->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endforeach
    @endif
</div>
