<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisualDcsRelease extends Model
{
    protected $table = 'visualdcs_releases';

    public const STATUS_STAGED = 'staged';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'release_id',
        'contract_version',
        'manifest_hash',
        'status',
        'storage_path',
        'promoted_at',
        'rolled_back_at',
    ];

    protected $casts = [
        'promoted_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function scopePromoted($query)
    {
        return $query->where('status', self::STATUS_PROMOTED);
    }
}
