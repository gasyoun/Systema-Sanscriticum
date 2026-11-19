<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H6174: счётчики подписочной квоты школьной роли на окно (месяц) — только
 * числа и каноник-слаг роли; персональных данных в таблице нет.
 *
 * Строки создаёт только квотная машина
 * (App\Services\Support\Hermes\Quota\SchoolRoleQuotaService); роль — каноник-
 * слаг ростера, новых canonical role ID модель не вводит.
 */
class SchoolRoleQuota extends Model
{
    protected $fillable = [
        'role_id',
        'window_key',
        'routine_committed',
        'routine_reserved',
        'check_committed',
        'check_reserved',
        'reserve_committed',
        'reserve_reserved',
    ];
}
