<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TelegramSupport;
use App\Models\ChatMessage;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use App\Support\UnifiedMessage;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Единый дашборд поддержки по ОБОИМ каналам (H1837, S10). До S10 страница видела
 * только импортированный TG-support; теперь список разговоров и сэмплы сообщений
 * канал-независимы (через {@see UnifiedMessage}), а фильтр «канал» позволяет
 * смотреть срез. Название страницы историческое — переименование тронуло бы slug
 * и закладки операторов.
 */
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

    /** Срез по каналу (пусто = все каналы). */
    public string $channel = '';

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

    /**
     * H3529: дневной coverage классификации по каналам для выбранной даты.
     * Coverage% = доля разговоров дня, у которых есть хоть одно назначение
     * темы кроме uncategorized; uncategorized rate — обратная доля (включая
     * разговоры без назначений вовсе). Числа читаются из того же
     * support_topic_assignments, что и вся страница, поэтому сходятся с SQL
     * по определению построения.
     *
     * @return array{rows: list<array{channel:string,label:string,total:int,categorized:int,coverage:int|null,uncategorized:int}>, total:int, categorized:int, coverage:int|null, uncategorized_rate:int|null}
     */
    public function getCoverageProperty(): array
    {
        $rollups = SupportDailyRollup::query()
            ->whereDate('conversation_date', $this->selectedDate)
            ->withCount([
                'topicAssignments as categorized_count' => fn ($query) => $query->where('category', '!=', 'uncategorized'),
            ])
            ->get();

        $byChannel = [];

        foreach ($rollups as $rollup) {
            $channel = $rollup->channel ?? '';
            $bucket = $byChannel[$channel] ??= ['total' => 0, 'categorized' => 0];
            $bucket['total']++;
            $bucket['categorized'] += ($rollup->categorized_count ?? 0) > 0 ? 1 : 0;
            $byChannel[$channel] = $bucket;
        }

        $order = [
            SupportDailyRollup::CHANNEL_TELEGRAM,
            SupportDailyRollup::CHANNEL_TELEGRAM_BOT,
            SupportDailyRollup::CHANNEL_WEB,
            SupportDailyRollup::CHANNEL_VK,
            SupportDailyRollup::CHANNEL_EMAIL,
        ];

        $make = static function (int $total, int $categorized): array {
            return [
                'total' => $total,
                'categorized' => $categorized,
                'coverage' => $total > 0 ? (int) round($categorized / $total * 100) : null,
                'uncategorized' => $total - $categorized,
            ];
        };

        $rows = [];

        foreach ($order as $channel) {
            if (! isset($byChannel[$channel])) {
                continue;
            }

            $stats = $make($byChannel[$channel]['total'], $byChannel[$channel]['categorized']);
            $stats['channel'] = $channel;
            $stats['label'] = (new SupportDailyRollup(['channel' => $channel]))->channelLabel();
            $rows[] = $stats;
        }

        // Каналы вне канонического списка (страховка от новых значений enum).
        foreach ($byChannel as $channel => $counts) {
            if (in_array($channel, $order, true)) {
                continue;
            }

            $stats = $make($counts['total'], $counts['categorized']);
            $stats['channel'] = $channel;
            $stats['label'] = (new SupportDailyRollup(['channel' => $channel]))->channelLabel();
            $rows[] = $stats;
        }

        $total = $rollups->count();
        $categorized = (int) collect($rows)->sum('categorized');
        $coverage = $total > 0 ? (int) round($categorized / $total * 100) : null;
        $uncategorized = $total - $categorized;

        return [
            'rows' => $rows,
            'total' => $total,
            'categorized' => $categorized,
            'coverage' => $coverage,
            'uncategorized_rate' => $total > 0 ? (int) round($uncategorized / $total * 100) : null,
        ];
    }

    /**
     * H3529: ссылка на последний замороженный отчёт харнесса пакета
     * (reports/*.md vendored-снапшота), закреплённая за pin-SHA. Пока отчётов
     * нет (волна 1 шаг 4 не заморозила baseline) — null, секция ссылки не
     * рисует.
     */
    public function getHarnessReportUrlProperty(): ?string
    {
        $reportsDir = base_path('tools/message-intent-classifier/reports');

        $reports = is_dir($reportsDir) ? collect(glob($reportsDir.'/*.md') ?: [])->sort()->values() : collect([]);

        if ($reports->isEmpty()) {
            return null;
        }

        $sha = trim((string) @file_get_contents(base_path('tools/message-intent-classifier/PINNED_SHA')));

        if ($sha === '') {
            return null;
        }

        return sprintf(
            'https://github.com/gasyoun/message-intent-classifier/blob/%s/reports/%s',
            $sha,
            basename((string) $reports->last()),
        );
    }

    public function getConversationsProperty(): EloquentCollection
    {
        return SupportDailyRollup::query()
            ->with([
                'chat.linkedUser:id,name,email',
                'conversation:id,user_id,guest_name,guest_token',
                'conversation.user:id,name',
                'webUser:id,name',
                'topicAssignments',
            ])
            ->whereDate('conversation_date', $this->selectedDate)
            ->when($this->channel !== '', fn ($query) => $query->where('channel', $this->channel))
            ->when($this->onlyUnanswered, fn ($query) => $query->where('is_unanswered', true))
            ->when($this->onlyNewContacts, fn ($query) => $query->where('has_new_contact', true))
            ->when($this->topic !== '', fn ($query) => $query->whereHas(
                'topicAssignments',
                fn ($topicQuery) => $topicQuery->where('category', $this->topic)
            ))
            // Фильтр по типу отвечавшего: на TG-стороне это колонка
            // responder_type, на веб-стороне тип ВЫВОДИМ из role (см.
            // UnifiedMessage) — поэтому две ветки, а не один запрос.
            ->when($this->responderType !== '', function ($query) {
                $query->where(function ($outer) {
                    $outer->where(function ($tg) {
                        $tg->where('channel', SupportDailyRollup::CHANNEL_TELEGRAM)
                            ->whereHas('chat.messages', function ($messageQuery) {
                                $messageQuery->whereDate('sent_at', $this->selectedDate)
                                    ->where('responder_type', $this->responderType);
                            });
                    })->orWhere(function ($web) {
                        $web->whereIn('channel', SupportDailyRollup::WEB_SIDE_CHANNELS)
                            ->whereExists(function ($sub) {
                                $sub->selectRaw('1')
                                    ->from('chat_messages')
                                    ->whereDate('chat_messages.created_at', $this->selectedDate)
                                    ->whereIn('chat_messages.role', $this->webRolesForResponderType($this->responderType))
                                    ->where(fn ($match) => $match
                                        ->whereColumn('chat_messages.support_conversation_id', 'support_daily_rollups.support_conversation_id')
                                        ->orWhereColumn('chat_messages.user_id', 'support_daily_rollups.web_user_id'));
                            });
                    });
                });
            })
            ->orderByDesc('last_message_at')
            ->get();
    }

    /**
     * Сэмплы сообщений выбранной строки — канал-независимо, через общий read-слой.
     *
     * @return Collection<int, UnifiedMessage>
     */
    public function getActiveMessagesProperty(): Collection
    {
        if (! $this->activeConversationId) {
            return new Collection;
        }

        $rollup = SupportDailyRollup::find($this->activeConversationId);
        if (! $rollup) {
            return new Collection;
        }

        if ($rollup->isTelegramSide()) {
            return TelegramSupportMessage::query()
                ->with(['chat', 'contact', 'responder:id,name'])
                ->where('telegram_support_chat_id', $rollup->telegram_support_chat_id)
                ->whereDate('sent_at', $rollup->conversation_date)
                ->orderBy('sent_at')
                ->get()
                ->map(fn (TelegramSupportMessage $message) => UnifiedMessage::fromTelegramSupportMessage($message))
                ->values();
        }

        return ChatMessage::query()
            ->with('answeredBy:id,name')
            ->whereDate('created_at', $rollup->conversation_date)
            ->when(
                $rollup->support_conversation_id !== null,
                fn ($query) => $query->where('support_conversation_id', $rollup->support_conversation_id),
                fn ($query) => $query->whereNull('support_conversation_id')
                    ->when(
                        $rollup->web_user_id !== null,
                        fn ($sub) => $sub->where('user_id', $rollup->web_user_id),
                        fn ($sub) => $sub->whereNull('user_id'),
                    ),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message))
            ->filter(fn (UnifiedMessage $message): bool => $message->channel === $rollup->channel)
            ->values();
    }

    /**
     * Роли `chat_messages`, дающие запрошенный тип отвечавшего. Веб-сторона не
     * хранит responder_type — маппинг здесь обязан совпадать с
     * {@see UnifiedMessage::fromChatMessage()}.
     *
     * @return array<int, string>
     */
    private function webRolesForResponderType(string $responderType): array
    {
        return match ($responderType) {
            UnifiedMessage::RESPONDER_HUMAN => ['curator'],
            UnifiedMessage::RESPONDER_AI => ['bot'],
            default => ['user'],
        };
    }

    /**
     * H3395: ручные использования шаблонов библиотеки куратором (Helpdesk-сенд,
     * начатый с шаблона) за 30 дней — топ-10 для таблицы на странице. Denominator
     * для ревью библиотеки H2339: без него видны только автосенды (dm_auto_sent),
     * ручные канреплаи были невидимы (S9 gap #3).
     *
     * @return Collection<int, array{template_id:int|null,title:string,uses:int,last_used_at:string|null}>
     */
    public function getManualTemplateUsesProperty(): Collection
    {
        return SupportAiReplyEvent::query()
            ->where('event_type', SupportAiReplyEvent::EVENT_TEMPLATE_USED)
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['meta', 'created_at'])
            ->groupBy(fn (SupportAiReplyEvent $event): string => (string) ($event->meta['template_id'] ?? 'unknown'))
            ->map(fn (Collection $group, string $templateId): array => [
                'template_id' => is_numeric($templateId) ? (int) $templateId : null,
                'title' => (string) ($group->first()->meta['title'] ?? '—'),
                'uses' => $group->count(),
                'last_used_at' => $group->max('created_at')?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            ])
            ->sortByDesc('uses')
            ->take(10)
            ->values();
    }

    public function getTopicOptionsProperty(): array
    {
        return collect($this->packet['topics'])
            ->pluck('category')
            ->values()
            ->all();
    }

    /**
     * Каналы, реально присутствующие в выбранном дне (значение => подпись). Пустой
     * список = один канал/ничего, селектор в шаблоне тогда не рисуется.
     *
     * @return array<string, string>
     */
    public function getChannelOptionsProperty(): array
    {
        return SupportDailyRollup::query()
            ->whereDate('conversation_date', $this->selectedDate)
            ->distinct()
            ->pluck('channel')
            ->mapWithKeys(fn (string $channel): array => [
                $channel => (new SupportDailyRollup(['channel' => $channel]))->channelLabel(),
            ])
            ->all();
    }
}
