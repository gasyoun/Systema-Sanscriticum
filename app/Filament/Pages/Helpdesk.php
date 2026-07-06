<?php

namespace App\Filament\Pages;

use App\Models\ChatMessage;
use App\Models\ReminderSuggestion;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Reminders\ReminderSuggestionService;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\UnifiedInboxReader;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

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

    /** Активная вкладка списка диалогов: inbox | mine | resolved. */
    public $activeTab = 'inbox';

    /** Чья карточка открыта в модалке инфо (null = закрыта). */
    public $infoUserId = null;

    /** Последнее ИИ-резюме диалога (за флагом support_ai_assist). */
    public $aiSummary = null;

    public $usersWithChats = []; // Вернули []

    public function mount()
    {
        $this->loadUsersList();

        if (request()->has('user_id')) {
            $this->selectUser(request()->get('user_id'));
        }
    }

    public function loadUsersList()
    {
        // Диалоги — веб-чат ИЛИ импортированный TG-support, сведённый на пользователя.
        $query = User::query()
            ->where(function ($query): void {
                $query->whereHas('chatMessages')
                    ->orWhereHas('linkedSupportChats');
            })
            ->tap(fn ($query) => $this->applyTabFilter($query))
            ->withCount(['chatMessages as unread_count' => function ($query) {
                $query->where('is_read', false)->where('role', 'user');
            }]);

        $this->usersWithChats = $query
            ->orderByDesc('unread_count')
            ->get()
            ->all();
    }

    /**
     * Сузить список диалогов по активной вкладке, опираясь на текущий (последний)
     * операционный тред пользователя (SupportConversation):
     *   inbox    — не закрыт и без ответственного (или треда ещё нет) → «входящие»;
     *   mine     — назначен на текущего куратора;
     *   resolved — тред закрыт.
     */
    protected function applyTabFilter($query): void
    {
        $meId = auth()->id();

        match ($this->activeTab) {
            'mine' => $query->whereHas('latestSupportConversation', fn ($q) => $q->where('assigned_to', $meId)),
            'resolved' => $query->whereHas('latestSupportConversation', fn ($q) => $q->where('status', SupportConversation::STATUS_CLOSED)),
            default => $query->where(function ($outer): void {
                $outer->whereDoesntHave('supportConversations')
                    ->orWhereHas('latestSupportConversation', fn ($q) => $q
                        ->where('status', '!=', SupportConversation::STATUS_CLOSED)
                        ->whereNull('assigned_to'));
            }),
        };
    }

    /** Переключить вкладку списка диалогов и перечитать список. */
    public function switchTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['inbox', 'mine', 'resolved'], true) ? $tab : 'inbox';
        $this->loadUsersList();
    }

    /**
     * Счётчики диалогов по вкладкам (для бейджей над списком). Считаем по тем же
     * критериям, что и applyTabFilter, но без изменения активного списка.
     *
     * @return array{inbox:int, mine:int, resolved:int}
     */
    public function getTabCountsProperty(): array
    {
        $meId = auth()->id();

        $base = fn () => User::query()->where(function ($query): void {
            $query->whereHas('chatMessages')->orWhereHas('linkedSupportChats');
        });

        return [
            'inbox' => $base()->where(function ($outer): void {
                $outer->whereDoesntHave('supportConversations')
                    ->orWhereHas('latestSupportConversation', fn ($q) => $q
                        ->where('status', '!=', SupportConversation::STATUS_CLOSED)
                        ->whereNull('assigned_to'));
            })->count(),
            'mine' => $base()->whereHas('latestSupportConversation', fn ($q) => $q->where('assigned_to', $meId))->count(),
            'resolved' => $base()->whereHas('latestSupportConversation', fn ($q) => $q->where('status', SupportConversation::STATUS_CLOSED))->count(),
        ];
    }

    /**
     * «Взять диалог себе»: назначить текущий тред открытого пользователя куратору.
     * Минимальный механизм назначения, чтобы вкладка «Мои» была рабочей (полный
     * механизм назначения из H221 D4 её дополнит).
     */
    public function takeConversation(): void
    {
        if (! $this->activeUserId) {
            return;
        }

        $thread = app(SupportConversationManager::class)->openFor($this->activeUserId);
        $thread->update(['assigned_to' => auth()->id()]);

        $this->loadUsersList();

        Notification::make()->title('Диалог назначен вам')->success()->send();
    }

    public function selectUser($userId)
    {
        $this->activeUserId = $userId;

        ChatMessage::where('user_id', $userId)
            ->where('role', 'user')
            ->update(['is_read' => true]);

        $this->loadUsersList();
    }

    /**
     * Единый поток сообщений обоих каналов (веб + TG-support). Computed, а не
     * public-свойство: UnifiedMessage — обычный объект, Livewire его не сериализует.
     *
     * @return Collection<int, \App\Support\UnifiedMessage>
     */
    public function getMessagesProperty(): Collection
    {
        if (! $this->activeUserId) {
            return collect();
        }

        return app(UnifiedInboxReader::class)->forUser($this->activeUserId);
    }

    /** Операционный тред активного пользователя (для статуса в шапке). */
    public function getThreadProperty(): ?SupportConversation
    {
        if (! $this->activeUserId) {
            return null;
        }

        return app(SupportConversationManager::class)->currentFor($this->activeUserId);
    }

    /**
     * Pending-предложения детектора напоминаний (reminders:detect-requests) для
     * открытого студента — баннер над лентой сообщений. Тот же сервис
     * подтверждения/отклонения, что и в очереди ReminderSuggestionResource.
     *
     * @return Collection<int, ReminderSuggestion>
     */
    public function getPendingReminderSuggestionsProperty(): Collection
    {
        if (! $this->activeUserId) {
            return collect();
        }

        return ReminderSuggestion::query()
            ->where('user_id', $this->activeUserId)
            ->pending()
            ->orderByDesc('id')
            ->get();
    }

    /** Один клик: планирует напоминание из данных предложения как есть. */
    public function approveReminderSuggestion(int $suggestionId): void
    {
        $suggestion = ReminderSuggestion::find($suggestionId);
        if (! $suggestion || $suggestion->status !== ReminderSuggestion::STATUS_PENDING) {
            return;
        }

        app(ReminderSuggestionService::class)->approve($suggestion, [
            'message' => (string) $suggestion->detected_text,
            'scheduled_for' => $suggestion->suggested_date ?? now()->addDay(),
            'channels' => ['to_telegram'],
        ], auth()->user());

        Notification::make()->title('Напоминание запланировано')->success()->send();
    }

    public function dismissReminderSuggestion(int $suggestionId): void
    {
        $suggestion = ReminderSuggestion::find($suggestionId);
        if (! $suggestion) {
            return;
        }

        app(ReminderSuggestionService::class)->dismiss($suggestion, auth()->user());
    }

    /**
     * Куда уйдёт ответ куратора для открытого диалога — чтобы оператор видел
     * канал ДО отправки и не писал не туда (актуально при support_unified_reply).
     *
     * @return array{key:string, label:string, emoji:string}
     */
    public function getReplyChannelProperty(): array
    {
        $cabinet = ['key' => 'web', 'label' => 'Кабинет', 'emoji' => '🔹'];

        if (! $this->activeUserId) {
            return $cabinet;
        }

        $channel = app(\App\Services\Support\SupportReplyService::class)->activeChannel($this->activeUserId);

        if ($channel === \App\Services\Support\SupportReplyService::CHANNEL_TELEGRAM_SUPPORT) {
            return ['key' => 'telegram', 'label' => 'Telegram-support', 'emoji' => '🔹'];
        }

        return $cabinet;
    }

    /** ИИ-черновик ответа: кладём в поле ввода, не отправляя. */
    public function suggestReply(): void
    {
        if (! $this->activeUserId) {
            return;
        }

        $draft = app(\App\Services\Support\SupportAiService::class)->suggestReply($this->activeUserId);
        if ($draft) {
            $this->newMessage = $draft;
        }
    }

    /** ИИ-резюме диалога для куратора (только показ). */
    public function summarizeThread(): void
    {
        if (! $this->activeUserId) {
            return;
        }

        $this->aiSummary = app(\App\Services\Support\SupportAiService::class)->summarize($this->activeUserId);
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

        // Единый ответ (за флагом): если разговор живёт в импортированном TG-support,
        // пишем туда, а не в веб-чат. Веб/бот-каналы идут прежним путём ниже.
        if (config('features.support_unified_reply')) {
            $router = app(\App\Services\Support\SupportReplyService::class);
            if ($router->activeChannel($user) === \App\Services\Support\SupportReplyService::CHANNEL_TELEGRAM_SUPPORT
                && $router->replyViaSupportChannel($user, $this->newMessage, $curator)) {
                $this->newMessage = '';
                $this->loadUsersList();

                Notification::make()->title('Ответ отправлен в Telegram-support')->success()->send();

                return;
            }
        }

        // Сохраняем ответ куратора в базу данных (кто ответил — answered_by).
        $curatorMessage = \App\Models\ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'answered_by' => $curator?->id,
            'text' => $this->newMessage,
            'is_read' => true,
        ]);

        app(\App\Services\Support\SupportConversationManager::class)
            ->recordMessage($user, $curatorMessage, $curatorMessage->created_at);

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
        $this->loadUsersList();

        // Тост называет канал доставки — оператор сразу видит, куда ушёл ответ.
        $sentToMessenger = ($user->telegram_id && \Illuminate\Support\Facades\Cache::has("chat_human_{$user->telegram_id}"))
            || ($user->vk_id && \Illuminate\Support\Facades\Cache::has("chat_human_vk_{$user->vk_id}"));

        Notification::make()
            ->title($sentToMessenger ? 'Ответ отправлен в мессенджер' : 'Ответ отправлен в кабинет')
            ->success()
            ->send();
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
            $systemMessage = \App\Models\ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => '🔄 [Системное сообщение: ИИ-ассистент снова активирован]',
                'is_read' => true,
            ]);

            app(\App\Services\Support\SupportConversationManager::class)
                ->recordMessage($user, $systemMessage, $systemMessage->created_at);
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
