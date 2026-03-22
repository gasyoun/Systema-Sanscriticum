<x-filament-panels::page>
    <div class="flex h-[75vh] bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden" style="--tw-bg-opacity: 1; background-color: rgb(255 255 255 / var(--tw-bg-opacity)); dark:bg-gray-900 dark:border-gray-800">
        
        {{-- ЛЕВАЯ КОЛОНКА: СПИСОК ЧАТОВ --}}
        <div class="w-1/3 border-r border-gray-200 flex flex-col dark:border-gray-800 bg-gray-50 dark:bg-gray-900">
            <div class="p-4 border-b border-gray-200 dark:border-gray-800 font-bold text-gray-700 dark:text-gray-200 flex justify-between items-center bg-white dark:bg-gray-900">
                <span>Студенты</span>
                <span class="text-xs bg-gray-200 dark:bg-gray-700 px-2 py-1 rounded-full">{{ count($usersWithChats) }}</span>
            </div>
            
            <div class="flex-1 overflow-y-auto" wire:poll.10s="loadUsersList"> {{-- Автообновление списка раз в 10 сек --}}
                @forelse($usersWithChats as $chatUser)
                    <button wire:click="selectUser({{ $chatUser->id }})" 
                        class="w-full text-left p-4 border-b border-gray-100 dark:border-gray-800 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors relative {{ $activeUserId == $chatUser->id ? 'bg-primary-50 dark:bg-primary-900/20 border-l-4 border-l-primary-500' : 'bg-white dark:bg-gray-900' }}">
                        
                        <div class="flex justify-between items-center mb-1">
                            <span class="font-semibold text-gray-900 dark:text-white truncate">{{ $chatUser->name }}</span>
                            @if($chatUser->unread_count > 0)
                                <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded-full shadow-sm animate-pulse">
                                    {{ $chatUser->unread_count }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                            {{ $chatUser->email }}
                        </div>
                    </button>
                @empty
                    <div class="p-6 text-center text-gray-400 text-sm">
                        Здесь пока пусто. Студенты еще не писали боту.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ПРАВАЯ КОЛОНКА: САМ ДИАЛОГ --}}
        <div class="w-2/3 flex flex-col bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] dark:bg-gray-900">
            @if($activeUserId)
                {{-- Шапка чата --}}
                @php $activeUser = collect($usersWithChats)->firstWhere('id', $activeUserId); @endphp
                <div class="p-4 border-b border-gray-200 dark:border-gray-800 bg-white/90 backdrop-blur dark:bg-gray-900 flex items-center justify-between shadow-sm z-10 relative">
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-white text-lg">{{ $activeUser->name ?? 'Студент' }}</h3>
                        <p class="text-xs text-green-500 font-medium">Telegram подключен</p>
                    </div>
                </div>

                {{-- Окно сообщений --}}
                <div class="flex-1 p-6 overflow-y-auto flex flex-col gap-4" wire:poll.10s="loadMessages">
                    @foreach($messages as $message)
                        <div class="max-w-[80%] rounded-2xl px-5 py-3 shadow-sm {{ 
                            $message->role === 'user' ? 'bg-white dark:bg-gray-800 border border-gray-100 dark:border-gray-700 rounded-bl-none self-start text-gray-800 dark:text-gray-200' : 
                            ($message->role === 'bot' ? 'bg-purple-50 dark:bg-purple-900/30 rounded-br-none self-end border border-purple-100 dark:border-purple-800 text-purple-900 dark:text-purple-200' : 
                            'bg-primary-500 text-white rounded-br-none self-end shadow-md shadow-primary-500/30') 
                        }}">
                            <div class="text-[10px] font-bold uppercase tracking-wider mb-1 opacity-70 flex justify-between gap-4">
                                <span>
                                    {{ $message->role === 'user' ? 'Студент' : ($message->role === 'bot' ? '🤖 ИИ-Куратор' : '👨‍🏫 Вы (Куратор)') }}
                                </span>
                                <span>{{ $message->created_at->format('H:i') }}</span>
                            </div>
                            <div class="text-sm leading-relaxed whitespace-pre-wrap">{!! nl2br(e($message->text)) !!}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Поле ввода ответа --}}
                <div class="p-4 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800">
                    <form wire:submit.prevent="sendMessageToStudent" class="flex gap-3">
                        <input type="text" wire:model.defer="newMessage" 
                            placeholder="Напишите ответ студенту..." 
                            class="flex-1 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white text-sm rounded-xl focus:ring-primary-500 focus:border-primary-500 block p-3 px-5 transition-colors" required>
                        <button type="submit" 
                            class="text-white bg-primary-600 hover:bg-primary-700 focus:ring-4 focus:outline-none focus:ring-primary-300 font-medium rounded-xl text-sm w-full sm:w-auto px-6 py-3 text-center transition-all shadow-md shadow-primary-500/30 hover:shadow-lg hover:shadow-primary-500/40 hover:-translate-y-0.5 flex items-center justify-center gap-2">
                            <svg class="w-4 h-4 transform rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                            Отправить
                        </button>
                    </form>
                </div>
            @else
                <div class="flex-1 flex items-center justify-center text-gray-400 bg-gray-50 dark:bg-gray-900/50">
                    <div class="text-center">
                        <svg class="w-16 h-16 mx-auto mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                        <p class="text-lg font-medium text-gray-500 dark:text-gray-400">Выберите диалог слева</p>
                        <p class="text-sm mt-1">чтобы начать общение со студентом</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>