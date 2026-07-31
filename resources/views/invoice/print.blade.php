<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Счет {{ $payment->invoiceNumber() }}</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color: #111; margin: 0; padding: 24px; background: #f8fafc; }
        .sheet { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; }
        h1 { font-size: 1.35rem; margin: 0 0 8px; }
        .muted { color: #64748b; font-size: 0.875rem; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #e2e8f0; padding: 10px 12px; text-align: left; font-size: 0.9rem; }
        th { background: #f1f5f9; }
        .total { font-size: 1.1rem; font-weight: 700; }
        .actions { margin: 16px auto 0; max-width: 720px; display: flex; gap: 12px; flex-wrap: wrap; }
        .actions a, .actions button { display: inline-block; padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; text-decoration: none; font-size: 0.9rem; cursor: pointer; }
        .warn { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.875rem; }
        .ok { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 0.875rem; }
        @media print {
            body { background: #fff; padding: 0; }
            .actions, .no-print { display: none !important; }
            .sheet { border: none; border-radius: 0; padding: 0; }
        }
    </style>
</head>
<body>
@if(session('success'))
    <div class="actions no-print"><div class="ok" style="flex:1">{{ session('success') }}</div></div>
@endif

<div class="actions no-print">
    <button type="button" onclick="window.print()">Распечатать / PDF</button>
    @if($payment->course)
        <a href="{{ route('shop.course.show', $payment->course) }}">К курсу</a>
    @endif
</div>

<div class="sheet">
    @unless($requisitesComplete)
        <div class="warn no-print">
            Реквизиты школы в системе не заполнены (BILLING_*). Счет для покупателя неполон —
            заполните env до отправки компаниям.
        </div>
    @endunless

    <h1>Счет на оплату № {{ $payment->invoiceNumber() }}</h1>
    <p class="muted">от {{ $payment->created_at?->timezone('Europe/Moscow')->format('d.m.Y') }} (МСК)</p>

    <h2 style="font-size:1rem;margin:24px 0 8px">Поставщик</h2>
    <p style="margin:0;line-height:1.5;font-size:0.95rem">
        <strong>{{ $legal['name'] ?: '—' }}</strong><br>
        @if(!empty($legal['inn'])) ИНН {{ $legal['inn'] }}@endif
        @if(!empty($legal['kpp'])) · КПП {{ $legal['kpp'] }}@endif
        @if(!empty($legal['ogrn'])) · ОГРН {{ $legal['ogrn'] }}@endif
        @if(!empty($legal['ogrnip'])) · ОГРНИП {{ $legal['ogrnip'] }}@endif
        <br>
        @if(!empty($legal['address'])) {{ $legal['address'] }}<br>@endif
        Банк: {{ $legal['bank_name'] ?: '—' }} · БИК {{ $legal['bik'] ?: '—' }}<br>
        р/с {{ $legal['account'] ?: '—' }}
        @if(!empty($legal['corr_account'])) · к/с {{ $legal['corr_account'] }}@endif
        @if(!empty($legal['email']) || !empty($legal['phone']))
            <br>{{ $legal['email'] ?? '' }} @if(!empty($legal['phone'])) · {{ $legal['phone'] }}@endif
        @endif
    </p>

    <h2 style="font-size:1rem;margin:24px 0 8px">Покупатель</h2>
    <p style="margin:0;line-height:1.5;font-size:0.95rem">
        <strong>{{ $payment->claimMeta('company_name', '—') }}</strong><br>
        ИНН {{ $payment->claimMeta('inn', '—') }}
        @if($payment->claimMeta('kpp')) · КПП {{ $payment->claimMeta('kpp') }}@endif
        <br>
        {{ $payment->claimMeta('legal_address', '') }}
        @if($payment->user)
            <br>Контакт: {{ $payment->user->name }} · {{ $payment->user->email }}
            @if($payment->claimMeta('contact_phone')) · {{ $payment->claimMeta('contact_phone') }}@endif
        @endif
    </p>

    <table>
        <thead>
            <tr>
                <th>№</th>
                <th>Наименование</th>
                <th>Кол-во</th>
                <th>Сумма, ₽</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>
                    {{ $payment->course?->title ?? 'Образовательная услуга' }}
                    — {{ $payment->operationLabel() }}
                </td>
                <td>1</td>
                <td>{{ number_format((float) $payment->amount, 2, '.', ' ') }}</td>
            </tr>
        </tbody>
    </table>

    <p class="total">Итого к оплате: {{ number_format((float) $payment->amount, 2, '.', ' ') }} ₽</p>
    <p class="muted">НДС не облагается / согласно применяемой системе налогообложения поставщика.</p>
    <p class="muted" style="margin-top:16px">
        Назначение платежа: Оплата по счету {{ $payment->invoiceNumber() }}
        @if($payment->user?->email)
            ({{ $payment->user->email }})
        @endif
        за {{ $payment->course?->title ?? 'курс' }}.
    </p>
    <p class="muted">Статус: {{ $payment->status === 'pending' ? 'ожидает оплаты' : \App\Models\Payment::statusLabel($payment->status) }}.</p>
</div>
</body>
</html>
