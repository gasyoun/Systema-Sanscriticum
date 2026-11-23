<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $book->title }} — Книжный клуб</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f5f4f0; color: #1f2937; line-height: 1.5; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 28px; margin: 0 0 4px; }
        .lead { color: #4b5563; margin: 0 0 24px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .card h2 { font-size: 18px; margin: 0 0 12px; }
        .price { font-size: 26px; font-weight: 800; color: #4338ca; }
        .badge { display: inline-block; font-size: 13px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
        .badge.in { background: #dcfce7; color: #166534; }
        .badge.back { background: #fef3c7; color: #92400e; }
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
        .total { display: flex; justify-content: space-between; align-items: baseline; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .total strong { font-size: 22px; }
        .hidden { display: none; }
        .radio-row { display: flex; gap: 16px; margin-bottom: 12px; }
        .radio-row label { display: flex; align-items: center; gap: 8px; font-weight: 500; margin: 0; }
        .radio-row input { width: auto; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ $book->title }}</h1>
    <p class="lead">Книга из тиража кампании — теперь можно купить отдельно, с доставкой или самовывозом.</p>

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
        <h2>Книга</h2>
        <p><strong>{{ $book->title }}</strong> — {{ \App\Services\BookClub\BookClubShopPricing::rub((float) $book->price) }} ₽ за экземпляр.</p>
        @if ($book->inStock())
            <span class="badge in">В наличии: {{ $book->stock }}</span>
        @else
            <span class="badge back">Под заказ 2–3 недели</span>
        @endif
        <p class="note">Посылка — до {{ $maxBooks }} книг. Доставка считается за каждую посылку:
            РФ {{ \App\Services\BookClub\BookClubShopPricing::rub((float) $rfPrice) }} ₽; заграница — от
            {{ \App\Services\BookClub\BookClubShopPricing::rub((float) min($abroadPrices ?: [$otherPrice])) }} ₽ за посылку; самовывоз — 0 ₽.
            Книг больше четырёх — сумму согласуем письмом.</p>
    </div>

    <div class="card">
        <h2>Заказ</h2>
        <form action="{{ route('book-club.shop.submit') }}" method="POST" enctype="multipart/form-data" id="shop-form">
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
                <label for="books_count">Сколько экземпляров *</label>
                <select id="books_count" name="books_count" required>
                    @foreach ([1, 2, 3, 4] as $option)
                        <option value="{{ $option }}" @selected((int) old('books_count', 1) === $option)>{{ $option }}</option>
                    @endforeach
                    <option value="5" @selected((int) old('books_count', 1) >= 5)>5 и больше</option>
                </select>
                <div class="field hidden" id="custom-count-wrap">
                    <label for="custom_books_count">Сколько именно (5–99)</label>
                    <input type="number" id="custom_books_count" min="5" max="99" value="{{ (int) old('books_count', 1) >= 5 ? (int) old('books_count', 1) : 5 }}">
                    <div class="hint">Книг больше четырёх — сумму и доставку согласуем письмом.</div>
                </div>
            </div>

            <div class="radio-row">
                <label><input type="radio" name="fulfillment" value="purchase" @checked(old('fulfillment', 'purchase') === 'purchase')> Доставка</label>
                @if ($pickupEnabled)
                    <label><input type="radio" name="fulfillment" value="pickup" @checked(old('fulfillment') === 'pickup')> Самовывоз (Москва)</label>
                @endif
            </div>
            @if ($pickupEnabled)
                <p class="note" id="pickup-note">{{ $pickupNote }}</p>
            @endif

            <div id="address-fields">
                <div class="field">
                    <label for="recipient_name">ФИО получателя (как на посылке) *</label>
                    <input type="text" id="recipient_name" name="recipient_name" value="{{ old('recipient_name') }}">
                </div>

                <div class="grid">
                    <div class="field">
                        <label for="country_code">Страна *</label>
                        <select id="country_code" name="country_code">
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
                        <input type="text" id="city" name="city" value="{{ old('city') }}">
                    </div>
                    <div class="field">
                        <label for="postal_code">Почтовый индекс *</label>
                        <input type="text" id="postal_code" name="postal_code" value="{{ old('postal_code') }}">
                    </div>
                </div>

                <div class="field">
                    <label for="address_line">Адрес (улица, дом, квартира) *</label>
                    <input type="text" id="address_line" name="address_line" value="{{ old('address_line') }}">
                </div>
            </div>

            <div id="abroad-extra" class="hidden">
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

            <div class="total">
                <span class="note" id="breakdown">Книга × 1 — {{ \App\Services\BookClub\BookClubShopPricing::rub((float) $defaultQuote['books_total']) }} ₽
                    + доставка {{ \App\Services\BookClub\BookClubShopPricing::rub((float) $defaultQuote['delivery']) }} ₽
                    ({{ $defaultQuote['parcels'] }} посыл.)</span>
                <strong id="total-amount">{{ \App\Services\BookClub\BookClubShopPricing::rub((float) $defaultQuote['total']) }} ₽</strong>
            </div>

            <p class="note" id="manual-note" style="display:none">Книг больше четырёх — заказ примем, сумму и доставку согласуем письмом.</p>

            <button type="submit" id="submit-btn">Перейти к оплате</button>
        </form>
    </div>

    <p class="note">
        @if (config('features.book_club_delivery'))
            Участник кампании planeta? Оплата только доставки — на
            <a href="{{ route('book-club.show') }}">странице доставки</a>.
        @endif
        Вопросы: <a href="https://t.me/bsanscritica">t.me/bsanscritica</a>.
    </p>
</div>
<script>
    (function () {
        var prices = {
            rf: {{ (float) $rfPrice }},
            other: {{ (float) $otherPrice }},
            abroad: @json((object) $abroadPrices)
        };
        var books = {{ max(1, (int) config('book_club.max_books_per_parcel')) }};
        var unit = {{ (float) $book->price }};
        var manualThreshold = {{ (int) $manualThreshold }};

        var select = document.getElementById('books_count');
        var customWrap = document.getElementById('custom-count-wrap');
        var custom = document.getElementById('custom_books_count');
        var country = document.getElementById('country_code');
        var addressWrap = document.getElementById('address-fields');
        var abroadWrap = document.getElementById('abroad-extra');
        var breakdown = document.getElementById('breakdown');
        var totalEl = document.getElementById('total-amount');
        var manualNote = document.getElementById('manual-note');
        var submitBtn = document.getElementById('submit-btn');

        function fulfillment() {
            var checked = document.querySelector('input[name="fulfillment"]:checked');
            return checked ? checked.value : 'purchase';
        }

        function count() {
            return select.value === '5' ? Math.max(5, parseInt(custom.value || '5', 10)) : parseInt(select.value, 10);
        }

        function fmt(n) {
            // Без округления до рубля: если MG поставит дробную цену, витрина
            // должна показать ровно ту сумму, которую спишет Точка.
            return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n) + ' ₽';
        }

        function sync() {
            var pickup = fulfillment() === 'pickup';
            var abroad = !pickup && country && country.value !== 'RU';
            var n = count();
            var parcels = Math.max(1, Math.ceil(n / books));
            var tariff = pickup ? 0 : (abroad ? (prices.abroad[country.value] || prices.other) : prices.rf);
            var delivery = tariff * parcels;
            var total = unit * n + delivery;

            customWrap.classList.toggle('hidden', select.value !== '5');
            if (select.value === '5') { custom.name = 'books_count'; select.removeAttribute('name'); }
            else { select.name = 'books_count'; custom.removeAttribute('name'); }

            addressWrap.classList.toggle('hidden', pickup);
            if (abroadWrap) { abroadWrap.classList.toggle('hidden', !abroad); }

            breakdown.textContent = 'Книга × ' + n + ' — ' + fmt(unit * n) + (delivery > 0 ? ' + доставка ' + fmt(delivery) + ' (' + parcels + ' посыл.)' : ' · самовывоз');
            totalEl.textContent = fmt(total);
            manualNote.style.display = n >= manualThreshold ? 'block' : 'none';
            submitBtn.textContent = pickup ? 'Оформить самовывоз' : (abroad ? 'Отправить заявку на книгу' : (n >= manualThreshold ? 'Оформить заказ' : 'Перейти к оплате'));
        }

        select.addEventListener('change', sync);
        custom.addEventListener('input', sync);
        if (country) { country.addEventListener('change', sync); }
        Array.prototype.forEach.call(document.querySelectorAll('input[name="fulfillment"]'), function (el) {
            el.addEventListener('change', sync);
        });
        sync();
    })();
</script>
</body>
</html>
