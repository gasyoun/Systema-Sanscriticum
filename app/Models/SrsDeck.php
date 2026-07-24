<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Колода SRS. Владелец user_id NULL = системная колода. См. миграцию srs_decks (H211).
 */
class SrsDeck extends Model
{
    protected $fillable = [
        'user_id',
        'course_id',
        'lesson_id',
        'note_type_id',
        'name',
        'slug',
        'language',
        'visibility',
        'description',
    ];

    public function noteType(): BelongsTo
    {
        return $this->belongsTo(SrsNoteType::class, 'note_type_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(SrsCard::class, 'deck_id');
    }

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'srs_deck_user', 'deck_id', 'user_id')
            ->withPivot(['new_per_day', 'direction', 'subscribed_at'])
            ->withTimestamps();
    }
}
