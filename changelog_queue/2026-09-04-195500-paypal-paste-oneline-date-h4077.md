_Created: 04-09-2026 · Last updated: 05-09-2026_

# H4077: PayPal claim paste — дата терялась на вставке одной строкой + понятный отказ формы доплаты (OxAlpha z-ai/glm-5.3-flash, 04-09-2026)

Живой кейс (Кессель Анастасия, Гренхен, 04-09-2026, курс 402): вставка деталей PayPal с телефона склеивает дамп в одну строку, и немецкая метка приклеивается к значению («Transaktionsdatum4. September 2026»). `labeledValue` ищет метку только с начала строки, а свободный поиск требует `\b` перед цифрой — между «m» и «4» границы нет → `paid_on` не заполнялся (валюта при этом заполнялась всегда вместе с суммой — пары amount+currency неразделимы). Вторая половина инцидента: студент перевёл 112 € (22 доплата + 90 за следующий блок), ввёл 112 в форму доплаты — строгий инвариант 22±0.5 дал отказ с коротким текстом, студент его не заметил → заявки нет, ack-письма нет (прод: 0 строк по txn `5HE06501S2923382H`, счёт-доплата [Payment 14280](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php) закрыт MG вручную в Filament).

- **Парсер** ([`paypal-claim-paste.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/js/paypal-claim-paste.js)): `extractDate` для свободного поиска сканирует копию с разклейкой «буква+цифра» («Transaktionsdatum 4.» → `\b` работает); labeled-путь остался построчным, txn/amount/payer не тронуты — диф 1 строка + скан-копия локальна.
- **Фикстура живого кейса** [`de_p2p_email_oneline_mg.txt`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/paypal_claim_paste/de_p2p_email_oneline_mg.txt) + expected.json: на фикс-версии падает, после правки зелёная; существующие 6 фикстур без изменений (партиал txn-only не научился «находить» лишнее).
- **Копия отказа доплаты** ([`PaypalClaimController::storeSupplement`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaypalClaimController.php)): отказ теперь объясняет путь — «укажите ровно 22; если перевели одной суммой доплату и следующий блок — отправьте форму с суммой 22 и напишите в Telegram, остаток зачтём». Инвариант суммы 22±0.5 НЕ ослаблен (money-contour H3990 в силе).
- **Форма** ([`paypal/claim.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php)): на доплате placeholder суммы «22» (было «Напр. 50») + строка в шаге 1 про ровную сумму и комбинированный перевод.
- Тесты: `PaypalClaimPasteParserTest` 8/8, `PaypalClaimTest` 23/23, Pint clean.

_Dr. Mārcis Gasūns_
