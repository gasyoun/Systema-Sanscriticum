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
            resize: none;
            min-height: 46px;
            max-height: 120px;
            outline: none;
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
    </style>

    <div class="chat-container">
        
        {{-- ЛЕВАЯ ПАНЕЛЬ --}}
        <div class="chat-sidebar">
            <div class="chat-header">
                <span style="font-weight: bold; color: #111827;">Диалоги</span>
                <span style="background: #f3f4f6; padding: 2px 8px; border-radius: 99px; font-size: 12px;">{{ count($usersWithChats) }}</span>
            </div>
            
            <div class="chat-list" wire:poll.10s="loadUsersList">
                @forelse($usersWithChats as $chatUser)
                    <button wire:click="selectUser({{ $chatUser->id }})" class="chat-user-item {{ $activeUserId == $chatUser->id ? 'active' : '' }}">
                        <div class="chat-avatar">{{ mb_substr($chatUser->name ?? 'С', 0, 1) }}</div>
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
                    <div>
                        <div style="font-weight: bold; font-size: 16px; color: #111827;">{{ $activeUser->name ?? 'Студент' }}</div>
                        <div style="font-size: 12px; color: #16a34a; font-weight: 500;">● Telegram подключен</div>
                    </div>
                    
                    {{-- Кнопка "Вернуть ИИ" (показывается только если бот на паузе) --}}
                    @if(\Illuminate\Support\Facades\Cache::has('chat_human_' . ($activeUser->telegram_id ?? '')) || \Illuminate\Support\Facades\Cache::has('chat_human_vk_' . ($activeUser->vk_id ?? '')))
                        <button wire:click="returnToBot" style="background: #ef4444; color: white; border: none; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2); transition: 0.2s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            Завершить чат (Вернуть ИИ)
                        </button>
                    @endif
                </div>

                {{-- Сообщения --}}
                <div class="messages-area" id="chat-messages" wire:poll.5s="loadMessages">
                    @forelse($messages as $message)
                        @php
                            $isUser = $message->role === 'user';
                            $isBot = $message->role === 'bot';
                            $isCurator = $message->role === 'curator';
                            
                            $wrapperClass = $isCurator ? 'curator' : 'user';
                            $bubbleClass = $isUser ? 'user-bubble' : ($isBot ? 'bot-bubble' : 'curator-bubble');
                            $senderName = $isUser ? 'Студент' : ($isBot ? 'ИИ-Куратор' : 'Вы (Куратор)');
                        @endphp
                        
                        <div class="msg-wrapper {{ $wrapperClass }}">
                            <div class="msg-content">
                                <div class="msg-sender">{{ $senderName }}</div>
                                <div class="msg-bubble {{ $bubbleClass }}">
                                    {!! nl2br(e($message->text)) !!}
                                </div>
                                <div class="msg-time">{{ $message->created_at->format('H:i') }}</div>
                            </div>
                        </div>
                    @empty
                        <div style="text-align: center; margin: auto; color: #9ca3af;">Начало диалога</div>
                    @endforelse
                </div>

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