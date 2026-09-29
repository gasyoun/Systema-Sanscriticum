<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * H5134 — «Избранное» (сердечки). Личный список юзера: курс (`course_id`)
 * или карточка ждуна без карточки курса (`waitlist_slug`, напр.
 * kosmografiya). Отдельно от голоса ждуна: не создаёт WaitlistVote и
 * ничего не пишет в очередь. Auth-only (MG 17-09-2026) — гостей ведёт на вход.
 */
class CourseFavorite extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'waitlist_slug',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Ключ сердечка для разметки/JS: «c:{id}» или «w:{slug}». */
    public function heartKey(): string
    {
        return $this->course_id !== null
            ? 'c:'.$this->course_id
            : 'w:'.$this->waitlist_slug;
    }

    /**
     * Мои сердечки-ключи одним запросом: ['c:12', 'w:kosmografiya'] —
     * для отметки состояния кнопок на витрине.
     *
     * @return array<int, string>
     */
    public static function heartKeysForUser(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->get()
            ->map(fn (self $favorite) => $favorite->heartKey())
            ->all();
    }

    public function scopeOfUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCourse($query, int $courseId)
    {
        return $query->where('course_id', $courseId);
    }

    // ================= Состояние текущего зрителя (H5134) =================

    /** Мои сердечки-ключи для разметки (flag OFF / гость — пусто). */
    public static function heartKeysForCurrentViewer(): array
    {
        if (! config('features.course_favorites', false) || ! Auth::check()) {
            return [];
        }

        return self::heartKeysForUser((int) Auth::id());
    }

    /** Курсы с сердечком текущего зрителя (для каталога/Livewire). */
    public static function favoritedCourseIdsForCurrentViewer(): array
    {
        if (! config('features.course_favorites', false) || ! Auth::check()) {
            return [];
        }

        return self::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('course_id')
            ->pluck('course_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
