<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Живые и предстоящие группы с активными тарифами. Для каждой оплаты — своя ссылка
        (у каждого тарифа свой ID): откройте ссылку тарифа и отправьте её ученику —
        форма самообслуживания покажет фиксированную сумму и примет уведомление об оплате.
    </p>

    <div class="space-y-4">
        @forelse ($this->groups as $group)
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white">
                        {{ $group['course']->title }}
                    </h3>
                    <div class="flex items-center gap-3 text-xs">
                        @if ($group['total_eur'])
                            <span class="text-gray-500 dark:text-gray-400">весь курс (блоки): <b class="text-gray-900 dark:text-white">{{ $group['total_eur'] }} €</b> / <b class="text-gray-900 dark:text-white">{{ $group['total_usd'] }} $</b></span>
                        @endif
                        <a href="{{ $group['url'] }}" target="_blank" rel="noopener"
                           class="font-semibold text-primary-600 dark:text-primary-400 hover:underline">
                            страница курса ↗
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wider text-gray-400 border-b border-gray-100 dark:border-white/10">
                                <th class="py-2 pr-3 font-semibold">Тариф</th>
                                <th class="py-2 pr-3 font-semibold">₽</th>
                                <th class="py-2 pr-3 font-semibold">EUR / USD</th>
                                <th class="py-2 font-semibold">Ссылка для ученика</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['tariffs'] as $t)
                                <tr class="border-b border-gray-50 dark:border-white/5">
                                    <td class="py-2 pr-3 font-semibold text-gray-800 dark:text-gray-200">{{ $t['title'] }}</td>
                                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-400">{{ $t['rub'] }} ₽</td>
                                    <td class="py-2 pr-3 text-gray-600 dark:text-gray-400">
                                        @if ($t['eur'] || $t['usd'])
                                            @if ($t['eur'])<b class="text-gray-900 dark:text-white">{{ $t['eur'] }} €</b>@endif
                                            @if ($t['eur'] && $t['usd']) / @endif
                                            @if ($t['usd'])<b class="text-gray-900 dark:text-white">{{ $t['usd'] }} $</b>@endif
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        <a href="{{ $t['link'] }}" target="_blank" rel="noopener"
                                           class="font-mono text-xs text-primary-600 dark:text-primary-400 hover:underline">
                                            {{ $t['link'] }}
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="rounded-xl bg-gray-50 p-8 text-center text-gray-500 dark:bg-white/5 dark:text-gray-400">
                Нет живых групп с активными тарифами.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
