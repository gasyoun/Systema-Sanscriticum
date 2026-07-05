<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Хай-вотер-марка reminders:detect-requests на источник, чтобы не пересканировать
 * всю историю ChatMessage/TelegramSupportMessage на каждый 15-минутный прогон.
 */
class ReminderDetectionCursor extends Model
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
