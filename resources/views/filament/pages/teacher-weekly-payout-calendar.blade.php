@php
    $money = fn ($v): string => number_format((float) $v, 2, ',', ' ');
    $grid = $grid ?? ['weeks' => [], 'tochka' => ['ok' => false], 'paypal_note' => ''];
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
</x-filament-panels::page>
