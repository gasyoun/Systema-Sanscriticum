<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $money = fn ($value, $currency = '₽') => number_format((float) $value, 2, ',', ' ') . ' ' . $currency;
        $badge = fn ($status) => match ($status) {
            'payable', 'fresh', 'funded' => 'success',
            'held', 'incomplete', 'unfunded' => 'danger',
            'zero', 'inactive', 'not_applicable' => 'gray',
            default => 'warning',
        };
    @endphp

    <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950/30">
        Это проверка и приватный экспорт, а не платёжный инструмент. Переводы выполняют люди в банке/PayPal и подтверждают существующим процессом кабинета.
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach ($report['evidence'] as $source => $evidence)
            <x-filament::section compact>
                <div class="flex items-center justify-between gap-3">
                    <span class="font-semibold">{{ $source }}</span>
                    <x-filament::badge :color="$badge($evidence['status'])">{{ $evidence['status'] }}</x-filament::badge>
                </div>
                <p class="mt-2 text-xs text-gray-500">{{ $evidence['note'] ?? ($evidence['as_of'] ?? $evidence['generated_at'] ?? 'fresh') }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Отпечаток утверждённого экспорта</x-slot>
        <div class="flex flex-wrap items-end gap-3">
            <label class="min-w-80 flex-1 text-sm">
                <span class="mb-1 block font-medium">SHA-256</span>
                <input wire:model="expectedFingerprint" class="w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-900" />
            </label>
            <x-filament::button wire:click="verifyFingerprint">Пересчитать перед переводом</x-filament::button>
        </div>
        <p class="mt-2 break-all font-mono text-xs text-gray-500">Текущий: {{ $report['fingerprint'] }}</p>
        @if ($fingerprintMatches !== null)
            <p class="mt-2 text-sm font-semibold {{ $fingerprintMatches ? 'text-success-600' : 'text-danger-600' }}">
                {{ $fingerprintMatches ? 'Совпадает — пакет не изменился.' : 'Не совпадает — регенерировать и утвердить заново.' }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Перепись {{ $report['actual_teacher_count'] }} / {{ $report['expected_teacher_count'] }}</x-slot>
        <x-slot name="description">Сортировка при нехватке средств: самая старая дата обязательства, затем ID преподавателя.</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="p-2">ID / преподаватель</th><th class="p-2">Статус</th><th class="p-2">К выплате</th><th class="p-2">Последний перевод</th><th class="p-2">Канал / средства</th><th class="p-2">Проверка</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($report['teachers'] as $row)
                        <tr>
                            <td class="p-2"><span class="text-xs text-gray-400">#{{ $row['teacher_id'] }}</span> {{ $row['name'] }}<div class="text-xs text-gray-400">due {{ $row['due_on'] }}</div></td>
                            <td class="p-2"><x-filament::badge :color="$badge($row['disposition'])">{{ $row['disposition'] }}</x-filament::badge></td>
                            <td class="p-2 tabular-nums">{{ $money($row['payable_rub']) }}@if($row['payable_eur'] !== null)<br>{{ $money($row['payable_eur'], '€') }}@endif</td>
                            <td class="p-2 text-xs">
                                @if($row['last_actual_transfer'])
                                    {{ $row['last_actual_transfer']['date'] }} · {{ $row['last_actual_transfer']['days_since'] }} дн. назад · {{ $money($row['last_actual_transfer']['amount_rub']) }}
                                    @if($row['last_actual_transfer']['amount_foreign'] !== null) · {{ $money($row['last_actual_transfer']['amount_foreign'], $row['last_actual_transfer']['currency']) }}@endif
                                    <br>{{ $row['last_actual_transfer']['channel'] }} · курс #{{ $row['last_actual_transfer']['course_id'] ?? '—' }} · блок {{ $row['last_actual_transfer']['block_number'] ?? '—' }} · ставка {{ $row['last_actual_transfer']['rate'] ?? '—' }}
                                    <br>авансы {{ json_encode($row['last_actual_transfer']['advances'], JSON_UNESCAPED_UNICODE) }} · зачёты {{ json_encode($row['last_actual_transfer']['offsets'], JSON_UNESCAPED_UNICODE) }}
                                    <br>{{ $row['last_actual_transfer']['evidence_reference'] ?: 'нет ссылки на доказательство' }}
                                @else—@endif
                            </td>
                            <td class="p-2 text-xs">{{ $row['channel'] }}<br><x-filament::badge :color="$badge($row['funding_state'])">{{ $row['funding_state'] }}</x-filament::badge>@if($row['remaining_obligation'] > 0)<br>остаток {{ $money($row['remaining_obligation'], $row['channel'] === 'paypal_mg' ? '€' : '₽') }}@endif</td>
                            <td class="p-2 text-xs">
                                <div class="break-all font-mono">{{ substr($row['fingerprint'], 0, 16) }}…</div>
                                <div>база {{ $money($row['base_rub']) }} · прошлые блоки {{ $money($row['prior_rub']) }} · авансы {{ $money($row['advances_total_rub']) }}</div>
                                <div>ставка {{ json_encode($row['rate_period'], JSON_UNESCAPED_UNICODE) }} · прямые {{ json_encode($row['direct_receipts'], JSON_UNESCAPED_UNICODE) }}</div>
                                @foreach($row['holds'] as $hold)<div class="mt-1 text-danger-600">{{ $hold }}</div>@endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
