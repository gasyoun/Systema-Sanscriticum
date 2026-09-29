<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TeacherPayoutEvidenceLink extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['transfer_id', 'teacher_payout_id', 'confirmed_by'];
}
