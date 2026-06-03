<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomeworkSubmission extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_NEEDS_REVISION = 'needs_revision';

    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'status',
        'last_activity_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(HomeworkComment::class, 'submission_id')->orderBy('created_at');
    }

    /** Студент ещё не отправил — можно редактировать/досдавать. */
    public function isEditableByStudent(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_NEEDS_REVISION], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_SUBMITTED => 'На проверке',
            self::STATUS_NEEDS_REVISION => 'На доработку',
            self::STATUS_ACCEPTED => 'Принято',
            default => $this->status,
        };
    }
}
