@component('mail::message')
# Заявка: перевод рублями Гасунсу

Получено уведомление о переводе **на личный счёт владельца школы** (мимо
Точки). Требуется **ручная сверка** — подтвердите получение перевода и
переведите платёж в статус «Оплачен» в админке: доступ ученику откроется,
доля преподавателя курса начислится движком выплат как с обычной школьной
оплаты (из гонорара НИЧЕГО не вычитается — это не teacher_personal).

- **Ученик:** {{ $payment->user?->name }} ({{ $payment->user?->email }})
- **Курс:** {{ $payment->course?->title ?? '-' }}
- **Тариф:** {{ $payment->operationLabel() }}
- **Блок:** {{ $payment->start_block ?: '-' }}@if($payment->end_block && $payment->end_block !== $payment->start_block)–{{ $payment->end_block }}@endif
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

Спасибо,{{ config('app.name') }}
@endcomponent
