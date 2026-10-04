# Реферальная программа Systema — механика (награда, триггер, трекинг)

_Created: 04-10-2026 · Last updated: 04-10-2026_

_H5825 (C5, batch-3 лист голосования, sheet 0LV, решение MG 03-10-2026). Документ
фиксирует механику по коду main — трекинг, награда и RU-копи уже в проде; здесь
они собраны как один контракт + лист подписи MG. Исполнитель: OxAlpha
(opencode/z-ai/glm-5.3-flash)._

## Суть в трех строках

- **Награда**: 500 ₽ денежным кредитом на `referral_credit` пригласившему;
  зачитывается автоматически в его следующую покупку.
- **Триггер**: приглашенный ВПЕРВЫЕ оплачивает курс (`PaymentObserver`, переход
  в paid). Ровно один раз на приглашенного, с реверсом при откате платежа.
- **Трекинг**: ссылка `/?ref=CODE` → код в сессии → привязка `users.referred_by`
  при регистрации на чекауте → аудит-таблица `referral_rewards`.

## 1. Награда

- Размер — `config('referral.credit_amount')`, default 500 ₽
  ([config/referral.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/referral.php)).
- Кошелек — колонка `users.referral_credit`. При следующей покупке суммируется
  в чекауте как шаг 6 прайс-каскада
  ([PaymentController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php)):
  кредит гасит итоговую цену до нуля, остаток остается на кошельке; на платеже
  остается `referral_credit_applied`.
- Обе стороны (MG 02-08-2026): кредит приглашенному
  (`REFERRAL_REFERRED_CREDIT_AMOUNT`) закодирован, но **default 0 = dark** —
  в проде не начисляется, пока человек не выставит env + `config:cache`.

## 2. Триггер

- [PaymentObserver.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentObserver.php)
  вызывает `ReferralService::rewardForPayment` и при `created`-сразу-paid, и при
  переходе статуса в paid (вебхук Точки pending → paid).
- Квалификация платежа
  ([ReferralService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/ReferralService.php)):
  реальная (не conditional), сумма > 0, `course_id` заполнен, НЕ deposit /
  trial / Расход / salary_payout. Бесплатные и служебные события награду не
  сжигают.
- Одноразовость: `referral_rewards.referred_id` unique + идемпотентность-гейт
  против старых прана-наград (`PranaTransaction` reason `referral`) — переход
  с праны на кредит не задвоил тех, кого уже наградили.
- Реверс: paid → failed/canceled снимает начисленный кредит (не ниже нуля) и
  удаляет строку награды, освобождая unique-ключ для законной будущей награды.
- Сбой награды не ломает оплату: `try/catch` с `Log::warning`.

## 3. Трекинг

- Приглашающий: `User::referralCode()` — ленивый минт уникального кода,
  `User::referralLink()` = `url('/?ref=CODE')`
  ([User.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/User.php)).
- Переход по ссылке: мидлвэр
  [CaptureReferral.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/CaptureReferral.php)
  (web-группа) запоминает `?ref=CODE` в сессии, первый код выигрывает, не
  перезаписывается.
- Привязка: при регистрации на чекауте `PaymentController` вызывает
  `ReferralService::attachReferrer($user, ref-из-формы ?: session('ref'))`.
  Идемпотентно: нельзя переписать существующего реферера, себя к себе,
  несуществующий код игнорируется.
- Аудит: таблица `referral_rewards` — по строке на приглашенного
  (referrer_id, referred_id, payment_id, amount, referred_amount).
- B2B-партнерская программа ([PartnerService.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PartnerService.php))
  независима: свои коды, свой мидлвэр `CapturePartnerReferral`, свои награды.

## 4. RU-копи: поверхности и регистр

Поверхности:

- кабинет студента, блок «Порекомендовать школу» —
  [student/partials/referral.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/referral.blade.php):
  личная ссылка + кнопка копирования + готовый текст от первого лица + счет
  начисленного кредита;
- именное приветствие приглашенному на главной —
  [partials/referral-welcome.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/referral-welcome.blade.php);
- копи-контракт и регистр (рекомендация, не заработок; без геймификации, без
  «бонусной пошлости», без ё) —
  [docs/copy/money-referral-invite-ask.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-referral-invite-ask.md)
  (H1294).

## 5. Конфиг-ручки

| Ручка | Default | Эффект |
|---|---|---|
| `REFERRAL_CREDIT_AMOUNT` | 500 | ₽ пригласившему за первую оплату приглашенного |
| `REFERRAL_REFERRED_CREDIT_AMOUNT` | 0 (dark) | ₽ приглашенному; >0 включает обе стороны |
| `CHECKOUT_REFERRAL_CREDIT_LOCK` | true | сериализация чекаутов одного студента, чтобы кредит не применился дважды |

## 6. MG sign-off list (решает человек, не агент)

| # | Пункт | Статус | Доказательство | Подпись MG |
|---|---|---|---|---|
| 1 | Механика (награда/триггер/трекинг) | действует в коде | §1–3 + тесты §7 | ☐ |
| 2 | RU-копи поверхностей | действует | §4 | ☐ |
| 3 | Минимальный трекинг достаточен (без платежной интеграции) | выполнено | §3, фейл-ветка §8 | ☐ |
| 4 | Обе стороны: включать ли приглашенному 500 ₽ (`REFERRAL_REFERRED_CREDIT_AMOUNT=500` + `config:cache`)? | решение | §1, §5 | ☐ |

## 7. Проверки

Рабочее дерево h5825-drain от origin/main, 04-10-2026, PHPUnit 11.5.56 / PHP 8.5
(депрекейшены ReflectionProperty — шум PHP 8.5, не падения):

- `vendor/bin/phpunit tests/Feature/ReferralProgramTest.php` — OK (21 tests, 47 assertions)
- `vendor/bin/phpunit tests/Feature/ReferralCreditCheckoutTest.php` — OK (6 tests, 21 assertions)
- `vendor/bin/phpunit tests/Feature/ReferralAskSurfacesTest.php` — OK (6 tests, 20 assertions)
- `vendor/bin/phpunit tests/Unit/PromoCodeTest.php` — OK (7 tests, 9 assertions)

## 8. Фейл-ветка хендоффа

Условие «трекинг требует платежной интеграции вне скоупа» НЕ сработало: трекинг
(`referral_code` / `referred_by` / `referral_rewards`) уже в main, платежная
интеграция не добавлялась — награда опирается на существующий контур оплаты
(`PaymentObserver`). Ручной вариант не потребовался.
