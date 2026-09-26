<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

/**
 * Отзыв из общей библиотеки. Привязывается к курсам через пивот
 * course_testimonial (с порядком показа sort_order).
 *
 * Отзыв, который студент прислал сам (/dvaram/otzyv), создаётся в статусе
 * pending и скрытым; в пул (вход, /otzyvy, курсы) он попадает только через
 * approve() в админке. Публичные выборки гейтятся is_visible — pending и
 * rejected всегда скрыты.
 */
class Testimonial extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'author_name',
        'city',
        'avatar_path',
        'body',
        'rating',
        'media_url',
        'video_path',
        'is_visible',
        'is_featured',
        'reviewed_at',
        'show_on_login',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_visible' => 'boolean',
        'is_featured' => 'boolean',
        'reviewed_at' => 'date',
        'show_on_login' => 'boolean',
        'publish_consent_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Модератор включил «Показывать» в форме, минуя кнопку «Одобрить», —
        // значит, одобрил: иначе отзыв висел бы в счётчике модерации, уже будучи в пуле.
        static::saving(function (Testimonial $t): void {
            if ($t->is_visible && $t->moderation_status === self::STATUS_PENDING) {
                $t->moderation_status = self::STATUS_APPROVED;
            }
        });
    }

    /** Отзывы для общесайтовой полосы на витрине каталога (H323). */
    public function scopeFeatured($query)
    {
        return $query->where('is_visible', true)->where('is_featured', true);
    }

    /** Присланные студентами и ещё не разобранные модератором. */
    public function scopePending($query)
    {
        return $query->where('moderation_status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->moderation_status === self::STATUS_PENDING;
    }

    /** Загруженный файлом видео-отзыв (диск public) — или null. */
    public function videoUrl(): ?string
    {
        return filled($this->video_path) ? Storage::disk('public')->url($this->video_path) : null;
    }

    /**
     * Куда ведёт «Смотреть/слушать отзыв» на сайте: загруженное видео важнее
     * внешней ссылки (своё не пропадёт, если у ролика на VK закроют доступ).
     */
    public function mediaLink(): ?string
    {
        return $this->videoUrl() ?? (filled($this->media_url) ? $this->media_url : null);
    }

    /**
     * Одобрить: отзыв уходит в общий пул — на страницу входа и в /otzyvy.
     * Дата на карточке — день отправки, если модератор не поставил свою.
     */
    public function approve(): void
    {
        $this->moderation_status = self::STATUS_APPROVED;
        $this->is_visible = true;
        $this->show_on_login = true;
        if ($this->reviewed_at === null && $this->submitted_at !== null) {
            $this->reviewed_at = $this->submitted_at->toDateString();
        }
        $this->save();
    }

    public function reject(): void
    {
        $this->moderation_status = self::STATUS_REJECTED;
        $this->is_visible = false;
        $this->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_testimonial')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
