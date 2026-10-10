# RUNBOOK — рублёвый самоприём /gasuns-pay/{тариф} (флип paid, replay-гард, фолбэк по чеку)

_Created: 09-10-2026 · Last updated: 09-10-2026_

**Аудитория:** ops/кураторы/агенты. **Когда:** ученик сообщил «перевёл рубли лично Гасунсу» (мимо Точки) — деньги вносятся через анкету `/gasuns-pay/{тариф}` или вручную по чеку, если анкеты не было. **⛔ Запрет:** `/teacher-pay` для этих денег ОПАСЕН — см. одноимённый раздел.
**Источники:** [GasunsPayClaimController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/GasunsPayClaimController.php) (H6198), [routes/web/05-payments-and-money.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web/05-payments-and-money.php), [config/services.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php), [RUNBOOK_FEATURE_FLAG_FLIP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_FEATURE_FLAG_FLIP.md), H6141 (кейс Хадиджи).

## Что это за канал (одним абзацем)

Анкета-уведомление «я перевёл рубли лично Гасунсу», зеркало `/teacher-pay` (H4627) и `/paypal` (pending-заявки). Учётная семантика — деньги ШКОЛЫ: `amount` = рублёвый номинал тарифа, `received_account=school`, `provider=gasuns_transfer`; гонорар преподавателю начисляется движком payout как с обычной школьной оплаты, из него ничего не вычитается. Чек хранится приватно (disk `local`, каталог `gasuns-pay-proofs`, НЕ public); при подаче уходят письма куратору, админу и ack ученику.

## Флаги (прод samskrte.ru = .92, `/var/www/html`)

| Флаг | Default | Смысл |
|---|---|---|
| `GASUNS_PAY_ENABLED` | OFF | вкл/выкл всей поверхности (выключена → 404) |
| `GASUNS_PAY_TRUST_EXISTING` | true (кодовый) | kill-switch авто-доверия устоявшимся ученикам |

Живое состояние прода на 09-10-2026 (проба): `enabled=true, trust=true`; переменная `GASUNS_PAY_TRUST_EXISTING` в `.env` не задана — работает кодовый default.

```bash
# рид-онли проба флагов (на .92):
cd /var/www/html && php artisan tinker --execute='echo "enabled=",var_export(config("services.gasuns_pay.enabled"),true)," trust=",var_export(config("services.gasuns_pay.trust_existing_students"),true);'

# живой GET поверхности (рид-онли):
curl -s -o /dev/null -w '%{http_code}\n' https://samskrte.ru/gasuns-pay/5134
# 09-10-2026: HTTP 200
```

## Авто-доверие: кто получает paid сразу

Вошедший УСТОЯВШИЙСЯ ученик (аккаунт ≥ 7 дней ИЛИ уже есть проведённый платёж) И у курса есть группы доступа → payment сразу `status=paid`, доступ открывает штатный `Payment::booted()`, сверка выборочная пост-фактум по выписке получателя (рулинг MG 06-10-2026 «по умолчанию сверка сразу проходит»).

Fail-closed понижения в pending: гость с новым email (аккаунт создаётся и логинится); гость с СУЩЕСТВУЮЩИМ email — отказ «войдите в личный кабинет»; курс без групп доступа — pending с `claim_meta.reconciliation_exception=no_access_groups`.

## Флип paid (для pending-заявок)

`*** GATE ***` (меняет деньги): когда выписка получателя подтвердила поступление:

1. Filament → группа «Финансы» → [PaymentResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php) → найти payment по `provider=gasuns_transfer`, `status=pending`.
2. `status` → `paid`. Доступ откроется сам обсервером `Payment::booted()`; ack-email ученику уже ушёл при подаче анкеты.

## Replay-гард (почему повторная заявка не пролезет)

Ключ повтора `gasuns:{user_id}:{tariff_id}` сидит в unique-индексе `payments.claim_replay_key` (миграция 2026_09_24, money-P0) плюс сериальный пре-чек: одна незакрытая (pending|paid) заявка на тариф от пользователя. Гонка двух одновременных POST отбивается `UniqueConstraintViolationException` → отказ-валидация, не 500. Легитимный второй перевод того же блока — только через администратора (ручное внесение ниже), не повторной анкетой.

## Фолбэк: ручной ввод по чеку

`*** GATE ***` Если анкета не подавалась (ученик прислал чек напрямую — прецедент Хадиджи, H6141):

1. Filament «Финансы» → PaymentResource → создать payment: user, course, `tariff` = accessKey тарифа, `amount` = рублёвый номинал тарифа, `provider=gasuns_transfer`, `received_account=school`, после сверки чека `status=paid`, в `payer_note` — «Перевод рублями лично Гасунсу · from: … · paid_on: …».
2. Файл чека хранить вне public-диска (зеркало `gasuns-pay-proofs`).

## ⛔ /teacher-pay для канала ОПАСЕН

`/teacher-pay` — канал ГОНОРАРОВ: там `received_account=teacher_personal`, платёж считается выручкой преподавателя и попадает в его ведомость и выплаты. Личная оплата Гасунсу, внесённая туда, искажает гонорар курса и ведомость (кейс Хадиджи: 8 000 ₽ выпали из расчёта Костиной H6141 именно из-за отсутствия правильного канала). Только `/gasuns-pay` или ручной Payment с `provider=gasuns_transfer`.

## Чек после внесения

```bash
# на .92: последние платежи канала (рид-онли):
cd /var/www/html && php artisan tinker --execute='echo App\Models\Payment::where("provider","gasuns_transfer")->orderByDesc("id")->limit(3)->get(["id","user_id","tariff","amount","status","created_at"])->toJson(JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);'
```

## Откат / kill-switch

Флаги в `.env` (правит владелец по визе MG) → `php artisan config:cache` → `systemctl reload php*-fpm*` → `php artisan horizon:terminate` (строго в этом порядке — гочи «Horizon до config:cache» канонизирован в [RUNBOOK_FEATURE_FLAG_FLIP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_FEATURE_FLAG_FLIP.md)). Выключение `GASUNS_PAY_ENABLED` даёт 404 на обе поверхности; уже созданные платежи не затрагиваются.

_Dr. Mārcis Gasūns_
