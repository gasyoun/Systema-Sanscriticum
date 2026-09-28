<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TeacherTransferMatch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['transfer_id', 'teacher_id', 'identity_id', 'match_basis', 'confirmed_by'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(TochkaOutgoingTransfer::class, 'transfer_id');
    }
}
