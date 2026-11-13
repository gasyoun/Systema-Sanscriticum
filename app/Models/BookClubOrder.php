<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заказ на доставку «Книжный клуб» (кампания planeta 219924, H8195).
 *
 * Почтовый адрес получателя + данные для ручного матчинга с экспортом кампании
 * (planeta_email + planeta_order_number ↔ fulfillment_list.csv). Деньги живут
 * в связанном Payment (tariff='book_club_delivery', course_id=null — платёж
 * НЕ выдаёт доступ к курсам, см. Payment::grantAccess).
 */
class BookClubOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'user_id',
        'planeta_email',
        'planeta_order_number',
        'recipient_name',
        'country_code',
        'country_name',
        'city',
        'postal_code',
        'address_line',
        'phone',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
