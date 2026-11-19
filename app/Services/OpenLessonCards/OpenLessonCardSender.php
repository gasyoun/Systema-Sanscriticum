<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\BotSubscriber;
use App\Models\OpenLessonCardSend;
use App\Models\OpenLessonRsvp;
use App\Models\Schedule;
use Illuminate\Support\Facades\Cache;

/**
 * Отправка одной стадии карточки одному чату.
 *
 * Клейм — строка open_lesson_card_sends, вставленная ДО вызова Telegram
 * (insertOrIgnore по unique schedule+chat+stage): это и есть TelegramSendGuard
 * для этой точки, только долговечный — переживает рестарт Redis и даёт журнал.
 * Отказ Telegram (4xx/5xx) — клейм снимается, ретрай безопасен; потерянный
 * ответ — клейм остаётся (дубль в личку хуже редкой потери).
 */
class OpenLessonCardSender
{
    public const SENT = 'sent';

    public const DUPLICATE = 'duplicate';

    public const BLOCKED = 'blocked';

    public const FAILED = 'failed';

    public const LOST = 'lost';

    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly OpenLessonCardBuilder $builder,
        private readonly OpenLessonOccasions $occasions,
        private readonly OpenLessonTelegram $telegram,
    ) {}

    /** @param  string  $stage  announce|morning|manual */
    public function sendCard(Schedule $schedule, string $chatId, string $stage): string
    {
        $journaled = $stage !== OpenLessonCardBuilder::STAGE_MANUAL;
        if ($journaled && ! $this->claim($schedule, $chatId, $stage)) {
            return self::DUPLICATE;
        }

        $rsvp = OpenLessonRsvp::query()->where('schedule_id', $schedule->id)->where('chat_id', $chatId)->first();
        $answer = $rsvp?->going_at !== null ? 'going' : null;

        $payload = [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $this->builder->inlineKeyboard($schedule, $answer)], JSON_UNESCAPED_UNICODE),
        ];
        $caption = $this->builder->caption($schedule, $stage === OpenLessonCardBuilder::STAGE_MANUAL ? OpenLessonCardSend::STAGE_ANNOUNCE : $stage);

        $response = $this->sendWithPhoto($schedule, $payload, $caption);

        return $this->settle($response, $schedule, $chatId, $stage, $journaled, function () use ($chatId): void {
            $this->ensureMenu($chatId);
        });
    }

    /** Ссылка на подключение тому, кто нажал «Я иду». Один раз на чат. */
    public function sendZoom(Schedule $schedule, string $chatId): string
    {
        $rsvp = OpenLessonRsvp::query()->where('schedule_id', $schedule->id)->where('chat_id', $chatId)->first();
        if ($rsvp === null || $rsvp->going_at === null || $rsvp->zoom_sent_at !== null) {
            return self::SKIPPED;
        }

        $url = $this->occasions->joinUrl($schedule);
        $streams = $this->occasions->streamLinks($schedule);
        if ($url === null && $streams === []) {
            return self::SKIPPED;
        }

        // Zoom — отдельной строкой сверху, трансляции — рядом под ним.
        $keyboard = [];
        if ($url !== null) {
            $keyboard[] = [['text' => '▶️ Подключиться в Zoom', 'url' => $url]];
        }
        if ($streams !== []) {
            $keyboard[] = array_map(fn (array $s): array => ['text' => $s['label'], 'url' => $s['url']], $streams);
        }

        if (! $this->claim($schedule, $chatId, OpenLessonCardSend::STAGE_ZOOM)) {
            return self::DUPLICATE;
        }

        $response = $this->telegram->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $this->builder->zoomText($schedule, $url !== null, $streams !== []),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        ]);

        return $this->settle($response, $schedule, $chatId, OpenLessonCardSend::STAGE_ZOOM, true, function () use ($rsvp): void {
            $rsvp->update(['zoom_sent_at' => now()]);
        });
    }

    /** Нижнее меню — один раз на чат (Telegram держит его, пока не сменят). */
    public function ensureMenu(string $chatId): void
    {
        $subscriber = BotSubscriber::query()->firstOrCreate(
            ['chat_id' => $chatId],
            ['source' => BotSubscriber::SOURCE_START],
        );
        if ($subscriber->menu_sent_at !== null || $subscriber->blocked_at !== null) {
            return;
        }

        // Ставим отметку до отправки: два параллельных джоба не пришлют меню дважды.
        $claimed = BotSubscriber::query()->whereKey($subscriber->id)->whereNull('menu_sent_at')->update(['menu_sent_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $response = $this->telegram->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Анонсы открытых занятий будут приходить сюда. Меню бота — внизу 👇',
            'reply_markup' => json_encode($this->builder->replyMenu(), JSON_UNESCAPED_UNICODE),
        ]);

        if ($response['status'] === OpenLessonTelegram::REFUSED) {
            $subscriber->update(['menu_sent_at' => null]);
        }
        if ($response['status'] === OpenLessonTelegram::BLOCKED) {
            $this->markBlocked($chatId);
        }
    }

    public function markBlocked(string $chatId): void
    {
        BotSubscriber::query()->updateOrCreate(
            ['chat_id' => $chatId],
            ['blocked_at' => now()],
        );
    }

    private function claim(Schedule $schedule, string $chatId, string $stage): bool
    {
        return OpenLessonCardSend::query()->insertOrIgnore([
            'schedule_id' => $schedule->id,
            'chat_id' => $chatId,
            'stage' => $stage,
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, result: array<string, mixed>, description: ?string}
     */
    private function sendWithPhoto(Schedule $schedule, array $payload, string $caption): array
    {
        $photo = $this->builder->photoFor($schedule);
        if ($photo === null) {
            return $this->telegram->call('sendMessage', $payload + ['text' => $caption, 'disable_web_page_preview' => true]);
        }

        // Картинка грузится один раз, дальше — file_id Telegram из кэша.
        $fileId = Cache::get($photo['key']);
        if (is_string($fileId) && $fileId !== '') {
            $response = $this->telegram->call('sendPhoto', $payload + ['photo' => $fileId, 'caption' => $caption]);
            if ($response['status'] !== OpenLessonTelegram::REFUSED) {
                return $response;
            }
            Cache::forget($photo['key']);
        }

        $response = $this->telegram->call('sendPhoto', $payload + ['caption' => $caption], $photo['file']);

        $sizes = $response['result']['photo'] ?? [];
        $uploaded = is_array($sizes) && $sizes !== [] ? (end($sizes)['file_id'] ?? null) : null;
        if (is_string($uploaded) && $uploaded !== '') {
            Cache::forever($photo['key'], $uploaded);
        }

        return $response;
    }

    /** @param  array{status: string, result: array<string, mixed>, description: ?string}  $response */
    private function settle(array $response, Schedule $schedule, string $chatId, string $stage, bool $journaled, callable $onSent): string
    {
        $journal = fn () => OpenLessonCardSend::query()
            ->where('schedule_id', $schedule->id)
            ->where('chat_id', $chatId)
            ->where('stage', $stage);

        switch ($response['status']) {
            case OpenLessonTelegram::OK:
                if ($journaled) {
                    $journal()->update([
                        'message_id' => $response['result']['message_id'] ?? null,
                        'sent_at' => now(),
                    ]);
                }
                $onSent();

                return self::SENT;

            case OpenLessonTelegram::BLOCKED:
                $this->markBlocked($chatId);

                return self::BLOCKED;

            case OpenLessonTelegram::REFUSED:
                if ($journaled) {
                    $journal()->delete();
                }

                return self::FAILED;

            default:
                return self::LOST;
        }
    }
}
