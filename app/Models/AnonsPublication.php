<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H5049: одна декларативная заявка на публикацию. publication_key
 * уникален — повторный прогон того же манифеста резолвится сюда и
 * никогда не дублирует публикацию.
 *
 * @property string $publication_key
 * @property string $campaign_id
 * @property string $creative_id
 * @property string $slot
 * @property string $manifest_hash
 * @property array<string, mixed> $manifest
 * @property bool $test_mode
 * @property int $frame_total
 * @property string $status
 */
class AnonsPublication extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'publication_key', 'campaign_id', 'creative_id', 'slot',
        'manifest_hash', 'manifest', 'test_mode', 'frame_total',
        'status', 'journal',
    ];

    protected $casts = [
        'manifest' => 'array',
        'test_mode' => 'boolean',
        'frame_total' => 'integer',
    ];

    /** @return HasMany<AnonsDestinationRun> */
    public function runs()
    {
        return $this->hasMany(AnonsDestinationRun::class, 'anons_publication_id');
    }
}
