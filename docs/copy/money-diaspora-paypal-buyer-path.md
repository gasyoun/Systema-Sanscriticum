# Диаспорный путь оплаты (PayPal) — финальная копия (H1292)

_Created: 20-07-2026 · Last updated: 23-08-2026_

_Parser residual (same day): DE P2P email sample retune after H2215 ship._

Лейн H1292 волны revenue-copy
([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md) ·
[контракт голоса](https://github.com/gasyoun/Uprava/blob/main/docs/ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md) ·
[handoff](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1292-Fable_Systema-Sanscriticum_money-diaspora-paypal-buyer-path_19.07.26.md)).
Исполнено Fable 5 (`claude-fable-5`), 20-07-2026.
**Prod enable + claim fields H2017** (Grok 4.5 (`grok-4.5`), 31-07-2026, [PR #969](https://github.com/gasyoun/Systema-Sanscriticum/pull/969)).
**QR в шапке + прямая ссылка** (ox-alpha (`x-preview-f-free`), 23-08-2026,
[PR #2012](https://github.com/gasyoun/Systema-Sanscriticum/pull/2012) /
[PR #2014](https://github.com/gasyoun/Systema-Sanscriticum/pull/2014)).
**Канон хэндла — gasuns, prod `PAYPAL_ME_LINK` переведен на него**
(ox-alpha, 23-08-2026; рулинг MG в чате).

## ✅ Prod status (23-08-2026)

| Item | Value |
|---|---|
| Flag | `PAYPAL_CLAIM_ENABLED=true` |
| Student pays to | **gasyoun@gmail.com** (`PAYPAL_RECIPIENT`); канонический хэндл **https://www.paypal.com/paypalme/gasuns** (MG 23-08-2026): кнопка `PAYPAL_ME_LINK` (prod `.env` переведен с устаревшего `paypalme/gasyoun`, бэкап `.env.bak.paypalme-gasuns.20260823`), постоянная строка в шаге 1 и QR из шапки claim-страницы — все ведут на gasuns |
| Checkout CTA | Visible («Оплатить через PayPal») |
| Claim form | /paypal/{tariff} — «Уведомление об оплате через PayPal»: triple from/date/amount, optional txn/proof (H2017); валюта по умолчанию EUR; payer = только email; комиссия на отправителе (+пересчет/доплата); валютный прайс блока из services.paypal.foreign_block_prices — рублевую цену не показываем (MG 23-08-2026) |
| Trust (ruling 22-08-2026) | Заявка **существующего ученика** (вошел в кабинет) сразу `paid` — доступ/финансы немедленно; флаг `PAYPAL_TRUST_EXISTING_STUDENTS` (default ON). Гости с новым email — по-прежнему pending → ручная сверка |
| Selective check | Filament фильтр **«PayPal: без сверки»** (`paypalUnverified`) → «Сверка пройдена» (штампует `verified_at`) / «Нет платежа — отменить» (paid→canceled, штатный откат доступа/финансов) |
| Admin confirm | Filament → filter «Заявки PayPal на проверке» → «Подтвердить PayPal» (только гостевые pending) |
| Access | Своим — мгновенно; гостям — только после human `pending` → `paid` (personal PayPal, no business API) |
| Claim notify mail | Still `ADMIN_EMAIL` (curator mailbox); student ack via `PaypalClaimStudentAckMail` (два варианта копии: trusted / guest) |

Ruling 22-08-2026 detail: авто-доверие пишется в `claim_meta.auto_trusted=true` +
`trusted_at`; выборочная сверка пост-фактум закрывается `verified_at`. Отмена
мошеннической заявки — кнопка «Нет платежа — отменить»: canceled на paid
запускает штатный откат (группы снимаются, финансы пересчитываются;
course_user pivot не трогается by design).

### Ученик прислал только скриншот PayPal (без заявки)

Скрин — хорошо, но не создает записи платежа. Канонический путь: отправить
ученику шаблон `/оплата-paypal-скрин`
([ORS-FAQ/Telegram_templates.md](https://github.com/gasyoun/ORS-FAQ/blob/main/Telegram_templates.md))
— заявка на сайте своим ученикам открывает доступ сразу. Фолбэк, если форма
ученику недоступна совсем: куратор создает Payment вручную в Filament
(Payments → New: user, course, тариф `block_N`, amount = рублевый номинал,
`foreign_amount`/`foreign_currency`, provider **PayPal**, status **paid** для
своего / **pending** для нового) — дальше конвейер тот же. Прямая ссылка на
форму конкретного тарифа строится как `/paypal/{tariff_id}` (id виден в
админке тарифов и в URL чекаута).

Historical note: H1292 shipped behind a dark flag; H2017 opened prod after MG asked to enable diaspora path. SMTP/queue: see current prod mail status (issue #504 was later re-diagnosed / closed in other work — treat live Horizon `mailing` as source of truth).

## Зачем этот лейн

[`partials/paypal-cta.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/paypal-cta.blade.php)
был 14 строками, все обещание — «Оплатите через PayPal и сообщите нам — доступ
откроем после сверки платежа»: без срока, без причины, без подтверждения. Для
диаспорного покупателя это **единственный** рабочий путь (Точка не берет
зарубежные карты), а
[`PaypalClaimReceivedMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PaypalClaimReceivedMail.php)
уходило только админу — студент, подавший заявку, не получал ничего. Ручная
сверка медленная по построению, и именно на этом пути страх «платеж исчез»
максимален (finding F3 аудита
[CHECKOUT_PURCHASE_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md);
этот лейн исполняет его тикет 5 и несет его готовую копию дальше).

Что добавлено:

- CTA на чекауте: ожидание (срок) + причина ручной сверки.
- Страница заявки: зачем этот путь, сколько занимает сверка, блок «Что будет
  дальше» (шаг 3), снятие страха двойного списания наверху страницы.
- Новое письмо студенту
  [`PaypalClaimStudentAckMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PaypalClaimStudentAckMail.php) —
  подтверждение приема заявки с деталями, сроком и эскалацией. Админское письмо
  не изменено (машинный порог handoff).
- Флеш после отправки: срок + обещание письма-подтверждения.

## Заметки о регистре

- **Причина ручной сверки названа честно**: «PayPal не поддерживает автосписание
  на нашей платформе» — формулировка аудита F3. Причина снимает главный страх
  диаспорного покупателя («платеж просто исчез?»): ручное — не сломанное.
- **Срок конкретен и с эскалацией**: «обычно в течение одного рабочего дня» +
  «если рабочий день прошел, а доступа нет — напишите нам в Telegram». Ограниченное
  ожидание с выходом — тот же ход, что «если через 10 минут…» из общей строки 1.
- **Money-fear правило**: снятие двойного списания стоит наверху страницы заявки
  (не в подвале) и внутри выделенного блока письма — общая строка 2 дословно.
- **«Намасте, {имя}!»** — маркер школы, сохранен в письме студенту.
- **Без ё** в новой копии (D13; «всё» в значении «все до единого» здесь не
  встретилось) — закреплено тестом `student_ack_mail_renders_expectations`.
  Существующие ё в нетронутых строках формы (`придёт`, комментарии кода) — зона
  H1295, лейн их не подметал.

## Строки в финальной форме

### CTA на чекауте (`partials/paypal-cta.blade.php`)

> **Оплата из-за рубежа?** Оплатите через PayPal и сообщите нам — доступ откроем
> после сверки платежа, обычно в течение одного рабочего дня.
>
> _(кнопка)_ Оплатить через PayPal
>
> _(мелкой строкой)_ PayPal не поддерживает автосписание на нашей платформе,
> поэтому каждый платеж сверяем вручную. Подтверждение заявки придет на email.

### Обязательные поля сверки (H2017)

Personal (non-business) PayPal has no auto-match API. Admin reconciles by:

1. **С какого PayPal платили** (`claim_meta.paypal_payer`)
2. **Дата оплаты** (`claim_meta.paid_on`)
3. **Сумма + валюта** (`foreign_amount` / `foreign_currency`)

Txn id and screenshot are optional helpers, not substitutes for the triple.

### Paste-to-fill (H2215)

На шаге 2 формы — блок **«Вставить детали из PayPal»**: студент копирует целиком
текст деталей платежа со страницы Activity (или из письма-чека) и жмет
«Заполнить из вставки». Клиентский парсер
([`public/js/paypal-claim-paste.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/js/paypal-claim-paste.js))
заполняет `paypal_payer` / `paid_on` / `foreign_amount` / `foreign_currency` /
`paypal_txn`, когда они находятся в тексте. Пустые поля не выдумываются; форма
не отправляется сама — студент проверяет и жмет «Отправить уведомление…».
Серверная валидация `StorePaypalClaimRequest` без изменений. Фикстуры EN/RU:
[`tests/fixtures/paypal_claim_paste/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/paypal_claim_paste).
**Primary real sample (MG 06-08-2026, DE P2P email):** fixture
[`tests/fixtures/paypal_claim_paste/de_p2p_email_mg.txt`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/paypal_claim_paste/de_p2p_email_mg.txt)
— fills **date + EUR amount + txn**; **from-account is absent** in this dump (sender
mail has no payer email), so the student still types `paypal_payer` by hand.
CHF conversion/fee lines are ignored (form only accepts USD/EUR). EN/RU synthetic
fixtures remain regression coverage.

### Страница заявки (`paypal/claim.blade.php`)

**Шапка (QR + вводный абзац, #2012):** справа от заголовка и вводного абзаца —
QR оплаты (`w-24 sm:w-28`, скругленная рамка) с подписью:

> Или отсканируйте QR в приложении PayPal

QR ведет на managed-ссылку PayPal (`paypal.com/qrcodes/managed/d83566ef-…`),
которая раскрывается только внутри приложения PayPal. Ассет статический,
без build-шага.

**Прямая ссылка (шаг 1, #2014):** под кнопкой `PAYPAL_ME_LINK` — постоянная
строка, не зависящая от env:

> Прямая ссылка для перевода: **https://www.paypal.com/paypalme/gasuns**

На checkout-CTA сырая ссылка сознательно не добавлена: оплата мимо страницы
заявки выпадает из уведомления и ручной сверки.

**Вводный абзац:**

> Этот путь — для оплаты из-за рубежа, где карта РФ не работает. PayPal не
> поддерживает автосписание на нашей платформе, поэтому оплата идет в два шага:
> вы переводите оплату и сообщаете нам, а мы сверяем платеж вручную — обычно в
> течение одного рабочего дня — и открываем доступ.

**Плашка наверху страницы (общая строка 2, дословно):**

> Если деньги списались — не платите повторно: напишите нам, мы проверим платеж
> и либо откроем доступ, либо вернем деньги.

**Шаг 3 — «Что будет дальше»:**

> 1. Сразу после отправки пришлем на email подтверждение, что заявка получена.
> 2. Обычно в течение одного рабочего дня сверим платеж в PayPal и откроем
>    доступ. Для нового аккаунта на email придет пароль от личного кабинета.
> 3. Если рабочий день прошел, а доступа нет — напишите нам в Telegram, обычно
>    отвечаем в течение рабочего дня.

Шаги 1 и 2 формы (куда платить / сообщить об оплате) не переписывались — их
строки уже конкретны и в голосе; лейн добавил рамку ожиданий вокруг них.

**Флеш после отправки (`PaypalClaimController::store`):**

> Спасибо, заявка получена — подтверждение уже уходит на ваш email. Мы сверим
> платеж, обычно в течение одного рабочего дня, и откроем доступ; для нового
> аккаунта пароль придет на email.

### Письмо студенту (`PaypalClaimStudentAckMail`)

**Тема:** Заявка получена — сверяем ваш платеж PayPal

**Прехедер:** Сверим платеж — обычно в течение одного рабочего дня — и откроем
доступ.

> Намасте, {имя}!
>
> Ваше уведомление об оплате через PayPal получено — вот что мы записали:
>
> | | |
> |---|---|
> | Курс | **«{курс}»** |
> | Тариф | полный курс / блок {N} / блоки {N}–{M} |
> | Заявленная сумма | {N.NN} $/€ |
>
> _(выделенный блок)_ Платеж сверяем вручную: PayPal не поддерживает автосписание
> на нашей платформе. Обычно сверка занимает не больше одного рабочего дня — как
> только она пройдет, доступ откроется. Для нового аккаунта пароль придет на
> email отдельным письмом.
>
> _(там же)_ Если деньги списались — не платите повторно: напишите нам, мы
> проверим платеж и либо откроем доступ, либо вернем деньги.
>
> Если рабочий день прошел, а доступа нет — напишите нам в Telegram, обычно
> отвечаем в течение рабочего дня.
>
> Вопросы — напишите нам в Telegram, обычно отвечаем в течение рабочего дня.
> С уважением, Общество ревнителей санскрита

Строка «Заявленная сумма» показывает валютную сумму студента
(`Payment::foreignAmountLabel`), не рублевый номинал: рублевая цифра, которую
студент не платил, в чеке-подтверждении читалась бы как ошибка. Рублевый номинал
остается в админском письме, где он учетный.

## Decisions taken unattended

1. **Срок сверки — «обычно в течение одного рабочего дня».** Задокументированного
   SLA сверки нигде нет; взят консервативный срок из готовой строки F3 аудита
   («доступ откроем в течение 1 рабочего дня после сверки платежа»), согласованный
   с латентностью поддержки из общей строки 3 («обычно отвечаем в течение
   рабочего дня»). Альтернатива — не называть срок — запрещена лейном прямо.
   MG подтверждает или ужесточает; правится в четырех местах (CTA, страница,
   флеш, письмо), все перечислены в этом доке.
2. **Флеш переписан**, а не сохранен: старый обещал только «как только сверим»,
   без срока, и содержал ё («платёж», «придёт»). Новый добавляет срок и обещание
   письма-подтверждения; ё снято (D13).
3. **Письмо студенту — стилизованный HTML** по образцу
   [`purchase-confirmation.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/purchase-confirmation.blade.php)
   (домашний паттерн студенческих писем), а не markdown-mail (паттерн админских).
   Альтернатива — markdown-mail как у админского зеркала — отвергнута ради
   единого вида писем студенту.
4. **Точка диспетча — `PaypalClaimController::store`, после админского письма.**
   Переиспользован существующий путь уведомлений (дефолт лейна «reuse the
   existing path»); наблюдатель/событие не заводились. Контроллер заявок — не
   заборная зона (правило 3 закрывает Точку, вебхук и
   `PaymentObserver::grantAccess`; заявка не создает и не двигает деньги).
5. **Объем тарифа в письме — копия приватного `tariffScope()` из
   `PurchaseConfirmationMail`**, а не `Payment::operationLabel()`: последний для
   служебных типов платежей добавляет эмодзи, запрещенные контрактом голоса в
   транзакционных письмах. Дубль ~15 строк принят сознательно; вынос в трейт —
   рефакторинг за рамками копи-лейна.
6. **Строки формы (шаги 1–2) не тронуты** — они уже конкретны и в голосе; лейн
   ограничился рамкой ожиданий (ввод, плашка, шаг 3), чтобы диф оставался
   копи-ревьюируемым. Существующие ё в них — зона H1295.

_Dr. Mārcis Gasūns_
