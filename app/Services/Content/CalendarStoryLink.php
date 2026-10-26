<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;

/**
 * Куда ведёт сторис слота: первая ссылка samskrte.ru из текста поста (обычно
 * трекинговая /ga/…), иначе — вышедший пост в публичном канале «Оповещение».
 */
final class CalendarStoryLink
{
    public static function for(ContentCalendarSlot $slot): ?string
    {
        if (preg_match('~https://samskrte\.ru/[^\s<>"()]+~u', PostFormatter::toPlain($slot->body), $match) === 1) {
            return rtrim($match[0], '.,;:!?»');
        }

        $username = ltrim((string) config('content_calendar.tg_public_username'), '@');
        $post = ContentCalendarPublication::query()
            ->where('content_calendar_slot_id', $slot->id)
            ->where('destination', (string) config('content_calendar.tg_ingest_source', 'tg_opoveshchenie'))
            ->where('status', ContentCalendarPublication::STATUS_PUBLISHED)
            ->value('external_id');

        return $username !== '' && filled($post) ? "https://t.me/{$username}/{$post}" : null;
    }
}
