<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * H1449 W1b — one рассылка of the homegrown campaign engine. All-OFF unless
 * `email_campaigns` (config/features.php) is enabled.
 */
class Campaign extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    protected $fillable = [
        'subject',
        'body_html',
        'segment',
        'status',
        'sent_at',
    ];

    protected $casts = [
        'segment' => 'array',
        'sent_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function openedCount(): int
    {
        return $this->recipients()->whereNotNull('opened_at')->count();
    }

    public function clickedCount(): int
    {
        return $this->recipients()->whereNotNull('clicked_at')->count();
    }
}
