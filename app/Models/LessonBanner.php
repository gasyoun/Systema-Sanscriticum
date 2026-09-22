<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Плашка одного занятия расписания (см. миграцию create_lesson_banners_table).
 */
class LessonBanner extends Model
{
    public const RENDERED = 'rendered';

    public const NO_TEMPLATE = 'no_template';

    public const NO_NUMBER = 'no_number';

    public const DELIVERED = 'delivered';

    public const NO_FOLDER = 'no_folder';

    public const DELIVERY_ERROR = 'error';

    /** Статусы доставки, которые n8n вправе прислать. */
    public const DELIVERY_STATUSES = [self::DELIVERED, self::NO_FOLDER, self::DELIVERY_ERROR];

    protected $fillable = [
        'schedule_id',
        'template_id',
        'render_status',
        'render_hash',
        'lesson_number',
        'image_disk',
        'image_path',
        'drive_filename',
        'rendered_at',
        'delivery_status',
        'delivery_error',
        'drive_file_id',
        'delivered_at',
    ];

    protected $casts = [
        'lesson_number' => 'integer',
        'rendered_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LessonBannerTemplate::class, 'template_id');
    }

    public function imageUrl(): ?string
    {
        if ($this->image_disk === null || $this->image_path === null) {
            return null;
        }

        return Storage::disk($this->image_disk)->url($this->image_path);
    }
}
