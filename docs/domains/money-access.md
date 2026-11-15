# Money / access — короткая карта

## Когда читать

Обязательно перед изменением оплаты, тарифов, цен, промокодов, депозитов, праны как скидки, реферального кредита, доступа к урокам, курсов-записей, возвратов и связанных вебхуков.

## Порядок чтения

1. `CLAUDE.md` → **Money contour**.
2. Эта карта.
3. [money-access-core-manual.md](../money-access-core-manual.md) — только разделы затрагиваемого механизма.
4. Код и профильные тесты.

## Инварианты

- Успешная оплата проходит через `Payment::processSuccessfulPayment()` → `Payment::grantAccess()` и добавляет пользователя в группу курса. Ручное назначение групп не является штатной заменой этого пути.
- `tariff.is_active` ограничивает **покупку**, а не доступ по уже существующей оплате. Скрытие курса не означает автоматически запрет продажи по прямой curator-ссылке.
- Источник истины по ключам доступа: `Tariff::accessKey()`, `Lesson::unlockingKeys()`, `Lesson::isUnlockedBy()`.
- Форматы ключей: `full`, `block_N`, `block_N_hH`. Не создавать параллельную логику вычисления ключей.
- Платные статусы получать через существующие scopes/константы `Payment`, а не дублировать список статусов.
- Цена рассчитывается через `Tariff::calculateFinalPriceForUser()`; апгрейд-кредит и уже оплаченные части нельзя потерять.
- Финансовые пороги находятся в конфигурации, не в новых литералах в коде.
- Курс-запись должен иметь собственные уроки. Исправление выполняется через `catalog:mirror-recording-lessons` после сухого прогона, а не выдачей доступа к другому курсу и не изменением тарифов/видимости.
- Право проверки ДЗ — `group_reviewer`, не `course_teacher`: последнее влияет на зарплатный контур.
- Взаиморасчёты выполняются только в `MutualSettlementService` и вычитаются до payout.

## Точки входа в код

| Область | Файлы |
|---|---|
| Платёж и доступ | `app/Models/Payment.php`, `app/Observers/PaymentObserver.php` |
| Тариф и цена | `app/Models/Tariff.php` |
| Ключи урока | `app/Models/Lesson.php` |
| HTTP оплаты/вебхуков | `app/Http/Controllers/PaymentController.php`, `WebhookController.php` |
| Курс-запись | `app/Console/Commands/MirrorRecordingLessons.php` |
| Зарплата и взаиморасчёты | `app/Services/TeacherSalaryService.php`, `MutualSettlementService.php` |

## Проверки

```bash
php artisan test --filter=CheckoutPriceTest
php artisan test --filter=TochkaWebhookTest
php artisan test --filter=MirrorRecordingLessonsTest
php artisan test --filter=TeacherSalary
```

Добавь тесты конкретного изменения: половины блоков, upgrade credit, возвраты, повторный вебхук, статус оплаты, границы доступа, поздние выплаты, закрытые периоды или PayPal — по фактическому контуру.

Для курса-записи:

```bash
php artisan catalog:mirror-recording-lessons <source> <target>
```

Сухой прогон обязателен. `--apply` выполнять только после проверки совпадения результата и по правилам репозитория.

## Связанные документы

- [webhook-security.md](../webhook-security.md)
- [money-core-adversarial-review.md](../money-core-adversarial-review.md)
- [revenue-recognition.md](../revenue-recognition.md)
- [ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md](../ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md)

## B2B-оплата «за группу целиком» (H8245)

Юрлицо/студия платит за всех учеников группы одним платежом (Адара → курс 463,
гр. «Грамматика по Кочергиной»), без чекаута учеников.

- **Тариф-ключ платежа:** `b2b_group` (константа `Payment::TARIFF_B2B_GROUP`,
  `Payment::isB2bGroup()`). Платёж несёт `group_id` — за какую группу платят.
- **Флаг `features.b2b_group_payment`** (env `B2B_GROUP_PAYMENT`, дефолт OFF).
  OFF: b2b_group-платёж — чисто денежная строка, контур прежний. ON:
  - ученики группы получают виртуальные ключи доступа через
    `B2bGroupEntitlement::extraTariffKeysFor()` (зеркало клубного контура:
    ключ живёт только на запросе, откат платежа закрывает доступ немедленно);
  - блок-калькулятор атрибутирует b2b-платёж группе по `payments.group_id`
    (плательщик — юрлицо, в group_user не состоит);
  - должники не считают (user, course), покрытые b2b-платежом группы.
- **Деньги всегда считаются** (вне флага): b2b_group не входит в
  `NON_REVENUE_TARIFFS`, курсовой accrual и 30% ЗП работают как у обычной
  оплаты. Приём — существующие пути: нал (`payment_method='cash'`,
  `received_account=school`), счёт юрлицу (`provider=invoice`, H2017), Точка.
- **Плательщику доступ не выдаётся** (не студент): `processB2bGroupPaid()` —
  только curator-уведомление; `grantsCourseAccess()` отсекает b2b_group, поэтому
  откат b2b-платежа НЕ детачит учеников группы.
- **Проверка:** `php artisan test --filter=B2bGroupPaymentTest`.
