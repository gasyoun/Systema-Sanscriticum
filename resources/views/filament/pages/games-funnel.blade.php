<x-filament-panels::page>
    @php
        $rows = $this->rows;
        $totals = $this->totals;
        $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) . '%' : '—';
    @endphp

    <div class="space-y-4">
        {{-- Шапка: суммарная воронка за окно --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                'plays' => 'Показы',
                'completes' => 'Решения',
                'walls' => 'Стена',
                'cta' => 'CTA «Начать бесплатно»',
            ] as $key => $label)
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ number_format($totals[$key]) }}</div>
                </div>
            @endforeach
        </div>

        <div class="text-sm text-gray-500 dark:text-gray-400">
            За последние {{ $this->days }} дн. Анонимно, без PII (H1360).
        </div>

        {{-- Разбивка по тренажёрам --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Тренажёр</th>
                        <th class="px-3 py-2">Уровень</th>
                        <th class="px-3 py-2 text-right">Показы</th>
                        <th class="px-3 py-2 text-right">Решения</th>
                        <th class="px-3 py-2 text-right">Стена</th>
                        <th class="px-3 py-2 text-right">CTA</th>
                        <th class="px-3 py-2 text-right">Реш./показ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($rows as $row)
                        <tr class="text-gray-800 dark:text-gray-200">
                            <td class="px-3 py-2 font-medium">{{ $row['drill'] }}</td>
                            <td class="px-3 py-2">{{ $row['band'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['plays']) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['completes']) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['walls']) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['cta']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $pct($row['completes'], $row['plays']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6 text-center text-gray-500 dark:text-gray-400">
                                Пока ни одного события в окне.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
