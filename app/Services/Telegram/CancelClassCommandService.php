<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\TelegramChatPost;
use App\Services\Schedule\LessonSeriesInfo;
use App\Services\Schedule\ScheduleMover;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Log;

/**
 * H4199: reply-команда админа «Отмена занятия» на пост-напоминание zapisi-бота.
 *
 * Поток: RemindZapisiClasses постит «Скоро занятие» (message_id запоминается в
 * telegram_chat_posts) → админ отвечает в чате группы «Отмена занятия» → вебхук
 * (ProcessTelegramZapisiUpdate) вызывает этот сервис → занятие отменяется
 * каскадом +7 дней (ScheduleMover::cancelAndShiftWeek) и в тот же чат уходит
 * анонс с датой следующего занятия.
 *
 * Безопасность:
 *  - команда распознаётся ТОЛЬКО у telegram user_id из whitelist
 *    (marketing_settings.zapisi_cancel_admin_ids);
 *  - занятие должно быть в будущем;
 *  - повторная команда на тот же пост подавляется клеймом TelegramSendGuard
 *    на сутки (иначе второй reply сдвинул бы цепочку ещё на неделю);
 *  - ВСЕ отказы (не в whitelist, нет маппинга, занятие прошло) — только в лог:
 *    бот не пишет в студенческий чат ничего, кроме подтверждения отмены.
 *
 * MG 08-09: подтверждение несёт причину (ровно одна главная — названная в
 * команде после «:»/«—», иначе авто-определение отпуск/каникулы), дату и
 * время следующего занятия с номером «13-е из 16» и строку о последнем
 * занятии потока. Каскад +7д — семантика по умолчанию; одиночная отмена
 * без сдвига — запасной путь через датированную команду (H4253).
 */
final class CancelClassCommandService
{
    /**
     * Команда + опциональная причина: «Отмена занятия», «Отмена: нет кворума»,
     * «Отменяю занятие — гос. каникулы». Без хвоста — причина авто-определяется.
     * Хвост, начинающийся с даты («Отмена: 08.09»), — НЕ эта команда:
     * датированная отмена — это DateAwareCancelService (H4253).
     */
    private const COMMAND_PATTERN = '/^(отмена занятия|отменяю занятие|отмена)(?:\s*[:—–-]\s*(?!\d{1,2}[.\/]\d{1,2})(.+))?$/u';

    /** Клейм «по этому посту уже отменили» — окно суточное, как у дедупа отправок. */
    private const CLAIM_TTL_SECONDS = 86400;

    /**
     * Распознаёт ли этот текст reply-команду (без проверки reply/whitelist) —
     * для CancelUsageHint: отличить «команда адресована» от «попытка мимо».
     *
     * @param  array<string, mixed>  $message
     */
    public static function matches(array $message): bool
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));

        return $text !== '' && preg_match(self::COMMAND_PATTERN, $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function handle(array $message): void
    {
        $replyTo = $message['reply_to_message']['message_id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $chatId = $message['chat']['id'] ?? null;

        if (! is_numeric($replyTo) || $fromId === null || $chatId === null) {
            return;
        }

        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($message['text'] ?? ''))));
        if (preg_match(self::COMMAND_PATTERN, $text, $command) !== 1) {
            return;
        }
        $statedReason = trim((string) ($command[2] ?? ''));

        $chatId = (string) $chatId;
        $replyTo = (int) $replyTo;
        $userId = (int) $fromId;

        if (! in_array($userId, $this->adminIds(), true)) {
            Log::info('CancelClassCommand: sender is not in the admin whitelist, ignored', [
                'chat_id' => $chatId,
                'telegram_user_id' => $userId,
            ]);

            return;
        }

        /** @var TelegramChatPost|null $post */
        $post = TelegramChatPost::query()
            ->where('chat_id', $chatId)
            ->where('message_id', $replyTo)
            ->where('kind', TelegramChatPost::KIND_ZAPISI_REMINDER)
            ->whereNotNull('schedule_id')
            ->first();

        if ($post === null) {
            Log::info('CancelClassCommand: no reminder post mapped to this reply, ignored', [
                'chat_id' => $chatId,
                'reply_to_message_id' => $replyTo,
            ]);

            return;
        }

        // Второй «Отмена занятия» на тот же пост — подавляем: каскад уже отработал,
        // повтор сдвинул бы цепочку ещё на неделю.
        if (! TelegramSendGuard::claimKey('tg:cancel:'.$chatId.':'.$replyTo, self::CLAIM_TTL_SECONDS)) {
            Log::info('CancelClassCommand: duplicate command on the same post, suppressed', [
                'chat_id' => $chatId,
                'reply_to_message_id' => $replyTo,
            ]);

            return;
        }

        $schedule = $post->schedule;

        if ($schedule === null || $schedule->group_id === null || $schedule->start === null) {
            Log::warning('CancelClassCommand: mapped schedule is gone or has no group/start', [
                'chat_id' => $chatId,
                'schedule_id' => $post->schedule_id,
            ]);

            return;
        }

        if ($schedule->start->isPast()) {
            Log::warning('CancelClassCommand: lesson already started, refusing cascade', [
                'schedule_id' => $schedule->id,
                'start' => $schedule->start->toDateTimeString(),
            ]);

            return;
        }

        $label = $this->lessonLabel($schedule);
        $number = LessonSeriesInfo::number($schedule->title);
        $reason = CancelReasonResolver::resolve($schedule, $statedReason !== '' ? $statedReason : null);
        // Дата «следующего» = текущий start этой строки + неделя (так его положит каскад).
        $nextStart = $schedule->start->copy()->addWeek();

        $shifted = app(ScheduleMover::class)->cancelAndShiftWeek($schedule);

        SendZapisiBotMessageJob::dispatch($chatId, CancelNoticeRenderer::build(
            $label,
            $nextStart,
            $number,
            LessonSeriesInfo::totalForGroup((int) $schedule->group_id),
            LessonSeriesInfo::lastRowForGroup((int) $schedule->group_id)?->start,
            $reason,
        ));

        Log::info('CancelClassCommand: lesson cancelled by admin reply', [
            'chat_id' => $chatId,
            'schedule_id' => $schedule->id,
            'telegram_user_id' => $userId,
            'shifted' => $shifted,
            'next_start' => $nextStart->toDateTimeString(),
        ]);
    }

    /**
     * Whitelist из marketing_settings.zapisi_cancel_admin_ids — telegram user_id
     * через запятую/пробел/точку с запятой.
     *
     * @return array<int, int>
     */
    private function adminIds(): array
    {
        $raw = trim((string) (MarketingSetting::cached()?->zapisi_cancel_admin_ids ?? ''));
        if ($raw === '') {
            return [];
        }

        return collect(preg_split('/[,\s;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** Заголовок занятия без хвостовой даты «(#13, 06.09.26)» — она устареет после сдвига. */
    private function lessonLabel(Schedule $schedule): string
    {
        $title = trim((string) ($schedule->title ?: 'Занятие'));
        $stripped = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $title));

        return $stripped !== '' ? $stripped : $title;
    }
}
