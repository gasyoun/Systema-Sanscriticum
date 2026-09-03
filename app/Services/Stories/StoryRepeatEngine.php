<?php

declare(strict_types=1);

namespace App\Services\Stories;

use App\Models\StoryPost;

/**
 * Repeat-движок story_posts (H3964, юнит 3).
 *
 * repeat_rule {every_days: N, times: M}: после УСПЕШНОЙ публикации строка
 * перепланирует КОПИЮ (status approved, publish_at = now + every_days)
 * пока лимит серии не исчерпан; учитывается обоими издателями — и
 * канальными текстами (stories:publish-due, Phase 1 колонки уже были в
 * миграции H3930), и сториз персоны (stories:publish-story).
 *
 * Семантика times: ОБЩЕЕ число публикаций серии, включая первую.
 * repeat_count строки после публикации — счётчик серии «сколько раз уже
 * выходило»; копия уносит счётчик с собой, поэтому серия гаснет ровно на
 * times публикациях. source_key у копии всегда null — уникальный индекс
 * (source, source_key) не должен ловить повтор с оригинала, идентичность
 * серии держится на журнальной ссылке.
 */
class StoryRepeatEngine
{
    /**
     * Вызывается издателем ПОСЛЕ пометки строки published (posted_at уже
     * стоит, repeat_count уже инкрементирован). Возвращает созданную копию
     * или null (правило отсутствует/исчерпано/невалидно).
     */
    public function afterPublication(StoryPost $post): ?StoryPost
    {
        $rule = $post->repeat_rule;
        if (! is_array($rule)) {
            return null;
        }

        $everyDays = (int) ($rule['every_days'] ?? 0);
        $times = (int) ($rule['times'] ?? 0);
        if ($everyDays < 1 || $times < 2) {
            return null;
        }

        if ((int) $post->repeat_count >= $times) {
            return null;
        }

        return StoryPost::query()->create([
            'kind' => $post->kind,
            'lane' => $post->lane,
            'payload' => $post->payload,
            'media_path' => $post->media_path,
            'source' => $post->source,
            'source_key' => null,
            'status' => StoryPost::STATUS_APPROVED,
            'publish_at' => now()->addDays($everyDays),
            'repeat_rule' => $rule,
            'repeat_count' => (int) $post->repeat_count,
            'journal' => now()->toDateTimeString()." repeat: копия #{$post->id} ("
                .$post->repeat_count."/{$times} серии), публикация через {$everyDays} дн.",
        ]);
    }

    /**
     * Отметить УСПЕШНУЮ публикацию: posted_at + инкремент счётчика серии.
     * Единственная точка правды для обоих издателей.
     */
    public function markPublished(StoryPost $post, ?string $telegramMessageId): void
    {
        $post->forceFill([
            'status' => StoryPost::STATUS_PUBLISHED,
            'posted_at' => now(),
            'repeat_count' => (int) $post->repeat_count + 1,
            'telegram_message_id' => $telegramMessageId,
        ])->save();
    }
}
