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
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Incoming: {{ $today['incoming'] }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Replies</div>
                <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $today['outgoing'] }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Human {{ $today['human_replies'] }} / AI {{ $today['ai_sent'] }}</div>
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
                            $title = $conversation->chat->title
                                ?? $conversation->chat->linkedUser?->name
                                ?? $conversation->chat->username
                                ?? ('Telegram chat '.$conversation->chat->telegram_chat_id);
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
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $conversation->last_message_at?->format('H:i') }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">in {{ $conversation->incoming_count }}</span>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">out {{ $conversation->outgoing_count }}</span>
                                @if($conversation->has_new_contact)
                                    <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-500/20 dark:text-green-400">new</span>
                                @endif
                                @if($conversation->is_unanswered)
                                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-400">unanswered</span>
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
                            $isOutgoing = $message->direction === 'outgoing';
                            $sender = $isOutgoing
                                ? ($message->responder?->name ?? $message->responder_marker ?? $message->responder_type ?? 'Support')
                                : 'Contact';
                        @endphp
                        <div @class(['tg-msg mb-3', 'ml-auto text-right' => $isOutgoing])>
                            <div class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ $sender }} · {{ $message->sent_at->format('d.m H:i') }}</div>
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
