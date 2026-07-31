@component('mail::message')
# Новый счет для юрлица

Запрошен счет на безнал. Доступ откроется после сверки поступления и перевода платежа в «Оплачено».

- **Студент:** {{ $payment->user?->name }} ({{ $payment->user?->email }})
- **Организация:** {{ $payment->claimMeta('company_name', '—') }}
- **ИНН:** {{ $payment->claimMeta('inn', '—') }}
@if($payment->claimMeta('kpp'))
- **КПП:** {{ $payment->claimMeta('kpp') }}
@endif
- **Курс:** {{ $payment->course?->title ?? '—' }}
- **Тариф:** {{ $payment->operationLabel() }}
- **Сумма:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽
- **Номер счета:** {{ $payment->invoiceNumber() }}
- **Примечание:** {{ $payment->payer_note ?: '—' }}

@component('mail::button', ['url' => \App\Filament\Resources\PaymentResource::getUrl('index')])
Открыть «Финансы» в админке
@endcomponent

Фильтр «Счета юрлиц на проверке» → кнопка «Подтвердить счет» после поступления.
@endcomponent
