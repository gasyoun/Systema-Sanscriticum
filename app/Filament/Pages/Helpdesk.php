<?php

namespace App\Filament\Pages;

use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\FollowUpTask;
use App\Models\MessageTemplate;
use App\Models\ReminderSuggestion;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Reminders\ReminderSuggestionService;
use App\Services\Support\HelpdeskStudentContextService;
use App\Services\Support\SupportAiService;
use App\Services\Support\SupportAnswerSuggestionService;
use App\Services\Support\SupportConversationManager;
use App\Services\Support\SupportConversationTopicService;
use App\Services\Support\SupportFollowUpService;
use App\Services\Support\SupportReplyService;
use App\Services\Support\UnifiedInboxReader;
use App\Support\Roles;
use App\Support\UnifiedMessage;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

    /**
     * Активный гостевой тред (SupportConversation с user_id = NULL, H536 Phase 5).
     * Веб-чат анонимного посетителя не привязан к `users`, поэтому его нельзя
     * открыть через $activeUserId — гостевые треды идут отдельной веткой.
     */
    public $activeGuestId = null;

    /**
     * Открытые гостевые треды для левого списка (H536 Phase 5): плоские массивы
     * ['id','name','unread','preview'], а не Eloquent — чтобы Livewire не тащил
     * модель в свойство. Наполняется в {@see loadUsersList()} тем же поллом.
     *
     * @var array<int, array{id:int, name:string, unread:int, preview:string}>
     */
    public $guestThreads = [];

    public $newMessage = '';

    /**
     * H3395: шаблон, вставленный в компоузер через «быстрые ответы» и ещё не
     * отправленный. Обнуляется первой успешной отправкой — ретрай/повторный
     * сенд без новой вставки шаблона usage-событие не создаёт (идемпотентность).
     */
    public $pendingTemplateId = null;

    /** Активная вкладка списка диалогов: inbox | mine | tech | resolved. */
    public $activeTab = 'inbox';

    /** Чья карточка открыта в модалке инфо (null = закрыта). */
    public $infoUserId = null;

    /** Последнее ИИ-резюме диалога (за флагом support_ai_assist). */
    public $aiSummary = null;

    /** Close-topic form (H2381): category from SupportTopicRule / other / uncategorized. */
    public $closeTopicCategory = '';

    /** Follow-up form fields (H2381, flag support_follow_up_tasks). */
    public $followUpDueAt = '';

    public $followUpNote = '';

    public $followUpType = 'message';

    public $followUpAssigneeId = '';

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
        // Диалоги — веб-чат ИЛИ импортированный TG-support ИЛИ ops-тред (техника из групп).
        $query = User::query()
            ->where(function ($query): void {
                $query->whereHas('chatMessages')
                    ->orWhereHas('linkedSupportChats')
                    ->orWhereHas('supportConversations');
            })
            ->tap(fn ($query) => $this->applyTabFilter($query))
            ->withCount(['chatMessages as unread_count' => function ($query) {
                $query->where('is_read', false)->where('role', 'user');
            }]);

        $this->usersWithChats = $query
            ->orderByDesc('unread_count')
            ->get()
            ->all();

        $this->loadGuestThreads();
    }

    /**
     * Открытые гостевые треды веб-чата (user_id = NULL) для левого списка
     * (H536 Phase 5). Отдельно от usersWithChats: у гостя нет `users`-записи,
     * поэтому он не попадает в user-keyed выборку выше. Не фильтруется вкладками
     * (inbox/mine/resolved — это про user-треды) — гости всегда «входящие».
     */
    /**
     * Треды без users-записи: гости веб-виджета (guest_token) И техвопросы из
     * Telegram-чатов от непривязанных авторов (source_telegram_chat_id).
     * Вторые появились, когда роутер перестал терять вопрос из-за отсутствия
     * привязки — ни приём, ни ответ её не требуют, юзербот работает по chat_id.
     */
    protected function loadGuestThreads(): void
    {
        $this->guestThreads = SupportConversation::query()
            ->whereNull('user_id')
            ->where(fn ($query) => $query
                ->whereNotNull('guest_token')
                ->orWhereNotNull('source_telegram_chat_id'))
            ->where('status', '!=', SupportConversation::STATUS_CLOSED)
            ->withCount(['chatMessages as unread_count' => function ($query): void {
                $query->where('is_read', false)->where('role', 'user');
            }])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function (SupportConversation $thread): array {
                $isTelegram = $thread->source_telegram_chat_id !== null;

                // У телеграм-треда переписка лежит в telegram_support_messages,
                // а не в chatMessages: у него нет веб-виджета, откуда бы шли те.
                $last = $isTelegram
                    ? $thread->telegramMessages()->orderByDesc('id')->first()
                    : $thread->chatMessages()->orderByDesc('id')->first();

                return [
                    'id' => $thread->id,
                    'name' => $thread->displayName(),
                    'unread' => (int) $thread->unread_count,
                    'preview' => $last ? mb_strimwidth(strip_tags((string) $last->text), 0, 42, '…') : '',
                    'location' => $thread->locationLabel(), // город/страна посетителя (H1196)
                    'source' => $isTelegram ? 'telegram' : 'web',
                    'is_technical' => $thread->isTechnical(),
                ];
            })
            ->all();
    }

    /**
     * Сузить список диалогов по активной вкладке, опираясь на текущий (последний)
     * операционный тред пользователя (SupportConversation):
     *   inbox    — не закрыт и без ответственного (или треда ещё нет) → «входящие»;
     *   mine     — назначен на текущего куратора;
     *   tech     — queue=technical, открыт;
     *   resolved — тред закрыт.
     */
    protected function applyTabFilter($query): void
    {
        $meId = auth()->id();

        match ($this->activeTab) {
            'mine' => $query->whereHas('latestSupportConversation', fn ($q) => $q->where('assigned_to', $meId)),
            'tech' => $query->whereHas('latestSupportConversation', fn ($q) => $q
                ->where('queue', SupportConversation::QUEUE_TECHNICAL)
                ->where('status', '!=', SupportConversation::STATUS_CLOSED)),
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
        $this->activeTab = in_array($tab, ['inbox', 'mine', 'tech', 'resolved'], true) ? $tab : 'inbox';
        $this->loadUsersList();
    }

    /**
     * Счётчики диалогов по вкладкам (для бейджей над списком). Считаем по тем же
     * критериям, что и applyTabFilter, но без изменения активного списка.
     *
     * @return array{inbox:int, mine:int, tech:int, resolved:int}
     */
    public function getTabCountsProperty(): array
    {
        $meId = auth()->id();

        $base = fn () => User::query()->where(function ($query): void {
            $query->whereHas('chatMessages')
                ->orWhereHas('linkedSupportChats')
                ->orWhereHas('supportConversations');
        });

        return [
            'inbox' => $base()->where(function ($outer): void {
                $outer->whereDoesntHave('supportConversations')
                    ->orWhereHas('latestSupportConversation', fn ($q) => $q
                        ->where('status', '!=', SupportConversation::STATUS_CLOSED)
                        ->whereNull('assigned_to'));
            })->count(),
            'mine' => $base()->whereHas('latestSupportConversation', fn ($q) => $q->where('assigned_to', $meId))->count(),
            'tech' => $base()->whereHas('latestSupportConversation', fn ($q) => $q
                ->where('queue', SupportConversation::QUEUE_TECHNICAL)
                ->where('status', '!=', SupportConversation::STATUS_CLOSED))->count(),
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

    /**
     * Полный механизм назначения (H221 D4, за флагом crm_cockpit): селектор в
     * шапке — назначить/переназначить тред любому куратору или снять
     * ответственного. Дополняет «взять себе» ({@see takeConversation}).
     *
     * @return array<int,string> id => name
     */
    public function getCuratorsProperty(): array
    {
        return User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::MANAGER])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Пустая строка снимает ответственного. Тред создаётся при необходимости. */
    public function assignThread($assigneeId): void
    {
        if (! $this->activeUserId) {
            return;
        }

        $thread = app(SupportConversationManager::class)->openFor((int) $this->activeUserId);
        $thread->forceFill([
            'assigned_to' => $assigneeId !== '' && $assigneeId !== null ? (int) $assigneeId : null,
        ])->save();

        $this->loadUsersList();

        Notification::make()->title('Ответственный обновлён')->success()->send();
    }

    public function selectUser($userId)
    {
        $this->activeUserId = $userId;
        $this->activeGuestId = null; // взаимоисключимо с гостевым тредом

        ChatMessage::where('user_id', $userId)
            ->where('role', 'user')
            ->update(['is_read' => true]);

        $this->loadUsersList();
    }

    /**
     * Открыть гостевой тред (H536 Phase 5): помечает входящие гостя прочитанными
     * и переводит правую панель в гостевой режим. Взаимоисключимо с {@see selectUser}.
     */
    public function selectGuest($conversationId): void
    {
        $thread = SupportConversation::query()
            ->whereNull('user_id')
            ->whereKey((int) $conversationId)
            ->first();

        if (! $thread) {
            return;
        }

        $this->activeGuestId = $thread->id;
        $this->activeUserId = null;

        ChatMessage::where('support_conversation_id', $thread->id)
            ->where('role', 'user')
            ->update(['is_read' => true]);

        $this->loadUsersList();
    }

    /** Активный гостевой тред (шапка/статус правой панели, H536 Phase 5). */
    public function getGuestThreadProperty(): ?SupportConversation
    {
        if (! $this->activeGuestId) {
            return null;
        }

        return SupportConversation::query()
            ->whereNull('user_id')
            ->whereKey((int) $this->activeGuestId)
            ->first();
    }

    /**
     * Сообщения активного гостевого треда в хронологии (H536 Phase 5).
     *
     * Раньше для telegram-тредов здесь собирались НЕСОХРАНЁННЫЕ ChatMessage:
     * шаблон ленты звал на элементах методы модели, а голый stdClass ронял
     * страницу пятисоткой («Call to undefined method»). Подпорка стоила дорого —
     * вместе с моделью терялся raw_payload, а с ним и статус доставки, поэтому
     * зависший ответ выглядел в ленте в точности как отправленный (инцидент
     * 15-08-2026, сообщение 15255). Теперь обе ленты говорят на одном языке
     * UnifiedMessage, и общий партиал показывает статус в обеих.
     *
     * @return Collection<int, UnifiedMessage>
     */
    public function getGuestMessagesProperty(): Collection
    {
        $thread = $this->guestThread;

        if (! $thread) {
            return collect();
        }

        return app(UnifiedInboxReader::class)->forConversation($thread);
    }

    /**
     * Ответ куратора в гостевой тред (H536 Phase 5): у гостя нет `users`-записи,
     * telegram_id/vk_id и обычного {@see sendMessageToStudent} пути — пишем
     * curator-сообщение с user_id = NULL прямо в тред и бродкастим его в приватный
     * канал support.conversation.{id}, откуда виджет посетителя (Phase 4) заберёт
     * ответ живым. Текст экранируется на выводе через htmlForWeb().
     */
    public function replyToGuest(): void
    {
        $this->validate(['newMessage' => 'required|string']);

        $thread = $this->guestThread;
        if (! $thread) {
            return;
        }

        // Техвопрос из Telegram-чата: у автора нет users-записи, но есть чат —
        // отвечаем туда юзерботом, реплаем на исходное сообщение.
        if ($thread->source_telegram_chat_id !== null) {
            $this->replyToTelegramThread($thread);

            return;
        }

        $curator = auth()->user();

        $curatorMessage = ChatMessage::create([
            'support_conversation_id' => $thread->id,
            'user_id' => null,
            'role' => 'curator',
            'answered_by' => $curator?->id,
            'text' => $this->newMessage,
            'is_read' => true,
        ]);

        $thread->forceFill(['last_message_at' => $curatorMessage->created_at])->save();

        event(new ChatMessageSent($curatorMessage));

        $this->newMessage = '';
        $this->loadUsersList();

        Notification::make()->title('Ответ отправлен гостю')->success()->send();
    }

    /** Ответ юзерботом в исходный Telegram-чат (тред без users-записи). */
    private function replyToTelegramThread(SupportConversation $thread): void
    {
        $sent = app(SupportReplyService::class)
            ->replyToUnlinkedThread($thread, $this->newMessage, auth()->user());

        if (! $sent) {
            Notification::make()
                ->title('Не удалось отправить: чат не найден в синке')
                ->danger()
                ->send();

            return;
        }

        $this->newMessage = '';
        $this->loadUsersList();

        // Тост говорит ровно то, что произошло: строка записана и доставка
        // поставлена в очередь. До 15-08-2026 здесь было «Ответ отправлен в
        // Telegram», и куратор считал сорванную доставку успехом.
        $this->notifyQueuedForTelegram();
    }

    /**
     * Ручной досыл зависшего ответа прямо из ленты.
     *
     * Гейт в три слоя, потому что id приходит из браузера: доступ к странице,
     * направление сообщения и принадлежность открытому диалогу. Livewire-запрос
     * авторизацию маршрута Filament сам не перепроверяет, поэтому abort_unless
     * стоит явно.
     */
    public function resendDelivery(int $messageId): void
    {
        abort_unless(static::canAccess(), 403);

        $message = TelegramSupportMessage::query()
            ->with(['chat', 'contact'])
            ->where('direction', 'outgoing')
            ->find($messageId);

        if (! $message || ! $this->ownsMessage($message)) {
            return;
        }

        $result = app(SupportReplyService::class)->resendPendingDelivery($message, auth()->user());

        match ($result) {
            SupportReplyService::RESEND_QUEUED => Notification::make()
                ->title('Дошлём')
                ->body('Ответ уйдёт ближайшим заходом синка — это до минуты. Статус обновится прямо в ленте.')
                ->success()->send(),
            SupportReplyService::RESEND_ALREADY_DELIVERED => Notification::make()
                ->title('Уже доставлено')
                ->body('Это сообщение ушло в Telegram — повторять не нужно.')
                ->warning()->send(),
            SupportReplyService::RESEND_DISABLED => Notification::make()
                ->title('Досылать некуда')
                ->body('Телеграм-юзербот сейчас выключен, синк не ходит. Сообщение ждёт — дошлите его, когда юзербот включат.')
                ->danger()->send(),
            SupportReplyService::RESEND_THROTTLED => Notification::make()
                ->title('Уже досылаем')
                ->body('Ответ перевзведён только что и ждёт ближайшего захода синка. Подождите минуту.')
                ->warning()->send(),
            default => Notification::make()
                ->title('Нечего досылать')
                ->body('Это сообщение пришло из синка, а не из хелпдеска — его доставку мы не отслеживаем.')
                ->warning()->send(),
        };

        $this->loadUsersList();
    }

    /**
     * Принадлежит ли сообщение открытому сейчас диалогу.
     *
     * Связь берём той же, какой её видит UnifiedInboxReader: гостевой тред — по
     * support_conversation_id, студент — по linked_user_id чата или контакта.
     */
    private function ownsMessage(TelegramSupportMessage $message): bool
    {
        if ($this->activeGuestId) {
            return (int) $message->support_conversation_id === (int) $this->activeGuestId;
        }

        if (! $this->activeUserId) {
            return false;
        }

        return (int) ($message->chat?->linked_user_id ?? 0) === (int) $this->activeUserId
            || (int) ($message->contact?->linked_user_id ?? 0) === (int) $this->activeUserId;
    }

    /**
     * Уведомление об ответе, ушедшем через юзербот: обещаем очередь, а не
     * доставку, и отдельно предупреждаем, когда юзербот выключен и отправлять
     * сообщение сейчас некому.
     */
    private function notifyQueuedForTelegram(): void
    {
        if (! config('services.telegram_support.enabled')) {
            Notification::make()
                ->title('Ответ сохранён, но не отправлен')
                ->body('Телеграм-юзербот выключен — сообщение ждёт отправки. Дошлите его кнопкой в ленте, когда синк включат.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Ответ записан, отправляем')
            ->body('Уйдёт в Telegram ближайшим заходом синка — это до минуты. Статус доставки — под сообщением в ленте.')
            ->success()
            ->send();
    }

    /**
     * Единый поток сообщений обоих каналов (веб + TG-support). Computed, а не
     * public-свойство: UnifiedMessage — обычный объект, Livewire его не сериализует.
     *
     * @return Collection<int, UnifiedMessage>
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
     * Pending факт-черновики ответов FAQ-суггестера (support:suggest-answers, H247)
     * для открытого студента ИЛИ гостевого треда (H1198, категория D — публичные
     * тарифы) — баннер над лентой. Куратор жмёт Принять/Изменить/Отклонить; бот
     * сам НИЧЕГО не отправляет.
     *
     * @return Collection<int, SupportAnswerSuggestion>
     */
    public function getPendingAnswerSuggestionsProperty(): Collection
    {
        if ($this->activeGuestId) {
            // Гость: нет user_id, привязка к треду — через ChatMessage.support_
            // conversation_id (без новой колонки на support_answer_suggestions).
            $chatMessageIds = ChatMessage::where('support_conversation_id', $this->activeGuestId)->pluck('id');

            return SupportAnswerSuggestion::query()
                ->whereNull('user_id')
                ->where('source_type', SupportAnswerSuggestion::SOURCE_CHAT_MESSAGE)
                ->whereIn('source_id', $chatMessageIds)
                ->pending()
                ->orderByDesc('id')
                ->get();
        }

        if (! $this->activeUserId) {
            return collect();
        }

        return SupportAnswerSuggestion::query()
            ->where('user_id', $this->activeUserId)
            ->pending()
            ->orderByDesc('id')
            ->get();
    }

    /** Принять черновик как есть: текст уходит в поле ответа, статус = accepted. */
    public function acceptAnswerSuggestion(int $suggestionId): void
    {
        $suggestion = $this->findPendingAnswerSuggestion($suggestionId);
        if (! $suggestion) {
            return;
        }

        $this->newMessage = (string) $suggestion->draft_text;
        app(SupportAnswerSuggestionService::class)->accept($suggestion, auth()->user());

        Notification::make()->title('Черновик подставлен в ответ')->success()->send();
    }

    /** Изменить: тот же черновик в поле ответа для правки, статус = edited. */
    public function editAnswerSuggestion(int $suggestionId): void
    {
        $suggestion = $this->findPendingAnswerSuggestion($suggestionId);
        if (! $suggestion) {
            return;
        }

        $this->newMessage = (string) $suggestion->draft_text;
        app(SupportAnswerSuggestionService::class)->edit($suggestion, auth()->user());

        Notification::make()->title('Черновик в поле ответа — отредактируйте и отправьте')->success()->send();
    }

    public function discardAnswerSuggestion(int $suggestionId): void
    {
        $suggestion = $this->findPendingAnswerSuggestion($suggestionId);
        if (! $suggestion) {
            return;
        }

        app(SupportAnswerSuggestionService::class)->discard($suggestion, auth()->user());
    }

    private function findPendingAnswerSuggestion(int $suggestionId): ?SupportAnswerSuggestion
    {
        $suggestion = SupportAnswerSuggestion::find($suggestionId);

        if (! $suggestion || $suggestion->status !== SupportAnswerSuggestion::STATUS_PENDING) {
            return null;
        }

        return $suggestion;
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

        $channel = app(SupportReplyService::class)->activeChannel($this->activeUserId);

        if ($channel === SupportReplyService::CHANNEL_TELEGRAM_SUPPORT) {
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

        $draft = app(SupportAiService::class)->suggestReply($this->activeUserId);
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

        $this->aiSummary = app(SupportAiService::class)->summarize($this->activeUserId);
    }

    /**
     * Быстрые ответы (H223 D5, за флагом crm_cockpit): активные шаблоны категории
     * «Поддержка» из общей библиотеки H221. Пусто → dropdown не рендерится.
     *
     * @return array<int,string> id => title
     */
    public function getSupportTemplatesProperty(): array
    {
        if (! config('features.crm_cockpit')) {
            return [];
        }

        return MessageTemplate::query()
            ->forCategory(MessageTemplate::CATEGORY_SUPPORT)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /**
     * Вставить текст шаблона в поле ответа с подстановкой плейсхолдеров под
     * активного студента. НЕ отправляет — куратор правит перед отправкой.
     */
    public function insertCannedReply($templateId): void
    {
        if (! $this->activeUserId || ! config('features.crm_cockpit')) {
            return;
        }

        $template = MessageTemplate::find($templateId);
        $user = User::find($this->activeUserId);
        if (! $template || ! $user) {
            return;
        }

        $this->newMessage = $template->render($user);
        $this->pendingTemplateId = $template->id;
    }

    public function sendMessageToStudent()
    {
        $this->validate([
            'newMessage' => 'required|string',
        ]);

        if (! $this->activeUserId) {
            return;
        }

        $user = User::find($this->activeUserId);

        $curator = auth()->user();
        $alias = $curator?->curatorDisplayName() ?? 'Куратор';

        // Единый ответ (за флагом) ИЛИ очередь «Техника»: peer = source_telegram_chat_id.
        $thread = app(SupportConversationManager::class)->currentFor($user);
        $forceTechTg = $thread?->isTechnical() === true;
        if (config('features.support_unified_reply') || $forceTechTg) {
            $router = app(SupportReplyService::class);
            if ($router->activeChannel($user) === SupportReplyService::CHANNEL_TELEGRAM_SUPPORT
                && $router->replyViaSupportChannel($user, $this->newMessage, $curator)) {
                $this->recordManualTemplateUse($user, $curator);
                $this->newMessage = '';
                $this->loadUsersList();

                $this->notifyQueuedForTelegram();

                return;
            }
        }

        // Сохраняем ответ куратора в базу данных (кто ответил — answered_by).
        $curatorMessage = ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'curator',
            'answered_by' => $curator?->id,
            'text' => $this->newMessage,
            'is_read' => true,
        ]);

        $this->recordManualTemplateUse($user, $curator);

        app(SupportConversationManager::class)
            ->recordMessage($user, $curatorMessage, $curatorMessage->created_at);

        // Живой push ответа куратора в виджет посетителя (H536 Phase 5): виджет
        // (Phase 4) слушает support.conversation.{id} и рендерит .chat.message.
        // При broadcasting.default=null (до деплоя Reverb) — тихий no-op.
        event(new ChatMessageSent($curatorMessage));

        // ==========================================
        // МАГИЯ: ОТПРАВЛЯЕМ В НУЖНЫЙ МЕССЕНДЖЕР
        // Студенту подписываем сообщение псевдонимом куратора (бэйдж).
        // ==========================================
        if ($user->telegram_id && Cache::has("chat_human_{$user->telegram_id}")) {
            // Если пауза стоит в Telegram — отвечаем ботом кабинета (фолбэк на основной)
            $token = config('services.telegram.student_bot_token')
                ?: config('services.telegram.bot_token');
            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $user->telegram_id,
                'text' => '👨‍🏫 <b>'.e($alias).'</b>:'."\n".$this->newMessage,
                'parse_mode' => 'HTML',
            ]);
        } elseif ($user->vk_id && Cache::has("chat_human_vk_{$user->vk_id}")) {
            // Если пауза стоит во ВКонтакте (ДОБАВЛЕНО asForm())
            Http::asForm()->post('https://api.vk.com/method/messages.send', [
                // env() в рантайме = null под config-кешем → VK error 15.
                'access_token' => config('services.vk.bot_token'),
                'v' => '5.131',
                'user_id' => $user->vk_id,
                'message' => '👨‍🏫 '.$alias.':'."\n".$this->newMessage,
                'random_id' => rand(100000, 999999999),
            ]);
        }

        $this->newMessage = '';
        $this->loadUsersList();

        // Тост называет канал доставки — оператор сразу видит, куда ушёл ответ.
        $sentToMessenger = ($user->telegram_id && Cache::has("chat_human_{$user->telegram_id}"))
            || ($user->vk_id && Cache::has("chat_human_vk_{$user->vk_id}"));

        Notification::make()
            ->title($sentToMessenger ? 'Ответ отправлен в мессенджер' : 'Ответ отправлен в кабинет')
            ->success()
            ->send();
    }

    /**
     * H3395: ручная отправка куратором, начатая с шаблона библиотеки, пишет
     * usage-событие `template_used` (denominator для H3392-ревью и будущей
     * обрезки библиотеки H2339). Один сенд = одно событие: маркер обнуляется
     * сразу, поэтому повторная отправка того же текста (ретрай) событий не
     * плодит. Автоответы сюда не попадают — у них свой `dm_auto_sent kind=template`.
     */
    private function recordManualTemplateUse(User $student, ?User $curator): void
    {
        if (! $this->pendingTemplateId) {
            return;
        }

        $template = MessageTemplate::find($this->pendingTemplateId);
        $this->pendingTemplateId = null;

        if (! $template) {
            return;
        }

        SupportAiReplyEvent::create([
            'event_type' => SupportAiReplyEvent::EVENT_TEMPLATE_USED,
            'meta' => [
                'template_id' => $template->id,
                'title' => $template->title,
                'category' => $template->category,
                'student_user_id' => $student->id,
                'curator_id' => $curator?->id,
                'channel' => 'helpdesk',
            ],
        ]);
    }

    public function returnToBot()
    {
        if (! $this->activeUserId) {
            return;
        }
        $user = User::find($this->activeUserId);

        if ($user) {
            // Сбрасываем кэш и уведомляем, если диалог был в ТГ
            if ($user->telegram_id && Cache::has("chat_human_{$user->telegram_id}")) {
                Cache::forget("chat_human_{$user->telegram_id}");
                $token = config('services.telegram.student_bot_token')
                    ?: config('services.telegram.bot_token');
                Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $user->telegram_id,
                    'text' => '🤖 Куратор завершил диалог. Я снова с вами! Чем я могу помочь?',
                    'parse_mode' => 'HTML',
                ]);
            }

            // Сбрасываем кэш и уведомляем, если диалог был в ВК (ДОБАВЛЕНО asForm())
            if ($user->vk_id && Cache::has("chat_human_vk_{$user->vk_id}")) {
                Cache::forget("chat_human_vk_{$user->vk_id}");
                Http::asForm()->post('https://api.vk.com/method/messages.send', [
                    // env() в рантайме = null под config-кешем → VK error 15.
                    'access_token' => config('services.vk.bot_token'),
                    'v' => '5.131',
                    'user_id' => $user->vk_id,
                    'message' => '🤖 Куратор завершил диалог. Я снова с вами! Чем я могу помочь?',
                    'random_id' => rand(100000, 999999999),
                ]);
            }

            // Записываем системное сообщение, чтобы было видно в админке
            $systemMessage = ChatMessage::create([
                'user_id' => $user->id,
                'role' => 'bot',
                'text' => '🔄 [Системное сообщение: ИИ-ассистент снова активирован]',
                'is_read' => true,
            ]);

            app(SupportConversationManager::class)
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
     * Данные для модалки: оплаты + P0 EdTech-контекст (H2381).
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

        return app(HelpdeskStudentContextService::class)->forUser($user);
    }

    /**
     * Resolve / close the active thread. When support_required_close_topic is ON,
     * a category is mandatory (other/uncategorized allowed).
     */
    public function resolveConversation(): void
    {
        $thread = $this->resolveActiveThread();
        if (! $thread || ! $thread->isOpen()) {
            return;
        }

        try {
            app(SupportConversationManager::class)->closeWithTopic(
                $thread,
                $this->closeTopicCategory !== '' ? $this->closeTopicCategory : null,
                auth()->user(),
            );
        } catch (\InvalidArgumentException $e) {
            Notification::make()
                ->title('Нужна тема обращения')
                ->body('Выберите тему перед закрытием диалога (или «Другое» / «Без категории»).')
                ->danger()
                ->send();

            return;
        }

        $this->closeTopicCategory = '';
        $this->loadUsersList();

        Notification::make()
            ->title('Диалог закрыт')
            ->success()
            ->send();
    }

    /** @return array<string, string> */
    public function getTopicOptionsProperty(): array
    {
        return app(SupportConversationTopicService::class)->categoryOptions();
    }

    public function getCurrentTopicLabelProperty(): ?string
    {
        $thread = $this->thread;
        if (! $thread) {
            return null;
        }

        return app(SupportConversationTopicService::class)->currentTopic($thread)?->category;
    }

    public function createSupportFollowUp(): void
    {
        if (! config('features.support_follow_up_tasks')) {
            return;
        }

        $thread = $this->resolveActiveThread();
        if (! $thread) {
            Notification::make()->title('Нет активного диалога')->danger()->send();

            return;
        }

        if ($this->followUpDueAt === '') {
            Notification::make()->title('Укажите срок follow-up')->danger()->send();

            return;
        }

        $assignee = $this->followUpAssigneeId !== ''
            ? User::find((int) $this->followUpAssigneeId)
            : auth()->user();

        try {
            app(SupportFollowUpService::class)->create(
                $thread,
                $this->followUpDueAt,
                $assignee,
                $this->followUpType ?: FollowUpTask::TYPE_MESSAGE,
                $this->followUpNote !== '' ? $this->followUpNote : null,
            );
        } catch (\Throwable $e) {
            Notification::make()->title('Не удалось создать задачу')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->followUpDueAt = '';
        $this->followUpNote = '';
        $this->followUpType = FollowUpTask::TYPE_MESSAGE;

        Notification::make()->title('Follow-up создан')->success()->send();
    }

    public function completeSupportFollowUp(int $taskId): void
    {
        if (! config('features.support_follow_up_tasks')) {
            return;
        }

        $task = FollowUpTask::query()
            ->forSupport()
            ->whereKey($taskId)
            ->first();

        if (! $task) {
            return;
        }

        app(SupportFollowUpService::class)->complete($task);

        Notification::make()->title('Задача выполнена')->success()->send();
    }

    /** Open support follow-ups for the active thread. */
    public function getOpenSupportFollowUpsProperty()
    {
        if (! config('features.support_follow_up_tasks')) {
            return collect();
        }

        $thread = $this->thread;
        if (! $thread) {
            return collect();
        }

        return app(SupportFollowUpService::class)->openForConversation($thread);
    }

    private function resolveActiveThread(): ?SupportConversation
    {
        if ($this->activeGuestId) {
            return SupportConversation::query()->whereKey((int) $this->activeGuestId)->first();
        }

        if ($this->activeUserId) {
            return app(SupportConversationManager::class)->currentFor((int) $this->activeUserId);
        }

        return null;
    }
}
