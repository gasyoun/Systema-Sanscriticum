# RUNBOOK — штатный путь оплаты из-за рубежа (PayPal-клейм → доступ вручную) + кейс Морозовой (H6646)

_Created: 13-10-2026 · Last updated: 13-10-2026_

**Аудитория:** кураторы/ops/агенты. **Когда:** ученик за рубежом не может оплатить картой через Точку — типовая причина: 3DS-код подтверждения уходит на российскую симку плательщика. **⛔ Граница:** 3DS-маршрутизацию чинит ТОЛЬКО банк плательщика (перенос кода на рабочий канал/карту) — школой кодом это не лечится; школой чинится **обходной путь оплаты**, и он штатный: PayPal-клейм + ручное открытие доступа.

**Источники:** [PaypalClaimController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaypalClaimController.php), [ClaimTrustPolicy.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payments/ClaimTrustPolicy.php) (H5083), [buyer-path копия H1292](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-diaspora-paypal-buyer-path.md), [money-access-core-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md), H6534 ([PR #3215](https://github.com/gasyoun/Systema-Sanscriticum/pull/3215), мульти-блок) + H6554 ([PR #3227](https://github.com/gasyoun/Systema-Sanscriticum/pull/3227), кросс-курсовой), [handoff H6646](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6646-OxAlpha_Systema-Sanscriticum_abroad-pay-fallback-morozova_13.10.26.md).

## Путь ученика (что продиктовать)

1. Перевод на школьный PayPal: постоянная строка и QR на claim-странице ведут на **paypal.me/gasuns** (канон хэндла, MG 23-08-2026); получатель `gasyoun@gmail.com`. Комиссия — на отправителе; при расхождении сумм — доплата.
2. Заявка на сайте: **`/paypal/{tariff_id}`** — форма «Уведомление об оплате через PayPal» (email плательщика, дата, сумма, опц. txn/скрин).
3. Один перевод на несколько блоков/курсов — штатно: селектор на блочных тарифах (мульти-блок в пределах курса — H6534; кросс-курсово — H6554). Вердикт сверки считается по **общей** сумме перевода.
4. Ученик прислал только скрин PayPal без заявки → отправить шаблон `/оплата-paypal-скрин` ([ORS-FAQ/Telegram_templates.md](https://github.com/gasyoun/ORS-FAQ/blob/main/Telegram_templates.md)): заявка своим ученикам открывает доступ сразу.

## Путь куратора — владелец шага ручного открытия доступа

**Владелец: куратор (Иван). Fallback: любой админ с Filament-доступом.**

| Шаг | Где | Срок (SLA) |
|---|---|---|
| Свежая заявка на проверке | Filament → Payments → фильтр **«Заявки PayPal на проверке»** → **«Подтвердить PayPal»** | **≤ 24 ч** от полной заявки |
| Выборочная сверка пост-фактум | Фильтр **«PayPal: без сверки»** → «Сверка пройдена» (штамп `verified_at`) / «Нет платежа — отменить» | в течение недели |
| Эскалация, если заявка/доступ не закрыты | куратор → MG | после **48 ч** |

- Своим (устоявшимся) ученикам доступ открывается **сразу** при подаче заявки (`ClaimTrustPolicy`: аккаунт ≥ 24 ч ИЛИ ≥ 1 проведённый платёж; `claim_meta.auto_trusted`). Гости ждут ручного подтверждения — это и есть шаг куратора.
- Доступ после ручного подтверждения открывает штатный `Payment::booted()` (группы курса). Курс без своих групп → `grant_access_fail_closed` не даст тихой дыры.
- **Фолбэк, если форма ученику недоступна совсем:** куратор создаёт Payment вручную в Filament (user, курс, тариф `block_N`, amount = рублёвый номинал, `foreign_amount`/`foreign_currency`, provider **PayPal**, status **paid** своим / **pending** новым) — конвейер дальше тот же.
- Доказательства перевода складываются в `paypal_receipt_evidence` / `paypal_payment_evidence_links` (импорт чеков — [ImportPaypalReceiptEvidence.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportPaypalReceiptEvidence.php)); повтор одного txn на тот же тариф блокирует replay-ключ.

**Что НЕ делать:** не трогать платёжный флоу/Точку ради зарубежной карты; не «чинить» 3DS кодом; не давать доступ кросс-курсово «на доверии» без Payment-строки; ПД построчно наружу не вывозить — только агрегаты.

## Кейс Морозовой — статус школы на 13-10-2026 (агрегаты, без ПД построчно)

Пробы прода .92, рид-онли, 13-10-2026:

| Факт | Значение |
|---|---|
| Блок 2 интенсива (курс 438, тариф «Блок 2», 8 000 ₽, активен) | оплатили с 08-09: **3 из 4** учеников курса |
| Морозова, оплаты по 438 с 08-09 | **0** (блок 2 не оплачен; долг 8 000 ₽ с 08-09 по данным на 15-09 — подтверждён) |
| Доступ к курсу 438 | **не отозван** (учится по блоку 1, который оплачен) |
| Открытая PayPal-заявка от неё | **0** — обходной путь пока не задействован |

**План (stop-budget: 3 попытки связи через куратора, дальше @DECIDE MG):**

1. Куратор (владелец шага) связывается с ученицей — до **3 попыток**, предлагает путь PayPal-клейм (перевод `paypal.me/gasuns` + заявка `/paypal/{tariff}`; мульти-блок селектором, если решит закрыть вперёд).
2. Оплата пришла → заявка/ручной Payment → доступ подтверждается вручную в тот же день (SLA выше).
3. Ни одна из 3 попыток не удалась к 2026-10-20 → **@DECIDE MG** (продление доступа без блока 2 / отзыв доступа / иное), решение фиксируется в [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).
4. Запись ответственного в треде школы — за куратором (шаг 1), резидюльный @DO заведён в GTD (строка при H6646).

## Baseline и пробы 13-10-2026

- `paypal_payment_evidence_links` жив: **17 записей за 30 д** (и всего 17 — таблице месяц) — зонд P3, рид-онли COUNT.
- Кросс-курсовой/мульти-блочный клейм смёржен и на проде: H6534 [PR #3215](https://github.com/gasyoun/Systema-Sanscriticum/pull/3215) (деплой `4723763f`), H6554 [PR #3227](https://github.com/gasyoun/Systema-Sanscriticum/pull/3227), оба 12-10-2026.
- Проба долга (агрегат): `payments` курс 438, тариф `block_2%`, paid-статусы, created_at ≥ 2026-08-09 → 3 distinct плательщика.
