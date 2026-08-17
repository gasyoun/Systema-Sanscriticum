<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PrivateArchiveControl extends Model
{
    protected $primaryKey = 'archive_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['archive_key', 'disabled_at', 'reason'];

    protected $casts = ['disabled_at' => 'datetime'];

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
