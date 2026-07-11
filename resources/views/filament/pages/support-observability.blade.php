<x-filament-panels::page>
    @php
        $report = $this->report();
        $accounts = $report['accounts'];
        $delivery = $report['delivery'];
        $rollup = $report['rollup'];
        $llm = $report['llm'];
        $windowDays = $this->windowDays();
    @endphp

    {{-- Здоровье userbot-сессий + лаг синка --}}
    <x-filament::section>
        <x-slot name="heading">Сессии userbot и лаг синка</x-slot>
        <x-slot name="description">Последний успешный проход telegram-support:sync по каждому аккаунту; лаг больше 15 минут у включённого аккаунта подсвечивается.</x-slot>

        @if ($accounts->isEmpty())
            <p class="text-gray-400 text-sm">Аккаунты не настроены.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Аккаунт</th>
                            <th class="py-2 pr-4">Включён</th>
                            <th class="py-2 pr-4">Успешный синк</th>
                            <th class="py-2 pr-4">Лаг</th>
                            <th class="py-2">Последняя ошибка</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['account']->name }}</td>
                                <td class="py-2 pr-4">{{ $row['account']->is_enabled ? 'да' : 'нет' }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['account']->last_successful_sync_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td class="py-2 pr-4 tabular-nums {{ $row['is_stale'] ? 'text-danger-600 font-semibold' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ $row['lag_minutes'] !== null ? $row['lag_minutes'].' мин' : 'никогда' }}
                                </td>
                                <td class="py-2 text-danger-600">{{ $row['account']->last_sync_error ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Доставка исходящих --}}
    <x-filament::section>
        <x-slot name="heading">Доставка ответов ({{ $windowDays }} дн.)</x-slot>
        <x-slot name="description">Исходящие, прошедшие путь helpdesk-ответа через userbot (DeliverSupportReply): сколько реально ушло, сколько зависло в pending.</x-slot>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $delivery['outgoing_total'] }}</div>
                <div class="text-xs text-gray-500">исходящих всего</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $delivery['delivered'] }}</div>
                <div class="text-xs text-gray-500">доставлено (из {{ $delivery['tracked'] }} отслеживаемых)</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums {{ $delivery['pending'] > 0 ? 'text-danger-600' : '' }}">{{ $delivery['pending'] }}</div>
                <div class="text-xs text-gray-500">зависло в pending</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $delivery['rate'] !== null ? $delivery['rate'].'%' : '—' }}</div>
                <div class="text-xs text-gray-500">доля успешной доставки</div>
            </div>
        </div>
    </x-filament::section>

    {{-- Сводка разговоров --}}
    <x-filament::section>
        <x-slot name="heading">TG-разговоры ({{ $windowDays }} дн.)</x-slot>
        <x-slot name="description">Из дневных агрегатов SupportDailyRollup.</x-slot>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $rollup['conversations'] }}</div>
                <div class="text-xs text-gray-500">разговоров</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums {{ $rollup['unanswered'] > 0 ? 'text-danger-600' : '' }}">{{ $rollup['unanswered'] }}</div>
                <div class="text-xs text-gray-500">без ответа{{ $rollup['unanswered_share'] !== null ? ' ('.$rollup['unanswered_share'].'%)' : '' }}</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $rollup['avg_first_response_seconds'] !== null ? round($rollup['avg_first_response_seconds'] / 60).' мин' : '—' }}</div>
                <div class="text-xs text-gray-500">средний первый ответ</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $rollup['incoming'] }}</div>
                <div class="text-xs text-gray-500">входящих</div>
            </div>
            <div>
                <div class="text-2xl font-semibold tabular-nums">{{ $rollup['outgoing'] }}</div>
                <div class="text-xs text-gray-500">исходящих</div>
            </div>
        </div>
    </x-filament::section>

    {{-- Расход LLM --}}
    <x-filament::section>
        <x-slot name="heading">Обращения к LLM ({{ $windowDays }} дн.)</x-slot>
        <x-slot name="description">
            События журнала SupportAiReplyEvent по типам, плюс оценка расхода из usage/model в meta (доступно только для событий, записанных после H763 — более ранние честно не учитываются, а не считаются нулём).
        </x-slot>

        @if ($llm['total'] === 0)
            <p class="text-gray-400 text-sm">Обращений к LLM за период не было.</p>
        @else
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <div class="text-2xl font-semibold tabular-nums">{{ $llm['total'] }}</div>
                    <div class="text-xs text-gray-500">обращений всего</div>
                </div>
                <div>
                    <div class="text-2xl font-semibold tabular-nums">{{ $llm['spend_usd'] !== null ? '$'.number_format($llm['spend_usd'], 4) : '—' }}</div>
                    <div class="text-xs text-gray-500">расход (оценка, из {{ $llm['priced_events'] }} событий с usage)</div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4">Тип события</th>
                            <th class="py-2">Событий</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($llm['by_type'] as $type => $count)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $type }}</td>
                                <td class="py-2 tabular-nums">{{ $count }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="py-2 pr-4 font-semibold">всего</td>
                            <td class="py-2 tabular-nums font-semibold">{{ $llm['total'] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
