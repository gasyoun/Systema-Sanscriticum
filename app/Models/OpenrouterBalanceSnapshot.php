<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per day: OpenRouter lifetime totals from GET /api/v1/credits.
 * total_usage grows monotonically — day-over-day deltas are the spend.
 */
class OpenrouterBalanceSnapshot extends Model
{
    protected $table = 'openrouter_balance_snapshots';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_credits' => 'decimal:2',
        'total_usage' => 'decimal:2',
    ];
}
