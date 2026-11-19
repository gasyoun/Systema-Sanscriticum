<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Jobs\PublishCalendarSlotJob;
use App\Jobs\ReleaseRoutedCalendarSlotJob;
use App\Models\ContentCalendarSlot;
use App\Support\TelegramSendGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Выбор площадок для поста из TG-канала (31-10-2026). Пост вышел в
 * «Оповещении» → бот записей шлёт в служебный чат админов
 * (CONTENT_CAL_TG_ROUTING_CHAT) сообщение с кнопками-галочками площадок,
 * «Разнести» и «Только Оповещение». Пока выбор не сделан, слот не
 * разносится (meta.routing.decided = null); через tg_ingest_routing_minutes
 * без выбора ReleaseRoutedCalendarSlotJob разносит по отмеченным (по
 * умолчанию — все площадки). Отмеченные площадки — slot.destinations, их же
 * видно и правится в админке. После решения сообщение правится в итог, чтобы
 * второй админ не разнёс пост повторно.
 *
 * callback_data: ccr:t:{slot}:{key} — галочка, ccr:go:{slot} — разнести,
 * ccr:no:{slot} — только канал-источник.
 */
class CalendarIngestRouting
{
    private const PREFIX = 'ccr:';

    public static function chatId(): string
    {
        return trim((string) config('content_calendar.tg_ingest_routing_chat', ''));
    }

    public static function isPending(ContentCalendarSlot $slot): bool
    {
        $routing = $slot->meta['routing'] ?? null;

        return is_array($routing) && ($routing['decided'] ?? null) === null;
    }

    /**
     * Сообщение с выбором в служебный чат. false — чат не задан или Telegram
     * не принял сообщение: тогда пост разносится сразу, как без выбора.
     */
    public function offer(ContentCalendarSlot $slot): bool
    {
        $chat = self::chatId();
        if ($chat === '' || ! TelegramSendGuard::claimKey('content-calendar:routing-offer:'.$slot->id, 86400)) {
            return false;
        }

        $minutes = (int) config('content_calendar.tg_ingest_routing_minutes', 15);
        $slot->forceFill([
            'destinations' => array_values(array_merge([self::sourceKey()], $this->choices($slot))),
            'meta' => array_merge($slot->meta ?? [], ['routing' => [
                'chat_id' => $chat,
                'message_id' => null,
                'until' => now()->addMinutes($minutes)->toDateTimeString(),
                'decided' => null,
            ]]),
        ])->save();

        $messageId = $this->call('sendMessage', [
            'chat_id' => $chat,
            'text' => $this->offerText($slot, $minutes),
            'reply_markup' => ['inline_keyboard' => $this->keyboard($slot)],
            'disable_web_page_preview' => true,
        ]);
        if ($messageId === null) {
            $slot->forceFill(['meta' => array_diff_key($slot->meta ?? [], ['routing' => true])])->save();

            return false;
        }

        $meta = $slot->meta;
        $meta['routing']['message_id'] = $messageId;
        $slot->forceFill(['meta' => $meta])->save();

        ReleaseRoutedCalendarSlotJob::dispatch($slot->id)->delay(now()->addMinutes($minutes));

        return true;
    }

    /** Тап по кнопке. false — не наша кнопка. */
    public function handleCallback(array $callback): bool
    {
        $data = (string) ($callback['data'] ?? '');
        if (! str_starts_with($data, self::PREFIX)) {
            return false;
        }

        $callbackId = (string) ($callback['id'] ?? '');
        $parts = explode(':', $data, 4);
        $slot = ContentCalendarSlot::find((int) ($parts[2] ?? 0));
        $chat = (string) ($callback['message']['chat']['id'] ?? '');

        if ($slot === null || $chat !== (string) ($slot->meta['routing']['chat_id'] ?? '')) {
            $this->answer($callbackId, 'Пост не найден.');

            return true;
        }

        $by = self::actorName((array) ($callback['from'] ?? []));
        $answer = Cache::lock('content-calendar:routing:'.$slot->id, 10)->block(5, function () use ($slot, $parts, $by): string {
            $slot->refresh();
            if (! self::isPending($slot)) {
                return 'Уже решено.';
            }

            return match ($parts[1] ?? '') {
                't' => $this->toggle($slot, (string) ($parts[3] ?? '')),
                'go' => $this->decide($slot, 'manual', $by) ? 'Разношу' : 'Уже решено.',
                'no' => $this->decide($slot, 'none', $by) ? 'Только в канале' : 'Уже решено.',
                default => '',
            };
        });

        $this->answer($callbackId, $answer === '' ? null : $answer);

        return true;
    }

    /**
     * Решение по слоту: manual / auto — разнести по отмеченным, none — только
     * канал-источник. false — уже решено раньше.
     */
    public function decide(ContentCalendarSlot $slot, string $how, ?string $by = null): bool
    {
        if (! self::isPending($slot)) {
            return false;
        }

        $meta = $slot->meta;
        $meta['routing']['decided'] = $how;
        $meta['routing']['by'] = $by;
        $meta['routing']['decided_at'] = now()->toDateTimeString();

        $updates = ['meta' => $meta];
        if ($how === 'none') {
            $updates['destinations'] = [self::sourceKey()];
            $updates['status'] = ContentCalendarSlot::STATUS_PUBLISHED;
        }
        $slot->forceFill($updates)->save();

        $this->closeOffer($slot);

        if ($how !== 'none') {
            PublishCalendarSlotJob::dispatch($slot->id);
        }

        return true;
    }

