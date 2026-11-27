@component('mail::message')
# Заказ магазина требует ручного шага

- **Заказ:** №{{ $order->id }} ({{ $order->statusLabel() }})
- **Режим:** {{ $order->mode === \App\Models\BookClubOrder::MODE_PICKUP ? 'самовывоз' : 'доставка' }}
- **Книга:** {{ $order->book?->title ?? config('book_club.book_title') }} × {{ $order->books_count ?? 1 }}
- **Сумма:** {{ number_format((float) $order->amount, 0, '.', ' ') }} ₽ (доставка {{ number_format((float) $order->delivery_amount, 0, '.', ' ') }} ₽)
- **Покупатель:** {{ $order->user?->name }} ({{ $order->user?->email }})
- **Телефон:** {{ $order->phone ?: '—' }}
@if($order->isPickup())
- **Самовывоз:** согласовать время и адрес
@else
- **Адрес:** {{ $order->postal_code }}, {{ $order->city }}, {{ $order->address_line }}
- **Страна:** {{ $order->country_name ?: $order->country_code }}
@endif

@component('mail::button', ['url' => \App\Filament\Resources\BookClubOrderResource::getUrl('edit', ['record' => $order])])
Открыть заказ в админке
@endcomponent
@endcomponent
