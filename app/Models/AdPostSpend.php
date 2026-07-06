<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Расход на отдельный рекламный пост: бюджет за пост и сколько человек написали.
 */
class AdPostSpend extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'posted_on',
        'title',
        'budget',
        'writers_count',
        'note',
        'is_locked',
    ];

    protected $casts = [
        'posted_on' => 'date',
        'budget' => 'decimal:2',
        'writers_count' => 'integer',
        'is_locked' => 'boolean',
    ];

    public function costPerWriter(): ?float
    {
        $writers = (int) $this->writers_count;

        return $writers > 0 ? (float) $this->budget / $writers : null;
    }
}
