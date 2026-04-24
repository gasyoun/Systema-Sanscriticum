<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    use HasFactory;

    // Отключаем updated_at — сырые события неизменяемы (append-only)
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'session_id',
        'event_type',
        'event_data',
        'url',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'event_data' => 'array', // JSON поле → массив в PHP
        'created_at' => 'datetime',
    ];

    /**
     * Константы типов событий.
     * Используем константы вместо "магических строк" — чтобы IDE автокомплит ловил
     * и при рефакторинге можно было легко найти все использования.
     */
    public const TYPE_LOGIN             = 'login';
    public const TYPE_LOGOUT            = 'logout';
    public const TYPE_LESSON_OPEN       = 'lesson_open';
    public const TYPE_LESSON_COMPLETE   = 'lesson_complete';
    public const TYPE_NOTE_SAVED        = 'note_saved';
    public const TYPE_MATERIAL_DOWNLOAD = 'material_download';
    public const TYPE_COURSE_VIEW       = 'course_view';
    public const TYPE_SESSION_TIMEOUT   = 'session_timeout';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'session_id');
    }
}