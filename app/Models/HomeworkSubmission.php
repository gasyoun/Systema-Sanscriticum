<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Работы, попадающие в очередь проверяющего по гранту на группы (H1729).
     *
     * Правило: у урока проставлена группа — сверяем по ней; урок общий
     * (group_id IS NULL) — требуем, чтобы НАШЛАСЬ группа из гранта, в которой
     * состоит автор работы И к которой привязан курс работы. Без второго
     * условия проверяющий группы 60 увидел бы работы того же ученика по
     * совершенно чужому курсу: ученик может состоять в нескольких группах.
     *
     * @param  array<int>  $groupIds
     */
    public function scopeInReviewableGroups(Builder $query, array $groupIds): Builder
    {
        if ($groupIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($groupIds) {
            $q->whereHas('lesson', fn (Builder $l) => $l->whereIn('group_id', $groupIds))
                ->orWhere(function (Builder $q2) use ($groupIds) {
                    $q2->whereDoesntHave('lesson', fn (Builder $l) => $l->whereNotNull('group_id'))
                        ->whereExists(function ($sub) use ($groupIds) {
                            $sub->selectRaw('1')
                                ->from('group_user')
                                ->join('course_group', 'course_group.group_id', '=', 'group_user.group_id')
                                ->whereColumn('group_user.user_id', 'homework_submissions.user_id')
                                ->whereColumn('course_group.course_id', 'homework_submissions.course_id')
                                ->whereIn('group_user.group_id', $groupIds);
                        });
                });
        });
    }

    /**
     * Группы, к которым эта работа относится, — тем же правилом, что и скоуп
     * выше. Нужен уведомлениям: по нему собираются проверяющие-получатели.
     *
     * @return array<int>
     */
    public function reviewGroupIds(): array
    {
        if ($this->lesson?->group_id) {
            return [(int) $this->lesson->group_id];
        }

        if (! $this->user_id || ! $this->course_id) {
            return [];
        }

        return DB::table('group_user')
            ->join('course_group', 'course_group.group_id', '=', 'group_user.group_id')
            ->where('group_user.user_id', $this->user_id)
            ->where('course_group.course_id', $this->course_id)
            ->pluck('group_user.group_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
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
