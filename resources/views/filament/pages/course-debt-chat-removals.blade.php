<x-filament-panels::page>
    <div class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <p class="font-medium text-gray-900 dark:text-gray-100">Как это работает</p>
        <ol class="mt-2 list-decimal space-y-1 ps-5 text-gray-700 dark:text-gray-300">
            <li>
                В список кандидатов человек попадает, когда сходится всё сразу:
                просрочка по курсу <b>≥ {{ $rule['days'] }} дн.</b>,
                последние <b>{{ $rule['contacts'] }}</b> обращения остались без ответа,
                у группы заполнен <code>telegram_chat_id</code>, у студента привязан Telegram.
            </li>
            <li>
                Исключение делаете вы — кнопкой <b>«Исключить из TG-чата»</b> в разделе
                <a class="text-primary-600 hover:underline" href="{{ \App\Filament\Pages\Debtors::getUrl() }}">Должники</a>.
                Эта страница в Telegram не ходит.
            </li>
            <li>
                Сразу после кика нажмите здесь <b>«Записать исключение»</b> — строка попадёт в реестр
                вместе с основанием (сумма, блоки, даты обращений).
            </li>
            <li>
                Вернуться в чат можно только когда закрыты <b>оба</b> долга: курсовой
                и взнос <b>{{ $rule['fee'] }} {{ $rule['currency'] }} за каждый чат</b>,
                из которого студента выгнали. Кнопка «Возвращён в чат» до этого не появится.
            </li>
        </ol>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Пропуск месяца клубного членства долгом не считается и в этот список не приводит.
            Автоматических действий в Telegram нет
            @if ($rule['autoTelegram'])
                <b class="text-danger-600">(внимание: автоматические мутации ВКЛЮЧЕНЫ в конфиге)</b>
            @endif
            .
        </p>
    </div>

    <div>
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
            Подлежат исключению — {{ $candidates->count() }}
        </h2>

        @if ($candidates->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Никто не подходит под правило.</p>
        @else
            <div class="mt-3 overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-2 font-medium">Студент</th>
                            <th class="px-3 py-2 font-medium">Курс</th>
                            <th class="px-3 py-2 font-medium">Чат</th>
                            <th class="px-3 py-2 font-medium">Просрочка</th>
                            <th class="px-3 py-2 font-medium">Долг</th>
                            <th class="px-3 py-2 font-medium">Молчит с</th>
                            <th class="px-3 py-2 font-medium">Взнос</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($candidates as $c)
                            <tr>
                                <td class="px-3 py-2">
                                    <a class="text-primary-600 hover:underline"
                                       href="{{ \App\Filament\Resources\UserResource::getUrl('edit', ['record' => $c->user->id]) }}">
                                        {{ $c->user->name ?: $c->user->email }}
                                    </a>
                                </td>
                                <td class="px-3 py-2">{{ $c->courseTitle }}</td>
                                <td class="px-3 py-2">{{ $c->chatLabel() }}</td>
                                <td class="px-3 py-2">{{ $c->daysOverdue }} дн.</td>
                                <td class="px-3 py-2">
                                    {{ $c->debtAmount !== null ? number_format($c->debtAmount, 0, ',', ' ').' ₽' : '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ $c->evidence->silentSince?->format('d.m.Y') ?? '—' }}
                                    <span class="text-xs text-gray-500">({{ $c->evidence->trailingUnanswered }} без ответа)</span>
                                </td>
                                <td class="px-3 py-2">{{ $c->reinstatementFee }} ₽</td>
                                <td class="px-3 py-2 text-right">
                                    <x-filament::button
                                        size="sm"
                                        color="danger"
                                        wire:click="recordRemoval({{ (int) $c->user->id }}, {{ $c->courseId }}, '{{ $c->telegramChatId }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        Записать исключение
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($rejected->isNotEmpty())
        <details class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <summary class="cursor-pointer font-medium text-gray-900 dark:text-gray-100">
                Должники, которые правилу НЕ соответствуют — {{ $rejected->count() }}
            </summary>
            <ul class="mt-2 space-y-1 text-gray-700 dark:text-gray-300">
                @foreach ($rejected as $c)
                    <li>
                        <b>{{ $c->user->name ?: $c->user->email }}</b> · {{ $c->courseTitle }}
                        @if ($c->telegramChatId !== '')
                            · {{ $c->chatLabel() }}
                        @endif
                        —
                        {{ implode('; ', array_map([\App\Services\Discipline\ChatRemovalEligibility::class, 'blockerLabel'], $c->blockers)) }}
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <div>
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Реестр исключений</h2>
        <div class="mt-3">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
