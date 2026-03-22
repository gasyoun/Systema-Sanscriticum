<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\Page;
use App\Models\User;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Http;

class Dialogs extends Page
{
    protected static string $resource = UserResource::class;
    protected static string $view = 'filament.resources.user-resource.pages.dialogs';
    
    // Переопределяем название в меню
    protected static ?string $navigationLabel = 'Чат с куратором';
    protected static ?string $title = 'Диалоги с ИИ и студентами';
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public $activeUserId = null; // ID студента, чей чат сейчас открыт
    public $newMessage = '';     // Текст ответа куратора
    public $usersWithChats = []; // Список студентов слева
    public $messages = [];       // Сообщения в открытом чате

    public function mount()
    {
        // Загружаем список всех студентов, у которых есть история переписки
        // Сначала те, у кого есть непрочитанные (is_read = 0)
        $this->loadUsersList();

        // Если мы перешли по ссылке из Telegram вида /admin/dialogs?user_id=5
        if (request()->has('user_id')) {
            $this->selectUser(request()->get('user_id'));
        }
    }

    public function loadUsersList()
    {
        $this->usersWithChats = User::whereHas('chatMessages') // берем только тех, кто писал боту
            ->withCount(['chatMessages as unread_count' => function ($query) {
                $query->where('is_read', false)->where('role', 'user');
            }])
            ->orderByDesc('unread_count') // Непрочитанные наверх
            ->get();
    }

    // Клик по студенту в левом меню
    public function selectUser($userId)
    {
        $this->activeUserId = $userId;
        
        // Помечаем все сообщения этого студента как прочитанные
        ChatMessage::where('user_id', $userId)
            ->where('role', 'user')
            ->update(['is_read' => true]);

        $this->loadMessages();
        $this->loadUsersList(); // Обновляем левое меню, чтобы пропал бейдж "Новое"
    }

    public function loadMessages()
    {
        if ($this->activeUserId) {
            $this->messages = ChatMessage::where('user_id', $this->activeUserId)
                ->orderBy('created_at', 'asc') // Старые сверху, новые снизу
                ->get();
        }
    }

    // Кнопка "Отправить"
    public function sendMessageToStudent()
    {
        if (empty(trim($this->newMessage)) || !$this->activeUserId) {
            return;
        }

        $user = User::find($this->activeUserId);

        // 1. Сохраняем твой ответ в базу
        ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'text' => $this->newMessage,
            'is_read' => true,
        ]);

        // 2. Отправляем в Telegram студенту через API
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = $user->telegram_id;
        
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => "👨‍🏫 <b>Куратор:</b>\n" . $this->newMessage,
            'parse_mode' => 'HTML',
        ]);

        // Очищаем поле ввода и перезагружаем чат
        $this->newMessage = '';
        $this->loadMessages();
    }
}