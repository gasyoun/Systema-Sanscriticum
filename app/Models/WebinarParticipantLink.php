<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H3761 — связка «экранное имя в Zoom → пользователь» внутри одного курса.
 * Заводится сопоставлением (`source = auto_name`) либо человеком (`manual`);
 * `webinar_attendances` при этом не меняется.
 */
class WebinarParticipantLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'user_id',
        'zoom_name',
        'zoom_name_key',
        'source',
        'confidence',
        'confirmed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
