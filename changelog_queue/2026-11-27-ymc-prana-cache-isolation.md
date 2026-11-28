# YMC: детерминизм награды праны — статик MarketingSetting больше не протекает между тестами (27-11-2026)

_Created: 27-11-2026 · Last updated: 27-11-2026_

`BookClubWebhookTest::test_delivery_payment_awards_no_prana` падал флаком `50 != 10`: тест задавал награду через `config('prana.rewards.payment_success') = 10`, но [`PranaSettings::reward()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Prana/PranaSettings.php) читает строку `marketing_settings` и только при её отсутствии падает в конфиг. Строку, созданную предыдущим тестом, откатывает `RefreshDatabase`, а процессный статик `PranaSettings::$cached` живёт дальше в том же PHPUnit-процессе — следующий тест наследует чужие **50**. `MarketingSetting::flushCached()` чистил только Laravel Cache, не этот статик; строки настроек создают десятки checkout/backfill-тестов, поэтому падение зависело от порядка прогона и снималось рераном.

Что исправлено:

- `MarketingSetting::flushCached()` дополнительно зовёт `PranaSettings::flush()` — сброс при сохранении/удалении строки (в том числе из Filament) и при любом ручном flush.
- `Tests\TestCase::setUp()` сбрасывает `PranaSettings` перед каждым тестом: изоляция всего класса дефекта, а не одной симптомной точки.
- Позитивный контроль в `BookClubWebhookTest` теперь фиксирует награду **строкой БД** (`is_prana_active=true`, `prana_reward_payment_success=10`): конфиг-оверрайд при живой строке молча игнорировался и был источником ложного сигнала.
- Новый регресс-тест `tests/Feature/Prana/PranaSettingsCacheIsolationTest` (2 теста): первый отравляет статик, второй обязан увидеть чистое состояние.

Проверка: **до** — `Failed asserting that 50 is identical to 10` (1 failed, 1 passed); **после** — 2/2, `BookClubWebhookTest` 4/4, money-контур `tests/Feature/BookClub` + `CheckoutPriceTest` + `TochkaWebhookTest` **89/89** (415 assertions), Pint чист.

Деньги и доступ не тронуты: ни сумм, ни тарифов, ни вебхуков, ни грантов — только чтение настроек праны и тестовая изоляция.

_Dr. Mārcis Gasūns_
