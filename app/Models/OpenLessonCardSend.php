<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Одна отправленная стадия карточки (announce/morning/zoom) одному чату. */
class OpenLessonCardSend extends Model
{
    public const STAGE_ANNOUNCE = 'announce';

    public const STAGE_MORNING = 'morning';

    public const STAGE_ZOOM = 'zoom';

    public const STAGES = [self::STAGE_ANNOUNCE, self::STAGE_MORNING, self::STAGE_ZOOM];

    protected $fillable = [
        'schedule_id',
        'chat_id',
        'stage',
        'message_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
