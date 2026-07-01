<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TelegramSupport;
use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

class TelegramSupportAnalytics extends Page
{
    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Аналитика';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Telegram support analytics';

    protected static ?string $slug = 'telegram-support-analytics';

    protected static string $view = 'filament.pages.telegram-support-analytics';

    public string $selectedDate = '';

    public string $topic = '';

    public string $responderType = '';

    public bool $onlyUnanswered = false;

    public bool $onlyNewContacts = false;

    public ?int $activeConversationId = null;

    public function mount(): void
    {
        $this->selectedDate = $this->selectedDate ?: CarbonImmutable::now(config('app.timezone'))->toDateString();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isTeacher() !== true;
    }

    public function updatedSelectedDate(): void
    {
        $this->activeConversationId = null;
    }

    public function selectConversation(int $conversationId): void
    {
        $this->activeConversationId = $conversationId;
    }

    public function getPacketProperty(): array
    {
        return app(SupportDashboardPacketBuilder::class)->build($this->selectedDate);
    }

    public function getConversationsProperty(): Collection
    {
        return SupportDailyRollup::query()
            ->with(['chat.linkedUser:id,name,email', 'topicAssignments'])
            ->whereDate('conversation_date', $this->selectedDate)
            ->when($this->onlyUnanswered, fn ($query) => $query->where('is_unanswered', true))
            ->when($this->onlyNewContacts, fn ($query) => $query->where('has_new_contact', true))
            ->when($this->topic !== '', fn ($query) => $query->whereHas(
                'topicAssignments',
                fn ($topicQuery) => $topicQuery->where('category', $this->topic)
            ))
            ->when($this->responderType !== '', function ($query) {
                $query->whereHas('chat.messages', function ($messageQuery) {
                    $messageQuery->whereDate('sent_at', $this->selectedDate)
                        ->where('responder_type', $this->responderType);
                });
            })
            ->orderByDesc('last_message_at')
            ->get();
    }

    public function getActiveMessagesProperty(): Collection
    {
        if (! $this->activeConversationId) {
            return new Collection;
        }

        $conversation = SupportDailyRollup::find($this->activeConversationId);
        if (! $conversation) {
            return new Collection;
        }

        return TelegramSupportMessage::query()
            ->with('responder:id,name')
            ->where('telegram_support_chat_id', $conversation->telegram_support_chat_id)
            ->whereDate('sent_at', $conversation->conversation_date)
            ->orderBy('sent_at')
            ->get();
    }

    public function getTopicOptionsProperty(): array
    {
        return collect($this->packet['topics'])
            ->pluck('category')
            ->values()
            ->all();
    }
}
