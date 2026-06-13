@php
    $totals = $this->getTotals();
    $buckets = $this->getBuckets();
    $showBreakdown = ($this->data['granularity'] ?? 'period') !== 'period' && count($buckets) > 1;
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Калькулятор стоимости лида за период</x-slot>
        <x-slot name="description">
            Бюджет Директа за выбранный диапазон считается пропорционально дням каждой записи.
        </x-slot>

        <form wire:submit.prevent class="space-y-6">
            {{ $this->form }}
        </form>

        @if ($totals)
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Бюджет за период</div>
                    <div class="mt-1 text-2xl font-bold">{{ $this->money($totals['budget']) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Лидов за период</div>
                    <div class="mt-1 text-2xl font-bold">{{ $totals['leads'] }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Стоимость лида</div>
                    <div @class([
                        'mt-1 text-2xl font-bold',
                        'text-danger-600 dark:text-danger-400' => $totals['cost'] !== null && $totals['cost'] >= 1000,
                        'text-success-600 dark:text-success-400' => $totals['cost'] !== null && $totals['cost'] < 1000,
                    ])>{{ $this->money($totals['cost']) }}</div>
                </div>
            </div>
        @endif

        @if ($showBreakdown)
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Интервал</th>
                            <th class="py-2 pr-4 text-right font-medium">Бюджет</th>
                            <th class="py-2 pr-4 text-right font-medium">Лидов</th>
                            <th class="py-2 text-right font-medium">Стоимость лида</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buckets as $bucket)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4">{{ $bucket['label'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $this->money($bucket['budget']) }}</td>
                                <td class="py-2 pr-4 text-right">{{ $bucket['leads'] }}</td>
                                <td class="py-2 text-right font-medium">{{ $this->money($bucket['cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
