<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;

/**
 * Куда ведёт сторис слота: на вышедший пост в публичном канале «Оповещение»
 * (решение 26-10-2026). Пост ещё не вышел или в «Оповещение» не шёл — null.
 */
final class CalendarStoryLink
{
    public static function for(ContentCalendarSlot $slot): ?string
    {
        $username = ltrim((string) config('content_calendar.tg_public_username'), '@');
        $post = ContentCalendarPublication::query()
            ->where('content_calendar_slot_id', $slot->id)
            ->where('destination', (string) config('content_calendar.tg_ingest_source', 'tg_opoveshchenie'))
            ->where('status', ContentCalendarPublication::STATUS_PUBLISHED)
            ->value('external_id');

        return $username !== '' && filled($post) ? "https://t.me/{$username}/{$post}" : null;
    }
}
