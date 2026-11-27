<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\BookClubOrderShippedMail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Mail;

/**
 * Заказ «Книжного клуба» (H8195 доставка · H8412 магазин).
 *
 * Одна таблица на обе витрины: mode=delivery_only — участник кампании planeta
 * 219924 оплачивает только доставку; mode=purchase — покупатель витрины берёт
 * книгу + доставку; mode=pickup — самовывоз. Деньги живут в связанном Payment
 * (tariff='book_club_delivery'|'book_club_shop', course_id=null — доступ к
 * курсам НЕ выдаётся, см. Payment::grantAccess).
 */
class BookClubOrder extends Model
{
    use HasFactory;

    public const MODE_DELIVERY_ONLY = 'delivery_only';

    public const MODE_PURCHASE = 'purchase';

    public const MODE_PICKUP = 'pickup';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PACKED = 'packed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'payment_id',
        'user_id',
        'book_id',
        'mode',
        'books_count',
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
        'delivery_amount',
        'status',
        'tracking_number',
        'shipped_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'delivery_amount' => 'decimal:2',
        'books_count' => 'integer',
        'payment_id' => 'integer',
        'user_id' => 'integer',
        'book_id' => 'integer',
        'shipped_at' => 'datetime',
    ];

    /**
     * Письмо покупателю при переходе в shipped — здесь, а не в Filament-странице,
     * чтобы смена статуса из любого места (админка, консоль, тест) вела себя
     * одинаково. shipped_at ставится один раз, повторный апдейт письма не шлёт.
     */
    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            if ($order->isDirty('status') && $order->status === self::STATUS_SHIPPED && ! $order->shipped_at) {
                $order->shipped_at = now();
            }
        });

        static::updated(function (self $order): void {
            if (! $order->wasChanged('status') || $order->status !== self::STATUS_SHIPPED) {
                return;
            }

            $email = $order->user?->email;
            if ($email) {
                Mail::to($email)->queue(new BookClubOrderShippedMail($order));
            }
        });
    }

    /** Подписи статусов для админки и писем. */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Ожидает оплаты',
            self::STATUS_PAID => 'Оплачен',
            self::STATUS_PACKED => 'Собран',
            self::STATUS_SHIPPED => 'Отправлен',
            self::STATUS_CANCELLED => 'Отменён',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? (string) $this->status;
    }

    public function isPickup(): bool
    {
        return $this->mode === self::MODE_PICKUP;
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
