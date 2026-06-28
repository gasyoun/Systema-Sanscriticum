<?php

namespace App\Filament\Pages;

use App\Models\ChatMessage;
use App\Models\User;
use Filament\Pages\Page;

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

    // Скрываем пункт меню и блокируем роут для преподавателей.
    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public $activeUserId = null;

    public $newMessage = '';

    /** Чья карточка открыта в модалке инфо (null = закрыта). */
    public $infoUserId = null;

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
                ->with('answeredBy:id,name')
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

        if (! $this->activeUserId) {
            return;
        }

        $user = \App\Models\User::find($this->activeUserId);

        $curator = auth()->user();
        $alias = $curator?->curatorDisplayName() ?? 'Куратор';

        // Сохраняем ответ куратора в базу данных (кто ответил — answered_by).
        \App\Models\ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'answered_by' => $curator?->id,
            'text' => $this->newMessage,
            'is_read' => true,
        ]);

        // ==========================================
        // МАГИЯ: ОТПРАВЛЯЕМ В НУЖНЫЙ МЕССЕНДЖЕР
        // Студенту подписываем сообщение псевдонимом куратора (бэйдж).
        // ==========================================
        if ($user->telegram_id && \Illuminate\Support\Facades\Cache::has("chat_human_{$user->telegram_id}")) {
            // Если пауза стоит в Telegram — отвечаем ботом кабинета (фолбэк на основной)
            $token = config('services.telegram.student_bot_token')
                ?: config('services.telegram.bot_token');
            \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $user->telegram_id,
                'text' => '👨‍🏫 <b>'.e($alias).'</b>:'."\n".$this->newMessage,
                'parse_mode' => 'HTML',
            ]);
        } elseif ($user->vk_id && \Illuminate\Support\Facades\Cache::has("chat_human_vk_{$user->vk_id}")) {
            // Если пауза стоит во ВКонтакте (ДОБАВЛЕНО asForm())
            \Illuminate\Support\Facades\Http::asForm()->post('https://api.vk.com/method/messages.send', [
                'access_token' => env('VK_BOT_TOKEN'),
                'v' => '5.131',
                'user_id' => $user->vk_id,
                'message' => '👨‍🏫 '.$alias.':'."\n".$this->newMessage,
                'random_id' => rand(100000, 999999999),
            ]);
        }

        $this->newMessage = '';
        $this->loadMessages();
    }

    public function returnToBot()
    {
        if (! $this->activeUserId) {
            return;
        }
        $user = \App\Models\User::find($this->activeUserId);

        if ($user) {
            // Сбрасываем кэш и уведомляем, если диалог был в ТГ
            if ($user->telegram_id && \Illuminate\Support\Facades\Cache::has("chat_human_{$user->telegram_id}")) {
                \Illuminate\Support\Facades\Cache::forget("chat_human_{$user->telegram_id}");
                $token = config('services.telegram.student_bot_token')
                    ?: config('services.telegram.bot_token');
                \Illuminate\Support\Facades\Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $user->telegram_id,
                    'text' => '🤖 Куратор завершил диалог. Я снова с вами! Чем я могу помочь?',
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
                    'message' => '🤖 Куратор завершил диалог. Я снова с вами! Чем я могу помочь?',
                    'random_id' => rand(100000, 999999999),
                ]);
            }

            // Записываем системное сообщение, чтобы было видно в админке
            \App\Models\ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => '🔄 [Системное сообщение: ИИ-ассистент снова активирован]',
                'is_read' => true,
            ]);

            $this->loadMessages();
        }
    }

    // ==========================================
    // МОДАЛКА «ИНФО О СТУДЕНТЕ» (по клику на имя в шапке чата)
    // ==========================================
    public function openStudentInfo()
    {
        $this->infoUserId = $this->activeUserId;
    }

    public function closeStudentInfo()
    {
        $this->infoUserId = null;
    }

    /**
     * Данные для модалки: основное, оплаты, обещания/рассрочки, скидки.
     * Грузится по требованию (только когда модалка открыта).
     */
    public function getStudentInfoProperty(): ?array
    {
        if (! $this->infoUserId) {
            return null;
        }

        $user = User::find($this->infoUserId);
        if (! $user) {
            return null;
        }

        $payments = $user->payments()->real()->with('course')->latest()->limit(8)->get();

        return [
            'user' => $user,
            'payments' => $payments,
            'payments_count' => $user->payments()->real()->count(),
            'payments_total' => (float) $user->payments()->real()->sum('amount'),
            'promises' => $user->paymentPromises()
                ->with('course')
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('promised_at')
                ->get(),
            'discounts' => $user->individualDiscounts()
                ->where('is_active', true)
                ->with('course')
                ->get(),
            // Последние занятия с посещаемостью (Zoom/клик).
            'attendance' => app(\App\Services\ClassAttendanceService::class)->forStudent($user, 8),
        ];
    }
}
