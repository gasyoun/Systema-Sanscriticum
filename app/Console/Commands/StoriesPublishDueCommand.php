<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Models\StoryPost;
use App\Services\Content\ContentProhibitionsChecklist;
use App\Services\Messaging\TelegramDeliveryChannel;
use App\Services\Stories\StoryRepeatEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Издатель очереди story_posts в канал (H3930, Phase 1).
 *
 * Текстовые (kind=text) и видео (kind=video, H6915) строки КАНАЛЬНОЙ
 * полосы (lane=channel): Bot API магнит-ботом (MarketingSetting.tg_bot_token
 * — марафон-издатель; НЕ кабинетный бот и НЕ zapisi-бот, FINDINGS §651).
 * Видео — sendVideo с подписью parse_mode=HTML; лимит Bot API 50 МБ и
 * отсутствие файла — понятный отказ в журнале, слот НЕ закрывается
 * (статус не published, повторный прогон дубля не создаёт — published
 * строки выходят из approved+due области).
 * Перед первой реальной отправкой прогона — дешевая getChat-проба пары
 * «токен × chat_id»: «chat not found» на предположенном креденшале —
 * сигнал, а не повод для ретрая.
 *
 * Фото-строки (persona-полоса, MTProto stories) скипаются с журналом —
 * издаваться молча не должны.
 *
 * Прод-инертен: features.telegram_story_publisher OFF (default) → ранний
 * возврат, ноль HTTP (та же форма флаг-гейта, что content:publish-due).
 */
final class StoriesPublishDueCommand extends Command
{
    /** Bot API: максимальный размер файла при стандартной multipart-загрузке. */
    private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    protected $signature = 'stories:publish-due';

    protected $description = 'Publish due approved story_posts (text, video) to the channel';

    public function handle(TelegramDeliveryChannel $telegram, StoryRepeatEngine $repeat, ContentProhibitionsChecklist $checklist): int
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
        $held = 0;
        $refused = 0;
        foreach ($due as $post) {
            if ($post->kind === StoryPost::KIND_VIDEO) {
                $receipt = $this->publishVideo($telegram, $token, $chatId, $post);
                if ($receipt === null) {
                    $refused++;

                    continue; // отказ в журнале, слот не закрыт — ретрай уместен
                }

                $repeat->markPublished($post, $receipt['message_id']);
                $copy = $repeat->afterPublication($post);
                $published++;
                $this->info("Published video #{$post->id} (source={$post->source}, key={$post->source_key}) "
                    ."message_id={$receipt['message_id']} permalink={$receipt['permalink']}."
                    .($copy !== null ? " + repeat copy #{$copy->id} due {$copy->publish_at?->format('d-m-Y H:i')}" : ''));

                continue;
            }

            if ($post->kind !== StoryPost::KIND_TEXT) {
                $post->forceFill([
                    'journal' => trim((string) $post->journal."\n".now()->toDateTimeString()
                        .' skip: kind='.$post->kind.' — это полоса persona (MTProto stories lane).'),
                ])->save();
                $skipped++;
                $this->warn("Skip #{$post->id}: kind={$post->kind} — persona lane (MTProto stories).");

                continue;
            }

            // H5020 pre-send checklist (§2.8 ratified 16-09-2026): a blocked
            // post goes back to draft with the reason journaled — never sent.
            $verdict = $checklist->check((string) $post->payload);
            if (! $verdict['ok']) {
                $post->forceFill([
                    'status' => StoryPost::STATUS_DRAFT,
                    'journal' => trim((string) $post->journal."\n".now()->toDateTimeString().' '.$checklist->journalLine($verdict)),
                ])->save();
                $held++;
                $this->warn("Hold #{$post->id}: ".$checklist->journalLine($verdict));

                continue;
            }
            if ($verdict['warns'] !== []) {
                $post->forceFill([
                    'journal' => trim((string) $post->journal."\n".now()->toDateTimeString().' prohibition-warn: '
                        .implode('; ', array_map(static fn (array $w): string => $w['rule'].' («'.$w['match'].'»)', $verdict['warns']))),
                ])->save();
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

        $this->info("publish-due: published={$published}, skipped_media={$skipped}, held_prohibition={$held}, refused_video={$refused}.");

        return self::SUCCESS;
    }

    /**
     * H6915: канальный видео-пост — sendVideo магнит-бота, подпись
     * parse_mode=HTML (как у текстового лейна). Квитанция message_id
     * + permalink возвращаются издателю; отказ выше лимита 50 МБ или
     * с отсутствующим файлом пишется в журнал, слот НЕ закрывается
     * (null → строка остаётся approved, дубля нет).
     *
     * @return array{message_id: string, permalink: string}|null
     */
    private function publishVideo(TelegramDeliveryChannel $telegram, string $token, string $chatId, StoryPost $post): ?array
    {
        $journal = (string) $post->journal;
        $media = (string) ($post->media_path ?? '');
        if ($media === '' || ! is_file($media) || ! is_readable($media)) {
            $post->forceFill(['journal' => trim($journal."\n".now()->toDateTimeString()
                ." video-refused: media_path missing/unreadable: {$media}")])->save();
            $this->warn("Refused #{$post->id}: video media missing/unreadable: {$media}");

            return null;
        }
        $bytes = (int) filesize($media);
        if ($bytes > self::MAX_VIDEO_BYTES) {
            $note = sprintf(
                'video-refused: %.1f MB exceeds the Bot API 50MB upload limit — re-encode or trim the fragment, slot stays open.',
                $bytes / 1024 / 1024
            );
            $post->forceFill(['journal' => trim($journal."\n".now()->toDateTimeString().' '.$note)])->save();
            $this->warn("Refused #{$post->id}: {$note}");

            return null;
        }

        $handle = fopen($media, 'rb');
        if ($handle === false) {
            $post->forceFill(['journal' => trim($journal."\n".now()->toDateTimeString()
                ." video-refused: cannot open {$media}")])->save();

            return null;
        }

        try {
            $response = Http::asMultipart()->post("https://api.telegram.org/bot{$token}/sendVideo", [
                ['name' => 'chat_id', 'contents' => $chatId],
                ['name' => 'caption', 'contents' => $this->toTelegramHtml((string) $post->payload)],
                ['name' => 'parse_mode', 'contents' => 'HTML'],
                ['name' => 'video', 'contents' => $handle],
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $messageId = (string) ($response->json('result.message_id') ?? '');
        if ($messageId === '') {
            $note = 'video-refused: sendVideo returned no message_id: '.mb_substr((string) $response->body(), 0, 200);
            $post->forceFill(['journal' => trim((string) $post->journal."\n".now()->toDateTimeString().' '.$note)])->save();
            $this->error("Failed #{$post->id}: {$note}");

            return null;
        }

        $permalink = $this->permalink($chatId, $messageId);
        $post->forceFill(['journal' => trim((string) $post->journal."\n".now()->toDateTimeString()
            ." video-published: message_id={$messageId} permalink={$permalink}")])->save();

        return ['message_id' => $messageId, 'permalink' => $permalink];
    }

    /** permalink канального поста: t.me работает только с @username-чатом. */
    private function permalink(string $chatId, string $messageId): string
    {
        if (str_starts_with($chatId, '@')) {
            return 'https://t.me/'.mb_substr($chatId, 1).'/'.$messageId;
        }

        return '(numeric chat_id — permalink unavailable, message_id only)';
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
