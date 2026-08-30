<x-filament-panels::page>
    {{-- Layout-only grid definitions: these column templates aren't part of Filament's
         precompiled CSS bundle, so express them here. Colours/spacing stay as Tailwind
         utilities (dark-mode aware) above. --}}
    <style>
        .tg-cards-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
        .tg-main-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        .tg-scroll { max-height: 620px; overflow-y: auto; }
        .tg-filter-select { min-width: 10rem; }
        .tg-msg { max-width: 78%; }
        .tg-table-wrap { overflow-x: auto; }
        @media (min-width: 1024px) {
            .tg-cards-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .tg-main-grid { grid-template-columns: minmax(320px, 420px) 1fr; }
        }
    </style>

    <div class="space-y-4" wire:poll.60s>
        @php
            $packet = $this->packet;
            $today = $packet['summary']['today'];
            $yesterday = $packet['summary']['yesterday'];
        @endphp

        {{-- Summary cards --}}
        <div class="tg-cards-grid">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Conversations</div>
                <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $today['conversations'] }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Yesterday: {{ $yesterday['conversations'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">New contacts</div>
                <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $today['new_contacts'] }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">First contact ever</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Unanswered</div>
                <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $today['unanswered'] }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Incoming: {{ $today['incoming'] }} · overdue {{ $today['unresolved_after_hours'] }}
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Replies</div>
                <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $today['outgoing'] }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Human {{ $today['human_replies'] }} / AI {{ $today['ai_sent'] }}</div>
            </div>
        </div>

        {{-- Разбивка по каналам (H1837, S10): единый отчёт без ручной сверки. Пока
             веб-агрегация выключена флагом, здесь одна строка — Telegram. --}}
        @if(count($today['by_channel']) > 1)
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <strong class="text-gray-950 dark:text-white">Каналы</strong>
                    <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">deflection по обоим сторам, одна таблица</span>
                </div>
                <div class="tg-table-wrap">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-left">Канал</th>
                                <th class="px-4 py-2 text-right">Разговоров</th>
                                <th class="px-4 py-2 text-right">Входящих</th>
                                <th class="px-4 py-2 text-right">Ответов</th>
                                <th class="px-4 py-2 text-right">Человек</th>
                                <th class="px-4 py-2 text-right">ИИ</th>
                                <th class="px-4 py-2 text-right">Без ответа</th>
                                <th class="px-4 py-2 text-right">Просрочено</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($today['by_channel'] as $channelKey => $channelMetrics)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-4 py-2 text-gray-950 dark:text-white">
                                        {{ (new \App\Models\SupportDailyRollup(['channel' => $channelKey]))->channelLabel() }}
                                    </td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['conversations'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['incoming'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['outgoing'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['human_replies'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['ai_sent'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['unanswered'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $channelMetrics['unresolved_after_hours'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- H3529: дневной coverage классификации по каналам. Источник —
             support_topic_assignments выбранной даты; uncategorized rate
             включает разговоры без назначений. Ссылка на отчёт харнесса
             появляется, когда пакет заморозит reports/*.md (шаг 4 волны 1). --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <strong class="text-gray-950 dark:text-white">Coverage классификации</strong>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->selectedDate }} · всего {{ $this->coverage['total'] }}
                </span>
            </div>
            <div class="tg-table-wrap">
                <table class="w-full text-sm" data-testid="support-coverage-panel">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-left">Канал</th>
                            <th class="px-4 py-2 text-right">Разговоров</th>
                            <th class="px-4 py-2 text-right">С темой</th>
                            <th class="px-4 py-2 text-right">Coverage %</th>
                            <th class="px-4 py-2 text-right">Uncategorized</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->coverage['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2 text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['total'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['categorized'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['coverage'] === null ? '—' : $row['coverage'].'%' }}</td>
                                <td class="px-4 py-2 text-right">{{ $row['uncategorized'] }}</td>
                            </tr>
                        @empty
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td colspan="5" class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                    Нет разговоров за выбранную дату
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($this->coverage['total'] > 0)
                        <tfoot class="border-t border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <td class="px-4 py-2">Итого</td>
                                <td class="px-4 py-2 text-right">{{ $this->coverage['total'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $this->coverage['categorized'] }}</td>
                                <td class="px-4 py-2 text-right">{{ $this->coverage['coverage'] }}%</td>
                                <td class="px-4 py-2 text-right">{{ $this->coverage['uncategorized_rate'] }}% uncategorized</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @if($this->harnessReportUrl)
                <div class="border-t border-gray-200 px-4 py-2 text-xs dark:border-white/10">
                    <a href="{{ $this->harnessReportUrl }}" target="_blank" rel="noopener"
                       class="text-primary-600 underline dark:text-primary-400">
                        Последний отчёт харнесса классификатора (pinned)
                    </a>
                </div>
            @endif
        </div>

        {{-- H3395: ручные использования шаблонов библиотеки куратором (Helpdesk-сенд,
             начатый с шаблона) за 30 дней — топ-10. Denominator для ревью библиотеки
             H2339 наравне с автосендами dm_auto_sent kind=template. --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <strong class="text-gray-950 dark:text-white">Шаблоны — ручные отправки</strong>
                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">топ-10 за 30 дней, helpdesk</span>
            </div>
            <div class="tg-table-wrap">
                <table class="w-full text-sm" data-testid="support-manual-templates">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2 text-left">Шаблон</th>
                            <th class="px-4 py-2 text-right">Ручных отправок</th>
                            <th class="px-4 py-2 text-right">Последняя</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->manualTemplateUses as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="px-4 py-2 text-gray-950 dark:text-white">
                                    {{ $row['title'] }}@if($row['template_id'] !== null) <span class="text-xs text-gray-400">#{{ $row['template_id'] }}</span>@endif
                                </td>
                                <td class="px-4 py-2 text-right">{{ $row['uses'] }}</td>
                                <td class="px-4 py-2 text-right text-gray-500 dark:text-gray-400">{{ $row['last_used_at'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td colspan="3" class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                    За 30 дней ручных отправок с шаблоном не было
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Filters --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                <input
                    type="date"
                    wire:model.live="selectedDate"
                    class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white dark:[color-scheme:dark]"
                >

                <select
                    wire:model.live="topic"
                    class="tg-filter-select rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
                >
                    <option value="">All topics</option>
                    @foreach($this->topicOptions as $topicOption)
                        <option value="{{ $topicOption }}">{{ $topicOption }}</option>
                    @endforeach
                </select>

                @if(count($this->channelOptions) > 1)
                    <select
                        wire:model.live="channel"
                        class="tg-filter-select rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
                    >
                        <option value="">Все каналы</option>
                        @foreach($this->channelOptions as $channelValue => $channelLabel)
                            <option value="{{ $channelValue }}">{{ $channelLabel }}</option>
                        @endforeach
                    </select>
                @endif

                <select
                    wire:model.live="responderType"
                    class="tg-filter-select rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5 dark:text-white"
                >
                    <option value="">All responders</option>
                    <option value="human">Human</option>
                    <option value="ai">AI</option>
                    <option value="unknown">Unknown</option>
                </select>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="onlyUnanswered" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5">
                    Unanswered
                </label>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="onlyNewContacts" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5">
                    New contacts
                </label>

                <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">Updated {{ now()->format('H:i:s') }}</span>
            </div>
        </div>

        {{-- Chats + messages --}}
        <div class="tg-main-grid">
            {{-- Chat list --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <strong class="text-gray-950 dark:text-white">Chats</strong>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $this->conversations->count() }} shown</span>
                </div>
                <div class="tg-scroll">
                    @forelse($this->conversations as $conversation)
                        @php
                            // Канал-независимая подпись: на веб-строке $conversation->chat === null.
                            $title = $conversation->subjectLabel();
                        @endphp
                        <button
                            type="button"
                            wire:click="selectConversation({{ $conversation->id }})"
                            @class([
                                'block w-full border-b border-gray-100 px-4 py-3 text-left transition hover:bg-primary-50 dark:border-white/5 dark:hover:bg-white/5',
                                'bg-primary-50 dark:bg-white/5' => $activeConversationId === $conversation->id,
                            ])
                        >
                            <div class="flex items-center justify-between gap-3">
                                <strong class="truncate text-gray-950 dark:text-white">{{ $title }}</strong>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ \App\Support\ChatTimestamp::label($conversation->last_message_at) }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-500/20 dark:text-blue-300">{{ $conversation->channelLabel() }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">in {{ $conversation->incoming_count }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">out {{ $conversation->outgoing_count }}</span>
                                @if($conversation->has_new_contact)
                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-500/20 dark:text-green-400">new</span>
                                @endif
                                @if($conversation->is_unanswered)
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-400">unanswered</span>
                                @endif
                                @if($conversation->unresolved_after_hours)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">overdue</span>
                                @endif
                                @foreach($conversation->topicAssignments as $assignment)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $assignment->category }}</span>
                                @endforeach
                            </div>
                        </button>
                    @empty
                        <div class="p-8 text-center text-sm text-gray-400 dark:text-gray-500">No conversations for this filter</div>
                    @endforelse
                </div>
            </div>

            {{-- Message samples --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <strong class="text-gray-950 dark:text-white">Message samples</strong>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $this->activeMessages->count() }} messages</span>
                </div>
                <div class="tg-scroll bg-gray-50 p-4 dark:bg-gray-900">
                    @forelse($this->activeMessages as $message)
                        @php
                            // UnifiedMessage (H1837): одна форма для обоих хранилищ.
                            $isOutgoing = ! $message->isIncoming();
                            $sender = $isOutgoing
                                ? ($message->responderName ?? $message->responderMarker ?? $message->responderType ?? 'Support')
                                : 'Contact';
                        @endphp
                        <div @class(['tg-msg mb-3', 'ml-auto text-right' => $isOutgoing])>
                            <div class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ $sender }} · {{ $message->sentAt->format('d.m H:i') }}</div>
                            <div @class([
                                'inline-block rounded-xl px-3 py-2 text-left text-sm',
                                'border border-gray-200 bg-white text-gray-800 dark:border-white/10 dark:bg-gray-800 dark:text-gray-100' => ! $isOutgoing,
                                'bg-primary-500 text-white' => $isOutgoing,
                            ])>{!! nl2br(e($message->text)) !!}</div>
                        </div>
                    @empty
                        <div class="flex items-center justify-center py-16 text-center text-sm text-gray-400 dark:text-gray-500">Select a chat to see messages</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
