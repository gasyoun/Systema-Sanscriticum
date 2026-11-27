@component('mail::message')
# Книга отправлена

Здравствуйте, {{ $order->recipient_name ?: $order->user?->name }}!

Ваш заказ №{{ $order->id }} отправлен.

- **Книга:** {{ $order->book?->title ?? config('book_club.book_title') }}
- **Экземпляров:** {{ $order->books_count ?? 1 }}
- **Трек-номер:** {{ $order->tracking_number ?: '—' }}
- **Отправлено:** {{ optional($order->shipped_at)->format('d.m.Y') ?? now()->format('d.m.Y') }}

Если трек-номер не отслеживается в первые сутки — это нормально: почта обновляет статус с задержкой. Вопросы — в Telegram [t.me/bsanscritica](https://t.me/bsanscritica).

Спасибо, что читаете с нами!
@endcomponent
