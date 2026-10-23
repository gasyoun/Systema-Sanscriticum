<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentCalendarSlot;
use App\Services\Content\PostFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * Страница-карточка слота календаря для превью ссылки в VK (og:image —
 * картинка вложения). Без подписи или без картинки — 404.
 */
class ContentCalendarShareController extends Controller
{
    public function show(int $slot, string $sig): View
    {
        abort_unless(hash_equals(ContentCalendarSlot::shareSignature($slot), $sig), 404);

        $record = ContentCalendarSlot::query()->find($slot);
        abort_unless($record !== null && $record->mediaType() === ContentCalendarSlot::MEDIA_PHOTO, 404);

        $text = trim(PostFormatter::toPlain($record->body));
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $title = Str::limit(trim((string) ($lines[0] ?? '')), 90) ?: config('app.name');
        $description = Str::limit(trim(implode(' ', array_slice($lines, 1))), 200);

        return view('content-calendar.share', [
            'title' => $title,
            'description' => $description,
            'text' => $text,
            'imageUrl' => $record->mediaUrl(),
            'pageUrl' => $record->shareUrl(),
        ]);
    }
}
