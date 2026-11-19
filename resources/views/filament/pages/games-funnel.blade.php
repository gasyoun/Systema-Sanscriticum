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

        {{-- CTA -> регистрация (H1678, цель D6: ≥15% при выборке ≥50) --}}
        @php $conv = $this->conversion; @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">CTA → регистрация</div>
            @if ($conv['clickers'] === 0)
                <div class="mt-1 text-lg text-gray-500 dark:text-gray-400">Пока нет кликов «Начать бесплатно» в окне.</div>
            @else
                <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">
                    {{ $conv['registered'] }} / {{ $conv['clickers'] }} = {{ $conv['rate'] }}%
                </div>
                <div class="mt-1 text-sm {{ $conv['baseline_only'] ? 'text-gray-500 dark:text-gray-400' : ($conv['rate'] >= 15 ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400') }}">
                    @if ($conv['baseline_only'])
                        Выборка &lt; 50 — baseline, KPI еще не судим.
                    @elseif ($conv['rate'] >= 15)
                        Цель ≥15% достигнута.
                    @else
                        Ниже цели 15%.
                    @endif
                </div>
            @endif
        </div>

        {{-- Разбивка по тренажерам --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Тренажер</th>
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

        {{-- H6393 (lila v2): по-лигатурная трудность — топ трудных чтений тренажёра «Лигатуры» --}}
        @php $lig = $this->ligatureRows; @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Лигатуры: трудные чтения (топ-{{ count($lig) }})
            </div>
            <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                По событиям item_result (H4692): медиана времени до первой связки и доля раундов с ошибкой.
                Включая режим «Близнецы» (band = twins).
            </div>
            @if (count($lig) === 0)
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Пока нет завершённых раундов лигатур в окне — таблица наполнится после первых решений.
                </div>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-2 py-1">Уровень</th>
                                <th class="px-2 py-1">Чтение (IAST)</th>
                                <th class="px-2 py-1 text-right">Раунды</th>
                                <th class="px-2 py-1 text-right">Медиана, мс</th>
                                <th class="px-2 py-1 text-right">Была ошибка</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($lig as $row)
                                <tr class="text-gray-800 dark:text-gray-200">
                                    <td class="px-2 py-1">{{ $row['band'] ?? '—' }}</td>
                                    <td class="px-2 py-1 font-medium">{{ $row['item'] }}</td>
                                    <td class="px-2 py-1 text-right">{{ number_format($row['rounds']) }}</td>
                                    <td class="px-2 py-1 text-right">{{ number_format($row['median_ms']) }}</td>
                                    <td class="px-2 py-1 text-right">{{ $row['wrong_rate'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
