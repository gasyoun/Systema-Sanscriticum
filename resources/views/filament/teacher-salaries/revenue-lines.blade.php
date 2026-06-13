@php
    $lines = $detail['lines'] ?? [];
    $total = $detail['total'] ?? 0;
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ') . ' ₽';
    $period = null;
    if ($block && ($block->starts_at || $block->ends_at)) {
        $period = ($block->starts_at?->format('d.m.Y') ?? '…') . ' – ' . ($block->ends_at?->format('d.m.Y') ?? '…');
    }
@endphp

<div class="space-y-2 text-sm">
    <div class="text-gray-500 dark:text-gray-400">
        Период блока:
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $period ?? 'даты блока не заданы' }}</span>
    </div>

    @if (empty($lines))
        <p class="text-gray-400">Платежей по этому блоку и группе не найдено — заполните сумму вручную.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-2 py-1.5">Студент</th>
                        <th class="px-2 py-1.5">Оплата</th>
                        <th class="px-2 py-1.5 text-right">Сумма</th>
                        <th class="px-2 py-1.5 text-right">В блок</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($lines as $line)
                        <tr>
                            <td class="px-2 py-1.5 text-gray-700 dark:text-gray-200">{{ $line['user_name'] }}</td>
                            <td class="px-2 py-1.5 text-gray-500">
                                {{ $line['label'] }}
                                @if (($line['covered_blocks'] ?? 1) > 1)
                                    <span class="text-gray-400">(÷{{ $line['covered_blocks'] }})</span>
                                @endif
                                <span class="text-gray-400">· {{ $line['created_at'] }}</span>
                            </td>
                            <td class="px-2 py-1.5 text-right text-gray-500">{{ $money($line['amount']) }}</td>
                            <td class="px-2 py-1.5 text-right font-medium text-gray-800 dark:text-gray-100">{{ $money($line['share']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-white/5 font-semibold">
                    <tr>
                        <td class="px-2 py-1.5" colspan="3">Итого за блок ({{ count($lines) }})</td>
                        <td class="px-2 py-1.5 text-right">{{ $money($total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
