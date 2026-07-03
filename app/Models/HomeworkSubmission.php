<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class HomeworkSubmission extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_NEEDS_CHANGES = 'needs_changes';

    public const STATUS_NEEDS_REVISION = self::STATUS_NEEDS_CHANGES;

    public const LEGACY_STATUS_NEEDS_REVISION = 'needs_revision';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_LATE = 'late';

    public const STATUS_LOCKED = 'locked';

    public const GRADE_1 = '1';

    public const GRADE_2 = '2';

    public const GRADE_3 = '3';

    public const GRADE_4 = '4';

    public const GRADE_5 = '5';

    public const GRADE_5_PLUS = '5_plus';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'status',
        'last_activity_at',
        'submitted_at',
        'review_started_at',
        'reviewed_by',
        'reviewed_at',
        'accepted_at',
        'locked_at',
        'grade',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'submitted_at' => 'datetime',
        'review_started_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    /** @return array<string, string> */
    public static function gradeOptions(): array
    {
        return [
            self::GRADE_1 => '1 — плохо',
            self::GRADE_2 => '2',
            self::GRADE_3 => '3',
            self::GRADE_4 => '4',
            self::GRADE_5 => '5 — хорошо',
            self::GRADE_5_PLUS => '5+ — отлично',
        ];
    }

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

    public function studentFilesBytes(): int
    {
        if (array_key_exists('student_homework_files_size', $this->attributes)) {
            return (int) $this->attributes['student_homework_files_size'];
        }

        return (int) DB::table('homework_files')
            ->join('homework_comments', 'homework_files.comment_id', '=', 'homework_comments.id')
            ->where('homework_comments.submission_id', $this->id)
            ->where('homework_comments.author_role', HomeworkComment::ROLE_STUDENT)
            ->sum('homework_files.size');
    }

    public function studentFilesCount(): int
    {
        if (array_key_exists('student_homework_files_count', $this->attributes)) {
            return (int) $this->attributes['student_homework_files_count'];
        }

        return (int) DB::table('homework_files')
            ->join('homework_comments', 'homework_files.comment_id', '=', 'homework_comments.id')
            ->where('homework_comments.submission_id', $this->id)
            ->where('homework_comments.author_role', HomeworkComment::ROLE_STUDENT)
            ->count('homework_files.id');
    }

    public function studentFilesSizeLabel(): string
    {
        return HomeworkFile::humanSizeFor($this->studentFilesBytes());
    }

    /** Студент ещё не отправил — можно редактировать/досдавать. */
    public function isEditableByStudent(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_NEEDS_CHANGES, self::LEGACY_STATUS_NEEDS_REVISION, self::STATUS_LATE], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_SUBMITTED => 'На проверке',
            self::STATUS_UNDER_REVIEW => 'Проверяется',
            self::STATUS_NEEDS_CHANGES, self::LEGACY_STATUS_NEEDS_REVISION => 'На доработку',
            self::STATUS_ACCEPTED => 'Принято',
            self::STATUS_LATE => 'Просрочено',
            self::STATUS_LOCKED => 'Закрыто',
            default => $this->status,
        };
    }

    public function gradeLabel(): ?string
    {
        if (! $this->grade) {
            return null;
        }

        return self::gradeOptions()[$this->grade] ?? $this->grade;
    }
}
