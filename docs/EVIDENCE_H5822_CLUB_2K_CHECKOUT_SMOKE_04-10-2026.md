# EVIDENCE H5822 — тир Клуб ₽2 000: аудит wiring + чекаут-смоук

_Created: 04-10-2026_

_Executor: OxAlpha (`opencode/z-ai/glm-5.3-flash`) · H5822 (C2 правления MG 03-10-2026, лист 0LV)_

## 1. Аудит текущего wiring тира ₽2 000

Тир уже введён в код ранее — H5822 не требовал нового прайсинга, а проверил сквозность:

| Точка wiring | Где | Факт | Статус |
|---|---|---|---|
| Конфиг тира | [`config/membership.php` `tiers.club.monthly_price = 2000`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/membership.php) | строка 21, коммит `205525a47` (MG, 16-08-2026) | ✅ без диффа |
| Цены за срок | [`app/Enums/MembershipTier.php::priceForTerm`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Enums/MembershipTier.php) | Club 1/3/12 = 2000 / 5700 (−5 %) / 20400 (−15 %) | ✅ |
| Цены в письмах | [`config/marathon.php` `membership_club_month_price = 2000`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon.php) | warmtail-письмо | ✅ |
| Тарифные строки БД | Filament / `membership:ensure-club-stream-tariffs` | цены читаются из БД (лендинг и чекаут — одна строка) | ✅ (ops-шаг человека) |
| Флаги | `MEMBERSHIP_TIERED`, `CLUB_MEMBERSHIP` (`config/features.php`) | прод: **OFF** — тёмный деплой, пользовательского флипа нет | ✅ |
| RU-копи на странице | [`resources/views/shop/club.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/club.blade.php) | карточки «Базовый» / «Клуб», цена ₽2 000 из БД, `2 000 ₽/мес` | ✅ (H2645) |
| Чекаут | `CheckoutController::show` + `payment.create` → Tochka | покрывался только лендинг-тестами; сквозного смоука не было | ⛔ → закрыто этим PR |

**Конфиг-дифф: пустой (намеренно).** Аудит показал: прайсинг ₽2k уже в конфиге с 16-08-2026, менять нечего; правки конфига без MG в money-контуре не делаются.

## 2. Что добавлено

Один файл — [`tests/Feature/Membership/ClubTwoKCheckoutSmokeTest.php`](https://github.com/gasyoun/Systema-sanscriticum/blob/main/tests/Feature/Membership/ClubTwoKCheckoutSmokeTest.php), 3 теста / 25 утверждений:

1. `test_tier_config_carries_the_2k_club_price` — конфиг 2000 + контракт H3331: Club 2000/5700/20400 и Basic 1000/2850/10200 (ловит перепутанные тиры).
2. `test_landing_and_checkout_render_the_2k_price_from_db` — лендинг `/klub` и страница чекаута рендерят «2 000» и CTA на чекаут тарифа.
3. `test_checkout_creates_pending_payment_then_paid_grants_club_membership` — полный контур: POST `payment.create` → pending-платёж ровно на 2000.00 с ключом `membership_club_1m` → вебхук (pending→paid) → `ClubMembership` тира Club на 1 месяц + клубная группа.

Точка подменена `Http::fake` — **ноль реальных списаний**; флаг `MEMBERSHIP_TIERED` включается только внутри тестового окружения, прод-флаги не тронуты.

## 3. RU-копи (текст, стейджнут за флагом)

Лендинг клуба (`/klub`, виден только при `CLUB_MEMBERSHIP=true`) отдаёт карточку тира:

> **Клуб** — 2 000 ₽/мес — вся библиотека записей курсов, полка в кабинете и тренажеры… Без живых потоков, без проверки домашних заданий, без сертификата — поэтому и цена такая.

Тест утверждения: `assertSee('Клуб')`, `assertSee('2 000')`, CTA `route('checkout.show', $tariff)` — PASS.

## 4. Smoke-лог

```
vendor/bin/phpunit tests/Feature/Membership/ClubTwoKCheckoutSmokeTest.php
PHPUnit 11.5.56 · Runtime: PHP 8.5.9
...  3 / 3 (100%)
OK (3 tests, 25 assertions)
```

Регрессия смежного пакета:

```
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Membership tests/Feature/CheckoutPriceTest.php
OK, but there were issues! Tests: 139, Assertions: 532, PHPUnit Deprecations: 4.
```

(4 депрекации — PHP 8.5 `setAccessible` в bootstrap, предсуществующие, к работе отношения не имеют. Pint `--dirty`: passed.)

## 5. Остаток (человек, money-row)

Флип на проде остаётся за MG — «ничего пользовательского без финального взгляда»: `MEMBERSHIP_TIERED=true` + `CLUB_MEMBERSHIP=true` (config:cache), тарифные строки 1/3/12 в Filament (или `membership:ensure-club-stream-tariffs --apply`), затем один живой чекаут по чеклисту H3331. Агент живых платежей и флипов не делает.

## 6. Delivery (пять полей H5822)

- **Changed:** новый чекаут-смоук-тест тира ₽2k (3 теста); отчёт-свидетельство. Конфиг/копи — без изменений (аудит: уже на месте).
- **Unchanged:** `config/membership.php`, `config/marathon.php`, `shop/club.blade.php`, прод-флаги, тарифные строки БД, платёжные пути.
- **Checks:** `vendor/bin/phpunit tests/Feature/Membership/ClubTwoKCheckoutSmokeTest.php` → OK (3/25); пакет Membership+CheckoutPrice 139 тестов → OK; Pint → passed.
- **Risks:** смоук не ловит расхождение цен БД-vs-контракт на проде (DB — источник истины; сверка цен — `membership:rehearse`/ops-шаг человека при флипе). PHP 8.5 депрекации в bootstrap — предсуществующие.
- **Inspect:** `tests/Feature/Membership/ClubTwoKCheckoutSmokeTest.php`, §1–§4 этого отчёта, `config/membership.php:21`, `resources/views/shop/club.blade.php`.

_Dr. Mārcis Gasūns_
