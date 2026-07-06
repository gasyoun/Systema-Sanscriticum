<x-filament-panels::page>
    {{-- Свои жесткие стили, которые Filament не сможет сломать --}}
    <style>
        .chat-container {
            display: flex;
            height: calc(100vh - 12rem);
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }
        .chat-sidebar {
            width: 320px;
            border-right: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            flex-direction: column;
        }
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #ffffff;
        }
        .chat-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chat-list {
            flex: 1;
            overflow-y: auto;
        }
        .chat-user-item {
            width: 100%;
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 12px;
            text-align: left;
            cursor: pointer;
            transition: background 0.2s;
        }
        .chat-user-item:hover { background: #f3f4f6; }
        .chat-user-item.active {
            background: #eff6ff; /* Светло-синий фон активного */
            border-left: 4px solid #f97316; /* Оранжевая полоска твоего Primary цвета */
        }
        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #ffedd5;
            color: #ea580c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            flex-shrink: 0;
        }

        /* === ЗОНА СООБЩЕНИЙ === */
        .messages-area {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
            background-color: #f8fafc;
            background-image: url("data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 20 20' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%239C92AC' fill-opacity='0.05' fill-rule='evenodd'%3E%3Ccircle cx='3' cy='3' r='3'/%3E%3Ccircle cx='13' cy='13' r='3'/%3E%3C/g%3E%3C/svg%3E");
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .msg-wrapper {
            display: flex;
            width: 100%;
        }
        .msg-wrapper.curator { justify-content: flex-end; }
        .msg-wrapper.user { justify-content: flex-start; }

        .msg-content {
            max-width: 65%;
            display: flex;
            flex-direction: column;
        }
        .msg-wrapper.curator .msg-content { align-items: flex-end; }
        .msg-wrapper.user .msg-content { align-items: flex-start; }

        .msg-sender {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }
        /* Бейдж канала сообщения (Кабинет / Telegram) */
        .msg-channel {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 6px;
            border-radius: 99px;
            font-size: 9px;
            font-weight: 700;
            text-transform: none;
            vertical-align: middle;
        }
        .msg-channel.web { background: #ede9fe; color: #6d28d9; }
        .msg-channel.telegram { background: #e0f2fe; color: #0369a1; }
        /* Статус операционного треда в шапке чата */
        .thread-status {
            font-size: 12px;
            font-weight: 600;
            padding: 2px 10px;
            border-radius: 99px;
        }
        .thread-status.open { background: #dcfce7; color: #15803d; }
        .thread-status.pending { background: #fef9c3; color: #a16207; }
        .thread-status.closed { background: #f3f4f6; color: #6b7280; }

        /* Баннер предложений напоминаний (детектор reminders:detect-requests) */
        .reminder-banner {
            margin: 12px 24px 0;
            padding: 12px 16px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
        }
        .reminder-banner + .reminder-banner { margin-top: 8px; }
        .reminder-banner-text {
            font-size: 13px;
            color: #92400e;
            margin-bottom: 6px;
        }
        .reminder-banner-meta {
            font-size: 11px;
            color: #b45309;
            margin-bottom: 8px;
        }
        .reminder-banner-actions {
            display: flex;
            gap: 8px;
        }
        .reminder-banner-actions a,
        .reminder-banner-actions button {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
        }
        .reminder-btn-approve { background: #16a34a; color: #fff; }
        .reminder-btn-edit { background: #ffffff; color: #92400e; border-color: #fde68a; }
        .reminder-btn-dismiss { background: #ffffff; color: #b91c1c; border-color: #fecaca; }

        /* ИДЕАЛЬНЫЕ ПУЗЫРИ */
        .msg-bubble {
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.5;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            word-wrap: break-word;
        }

        /* Пузырь студента (серый/белый) */
        .msg-bubble.user-bubble {
            background: #ffffff;
            color: #1f2937;
            border: 1px solid #e5e7eb;
            border-top-left-radius: 4px;
        }

        /* Пузырь ИИ (светло-синий) */
        .msg-bubble.bot-bubble {
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            border-top-left-radius: 4px;
        }

        /* Пузырь Куратора (оранжевый) */
        .msg-bubble.curator-bubble {
            background: #f97316; /* Твой оранжевый цвет */
            color: #ffffff;
            border-top-right-radius: 4px;
        }

        .msg-time {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 4px;
        }

        /* === ВВОД СООБЩЕНИЯ === */
        .chat-input-area {
            padding: 16px 24px;
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
        }
        .input-group {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }
        .chat-textarea {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: #f9fafb;
            /* Фон поля всегда светлый — фиксируем тёмный текст, иначе в тёмной
               теме Filament наследуется светлый цвет и набор не виден. */
            color: #111827;
            resize: none;
            min-height: 46px;
            max-height: 120px;
            outline: none;
        }
        .chat-textarea::placeholder {
            color: #9ca3af;
        }
        .chat-textarea:focus {
            border-color: #f97316;
            background: #ffffff;
        }
        .btn-send {
            height: 46px;
            padding: 0 24px;
            background: #f97316;
            color: white;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-send:hover { background: #ea580c; }

        /* === Тёмная тема: Filament вешает .dark на <html>. Правила чисто
           аддитивные — светлую тему не трогают. Инлайновые цвета в разметке
           перебиваем точечно по подстроке атрибута style (+ !important). === */
        .dark .chat-container { background: #16181d; border-color: rgba(255,255,255,.1); }
        .dark .chat-sidebar { background: #111318; border-right-color: rgba(255,255,255,.1); }
        .dark .chat-main { background: #16181d; }
        .dark .chat-header { background: #16181d; border-bottom-color: rgba(255,255,255,.1); }
        .dark .chat-user-item { border-bottom-color: rgba(255,255,255,.06); }
        .dark .chat-user-item:hover { background: rgba(255,255,255,.05); }
        .dark .chat-user-item.active { background: rgba(249,115,22,.12); }
        .dark .chat-avatar { background: rgba(249,115,22,.2); color: #fdba74; }
        .dark .messages-area { background-color: #0b0e14; }
        .dark .msg-sender { color: #9ca3af; }
        .dark .msg-time { color: #6b7280; }
        .dark .msg-bubble.user-bubble { background: #1f2937; color: #e5e7eb; border-color: rgba(255,255,255,.1); }
        .dark .msg-bubble.bot-bubble { background: rgba(59,130,246,.15); color: #bfdbfe; border-color: rgba(59,130,246,.35); }
        .dark .chat-input-area { background: #16181d; border-top-color: rgba(255,255,255,.1); }
        .dark .chat-textarea { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.15); color: #f3f4f6; }
        .dark .chat-textarea::placeholder { color: #6b7280; }
        .dark .chat-textarea:focus { background: rgba(255,255,255,.08); border-color: #f97316; }
        .dark .thread-status.open { background: rgba(34,197,94,.2); color: #86efac; }
        .dark .thread-status.pending { background: rgba(234,179,8,.2); color: #fde047; }
        .dark .thread-status.closed { background: rgba(255,255,255,.1); color: #9ca3af; }
        .dark .msg-channel.web { background: rgba(139,92,246,.25); color: #c4b5fd; }
        .dark .msg-channel.telegram { background: rgba(14,165,233,.25); color: #7dd3fc; }
        .dark .reminder-banner { background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.35); }
        .dark .reminder-banner-text { color: #fcd34d; }
        .dark .reminder-banner-meta { color: #fbbf24; }
        .dark .reminder-btn-edit { background: transparent; color: #fcd34d; border-color: rgba(245,158,11,.4); }
        .dark .reminder-btn-dismiss { background: transparent; color: #fca5a5; border-color: rgba(248,113,113,.4); }
        .dark [style*="color: #111827"] { color: #f3f4f6 !important; }
        .dark [style*="color: #1f2937"] { color: #e5e7eb !important; }
        .dark [style*="background: #f3f4f6"] { background: rgba(255,255,255,.1) !important; color: #e5e7eb !important; }
        .dark [style*="color: #6b7280"] { color: #9ca3af !important; }
    </style>

    <div class="chat-container">

        {{-- ЛЕВАЯ ПАНЕЛЬ --}}
        <div class="chat-sidebar">
            <div class="chat-header">
                <span style="font-weight: bold; color: #111827;">Диалоги</span>
                <span style="background: #f3f4f6; padding: 2px 8px; border-radius: 99px; font-size: 12px;">{{ count($usersWithChats) }}</span>
            </div>

            @if(config('features.crm_cockpit'))
                {{-- Фильтр по назначению (H221): все / назначены мне / без ответственного --}}
                <div style="display: flex; gap: 4px; padding: 8px 10px; border-bottom: 1px solid rgba(0,0,0,.06);">
                    @foreach(['all' => 'Все', 'mine' => 'Мои', 'unassigned' => 'Без ответств.'] as $key => $label)
                        <button type="button" wire:click="setAssignmentFilter('{{ $key }}')"
                            style="flex: 1; font-size: 11px; font-weight: 600; padding: 5px 4px; border-radius: 6px; cursor: pointer; border: 1px solid {{ $assignmentFilter === $key ? '#f59e0b' : 'rgba(0,0,0,.12)' }}; background: {{ $assignmentFilter === $key ? '#f59e0b' : 'transparent' }}; color: {{ $assignmentFilter === $key ? '#1c1917' : '#374151' }};">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="chat-list" wire:poll.10s="loadUsersList">
                @forelse($usersWithChats as $chatUser)
                    <button wire:click="selectUser({{ $chatUser->id }})" class="chat-user-item {{ $activeUserId == $chatUser->id ? 'active' : '' }}">
                        @include('partials.user-avatar', ['user' => $chatUser, 'size' => 40])
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                                <strong style="font-size: 14px; color: #1f2937; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $chatUser->name }}</strong>
                                @if($chatUser->unread_count > 0)
                                    <span style="background: #ef4444; color: white; font-size: 10px; padding: 2px 6px; border-radius: 99px; font-weight: bold;">{{ $chatUser->unread_count }}</span>
                                @endif
                            </div>
                            <div style="font-size: 12px; color: #6b7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $chatUser->email }}</div>
                        </div>
                    </button>
                @empty
                    <div style="text-align: center; color: #9ca3af; padding: 40px 20px;">Нет диалогов</div>
                @endforelse
            </div>
        </div>

        {{-- ПРАВАЯ ПАНЕЛЬ --}}
        <div class="chat-main">
            @if($activeUserId)
                @php $activeUser = collect($usersWithChats)->firstWhere('id', $activeUserId); @endphp

                {{-- Шапка открытого чата --}}
                <div class="chat-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        @include('partials.user-avatar', ['user' => $activeUser, 'size' => 40])
                        <div>
                        {{-- Имя кликабельно: открывает модалку с инфо о студенте --}}
                        <button type="button" wire:click="openStudentInfo"
                                title="Открыть карточку студента"
                                style="background: none; border: none; padding: 0; cursor: pointer; font-weight: bold; font-size: 16px; color: #111827; display: inline-flex; align-items: center; gap: 6px;"
                                onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                            {{ $activeUser->name ?? 'Студент' }}
                            <svg style="width: 15px; height: 15px; color: #9ca3af;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                        @php $thread = $this->thread; @endphp
                        @if($thread)
                            <div style="font-size: 12px; margin-top: 2px;">
                                <span class="thread-status {{ $thread->status }}">
                                    @switch($thread->status)
                                        @case('open') Открыт @break
                                        @case('pending') Ожидает @break
                                        @default Закрыт
                                    @endswitch
                                </span>
                            </div>
                        @endif
                        @if(config('features.crm_cockpit'))
                            {{-- Назначение ответственного за тред (H221) --}}
                            <div style="font-size: 12px; margin-top: 4px; display: flex; align-items: center; gap: 6px;">
                                <span style="color: #6b7280;">Ответственный:</span>
                                <select wire:change="assignThread($event.target.value)"
                                    style="font-size: 12px; padding: 2px 6px; border-radius: 6px; border: 1px solid rgba(0,0,0,.15); background: #fff; color: #111827;">
                                    <option value="" @selected(!($thread && $thread->assigned_to))>— не назначен —</option>
                                    @foreach($this->curators as $cid => $cname)
                                        <option value="{{ $cid }}" @selected($thread && (int) $thread->assigned_to === (int) $cid)>{{ $cname }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        </div>
                    </div>

                    {{-- Кнопка "Вернуть ИИ" (показывается только если бот на паузе) --}}
                    @if(\Illuminate\Support\Facades\Cache::has('chat_human_' . ($activeUser->telegram_id ?? '')) || \Illuminate\Support\Facades\Cache::has('chat_human_vk_' . ($activeUser->vk_id ?? '')))
                        <button wire:click="returnToBot" style="background: #ef4444; color: white; border: none; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2); transition: 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            Завершить чат (Вернуть ИИ)
                        </button>
                    @endif
                </div>

                {{-- Баннер: детектор нашёл просьбу «напомните мне» в переписке. Ничего
                     не отправлено само — только предложение, куратор подтверждает/правит/
                     отклоняет. Та же логика, что в очереди «Предложения напоминаний». --}}
                @foreach($this->pendingReminderSuggestions as $suggestion)
                    <div class="reminder-banner" wire:key="reminder-suggestion-{{ $suggestion->id }}">
                        <div class="reminder-banner-text">
                            🔔 Похоже, студент просит напомнить: «{{ \Illuminate\Support\Str::limit($suggestion->detected_text, 160) }}»
                        </div>
                        <div class="reminder-banner-meta">
                            @if($suggestion->suggested_date)
                                Предполагаемая дата: {{ $suggestion->suggested_date->format('d.m.Y H:i') }} ·
                            @endif
                            Уверенность: {{ number_format($suggestion->confidence * 100, 0) }}%
                        </div>
                        <div class="reminder-banner-actions">
                            <button type="button" class="reminder-btn-approve" wire:click="approveReminderSuggestion({{ $suggestion->id }})">Подтвердить</button>
                            <a class="reminder-btn-edit" href="{{ \App\Filament\Resources\ReminderSuggestionResource::getUrl('edit', ['record' => $suggestion]) }}">Изменить</a>
                            <button type="button" class="reminder-btn-dismiss" wire:click="dismissReminderSuggestion({{ $suggestion->id }})">Отклонить</button>
                        </div>
                    </div>
                @endforeach

                {{-- Сообщения: единый поток веб-чата и импортированного TG-support --}}
                <div class="messages-area" id="chat-messages" wire:poll.5s>
                    @forelse($this->messages as $message)
                        <div class="msg-wrapper {{ $message->wrapperClass() }}">
                            <div class="msg-content">
                                <div class="msg-sender">
                                    {{ $message->senderLabel() }}
                                    <span class="msg-channel {{ $message->channel }}">{{ $message->channelLabel() }}</span>
                                </div>
                                <div class="msg-bubble {{ $message->bubbleClass() }}">
                                    {!! $message->htmlText() !!}
                                </div>
                                <div class="msg-time" title="{{ $message->sentAt->format('d.m.Y H:i') }}">{{ $message->sentAt->format('H:i') }}</div>
                            </div>
                        </div>
                    @empty
                        <div style="text-align: center; margin: auto; color: #9ca3af;">Начало диалога</div>
                    @endforelse
                </div>

                {{-- ИИ-ассист (за флагом): черновик ответа + резюме диалога --}}
                @if(config('features.support_ai_assist'))
                    <div style="padding: 8px 24px 0; display: flex; gap: 8px; align-items: center;">
                        <button type="button" wire:click="suggestReply" wire:loading.attr="disabled"
                            style="background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;">
                            🤖 Подсказать ответ
                        </button>
                        <button type="button" wire:click="summarizeThread" wire:loading.attr="disabled"
                            style="background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer;">
                            📝 Резюме
                        </button>
                        <span wire:loading style="font-size: 11px; color: #9ca3af;">ИИ думает…</span>
                    </div>
                    @if($aiSummary)
                        <div style="margin: 8px 24px 0; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534; white-space: pre-line;">{{ $aiSummary }}</div>
                    @endif
                @endif

                {{-- Ввод --}}
                <div class="chat-input-area">
                    <form wire:submit.prevent="sendMessageToStudent" class="input-group">
                        <textarea wire:model.defer="newMessage"
                            class="chat-textarea"
                            placeholder="Напишите ответ..."
                            required
                            oninput="this.style.height = ''; this.style.height = Math.min(this.scrollHeight, 120) + 'px'"
                            onkeydown="if(event.keyCode===13 && !event.shiftKey) { event.preventDefault(); @this.sendMessageToStudent(); }"
                        ></textarea>
                        <button type="submit" class="btn-send">Отправить</button>
                    </form>
                    <div style="text-align: center; font-size: 11px; color: #9ca3af; margin-top: 8px;">
                        Enter — отправить, Shift+Enter — новая строка
                    </div>
                </div>
            @else
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: #9ca3af;">
                    Выберите диалог слева
                </div>
            @endif
        </div>
    </div>

    {{-- Модалка с инфо о студенте (оплаты, обещания, рассрочки, скидки) --}}
    @if($infoUserId && $this->studentInfo)
        @include('filament.pages.partials.student-info-modal', ['info' => $this->studentInfo])
    @endif

    <script>
        function scrollToBottom() {
            let container = document.getElementById('chat-messages');
            if(container) {
                container.scrollTop = container.scrollHeight;
            }
        }

        document.addEventListener('livewire:load', function () {
            scrollToBottom();
            Livewire.hook('message.processed', (message, component) => {
                scrollToBottom();
            });
        });
    </script>
</x-filament-panels::page>
