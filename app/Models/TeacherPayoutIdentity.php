<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TeacherPayoutIdentity extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = ['teacher_id', 'provider', 'identity_type', 'identity_hmac', 'display_tail', 'valid_from', 'valid_to', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date', 'confirmed_at' => 'datetime'];
}
