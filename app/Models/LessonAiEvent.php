<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна строка журнала ИИ-помощника занятия («Спроси Сократа»).
 *
 * Append-only: пишется только через App\Services\Knowledge\LessonAiEventLog,
 * который сам решает, можно ли сохранить текст вопроса и ответа (нужно
 * действующее согласие ai_test + отметка тестировщика). Читается страницей
 * админки «ИИ-помощник занятия» (LessonAiEventResource).
 */
class LessonAiEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_DONE = 'done';

    public const STATUS_ERROR = 'error';

    public const SOURCE_MODEL = 'model';

    public const SOURCE_CACHED = 'cached';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'task',
        'status',
        'source',
        'error_code',
        'question',
        'selection',
        'answer_md',
        'notes_chars',
        'model',
        'latency_ms',
        'tokens_in',
        'tokens_out',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'notes_chars' => 'integer',
        'latency_ms' => 'integer',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
