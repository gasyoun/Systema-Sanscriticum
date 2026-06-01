<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Расход на отдельный рекламный пост: бюджет за пост и сколько человек написали.
 */
class AdPostSpend extends Model
{
    use HasFactory;

    protected $fillable = [
        'posted_on',
        'title',
        'budget',
        'writers_count',
        'note',
    ];

    protected $casts = [
        'posted_on' => 'date',
        'budget' => 'decimal:2',
        'writers_count' => 'integer',
    ];

    public function costPerWriter(): ?float
    {
        $writers = (int) $this->writers_count;

        return $writers > 0 ? (float) $this->budget / $writers : null;
    }
}