    private function toggle(ContentCalendarSlot $slot, string $key): string
    {
        if (! in_array($key, $this->choices($slot), true)) {
            return '';
        }

        $chosen = (array) ($slot->destinations ?? []);
        $chosen = in_array($key, $chosen, true)
            ? array_values(array_diff($chosen, [$key]))
            : [...$chosen, $key];
        $slot->forceFill(['destinations' => $chosen])->save();

        $this->call('editMessageReplyMarkup', [
            'chat_id' => $slot->meta['routing']['chat_id'],
            'message_id' => $slot->meta['routing']['message_id'],
            'reply_markup' => ['inline_keyboard' => $this->keyboard($slot)],
        ]);

        return '';
    }

    /**
     * Площадки, между которыми выбирают: все, куда слот ушёл бы без выбора,
     * кроме канала-источника.
     *
     * @return list<string>
     */
    private function choices(ContentCalendarSlot $slot): array
    {
        $all = (new ContentCalendarSlot(['story_media_path' => $slot->story_media_path]))->targetDestinations();

        return array_values(array_diff($all, [self::sourceKey()]));
    }

    /** @return list<list<array{text: string, callback_data: string}>> */
    private function keyboard(ContentCalendarSlot $slot): array
    {
        $chosen = (array) ($slot->destinations ?? []);
        $buttons = array_map(fn (string $key): array => [
            'text' => (in_array($key, $chosen, true) ? '✅ ' : '⬜ ').(config("content_calendar.destinations.{$key}.label") ?? $key),
            'callback_data' => self::PREFIX."t:{$slot->id}:{$key}",
        ], $this->choices($slot));

        return [
            ...array_chunk($buttons, 2),
            [
                ['text' => '🚀 Разнести', 'callback_data' => self::PREFIX."go:{$slot->id}"],
                ['text' => '✖ Только '.self::sourceLabel(), 'callback_data' => self::PREFIX."no:{$slot->id}"],
            ],
        ];
    }

    private function offerText(ContentCalendarSlot $slot, int $minutes): string
    {
        $preview = mb_strimwidth(preg_replace('/^Из канала: /u', '', (string) $slot->title) ?? '', 0, 120, '…');

        return 'Новый пост в «'.self::sourceLabel()."» (слот #{$slot->id}):\n«{$preview}»\n\n"
            ."Куда разнести? Отметьте площадки и нажмите «Разнести». Без выбора через {$minutes} мин пост уйдёт по отмеченным.";
    }

    /** Сообщение с кнопками → итог, клавиатура снимается. */
    private function closeOffer(ContentCalendarSlot $slot): void
    {
        $routing = (array) ($slot->meta['routing'] ?? []);
        if (($routing['message_id'] ?? null) === null) {
            return;
        }

        $targets = array_values(array_diff($slot->targetDestinations(), [self::sourceKey()]));
        $where = $targets === [] ? 'только «'.self::sourceLabel().'»' : ContentCalendarSlot::destinationSummary($targets);
        $result = match ($routing['decided']) {
            'none' => 'Оставлен только в «'.self::sourceLabel().'»',
            'auto' => 'Выбора не было — разнесён: '.$where,
            default => 'Разнесён: '.$where,
        };
        $by = filled($routing['by'] ?? null) ? ' — '.$routing['by'] : '';

        $this->call('editMessageText', [
            'chat_id' => $routing['chat_id'],
            'message_id' => $routing['message_id'],
            'text' => 'Пост «'.self::sourceLabel()."», слот #{$slot->id}: {$result}{$by}.",
        ]);
    }

    private static function sourceKey(): string
    {
        return (string) config('content_calendar.tg_ingest_source');
    }

    private static function sourceLabel(): string
    {
        return (string) (config('content_calendar.channels.opoveshchenie') ?? 'Оповещение');
    }

    /** @param  array<string, mixed>  $from */
    private static function actorName(array $from): string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name !== '' ? $name : (isset($from['username']) ? '@'.$from['username'] : 'админ');
    }

    private function answer(string $callbackId, ?string $text): void
    {
        if ($callbackId === '') {
            return;
        }
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackId, 'text' => $text]));
    }

    /**
     * Вызов Bot API ботом календаря. Для sendMessage — message_id, иначе 0;
     * null — ошибка.
     *
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload): ?int
    {
        $token = CalendarTelegramPublisher::botToken();
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning("CalendarIngestRouting: {$method} не принят", ['status' => $response->status(), 'description' => $response->json('description')]);

                return null;
            }

            return (int) ($response->json('result.message_id') ?? 0);
        } catch (Throwable $e) {
            Log::warning("CalendarIngestRouting: {$method} не отправлен", ['error' => $e->getMessage()]);

            return null;
        }
    }
}
