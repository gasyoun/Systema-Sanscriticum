<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Models\StoryPost;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Stories\StoryRepeatEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Издатель очереди story_posts в канал @rusamskrtam (H3930, Phase 1).
 *
 * Только текстовые строки (kind=text) КАНАЛЬНОЙ полосы (lane=channel,
 * H3964: persona-полоса принадлежит stories:publish-story): Bot API
 * sendMessage магнит-ботом (MarketingSetting.tg_bot_token —
 * марафон-издатель; НЕ кабинетный бот и НЕ zapisi-бот, FINDINGS §651).
 * Перед первой реальной отправкой прогона — дешёвая getChat-проба пары
 * «токен × chat_id»: «chat not found» на предположенном креденшале —
 * сигнал, а не повод для ретрая.
 *
 * Photo/video строки (persona-полоса, MTProto stories) скипаются с
 * журналом — издаваться молча не должны.
 *
 * Прод-инертен: features.telegram_story_publisher OFF (default) → ранний
 * возврат, ноль HTTP (та же форма флаг-гейта, что content:publish-due).
 */
final class StoriesPublishDueCommand extends Command
{
    protected $signature = 'stories:publish-due';

    protected $description = 'Publish due approved story_posts (text) to the @rusamskrtam channel';

    public function handle(TelegramDeliveryChannel $telegram, StoryRepeatEngine $repeat): int
    {
        if (! config('features.telegram_story_publisher')) {
            $this->warn('telegram_story_publisher flag is OFF — no-op.');

            return self::SUCCESS;
        }

        $chatId = (string) config('services.telegram_story.channel_chat_id', '');
        if ($chatId === '') {
            $this->error('services.telegram_story.channel_chat_id is empty (set TELEGRAM_STORY_CHANNEL_CHAT_ID).');

            return self::FAILURE;
        }

        $token = (string) (MarketingSetting::cached()?->tg_bot_token ?? '');
        if ($token === '') {
            $this->error('MarketingSetting.tg_bot_token is empty — magnet bot credential missing (FINDINGS §651).');

            return self::FAILURE;
        }

        // FINDINGS §651: getChat-проба креденшала перед первой отправкой.
        $probe = Http::get("https://api.telegram.org/bot{$token}/getChat", ['chat_id' => $chatId]);
        if (! $probe->successful() || $probe->json('ok') !== true) {
            $this->error('getChat probe failed for the assumed credential — refusing to post. '
                .'Typical causes: magnet bot not admin of the channel; wrong TELEGRAM_STORY_CHANNEL_CHAT_ID. '
                .'Response: '.mb_substr((string) $probe->body(), 0, 200));

            return self::FAILURE;
        }

        $due = StoryPost::query()
            ->approved()
            ->due()
            ->lane(StoryPost::LANE_CHANNEL)
            ->orderBy('publish_at')
            ->get();

        $published = 0;
        $skipped = 0;
        foreach ($due as $post) {
            if ($post->kind !== StoryPost::KIND_TEXT) {
                $post->forceFill([
                    'journal' => trim((string) $post->journal."\n".now()->toDateTimeString()
                        .' skip: kind='.$post->kind.' — это полоса persona (MTProto stories lane).'),
                ])->save();
                $skipped++;
                $this->warn("Skip #{$post->id}: kind={$post->kind} — persona lane (MTProto stories).");

                continue;
            }

            try {
                $telegram->sendMessage($chatId, $this->toTelegramHtml((string) $post->payload));
            } catch (Throwable $e) {
                $this->error("Failed #{$post->id}: ".$e->getMessage());

                return self::FAILURE;
            }

            $repeat->markPublished($post, null);
            $copy = $repeat->afterPublication($post);
            $published++;
            $this->info("Published #{$post->id} (source={$post->source}, key={$post->source_key})."
                .($copy !== null ? " + repeat copy #{$copy->id} due {$copy->publish_at?->format('d-m-Y H:i')}" : ''));
        }

        $this->info("publish-due: published={$published}, skipped_media={$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Очередь — чистый текст с эмодзи; экранируем под parse_mode=HTML
     * TelegramDeliveryChannel, не превращая переносы в <br> (как у
     * марафон-издателя).
     */
    private function toTelegramHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
