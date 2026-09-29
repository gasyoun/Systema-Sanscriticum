<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H5049 R7: наблюдение метрики с ЯВНЫМ состоянием отсутствия.
 * state=value — число; unavailable/not_supported/pending/failed —
 * причинное отсутствие, НИКОГДА не ноль.
 *
 * @property string $publication_key
 * @property string $destination
 * @property string $metric
 * @property string $state
 * @property int|null $value
 * @property array<string, string>|null $utm
 */
class AnonsMetric extends Model
{
    public const STATE_VALUE = 'value';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_NOT_SUPPORTED = 'not_supported';

    public const STATE_PENDING = 'pending';

    public const STATE_FAILED = 'failed';

    public const METRIC_VIEWS = 'views';

    public const METRIC_REACTIONS = 'reactions';

    public const METRIC_FORWARDS = 'forwards';

    public const METRIC_LINK_CLICKS = 'link_clicks';

    protected $fillable = [
        'publication_key', 'destination', 'metric', 'state',
        'value', 'utm', 'observed_at',
    ];

    protected $casts = [
        'value' => 'integer',
        'utm' => 'array',
        'observed_at' => 'datetime',
    ];
}
