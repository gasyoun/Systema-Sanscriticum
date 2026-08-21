<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Support\Faq\Bm25FaqRetriever;
use Illuminate\Support\Facades\Log;

/**
 * B: простые вопросы в личке саппорт-аккаунта отвечает бот, сложные — подсказка
 * кураторам в Telegram. Откат на A: SUPPORT_DM_AUTO_REPLY=false (флаг default OFF).
 *
 * Простое = категории A/B/C с живыми фактами LMS (Zoom / запись / расписание).
 * Деньги (D) и всё без фактов — не отправляем студенту.
 *
 * Доставка исходящего — pending + ближайший telegram-support:sync
 * ({@see PendingSupportReplyDrainer}). Отсюда MadelineProto не открываем.
 */
final class SupportDmAutoReply
{
    public const VIA = 'support_dm_auto_reply';

    public const EVENT_SENT = 'dm_auto_sent';

    public const EVENT_HINTED = 'dm_hinted';

    /** @var list<string> */
    private const SIMPLE_CATEGORIES = [
        SupportAnswerSuggestion::CATEGORY_ZOOM,
        SupportAnswerSuggestion::CATEGORY_RECORDING,
        SupportAnswerSuggestion::CATEGORY_SCHEDULE,
    ];

    public function __construct(
        private readonly SupportAnswerSuggester $suggester,
        private readonly SupportAnswerFactResolver $facts,
        private readonly SupportReplyService $replies,
        private readonly TelegramAdminNotifier $admins,
        private readonly Bm25FaqRetriever $faq,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_dm_auto_reply', false);
    }

    /**
     * @return array{status: string, category: ?string}
     */
    public function handle(TelegramSupportMessage $incoming, ?int $linkedUserId, string $chatType): array
    {
        if (! $this->isEnabled()) {
            return ['status' => 'off', 'category' => null];
        }

        if ($incoming->direction !== 'incoming' || $chatType !== 'private') {
            return ['status' => 'skip', 'category' => null];
        }

        $text = trim((string) $incoming->text);
        if ($text === '') {
            return ['status' => 'skip', 'category' => null];
        }

        if ($this->alreadyHandled($incoming)) {
            return ['status' => 'duplicate', 'category' => null];
        }

        $category = $this->suggester->categorize($text);
        $user = $linkedUserId ? User::query()->find($linkedUserId) : null;

        if ($user !== null
            && $category !== null
            && in_array($category, self::SIMPLE_CATEGORIES, true)
        ) {
            $resolved = $this->facts->resolve($category, $user);
            if ($resolved !== null && trim((string) $resolved['draft']) !== '') {
                return $this->sendSimple($incoming, $user, $category, (string) $resolved['draft']);
            }
        }

        return $this->hintComplex($incoming, $user, $category, $text);
    }

    /**
     * @return array{status: string, category: string}
     */
    private function sendSimple(
        TelegramSupportMessage $incoming,
        User $user,
        string $category,
        string $draft,
    ): array {
        $outgoing = $this->replies->queueAiReply(
            $user,
            $draft,
            (int) $incoming->telegram_message_id,
        );

        if ($outgoing === null) {
            Log::warning('SupportDmAutoReply: не удалось поставить pending исходящее', [
                'user_id' => $user->id,
                'incoming_id' => $incoming->id,
            ]);

            return $this->hintComplex($incoming, $user, $category, (string) $incoming->text);
        }

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $outgoing->id,
            'event_type' => self::EVENT_SENT,
            'meta' => [
                'via' => self::VIA,
                'category' => $category,
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
            ],
        ]);

        return ['status' => 'sent', 'category' => $category];
    }

    /**
     * @return array{status: string, category: ?string}
     */
    private function hintComplex(
        TelegramSupportMessage $incoming,
        ?User $user,
        ?string $category,
        string $text,
    ): array {
        $hits = $this->faq->retrieve($text, 3);
        $name = $user?->name ?? 'без привязки';
        $catLabel = $category ?? 'без категории';
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeQuestion = htmlspecialchars(mb_substr($text, 0, 500), ENT_QUOTES, 'UTF-8');

        $lines = [
            '💡 <b>Сложный вопрос — бот не ответил</b>',
            "Студент: {$safeName}",
            "Категория: {$catLabel}",
            '',
            "<i>{$safeQuestion}</i>",
        ];

        if ($hits !== []) {
            $lines[] = '';
            $lines[] = '<b>Из FAQ:</b>';
            foreach (array_slice($hits, 0, 3) as $i => $hit) {
                $title = htmlspecialchars((string) ($hit['title'] ?? $hit['chunk_id'] ?? ''), ENT_QUOTES, 'UTF-8');
                $snippet = htmlspecialchars(mb_substr((string) ($hit['snippet'] ?? ''), 0, 220), ENT_QUOTES, 'UTF-8');
                $n = $i + 1;
                $lines[] = "{$n}. {$title}";
                if ($snippet !== '') {
                    $lines[] = $snippet;
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Студенту ничего не ушло. Ответьте в этом же Telegram.';

        $this->admins->notifyAdmins(implode("\n", $lines));

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => self::EVENT_HINTED,
            'meta' => [
                'via' => self::VIA,
                'category' => $category,
                'source_telegram_message_id' => (int) $incoming->telegram_message_id,
                'faq_chunk_ids' => array_values(array_map(
                    static fn (array $hit): string => (string) ($hit['chunk_id'] ?? ''),
                    $hits,
                )),
            ],
        ]);

        return ['status' => 'hinted', 'category' => $category];
    }

    private function alreadyHandled(TelegramSupportMessage $incoming): bool
    {
        $msgId = (int) $incoming->telegram_message_id;

        return TelegramSupportMessage::query()
            ->where('telegram_chat_id', $incoming->telegram_chat_id)
            ->where('direction', 'outgoing')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->contains(function (TelegramSupportMessage $outgoing) use ($msgId): bool {
                $payload = $outgoing->raw_payload ?? [];

                return ($payload['via'] ?? null) === self::VIA
                    && (int) ($payload['reply_to_msg_id'] ?? 0) === $msgId;
            });
    }
}
