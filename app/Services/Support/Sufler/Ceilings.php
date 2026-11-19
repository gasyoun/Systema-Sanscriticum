<?php

declare(strict_types=1);

namespace App\Services\Support\Sufler;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportMessage;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Support\Carbon;

/**
 * H5776: суфлёр, политика v1 — machinery потолков из policy/sufler.policy.yml.
 *
 * max_steps_per_ticket (по умолчанию 8): сколько автоотправок бот имеет право
 * сделать на один «тикет» (чат саппорта) в скользящем окне. Нарушение — не
 * повод звать человека чинить бота, а авто-стоп: отправки нет, событие-трейс
 * есть, вопрос уходит куратору подсказкой.
 *
 * tokens_per_ticket: значение приходит из конфига
 * services.telegram_support.sufler_tokens_per_ticket; monthly_allowance даёт
 * MG (политика: поле TBD) — пока числа нет, потолок DISABLED, и это честно
 * читается из tokensPerTicket() === null. Флаги пилота при этом остаются OFF
 * по умолчанию — включение возможно только после заполнения allowance.
 *
 * Учитываются реально отправленные события (dm_auto_sent) на ИСХОДЯЩИХ
 * сообщениях чата — теневые и заблокированные в счёт шагов не идут.
 */
final class Ceilings
{
    public const REASON_MAX_STEPS = 'max_steps_per_ticket';

    public const REASON_TOKENS = 'tokens_per_ticket';

    public function maxStepsPerTicket(): int
    {
        return max(1, (int) config('services.telegram_support.sufler_max_steps_per_ticket', 8));
    }

    /**
     * null = потолок ещё не задан (monthly_allowance TBD у MG): не нарушен,
     * а отсутствует — отдельный, читаемый статус.
     */
    public function tokensPerTicket(): ?int
    {
        $raw = config('services.telegram_support.sufler_tokens_per_ticket');

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        return max(1, (int) $raw);
    }

    public function windowHours(): int
    {
        return max(1, (int) config('services.telegram_support.sufler_steps_window_hours', 24));
    }

    /**
     * Нарушен ли потолок для этой отправки. null — можно отправлять;
     * массив — авто-стоп с причиной и деталями для трейса.
     *
     * @param  array<string, mixed>  $metaExtra  meta будущей отправки (usage у llm_draft)
     * @return array{reason: string, detail: string}|null
     */
    public function exceeded(int $chatId, string $kind, array $metaExtra = []): ?array
    {
        $used = $this->stepsUsed($chatId);

        if ($used >= $this->maxStepsPerTicket()) {
            return [
                'reason' => self::REASON_MAX_STEPS,
                'detail' => sprintf(
                    '%d auto-sends already used in the last %dh window (ceiling %d)',
                    $used,
                    $this->windowHours(),
                    $this->maxStepsPerTicket(),
                ),
            ];
        }

        if ($kind === SupportDmAutoReply::KIND_LLM_DRAFT) {
            $incoming = (int) ($metaExtra['usage']['total_tokens'] ?? 0);
            $prior = $this->tokensUsed($chatId);

            $ceiling = $this->tokensPerTicket();

            if ($ceiling !== null && $prior + $incoming > $ceiling) {
                return [
                    'reason' => self::REASON_TOKENS,
                    'detail' => sprintf('%d prior + %d incoming > ceiling %d tokens', $prior, $incoming, $ceiling),
                ];
            }
        }

        return null;
    }

    /** Сколько автоотправок уже ушло в этом чате внутри скользящего окна. */
    public function stepsUsed(int $chatId): int
    {
        return SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->whereIn(
                'telegram_support_message_id',
                TelegramSupportMessage::query()
                    ->select('id')
                    ->where('telegram_chat_id', $chatId)
                    ->where('direction', 'outgoing'),
            )
            ->where('created_at', '>=', Carbon::now()->subHours($this->windowHours()))
            ->count();
    }

    /** Сколько LLM-токенов уже израсходовано в этом чате в окне (сумма meta.usage). */
    public function tokensUsed(int $chatId): int
    {
        $events = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SENT)
            ->whereIn(
                'telegram_support_message_id',
                TelegramSupportMessage::query()
                    ->select('id')
                    ->where('telegram_chat_id', $chatId)
                    ->where('direction', 'outgoing'),
            )
            ->where('created_at', '>=', Carbon::now()->subHours($this->windowHours()))
            ->get();

        $sum = 0;
        foreach ($events as $event) {
            $meta = $event->meta;
            if (is_array($meta) && (($meta['kind'] ?? '') === SupportDmAutoReply::KIND_LLM_DRAFT)) {
                $usage = $meta['usage'] ?? null;
                $sum += is_array($usage) ? (int) ($usage['total_tokens'] ?? 0) : 0;
            }
        }

        return $sum;
    }
}
