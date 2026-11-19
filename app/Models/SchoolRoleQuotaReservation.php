<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H6174: резервация токенов подписочной квоты — audit-trail concurrent
 * reservation/release/commit. Один request_id = один side-effect (уник-индекс,
 * повтор события не удваивает расход).
 *
 * Поля — только счётчики/идентификаторы (role_id, window_key, lane, units,
 * status, consumed_units, request_id); текстов полей нет, ПД не хранятся.
 */
class SchoolRoleQuotaReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'request_id',
        'role_id',
        'window_key',
        'lane',
        'is_experiment',
        'units',
        'status',
        'consumed_units',
    ];

    protected $casts = [
        'is_experiment' => 'boolean',
    ];
}
