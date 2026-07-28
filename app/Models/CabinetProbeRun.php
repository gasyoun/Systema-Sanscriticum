<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * История прогонов cabinet:probe (H1794).
 *
 * @property int $id
 * @property Carbon $ran_at
 * @property bool $healthy
 * @property bool $critical
 * @property int $duration_ms
 * @property int $failure_count
 * @property array<int, string>|null $failures
 * @property string|null $summary
 */
class CabinetProbeRun extends Model
{
    protected $fillable = [
        'ran_at',
        'healthy',
        'critical',
        'duration_ms',
        'failure_count',
        'failures',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'healthy' => 'boolean',
            'critical' => 'boolean',
            'failures' => 'array',
        ];
    }
}
