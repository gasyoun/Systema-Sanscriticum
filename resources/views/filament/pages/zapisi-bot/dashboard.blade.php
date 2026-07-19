<x-filament-panels::page>
    @if (! $this->chatId)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Chat ID не задан. Заполните <strong>zapisi_chat_id</strong> в настройках маркетинга
                (MarketingSetting) — id чата @zapisi_ORSbot, обнаруживается через
                <code>php artisan telegram-harvest:peers</code>.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="fi-section rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
                <h2 class="text-base font-semibold mb-4">Состав чата</h2>

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

        <div class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm dark:bg-gray-900">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Расписание напоминаний — раздел
                <a href="{{ \App\Filament\Resources\ZapisiClassScheduleResource::getUrl() }}" class="text-primary-600 underline">
                    «Расписание»
                </a>.
            </p>
        </div>
    @endif
</x-filament-panels::page>
