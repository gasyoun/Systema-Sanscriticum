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
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- ЛЕВАЯ ПАНЕЛЬ: список чатов (учебных групп) --}}
            <div class="fi-section rounded-xl bg-white p-4 shadow-sm dark:bg-gray-900 lg:col-span-1">
                <h2 class="text-base font-semibold mb-3">Чаты групп</h2>
                <ul class="space-y-1 max-h-[32rem] overflow-y-auto">
                    @foreach ($this->groups as $group)
                        <li>
                            <button type="button" wire:click="selectGroup({{ $group->id }})"
                                @class([
                                    'w-full text-left rounded-lg px-3 py-2 text-sm transition',
                                    'bg-primary-50 text-primary-700 font-medium dark:bg-primary-500/10 dark:text-primary-400' => $this->selectedGroupId === $group->id,
                                    'hover:bg-gray-50 dark:hover:bg-gray-800' => $this->selectedGroupId !== $group->id,
                                ])>
                                <div>{{ $group->name }}</div>
                                <div class="text-xs text-gray-400">{{ $group->telegram_chat_id }}</div>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- ПРАВАЯ ПАНЕЛЬ: состав + сообщения выбранного чата --}}
            <div class="lg:col-span-2 space-y-6">
                <div class="fi-section rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
                    <h2 class="text-base font-semibold mb-4">
                        Состав чата
                        @if ($this->selectedGroup)
                            <span class="text-sm font-normal text-gray-500">— {{ $this->selectedGroup->name }}</span>
                        @endif
                    </h2>

                    @if (! $this->roster)
                        <p class="text-sm text-gray-500">
                            Ростер ещё не снят. Запустите
                            <code>php artisan telegram-harvest:roster {{ $this->chatId }}</code> на хосте.
                        </p>
                    @else
                        <p class="text-xs text-gray-500 mb-3">
                            {{ $this->roster['count'] }} участник(ов), снято {{ $this->roster['fetched_at'] }}
                        </p>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-800 max-h-96 overflow-y-auto">
                            @foreach ($this->roster['members'] as $member)
                                <li class="py-2 text-sm flex justify-between">
                                    <span>{{ $member['name'] ?? '—' }}</span>
                                    <span class="text-gray-500">
                                        {{ $member['username'] ? '@'.$member['username'] : $member['id'] }}
                                    </span>
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
        </div>

        <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Напоминания о занятиях бот шлёт автоматически из расписания
                (<code>zapisi:remind-classes</code>) в чат каждой группы. Время и текст —
                в настройках маркетинга, раздел «@zapisi_ORSbot (записи)».
            </p>
        </div>
    @endif
</x-filament-panels::page>
