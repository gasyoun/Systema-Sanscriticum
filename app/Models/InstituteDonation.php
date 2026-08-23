<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Добровольное пожертвование «Меценатам Института» (ст. 582 ГК, ИП Гасунс).
 * Вне платёжного контура доступов: успех вебхука меняет только статус этой
 * строки — ни Payment, ни групп, ни писем доступа.
 */
final class InstituteDonation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'amount',
        'status',
        'donor_name',
        'email',
        'publish_name',
        'show_amount',
        'tochka_link_id',
        'last_bank_status',
        'paid_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
    ];

    protected $attributes = [
        'publish_name' => false,
        'show_amount' => false,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'publish_name' => 'boolean',
            'show_amount' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    /** Строки публичного реестра благодарностей (N3). */
    public function scopePublicRegistry(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID)
            ->where('publish_name', true)
            ->orderByDesc('paid_at');
    }
}
