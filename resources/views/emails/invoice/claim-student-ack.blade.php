@component('mail::message')
@if(!empty($payment->user?->name))
Намасте, {{ $payment->user->name }}!
@else
Здравствуйте!
@endif

Счет на оплату сформирован.

- **Курс:** {{ $payment->course?->title ?? '—' }}
- **Сумма:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽
- **Номер счета:** {{ $payment->invoiceNumber() }}

Оплатите по реквизитам из счета. Обычно в течение одного-двух рабочих дней после поступления мы сверим платеж и откроем доступ. Для нового аккаунта пароль придет на email.

Если деньги уже ушли, а доступа нет — не платите повторно: [напишите нам в Telegram](https://t.me/rusamskrtam).

@component('mail::button', ['url' => route('invoice.print', $payment)])
Открыть счет
@endcomponent

Общество ревнителей санскрита
@endcomponent
