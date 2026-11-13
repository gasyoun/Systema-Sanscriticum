<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Книжный клуб — оплата доставки</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f5f4f0; color: #1f2937; line-height: 1.5; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 28px; margin: 0 0 4px; }
        .lead { color: #4b5563; margin: 0 0 24px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .card h2 { font-size: 18px; margin: 0 0 12px; }
        table.tariffs { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.tariffs td, table.tariffs th { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        table.tariffs td.price { text-align: right; font-weight: 700; white-space: nowrap; }
        .note { font-size: 14px; color: #6b7280; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 16px; background: #fff; }
        .field { margin-bottom: 12px; }
        .hint { font-size: 13px; color: #6b7280; margin-top: 2px; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
        .errors ul { margin: 4px 0 0; padding-left: 20px; }
        .success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
        button { display: block; width: 100%; padding: 16px; font-size: 18px; font-weight: 700; color: #fff; background: #4338ca; border: 0; border-radius: 12px; cursor: pointer; }
        button:hover { background: #3730a3; }
        .abroad-extra { display: none; }
        .abroad-extra.visible { display: block; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Книжный клуб — оплата доставки</h1>
    <p class="lead">Книги уже оплачены вами на planeta. Здесь — только доставка: заполните адрес
        и данные заказа, оплатите доставку, и мы отправим книги.</p>

    @if (session('success'))
        <div class="success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">
            <strong>Проверьте форму:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <h2>Тарифы доставки</h2>
        <table class="tariffs">
            <tr><th>Направление</th><th class="price">Цена</th></tr>
            <tr><td>Россия — посылка до {{ $maxKg }} кг ({{ $maxBooks }} книги)</td><td class="price">{{ number_format($rfPrice, 0, '.', ' ') }} ₽</td></tr>
            @foreach ($abroadPrices as $code => $price)
                <tr><td>{{ $countries[$code] ?? $code }}</td><td class="price">{{ number_format($price, 0, '.', ' ') }} ₽</td></tr>
            @endforeach
            <tr><td>Другая страна</td><td class="price">{{ number_format($otherPrice, 0, '.', ' ') }} ₽</td></tr>
        </table>
        <p class="note">Оплата доставки принимается до {{ \Carbon\Carbon::parse($paymentDeadline)->format('d.m.Y') }}.
            3 и более книги в заказе — посылка считается по фактической стоимости, мы свяжемся с вами.
            Самовывоз в Москве — бесплатно, по записи.</p>
    </div>

    <div class="card">
        <h2>Данные для отправки</h2>
        <form action="{{ route('book-club.submit') }}" method="POST" enctype="multipart/form-data" id="bookclub-form">
            @csrf

            <div class="grid">
                <div class="field">
                    <label for="surname">Фамилия *</label>
                    <input type="text" id="surname" name="surname" required minlength="2" value="{{ old('surname') }}">
                </div>
                <div class="field">
                    <label for="name">Имя *</label>
                    <input type="text" id="name" name="name" required minlength="2" value="{{ old('name') }}">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="email">Email (для аккаунта и связи) *</label>
                    <input type="email" id="email" name="email" required value="{{ old('email') }}">
                </div>
                <div class="field">
                    <label for="phone">Телефон</label>
                    <input type="text" id="phone" name="phone" value="{{ old('phone') }}">
                </div>
            </div>

            <div class="field">
                <label for="recipient_name">ФИО получателя (как на посылке) *</label>
                <input type="text" id="recipient_name" name="recipient_name" required value="{{ old('recipient_name') }}">
            </div>

            <div class="grid">
                <div class="field">
                    <label for="country_code">Страна *</label>
                    <select id="country_code" name="country_code" required>
                        @foreach ($countries as $code => $label)
                            <option value="{{ $code }}" @selected(old('country_code', 'RU') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="country_name">Название страны</label>
                    <input type="text" id="country_name" name="country_name" value="{{ old('country_name') }}"
                           placeholder="Если выбрали «Другая страна»">
                </div>
            </div>

            <div class="grid">
                <div class="field">
                    <label for="city">Город *</label>
                    <input type="text" id="city" name="city" required value="{{ old('city') }}">
                </div>
                <div class="field">
                    <label for="postal_code">Почтовый индекс *</label>
                    <input type="text" id="postal_code" name="postal_code" required value="{{ old('postal_code') }}">
                </div>
            </div>

            <div class="field">
                <label for="address_line">Адрес (улица, дом, квартира) *</label>
                <input type="text" id="address_line" name="address_line" required value="{{ old('address_line') }}">
            </div>

            <div class="field">
                <label for="planeta_email">Email, указанный на planeta *</label>
                <input type="email" id="planeta_email" name="planeta_email" required value="{{ old('planeta_email') }}">
                <div class="hint">По нему мы найдём ваш заказ в кампании.</div>
            </div>

            <div class="field">
                <label for="planeta_order_number">Номер заказа planeta *</label>
                <input type="text" id="planeta_order_number" name="planeta_order_number" required
                       placeholder="например, 20241121-4433387" value="{{ old('planeta_order_number') }}">
                <div class="hint">Указан в письме о поддержке кампании.</div>
            </div>

            <div class="abroad-extra" id="abroad-extra">
                <h2>Оплата из-за рубежа (PayPal)</h2>
                @if ($paypalMeLink)
                    <p class="note">Оплатите сумму по тарифу вашей страны по ссылке
                        <strong>{{ $paypalMeLink }}</strong>@if($paypalRecipient) (получатель: {{ $paypalRecipient }})@endif,
                        затем укажите номер транзакции PayPal или приложите чек.</p>
                @endif
                <div class="grid">
                    <div class="field">
                        <label for="foreign_amount">Сумма оплаты *</label>
                        <input type="number" id="foreign_amount" name="foreign_amount" min="1" step="0.01"
                               value="{{ old('foreign_amount') }}">
                    </div>
                    <div class="field">
                        <label for="foreign_currency">Валюта *</label>
                        <select id="foreign_currency" name="foreign_currency">
                            <option value="USD" @selected(old('foreign_currency') === 'USD')>USD</option>
                            <option value="EUR" @selected(old('foreign_currency') === 'EUR')>EUR</option>
                        </select>
                    </div>
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="paypal_txn">Номер транзакции PayPal</label>
                        <input type="text" id="paypal_txn" name="paypal_txn" value="{{ old('paypal_txn') }}">
                    </div>
                    <div class="field">
                        <label for="proof">Чек (файл)</label>
                        <input type="file" id="proof" name="proof" accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                </div>
                <div class="field">
                    <label for="comment">Комментарий</label>
                    <input type="text" id="comment" name="comment" value="{{ old('comment') }}">
                </div>
                <p class="note">Нужно хотя бы одно: номер транзакции или чек. Мы сверим платёж и подтвердим отправку.</p>
            </div>

            <button type="submit" id="submit-btn">Перейти к оплате</button>
        </form>
    </div>

    <p class="note">Вопросы по выдаче: <a href="https://t.me/bsanscritica">t.me/bsanscritica</a>.
        Кампания: <a href="{{ $campaignUrl }}">planeta.ru/campaigns/{{ config('book_club.campaign_id') }}</a>.</p>
</div>
<script>
    (function () {
        var select = document.getElementById('country_code');
        var extra = document.getElementById('abroad-extra');
        var btn = document.getElementById('submit-btn');
        function sync() {
            var abroad = select.value !== 'RU';
            extra.classList.toggle('visible', abroad);
            btn.textContent = abroad ? 'Отправить заявку на доставку' : 'Перейти к оплате';
        }
        select.addEventListener('change', sync);
        sync();
    })();
</script>
</body>
</html>
