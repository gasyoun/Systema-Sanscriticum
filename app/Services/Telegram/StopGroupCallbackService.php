<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Group;
use App\Models\MarketingSetting;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H8526: обработка тапа по кнопкам предложения останова (StopGroupIntentService).
 * callback_data: «srx:<groupId>» — серия окончена (архив), «srv:<groupId>» —
 * каникулы (пауза), «srxn» — не останавливать.
 *
 * Правила — как в AnnounceCancelCallbackService (H4519): тап валиден только
 * для учителя этой группы либо staff (H4253-правило); решение подтверждается
 * правкой сообщения с кнопкой (клавиатура уходит); повтор гасится клеймом на
 * группу на неделю. Отказ не-автору — видимая всплывшка.
 */
final class StopGroupCallbackService
{
    private const ARCHIVE_PREFIX = 'srx:';

    private const PAUSE_PREFIX = 'srv:';

    private const KEEP_PREFIX = 'srxn';

    private const CLAIM_TTL_SECONDS = 604800;

    public function handle(array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        $isArchive = str_starts_with($data, self::ARCHIVE_PREFIX);
        $isPause = str_starts_with($data, self::PAUSE_PREFIX);
        $isKeep = $data === self::KEEP_PREFIX;
        if (! $isArchive && ! $isPause && ! $isKeep) {
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
            $this->editOffer($chatId, $offerMessageId, 'Оставлено без изменений — бот продолжает работу.');
            $this->answer($callbackId, 'Оставлено');

            return true;
        }

        $groupId = (int) substr($data, strlen($isArchive ? self::ARCHIVE_PREFIX : self::PAUSE_PREFIX));

        $actor = app(TelegramCommandAcl::class)->resolve((int) $fromId);
        if ($actor === null) {
            $this->answer($callbackId, 'Недостаточно прав.');

            return true;
        }

        $group = Group::find($groupId);
        if ($group === null) {
            $this->editOffer($chatId, $offerMessageId, 'Группа не найдена.');
            $this->answer($callbackId);

            return true;
        }

        if (! TelegramCommandAcl::managesAll($actor['role'])) {
            $teacher = $actor['teacher'];
            if ($teacher === null || ! Group::ledBy($teacher->id)->whereKey($group->id)->exists()) {
                $this->answer($callbackId, 'Это не ваша группа.');

                return true;
            }
        }

        $actorLabel = $actor['role'].' user#'.$actor['user']->id;

        if ($isPause) {
            if ($group->status === Group::STATUS_ARCHIVED) {
                $this->editOffer($chatId, $offerMessageId, 'Группа уже в архиве — каникулы не нужны.');
                $this->answer($callbackId);

                return true;
            }

            if (! TelegramSendGuard::claimKey('tg:stop-pause:'.$group->id, self::CLAIM_TTL_SECONDS)) {
                $this->editOffer($chatId, $offerMessageId, 'Каникулы для этой группы уже включены.');
                $this->answer($callbackId);

                return true;
            }

            StopGroupIntentService::pauseGroup($group, $actorLabel);

            $this->editOffer($chatId, $offerMessageId, '⏸ Каникулы включены: напоминания в этой группе на паузе '
                .'до «Каникулы окончены» (или снимите флаг в панели).');
            $this->answer($callbackId, 'Каникулы включены');

            return true;
        }

        if (! TelegramSendGuard::claimKey('tg:stop-archive:'.$group->id, self::CLAIM_TTL_SECONDS)) {
            $this->editOffer($chatId, $offerMessageId, 'Группа уже остановлена.');
            $this->answer($callbackId);

            return true;
        }

        StopGroupIntentService::archiveGroup($group, 'кнопка «Серия окончена»', $actorLabel);

        $this->editOffer($chatId, $offerMessageId, '🛑 Группа архивирована: бот больше не пишет в этот чат. '
            .'Воскресение — только через MG.');
        $this->answer($callbackId, 'Остановлено');

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
            Log::warning('StopGroupIntent: offer edit failed', [
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
            Log::warning('StopGroupIntent: answerCallbackQuery failed', ['error' => $exception->getMessage()]);
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
