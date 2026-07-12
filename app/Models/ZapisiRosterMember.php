<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H164 (D9): thin local cache of @zapisi_ORSbot's chat roster, synced from
 * the personal-account MadelineProto session (telegram-zapisi:sync-roster).
 * The authoritative record is the out-of-git corpus store — this table only
 * exists so the Filament dashboard tab has something to query without
 * reading the raw store on every page load.
 */
class ZapisiRosterMember extends Model
{
    protected $fillable = [
        'chat_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'is_bot',
        'synced_at',
    ];

    protected $casts = [
        'is_bot' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function displayName(): string
    {
        $name = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));

        return $name !== '' ? $name : ($this->username ?? ('id'.$this->telegram_user_id));
    }
}
