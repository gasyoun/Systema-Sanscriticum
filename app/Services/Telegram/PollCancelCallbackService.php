<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Schedule;
use App\Services\Bot\UnblockBotCommand;
use App\Services\Schedule\LessonSeriesInfo;
use App\Services\Schedule\ScheduleMover;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Обработка тапа по кнопке под итогами автоопроса о присутствии в личке админа
 * (ZapisiPollReporter). callback_data: «pcx:<scheduleId>» — отменить, «pck» —
 * оставить.
 *
 * Отмена идёт каскадом +7 дней (ScheduleMover::cancelAndShiftWeek), как у
 * reply-команды «Отмена занятия» (H4199); в чат группы уходит подтверждение с
 * причиной «отсутствие кворума». Права — super_admin/admin/manager
 * (UnblockBotCommand::isAuthorized); повтор гасится клеймом на занятие.
 * Сообщение с кнопками ПРАВИТСЯ в результат, чтобы клавиатура не осталась
 * мёртвой после решения.
 */
final class PollCancelCallbackService
{
    public const CANCEL_PREFIX = 'pcx:';

    public const KEEP_PREFIX = 'pck';

    private const CLAIM_TTL_SECONDS = 604800;

    public function handle(array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        $isCancel = str_starts_with($data, self::CANCEL_PREFIX);
        $isKeep = $data === self::KEEP_PREFIX;
        if (! $isCancel && ! $isKeep) {
            return false;
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $fromId = $callback['from']['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? null;
        $messageId = $callback['message']['message_id'] ?? null;

        if ($isKeep) {
            $this->editMessage($chatId, $messageId, 'Занятие оставлено в расписании.');
            $this->answer($callbackId, 'Оставлено');

            return true;
        }

        $actor = is_numeric($fromId) ? TelegramCommandAcl::findUser((int) $fromId) : null;
        if (! UnblockBotCommand::isAuthorized($actor)) {
            $this->answer($callbackId, 'Недостаточно прав.');

            return true;
        }

        $scheduleId = (int) substr($data, strlen(self::CANCEL_PREFIX));
        $schedule = Schedule::withTrashed()->find($scheduleId);

        if ($schedule === null || $schedule->group_id === null || $schedule->start === null) {
            $this->editMessage($chatId, $messageId, 'Занятие не найдено.');
            $this->answer($callbackId);

            return true;
        }

        if ($schedule->trashed()) {
            $this->editMessage($chatId, $messageId, 'Это занятие уже снято с расписания.');
            $this->answer($callbackId);

            return true;
        }

        if ($schedule->start->isPast()) {
            $this->editMessage($chatId, $messageId, 'Занятие уже началось или прошло — отмена недоступна.');
            $this->answer($callbackId, 'Уже прошло');

            return true;
        }

        if (! TelegramSendGuard::claimKey('tg:poll-cancel:'.$scheduleId, self::CLAIM_TTL_SECONDS)) {
            $this->editMessage($chatId, $messageId, 'Это занятие уже отменено.');
            $this->answer($callbackId);

            return true;
        }

        $label = $this->lessonLabel($schedule);
        $number = LessonSeriesInfo::number($schedule->title);
        // Каскад кладёт эту строку на start+7д — это и есть следующее занятие.
        $nextStart = $schedule->start->copy()->addWeek();

        $shifted = app(ScheduleMover::class)->cancelAndShiftWeek($schedule);

        $chat = $schedule->group?->telegram_chat_id;
        if (is_string($chat) && $chat !== '') {
            SendZapisiBotMessageJob::dispatch($chat, CancelNoticeRenderer::build(
                $label,
                $nextStart,
                $number,
                LessonSeriesInfo::totalForGroup((int) $schedule->group_id),
                LessonSeriesInfo::lastRowForGroup((int) $schedule->group_id)?->start,
                'отсутствие кворума',
            ));
        }

        $this->editMessage($chatId, $messageId, 'Занятие '.$schedule->start->format('d.m H:i').' отменено (серия сдвинута на неделю).');
        $this->answer($callbackId, 'Отменено');

        Log::info('ZapisiPoll: lesson cancelled via result button', [
            'schedule_id' => $scheduleId,
            'telegram_user_id' => $fromId,
            'shifted' => $shifted,
        ]);

        return true;
    }

    /** Заголовок занятия без хвостовой даты «(#13, 06.09.26)» — она устареет после сдвига. */
    private function lessonLabel(Schedule $schedule): string
    {
        $title = trim((string) ($schedule->title ?: 'Занятие'));
        $stripped = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $title));

        return $stripped !== '' ? $stripped : $title;
    }

    /**
     * Правка сообщения-итогов: клавиатура уходит (editMessageText без
     * reply_markup снимает inline-клавиатуру). Сообщение слал основной бот —
     * правим им же.
     */
    private function editMessage($chatId, $messageId, string $text): void
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '' || ! is_numeric($chatId) || ! is_numeric($messageId)) {
            return;
        }

        try {
            Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/editMessageText", [
                'chat_id' => (string) $chatId,
                'message_id' => (int) $messageId,
                'text' => $text,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('PollCancelCallback: result edit failed', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function answer(string $callbackId, ?string $text = null): void
    {
        if ($callbackId === '') {
            return;
        }

        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            return;
        }

        $payload = ['callback_query_id' => $callbackId];
        if ($text !== null) {
            $payload['text'] = $text;
            $payload['show_alert'] = false;
        }

        try {
            Http::post("https://api.telegram.org/bot{$token}/answerCallbackQuery", $payload);
        } catch (\Throwable $exception) {
            Log::warning('PollCancelCallback: answerCallbackQuery failed', ['error' => $exception->getMessage()]);
        }
    }
}
