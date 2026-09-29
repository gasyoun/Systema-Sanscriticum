<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * H5049 R11: каталог архивных ассетов (посты/сториз) с хэшами и текстом —
 * поиск старого вечнозелёного ассета без повторного скана Telegram-истории.
 *
 * @property string $platform
 * @property string $account
 * @property string $remote_id
 * @property string|null $media_hash
 * @property string|null $phash
 * @property array<string, int>|null $dimensions
 * @property string|null $text
 * @property array<int, string>|null $tags
 * @property string|null $destination_url
 * @property Carbon|null $expires_at
 * @property array<int, string>|null $usage_history
 * @property string|null $media_path
 */
class AnonsArchiveItem extends Model
{
    protected $fillable = [
        'platform', 'account', 'remote_id', 'captured_at', 'media_hash',
        'phash', 'dimensions', 'text', 'tags', 'destination_url',
        'expires_at', 'usage_history', 'media_path',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'expires_at' => 'datetime',
        'dimensions' => 'array',
        'tags' => 'array',
        'usage_history' => 'array',
    ];
}
