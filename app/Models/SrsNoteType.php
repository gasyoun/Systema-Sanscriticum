<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тип карточки SRS: набор полей + язык. См. миграцию srs_note_types (H211).
 */
class SrsNoteType extends Model
{
    protected $fillable = [
        'key',
        'name',
        'language',
        'fields',
    ];

    protected $casts = [
        'fields' => 'array',
    ];

    public function decks(): HasMany
    {
        return $this->hasMany(SrsDeck::class, 'note_type_id');
    }
}
