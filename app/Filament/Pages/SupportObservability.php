<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Clusters\TelegramSupport;
use App\Models\SupportAiReplyEvent;
use App\Models\SupportDailyRollup;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Дашборд наблюдаемости поддержки (W3.3, H597): здоровье userbot-сессий, лаг
 * синка, доля успешной доставки исходящих, объём обращений к LLM. Read-only
 * поверх существующих агрегатов — никакой новой логики записи. За рубильником
 * features.support_observability по образцу attendance_dashboard.
 */
class SupportObservability extends Page
{
    /** Окно всех метрик, дней назад от сегодня. */
    private const WINDOW_DAYS = 14;

    /** Синк считается протухшим после стольких минут без успешного прохода. */
    private const SYNC_STALE_MINUTES = 15;

    protected static ?string $cluster = TelegramSupport::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationLabel = 'Наблюдаемость';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?string $title = 'Наблюдаемость поддержки';

    protected static ?string $slug = 'support-observability';

    protected static string $view = 'filament.pages.support-observability';

    /** Инфраструктурный дашборд — только админ (+ супер-админ через RoleGate). */
    public static function canAccess(): bool
    {
        if (! config('features.support_observability')) {
            return false;
        }

        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function windowDays(): int
    {
        return self::WINDOW_DAYS;
    }

    /**
     * @return array{
     *     accounts: Collection,
     *     delivery: array{outgoing_total: int, tracked: int, delivered: int, pending: int, rate: float|null},
     *     rollup: array{conversations: int, unanswered: int, unanswered_share: float|null, avg_first_response_seconds: int|null, incoming: int, outgoing: int},
     *     llm: array{total: int, by_type: Collection}
     * }
     */
    public function report(): array
    {
        $from = now()->subDays(self::WINDOW_DAYS);

        return [
            'accounts' => $this->accounts(),
            'delivery' => $this->delivery($from),
            'rollup' => $this->rollup($from),
            'llm' => $this->llm($from),
        ];
    }

    /** Здоровье сессий: включённость, последний успешный синк, лаг, последняя ошибка. */
    private function accounts(): Collection
    {
        return TelegramSupportAccount::query()
            ->orderBy('name')
            ->get()
            ->map(fn (TelegramSupportAccount $account): array => [
                'account' => $account,
                'lag_minutes' => $account->last_successful_sync_at
                    ? (int) $account->last_successful_sync_at->diffInMinutes(now())
                    : null,
                'is_stale' => $account->is_enabled
                    && (! $account->last_successful_sync_at
                        || $account->last_successful_sync_at->lt(now()->subMinutes(self::SYNC_STALE_MINUTES))),
            ]);
    }

    /**
     * Доставка: исходящие за окно; из прошедших путь helpdesk-ответа
     * (raw_payload с ключом pending_delivery, см. SupportReplyService /
     * DeliverSupportReply) — сколько реально ушло, сколько зависло.
     *
     * @return array{outgoing_total: int, tracked: int, delivered: int, pending: int, rate: float|null}
     */
    private function delivery(Carbon $from): array
    {
        $outgoing = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', $from)
            ->get(['id', 'raw_payload', 'sent_at']);

        $tracked = $outgoing->filter(
            fn (TelegramSupportMessage $message): bool => array_key_exists('pending_delivery', $message->raw_payload ?? []),
        );
        $pending = $tracked->filter(
            fn (TelegramSupportMessage $message): bool => (bool) ($message->raw_payload['pending_delivery'] ?? false),
        );
        $delivered = $tracked->count() - $pending->count();

        return [
            'outgoing_total' => $outgoing->count(),
            'tracked' => $tracked->count(),
            'delivered' => $delivered,
            'pending' => $pending->count(),
            'rate' => $tracked->isNotEmpty() ? round($delivered / $tracked->count() * 100, 1) : null,
        ];
    }

    /**
     * Сводка TG-разговоров за окно из готовых дневных агрегатов.
     *
     * @return array{conversations: int, unanswered: int, unanswered_share: float|null, avg_first_response_seconds: int|null, incoming: int, outgoing: int}
     */
    private function rollup(Carbon $from): array
    {
        $rollups = SupportDailyRollup::query()
            ->whereDate('conversation_date', '>=', $from->toDateString())
            ->get([
                'id', 'is_unanswered', 'first_response_seconds',
                'incoming_count', 'outgoing_count',
            ]);

        $withResponse = $rollups->whereNotNull('first_response_seconds');

        return [
            'conversations' => $rollups->count(),
            'unanswered' => $rollups->where('is_unanswered', true)->count(),
            'unanswered_share' => $rollups->isNotEmpty()
                ? round($rollups->where('is_unanswered', true)->count() / $rollups->count() * 100, 1)
                : null,
            'avg_first_response_seconds' => $withResponse->isNotEmpty()
                ? (int) round($withResponse->avg('first_response_seconds'))
                : null,
            'incoming' => (int) $rollups->sum('incoming_count'),
            'outgoing' => (int) $rollups->sum('outgoing_count'),
        ];
    }

    /**
     * Расход LLM: события ИИ-журнала за окно по типам. Токены/деньги в журнале
     * не хранятся — метрика честно показывает объём обращений, не рубли.
     *
     * @return array{total: int, by_type: Collection}
     */
    private function llm(Carbon $from): array
    {
        $byType = SupportAiReplyEvent::query()
            ->where('created_at', '>=', $from)
            ->get(['id', 'event_type'])
            ->groupBy('event_type')
            ->map(fn ($events): int => $events->count())
            ->sortDesc();

        return [
            'total' => (int) $byType->sum(),
            'by_type' => $byType,
        ];
    }
}
