@component('mail::message')
# Заявка на оплату банковским переводом

Получено уведомление об оплате банковским переводом (SEPA/SWIFT). Требуется **ручная сверка по выписке получателя** — после сверки переведите платеж в статус «Оплачен» в админке.

- **Ученик:** {{ $payment->user?->name }} ({{ $payment->user?->email }})
- **Курс:** {{ $payment->course?->title ?? '-' }}
- **Тариф:** {{ $payment->operationLabel() }}
- **Заявленная сумма:** {{ $payment->foreignAmountLabel() ?: '-' }}
- **Отправитель:** {{ $payment->claimMeta('sender_name', '-') }}
- **Дата оплаты:** {{ $payment->claimMeta('paid_on', '-') }}
- **Референция:** {{ $payment->claimMeta('reference', '-') ?: '-' }}
- **Номинал тарифа:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽
- **Примечание:** {{ $payment->payer_note ?: '-' }}
@if($payment->proof_path)
- **Подтверждение:** файл приложен (смотреть в админке)
@endif

@component('mail::button', ['url' => \App\Filament\Resources\PaymentResource::getUrl('index')])
Открыть «Платежи» в админке
@endcomponent

После сверки по выписке переведите платеж в статус «Оплачен» (фильтр «SEPA-заявки на проверке» → кнопка «Подтвердить перевод») — ученик получит доступ автоматически.
@endcomponent
