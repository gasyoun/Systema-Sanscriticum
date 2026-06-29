<x-filament-panels::page>
    <style>
        .tg-support-wrap { display: grid; gap: 18px; }
        .tg-support-cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .tg-support-card { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 14px; }
        .tg-support-label { color: #6b7280; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .tg-support-value { color: #111827; font-size: 26px; font-weight: 700; margin-top: 4px; }
        .tg-support-grid { display: grid; grid-template-columns: minmax(320px, 420px) 1fr; gap: 16px; min-height: 520px; }
        .tg-support-panel { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; overflow: hidden; }
        .tg-support-panel-head { padding: 14px 16px; border-bottom: 1px solid #e5e7eb; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .tg-support-list { max-height: 620px; overflow-y: auto; }
        .tg-support-row { width: 100%; border: 0; border-bottom: 1px solid #f3f4f6; background: transparent; padding: 14px 16px; text-align: left; cursor: pointer; }
        .tg-support-row:hover, .tg-support-row.active { background: #fff7ed; }
        .tg-support-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .tg-support-badge { border-radius: 999px; background: #f3f4f6; color: #374151; padding: 2px 8px; font-size: 11px; font-weight: 600; }
        .tg-support-badge.warn { background: #fee2e2; color: #991b1b; }
        .tg-support-badge.good { background: #dcfce7; color: #166534; }
        .tg-support-messages { padding: 18px; max-height: 620px; overflow-y: auto; background: #f8fafc; }
        .tg-support-msg { max-width: 78%; margin-bottom: 12px; }
        .tg-support-msg.out { margin-left: auto; text-align: right; }
        .tg-support-bubble { display: inline-block; border-radius: 12px; padding: 10px 12px; background: #fff; border: 1px solid #e5e7eb; color: #1f2937; }
        .tg-support-msg.out .tg-support-bubble { background: #f97316; border-color: #f97316; color: #fff; }
        .tg-support-meta { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        .tg-support-filter { border: 1px solid #d1d5db; border-radius: 7px; padding: 7px 9px; font-size: 13px; background: #fff; }
        @media (max-width: 1000px) {
            .tg-support-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .tg-support-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="tg-support-wrap" wire:poll.60s>
        @php
            $packet = $this->packet;
            $today = $packet['summary']['today'];
            $yesterday = $packet['summary']['yesterday'];
        @endphp

        <div class="tg-support-cards">
            <div class="tg-support-card">
                <div class="tg-support-label">Conversations</div>
                <div class="tg-support-value">{{ $today['conversations'] }}</div>
                <div class="tg-support-label">Yesterday: {{ $yesterday['conversations'] }}</div>
            </div>
            <div class="tg-support-card">
                <div class="tg-support-label">New contacts</div>
                <div class="tg-support-value">{{ $today['new_contacts'] }}</div>
                <div class="tg-support-label">First contact ever</div>
            </div>
            <div class="tg-support-card">
                <div class="tg-support-label">Unanswered</div>
                <div class="tg-support-value">{{ $today['unanswered'] }}</div>
                <div class="tg-support-label">Incoming: {{ $today['incoming'] }}</div>
            </div>
            <div class="tg-support-card">
                <div class="tg-support-label">Replies</div>
                <div class="tg-support-value">{{ $today['outgoing'] }}</div>
                <div class="tg-support-label">Human {{ $today['human_replies'] }} / AI {{ $today['ai_sent'] }}</div>
            </div>
        </div>

        <div class="tg-support-panel">
            <div class="tg-support-panel-head">
                <input class="tg-support-filter" type="date" wire:model.live="selectedDate">

                <select class="tg-support-filter" wire:model.live="topic">
                    <option value="">All topics</option>
                    @foreach($this->topicOptions as $topicOption)
                        <option value="{{ $topicOption }}">{{ $topicOption }}</option>
                    @endforeach
                </select>

                <select class="tg-support-filter" wire:model.live="responderType">
                    <option value="">All responders</option>
                    <option value="human">Human</option>
                    <option value="ai">AI</option>
                    <option value="unknown">Unknown</option>
                </select>

                <label class="tg-support-label">
                    <input type="checkbox" wire:model.live="onlyUnanswered">
                    Unanswered
                </label>

                <label class="tg-support-label">
                    <input type="checkbox" wire:model.live="onlyNewContacts">
                    New contacts
                </label>

                <span class="tg-support-label">Updated {{ now()->format('H:i:s') }}</span>
            </div>
        </div>

        <div class="tg-support-grid">
            <div class="tg-support-panel">
                <div class="tg-support-panel-head">
                    <strong>Chats</strong>
                    <span class="tg-support-label">{{ $this->conversations->count() }} shown</span>
                </div>
                <div class="tg-support-list">
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
                            class="tg-support-row {{ $activeConversationId === $conversation->id ? 'active' : '' }}"
                        >
                            <div style="display:flex;justify-content:space-between;gap:12px;">
                                <strong style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $title }}</strong>
                                <span class="tg-support-label">{{ $conversation->last_message_at?->format('H:i') }}</span>
                            </div>
                            <div class="tg-support-badges">
                                <span class="tg-support-badge">in {{ $conversation->incoming_count }}</span>
                                <span class="tg-support-badge">out {{ $conversation->outgoing_count }}</span>
                                @if($conversation->has_new_contact)
                                    <span class="tg-support-badge good">new</span>
                                @endif
                                @if($conversation->is_unanswered)
                                    <span class="tg-support-badge warn">unanswered</span>
                                @endif
                                @foreach($conversation->topicAssignments as $assignment)
                                    <span class="tg-support-badge">{{ $assignment->category }}</span>
                                @endforeach
                            </div>
                        </button>
                    @empty
                        <div style="padding:32px;text-align:center;color:#9ca3af;">No conversations for this filter</div>
                    @endforelse
                </div>
            </div>

            <div class="tg-support-panel">
                <div class="tg-support-panel-head">
                    <strong>Message samples</strong>
                    <span class="tg-support-label">{{ $this->activeMessages->count() }} messages</span>
                </div>
                <div class="tg-support-messages">
                    @forelse($this->activeMessages as $message)
                        @php
                            $isOutgoing = $message->direction === 'outgoing';
                            $sender = $isOutgoing
                                ? ($message->responder?->name ?? $message->responder_marker ?? $message->responder_type ?? 'Support')
                                : 'Contact';
                        @endphp
                        <div class="tg-support-msg {{ $isOutgoing ? 'out' : 'in' }}">
                            <div class="tg-support-meta">{{ $sender }} · {{ $message->sent_at->format('d.m H:i') }}</div>
                            <div class="tg-support-bubble">{!! nl2br(e($message->text)) !!}</div>
                        </div>
                    @empty
                        <div style="padding:60px 20px;text-align:center;color:#9ca3af;">Select a chat to see messages</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
