<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\User;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class Helpdesk extends Page
{
    // Задаем иконку и название для главного меню
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Чат с куратором';
    protected static ?string $title = 'Диалоги с ИИ и студентами';
    protected static ?string $slug = 'dialogs'; 
    // ==================================

    // Можешь раскомментировать строку ниже, если хочешь поместить чат в отдельную группу меню
    // protected static ?string $navigationGroup = 'Управление';

    protected static string $view = 'filament.pages.helpdesk';

    public $activeUserId = null;
    public $newMessage = '';     
    public $usersWithChats = []; // Вернули []
    public $messages = [];       // Вернули []       

    public function mount()
    {
        $this->loadUsersList();

        if (request()->has('user_id')) {
            $this->selectUser(request()->get('user_id'));
        }
    }

    public function loadUsersList()
    {
        $this->usersWithChats = User::whereHas('chatMessages')
            ->withCount(['chatMessages as unread_count' => function ($query) {
                $query->where('is_read', false)->where('role', 'user');
            }])
            ->orderByDesc('unread_count') 
            ->get()
            ->all(); // <--- ВОТ ЭТО СПАСЕТ СИТУАЦИЮ
    }

    public function selectUser($userId)
    {
        $this->activeUserId = $userId;
        
        ChatMessage::where('user_id', $userId)
            ->where('role', 'user')
            ->update(['is_read' => true]);

        $this->loadMessages();
        $this->loadUsersList(); 
    }

    public function loadMessages()
    {
        if ($this->activeUserId) {
            $this->messages = ChatMessage::where('user_id', $this->activeUserId)
                ->orderBy('created_at', 'asc') 
                ->get()
                ->all(); // <--- И ЗДЕСЬ ТОЖЕ
        }
    }

    public function sendMessageToStudent()
    {
        $this->validate([
            'newMessage' => 'required|string',
        ]);

        if (!$this->activeUserId) return;

        $user = \App\Models\User::find($this->activeUserId);

        // Сохраняем ответ куратора в базу данных
        \App\Models\ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'admin',
            'text' => $this->newMessage,
            'is_read' => true,
        ]);

        // ==========================================
        // МАГИЯ: ОТПРАВЛЯЕМ В НУЖНЫЙ МЕССЕНДЖЕР
        // ==========================================
        if ($user->telegram_id && \Illuminate\Support\Facades\Cache::has("chat_human_{$user->telegram_id}")) {
            // Если пауза стоит в Telegram
            $token = env('TELEGRAM_BOT_TOKEN');
            \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $user->telegram_id,
                'text' => $this->newMessage,
                'parse_mode' => 'HTML',
            ]);
        } elseif ($user->vk_id && \Illuminate\Support\Facades\Cache::has("chat_human_vk_{$user->vk_id}")) {
            // Если пауза стоит во ВКонтакте (ДОБАВЛЕНО asForm())
            \Illuminate\Support\Facades\Http::asForm()->post('https://api.vk.com/method/messages.send', [
                'access_token' => env('VK_BOT_TOKEN'),
                'v' => '5.131',
                'user_id' => $user->vk_id,
                'message' => $this->newMessage,
                'random_id' => rand(100000, 999999999),
            ]);
        }

        $this->newMessage = '';
        $this->loadMessages();
    }

    public function returnToBot()
    {
        if (!$this->activeUserId) return;
        $user = \App\Models\User::find($this->activeUserId);
        
        if ($user) {
            // Сбрасываем кэш и уведомляем, если диалог был в ТГ
            if ($user->telegram_id && \Illuminate\Support\Facades\Cache::has("chat_human_{$user->telegram_id}")) {
                \Illuminate\Support\Facades\Cache::forget("chat_human_{$user->telegram_id}");
                $token = env('TELEGRAM_BOT_TOKEN');
                \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $user->telegram_id,
                    'text' => "🤖 Куратор завершил диалог. Я снова с вами! Чем я могу помочь?",
                    'parse_mode' => 'HTML',
                ]);
            }
            
            // Сбрасываем кэш и уведомляем, если диалог был в ВК (ДОБАВЛЕНО asForm())
            if ($user->vk_id && \Illuminate\Support\Facades\Cache::has("chat_human_vk_{$user->vk_id}")) {
                \Illuminate\Support\Facades\Cache::forget("chat_human_vk_{$user->vk_id}");
                \Illuminate\Support\Facades\Http::asForm()->post('https://api.vk.com/method/messages.send', [
                    'access_token' => env('VK_BOT_TOKEN'),
                    'v' => '5.131',
                    'user_id' => $user->vk_id,
                    'message' => "🤖 Куратор завершил диалог. Я снова с вами! Чем я могу помочь?",
                    'random_id' => rand(100000, 999999999),
                ]);
            }
            
            // Записываем системное сообщение, чтобы было видно в админке
            \App\Models\ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => "🔄 [Системное сообщение: ИИ-ассистент снова активирован]",
                'is_read' => true,
            ]);

            $this->loadMessages();
        }
    }
}