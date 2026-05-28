<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Разовый доступ к отдельному уроку — поверх обычной модели «купил блок/курс».
 * Нужен для сценария «студент пропустил первое бесплатное занятие и хочет попасть
 * на 2-е занятие 3-го блока, не оплачивая весь блок». Активность гранта
 * определяется не статусом, а датой `revoked_at` (NULL = активен) и опциональным
 * `expires_at` (если не NULL — должен быть в будущем).
 */
class LessonAccessGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'course_id',
        'reason',
        'granted_by',
        'granted_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Есть ли у пользователя активный grant именно на этот урок.
     * Используется в проверках доступа в StudentController.
     */
    public static function userCanWatch(User $user, Lesson $lesson): bool
    {
        return self::query()
            ->where('user_id', $user->id)
            ->where('lesson_id', $lesson->id)
            ->active()
            ->exists();
    }

    /**
     * @return list<int>
     */
    public static function userGrantedLessonIds(User $user, int $courseId): array
    {
        return self::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->active()
            ->pluck('lesson_id')
            ->all();
    }
}
