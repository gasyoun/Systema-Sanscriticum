<x-filament-panels::page>
    @if ($this->groups->isEmpty())
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Ни у одной учебной группы не задан <strong>telegram_chat_id</strong>.
                Заполните его в разделе <strong>Группы</strong> (поле «Telegram chat_id группы»)
                — тогда группа появится здесь, а бот сможет слать в неё напоминания из расписания.
            </p>
        </div>
    @else
        {{-- Выпадающий список групп с поиском (Filament searchable Select) --}}
        <div class="max-w-md">
            {{ $this->form }}
        </div>

        @if ($this->selectedGroup)
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div class="fi-section rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="flex items-center gap-3 min-w-0">
                            @if ($this->avatar)
                                <img src="{{ $this->avatar }}" alt="" class="h-11 w-11 rounded-full object-cover shrink-0">
                            @else
                                <div class="h-11 w-11 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-sm font-semibold text-gray-400 shrink-0">
                                    {{ mb_strtoupper(mb_substr($this->selectedGroup->name, 0, 1)) }}
                                </div>
                            @endif
                            <div class="min-w-0">
                                <h2 class="text-base font-semibold truncate">Состав чата</h2>
                                <div class="text-xs text-gray-500 truncate">{{ $this->selectedGroup->name }}</div>
                                <div class="text-xs text-gray-400">chat_id: <code>{{ $this->chatId }}</code></div>
                            </div>
                        </div>
                        <x-filament::button
                            color="gray"
                            size="sm"
                            wire:click="checkZapisiRights"
                            class="shrink-0"
                        >
                            Права zapisi
                        </x-filament::button>
                    </div>

                    @if (! $this->roster)
                        <p class="text-sm text-gray-500">
                            Ростер ещё не снят. Запустите
                            <code>php artisan telegram-harvest:roster {{ $this->chatId }}</code> на хосте.
                        </p>
                    @else
                        <p @class(['text-xs text-gray-500', 'mb-1' => $this->roster['is_stale'], 'mb-3' => ! $this->roster['is_stale']])>
                            {{ $this->roster['count'] }} участник(ов), снято
                            {{ $this->roster['fetched_at_human'] ?? 'неизвестно когда' }}
                            @if ($this->roster['fetched_at'])
                                <span class="text-gray-400">({{ $this->roster['fetched_at'] }})</span>
                            @endif
                        </p>

                        @if ($this->roster['is_stale'])
                            <p class="mb-3 rounded-lg bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                                Снимок протух (старше {{ $this->roster['stale_after_hours'] }} ч) — состав мог измениться.
                                Обновляет <code>telegram-harvest:roster-groups</code> раз в час; ему нужна включённая
                                MTProto-сессия (<code>TELEGRAM_SUPPORT_ENABLED</code>).
                            </p>
                        @endif
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800 max-h-96 overflow-y-auto">
                            @foreach ($this->roster['members'] as $member)
                                @php
                                    $memberId = (int) ($member['id'] ?? 0);
                                    $isBot = ! empty($member['is_bot']);
                                @endphp
                                <li class="py-2 text-sm flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="font-medium">{{ $member['name'] ?? '—' }}</span>
                                        @if ($isBot)
                                            <span class="ml-1 text-[10px] uppercase tracking-wide text-gray-400">бот</span>
                                        @endif
                                        <div class="text-xs text-gray-500 truncate">
                                            @if (! empty($member['username']))
                                                <span>{{ '@'.$member['username'] }}</span>
                                            @endif
                                            @if ($memberId > 0)
                                                <span class="text-gray-400">id {{ $memberId }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @if (! $isBot && $memberId > 0)
                                        <x-filament::button
                                            color="danger"
                                            size="xs"
                                            wire:click="kickMember({{ $memberId }})"
                                            wire:confirm="Исключить {{ $member['name'] ?? $memberId }} из Telegram-чата? (soft kick; LMS не меняется)"
                                            class="shrink-0"
                                        >
                                            Исключить
                                        </x-filament::button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="fi-section rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
                    <h2 class="text-base font-semibold mb-4">Последние сообщения</h2>

                    @if (empty($this->recentMessages))
                        <p class="text-sm text-gray-500">
                            Сообщений пока нет (нужен добавленный peer в TELEGRAM_HARVEST_PEERS
                            и/или активный вебхук бота).
                        </p>
                    @else
                        <p @class(['text-xs text-gray-500', 'mb-1' => $this->corpusFreshness['is_silent'], 'mb-3' => ! $this->corpusFreshness['is_silent']])>
                            последнее сообщение {{ $this->corpusFreshness['last_sent_human'] ?? '—' }}
                        </p>

                        @if ($this->corpusFreshness['is_silent'])
                            <p class="mb-3 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                                Новых сообщений нет дольше {{ $this->corpusFreshness['silence_after_hours'] }} ч.
                                Если в чате при этом пишут — проверьте вебхук бота:
                                <code>getWebhookInfo</code> и <code>php artisan zapisi:set-webhook</code>.
                            </p>
                        @endif

                        <ul class="divide-y divide-gray-100 dark:divide-gray-800 max-h-96 overflow-y-auto">
                            @foreach ($this->recentMessages as $message)
                                <li class="py-2 text-sm">
                                    <div class="flex justify-between text-xs text-gray-500">
                                        <span>{{ $message['author_name'] ?? $message['author_username'] ?? '—' }}</span>
                                        <span>{{ $message['sent_at'] ?? '' }}</span>
                                    </div>
                                    <p class="mt-1">
                                        {{ $message['text'] ?: '' }}
                                        @if ($message['has_media'] ?? false)
                                            <span class="text-primary-600">[{{ $message['media_type'] }}]</span>
                                        @endif
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Напоминания о занятиях бот шлёт автоматически из расписания
                (<code>zapisi:remind-classes</code>) в чат каждой группы. Время и текст —
                в настройках маркетинга, раздел «@zapisi_ORSbot (записи)».
            </p>
        </div>
    @endif
</x-filament-panels::page>
