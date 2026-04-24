<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'link',
        'start',
        'end',
        'color',
        'group_id',
        'course_id',
    ];

    protected $casts = [
        'start' => 'datetime',
        'end'   => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Умная отдача ссылки: сначала колонка `link`,
     * затем fallback на парсинг из description (для старых записей).
     */
    protected function link(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if (!empty($value)) {
                    return $value;
                }

                if (empty($this->attributes['description'])) {
                    return null;
                }

                // Ищем первую http(s)-ссылку в описании
                if (preg_match('~https?://[^\s<>"]+~iu', $this->attributes['description'], $matches)) {
                    return $matches[0];
                }

                return null;
            }
        );
    }

    /**
     * Признак, что событие идёт прямо сейчас (нужно для бейджа LIVE).
     */
    public function isLive(): bool
    {
        $now = now();
        $end = $this->end ?? $this->start->copy()->addHours(2);

        return $now->between($this->start, $end);
    }
}