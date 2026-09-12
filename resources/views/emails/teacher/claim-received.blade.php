@component('mail::message')
# Заявка: оплата напрямую преподавателю

Получено уведомление об оплате на **личный счёт преподавателя**. Требуется
**ручная сверка по выписке преподавателя** — после сверки переведите платеж
в статус «Оплачен» в админке, и номинал вычтется из его гонорара автоматически.

- **Ученик:** {{ $payment->user?->name }} ({{ $payment->user?->email }})
- **Преподаватель-получатель:** {{ $payment->receivedByTeacher?->name ?? '-' }}
- **Курс:** {{ $payment->course?->title ?? '-' }}
- **Тариф:** {{ $payment->operationLabel() }}
- **Заявленная сумма:** {{ $payment->foreignAmountLabel() ?: '-' }}
- **Отправитель:** {{ $payment->claimMeta('sender_name', '-') }}
- **Дата оплаты:** {{ $payment->claimMeta('paid_on', '-') }}
- **Референция:** {{ $payment->claimMeta('reference', '-') ?: '-' }}
- **Номинал тарифа:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽
- **Примечание:** {{ $payment->payer_note ?: '-' }}
@if($payment->proof_path)
- **Чек:** файл приложен (смотреть в админке)
@endif

@component('mail::button', ['url' => \App\Filament\Resources\PaymentResource::getUrl('index')])
Открыть «Платежи» в админке
@endcomponent

После сверки по выписке переведите платеж в статус «Оплачен» (фильтр
«Оплаты преподавателю на проверке» → кнопка «Подтвердить перевод
преподавателю») — ученик получит доступ, а сумма зачтётся в гонорар.
@endcomponent
