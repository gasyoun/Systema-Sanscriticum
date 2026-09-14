<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Services\Schedule\LessonSeriesInfo;
use App\Services\Schedule\ScheduleMover;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H4519: обработка тапа по кнопке предложения отмены (AnnounceCancelService).
 * callback_data: «acx:<scheduleId>» — снять, «acxn» — не отменять.
 *
 * Правила: тап валиден только для автора-учителя этой группы либо staff
 * (H4253-правило); прошедшее/уже снятое занятие отказывает; повтор гасится
 * клеймом на (schedule). Сообщение с кнопкой ПРАВИТСЯ в результат — чат
 * остаётся чистым, без мёртвых клавиатур. Отказ не-автору — видимая всплывшка.
 */
final class AnnounceCancelCallbackService
{
    private const CANCEL_PREFIX = 'acx:';

    private const KEEP_PREFIX = 'acxn';

    private const CLAIM_TTL_SECONDS = 604800;

    public function handle(array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        $isCancel = str_starts_with($data, self::CANCEL_PREFIX);
        $isKeep = $data === self::KEEP_PREFIX || str_starts_with($data, self::KEEP_PREFIX);
        if (! $isCancel && ! $isKeep) {
            return false;
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $fromId = $callback['from']['id'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? null;
        $offerMessageId = $callback['message']['message_id'] ?? null;

        if ($fromId === null || ! is_numeric($chatId) || ! is_numeric($offerMessageId)) {
            $this->answer($callbackId);

            return true;
        }

        $chatId = (string) $chatId;
        $offerMessageId = (int) $offerMessageId;

        if ($isKeep) {
            $this->editOffer($chatId, $offerMessageId, 'Оставлено в расписании.');
            $this->answer($callbackId, 'Оставлено');

            return true;
        }

        $scheduleId = (int) substr($data, strlen(self::CANCEL_PREFIX));

        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null) {
            $this->answer($callbackId, 'Недостаточно прав.');

            return true;
        }

        $schedule = Schedule::withTrashed()->find($scheduleId);
        if ($schedule === null) {
            $this->editOffer($chatId, $offerMessageId, 'Занятие не найдено.');
            $this->answer($callbackId);

            return true;
        }

        if ($schedule->trashed()) {
            $this->editOffer($chatId, $offerMessageId, 'Это занятие уже снято с расписания.');
            $this->answer($callbackId);

            return true;
        }

        $group = Group::find($schedule->group_id);
        if ($group === null) {
            $this->answer($callbackId, 'Группа занятия не найдена.');

            return true;
        }

        if (! TelegramCommandAcl::managesAll($actor['role'])) {
            $teacher = $actor['teacher'];
            if ($teacher === null || ! Group::ledBy($teacher->id)->whereKey($group->id)->exists()) {
                $this->answer($callbackId, 'Это не ваша группа.');

                return true;
            }
        }

        if ($schedule->start->isPast()) {
            $this->editOffer($chatId, $offerMessageId, 'Занятие уже началось или прошло — снятие недоступно.');
            $this->answer($callbackId, 'Уже прошло');

            return true;
        }

        if (! TelegramSendGuard::claimKey('tg:announce-cancel:'.$scheduleId, self::CLAIM_TTL_SECONDS)) {
            $this->editOffer($chatId, $offerMessageId, 'Это занятие уже снято с расписания.');
            $this->answer($callbackId);

            return true;
        }

        app(ScheduleMover::class)->cancelSingle($schedule);

        $cancelledDate = $schedule->start->format('d.m.Y');
        $reason = CancelReasonResolver::resolve($schedule);
        $next = LessonSeriesInfo::nextRowAfter((int) $group->id, now());
        $seriesEnded = $next === null;

        SendZapisiBotMessageJob::dispatch($chatId, CancelNoticeRenderer::buildDated(
            [$cancelledDate],
            [],
            $next?->start,
            $next !== null ? LessonSeriesInfo::number($next->title) : null,
            LessonSeriesInfo::totalForGroup((int) $group->id),
            LessonSeriesInfo::lastRowForGroup((int) $group->id)?->start,
            $reason,
            $seriesEnded,
        ));

        $this->editOffer($chatId, $offerMessageId, 'Занятие '.$schedule->start->format('d.m H:i').' снято с расписания.');
        $this->answer($callbackId, 'Снято');

        Log::info('AnnounceCancel: cancelled via button', [
            'chat_id' => $chatId,
            'schedule_id' => $scheduleId,
            'role' => $actor['role'],
        ]);

        return true;
    }

    /**
     * Правка сообщения-предложения: текст результата, клавиатура уходит
     * (editMessageText без reply_markup снимает inline-клавиатуру).
     */
    private function editOffer(string $chatId, int $messageId, string $text): void
    {
        $token = $this->botToken();
        if ($token === '') {
            return;
        }

        try {
            Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/editMessageText", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('AnnounceCancel: offer edit failed', [
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

        $token = $this->botToken();
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
            Log::warning('AnnounceCancel: answerCallbackQuery failed', ['error' => $exception->getMessage()]);
        }
    }

    private function botToken(): string
    {
        $zapisi = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($zapisi !== '') {
            return $zapisi;
        }

        return (string) (config('services.telegram.student_bot_token')
            ?: config('services.telegram.bot_token')
            ?: '');
    }
}
