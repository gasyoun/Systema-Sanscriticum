<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * High-water mark for promises:detect-deferrals per source type
 * (chat_message / telegram_support_message). Twin of ReminderDetectionCursor.
 */
class PromiseSuggestionDetectionCursor extends Model
{
    protected $fillable = [
        'source_type',
        'last_source_id',
    ];

    public static function lastId(string $sourceType): int
    {
        return (int) (static::query()->where('source_type', $sourceType)->value('last_source_id') ?? 0);
    }

    public static function advance(string $sourceType, int $id): void
    {
        static::query()->updateOrCreate(
            ['source_type' => $sourceType],
            ['last_source_id' => $id],
        );
    }
}
