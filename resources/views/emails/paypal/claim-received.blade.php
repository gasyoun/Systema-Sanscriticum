@component('mail::message')
# Новая заявка на оплату через PayPal

Студент сообщил об оплате из-за рубежа. Требуется **ручная сверка в PayPal** — доступ откроется, когда вы переведете платеж в статус «Оплачено» в админке.

- **Студент:** {{ $payment->user?->name }} ({{ $payment->user?->email }})
- **Курс:** {{ $payment->course?->title ?? '—' }}
- **Тариф:** {{ $payment->operationLabel() }}
- **Заявленная сумма:** {{ $payment->foreignAmountLabel() ?: '—' }}
- **Рублевый номинал:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽
- **Примечание:** {{ $payment->payer_note ?: '—' }}
@if($payment->proof_path)
- **Подтверждение:** файл приложен (кнопка «Чек» в карточке платежа)
@endif

@component('mail::button', ['url' => \App\Filament\Resources\PaymentResource::getUrl('index')])
Открыть «Финансы» в админке
@endcomponent

После сверки в PayPal переведите платеж в статус «Оплачено» (фильтр «Заявки PayPal на проверке» → кнопка «Подтвердить PayPal») — студент получит доступ автоматически.
@endcomponent
