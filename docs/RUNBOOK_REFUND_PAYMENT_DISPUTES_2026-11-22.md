# Ранбук возвратов и платёжных споров: заявление → доказательства → виза → возврат → спор провайдера → доступ → сверка → закрытие

_Created: 22-11-2026 · Last updated: 22-11-2026_

**Аудиторие:** кураторы первой линии (шаблон №1 ранбука поддержки), Иван (ops, прод .92, Filament-админка), MG (визы на списания/возвраты и включение денежных флагов).
**Когда:** ученик просит вернуть деньги; банк/провайдер вернул captured-деньги; пришёл PayPal-спор/чарджбэк; после возврата доступ «висит» или снят лишний.
**⛔ Граница:** ранбук — операторская документация. Он не двигает деньги, не меняет доступ руками в БД, не включает флаги и не деплоит; каждую реальную выплату возвращает человек по визе MG. Прод-код по этому ранбуку не пишется.

**Источники:** оферта [`public/docs/oferta.pdf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/docs/oferta.pdf) §11 + Приложение №1 (юридическая база), студентская страница [`/vozvrat`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php) с [term-by-term diff](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-refund-policy-student-surface.md), лестница эскалации [`RUNBOOK_SUPPORT_ESCALATION.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md), D10-правила возврата в коде ([`RefundAccessPolicy.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reconciliation/RefundAccessPolicy.php), [`Payment.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php)), сверка [`MONEY_RECONCILIATION_P3_CONTRACT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_RECONCILIATION_P3_CONTRACT_2026.md) + [решения D1–D22/P0–P5](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md), доказательства H5445 ([evidence-док](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EVIDENCE_MONEY_RECONCILIATION_P3_H5445_24-09-2026.md), [handoff](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H5445-Opus_Systema-Sanscriticum_money-p3-daily-reconciliation-access_24.09.26.md)), аудит зрелости H6287 ([отчёт](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/MATURITY_PAYMENTS_CIRCUIT_2026-10-09.md), топ-3.3 «refund-контуры выключены»), PayPal-споры: [`RUNBOOK_ABROAD_PAY_PAYPAL_CLAIM_H6646_13-10-2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_ABROAD_PAY_PAYPAL_CLAIM_H6646_13-10-2026.md), включение флагов: [`RUNBOOK_FEATURE_FLAG_FLIP.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_FEATURE_FLAG_FLIP.md).

## 1. Что задеплоено сегодня, а что спит за флагом OFF

Единственное различие «сегодня / после включения» — флаг `MONEY_REFUND_ACCESS_RULES`
([`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php), строка `money_refund_access_rules`, default `false`). Ещё один флаг, `MONEY_DAILY_RECONCILIATION` (там же, default `false`), будит ночной прогон сверки в 04:35.

Третий флаг стоит держать в поле зрения: усечение выручки исходной оплаты по месяцу возврата (строка 1 таблицы) даёт [`revenue.reverse_unrecognized_on_refund`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/revenue.php) (`config/revenue.php`, default `false`; на проде включён с 12-07-2026 — [docs/revenue-recognition.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/revenue-recognition.md)). Выключение меняет признание выручки при возврате независимо от двух флагов выше.

| Поведение | Сегодня (флаги OFF, прод на 22-11-2026) | После включения `MONEY_REFUND_ACCESS_RULES=true` |
|---|---|---|
| Возврат записывается отдельной строкой «Расход» с «Возврат за платёж» (`refund_of_payment_id`) в Filament → Payments | работает; выручка исходной оплаты усекается по месяц возврата | то же |
| Частичный возврат **без** распределения по блокам | сохраняется молча — D10-гард no-op | отвергается: «D10: частичный возврат проводится только с явным распределением по блокам…» |
| Полный возврат отзывает оставшийся доступ | нет — доступ после возврата снимается только ручной чисткой | да: снимаются access-only siblings + группы, если не осталось дающих доступ оплат |
| Частичный возврат снимает доступ названных блоков | нет | да (`block_N`-строки диапазона удаляются) |
| «Оплата ещё даёт доступ» исключает возвращённые | нет — возвр. оплата продолжает считаться дающей доступ | да (`excludeRefundedAccess`) |
| Дневная сверка `money:reconcile-daily` 04:35 | команда есть, вручную и read-only; слот 04:35 зарегистрирован ([SchedulesOvernightAndCrm.php:115](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/SchedulesOvernightAndCrm.php)), но при флаге OFF это ежедневный no-op (ничего не пишет, в логах — SUCCESS с предупреждением) | при `MONEY_DAILY_RECONCILIATION=true` — плановый прогон с persist и алертом |
| Откат платежа в `failed`/`canceled` (банк отклонил) | работает всегда, без флага: прана/реферал возвращаются, access-only siblings снимаются, группы пересчитываются | без изменений |

Включение любого из двух флагов — осознанное решение по денежному контуру: виза MG, рецепт — [`RUNBOOK_FEATURE_FLAG_FLIP.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_FEATURE_FLAG_FLIP.md) (`.env` → `config:cache` → reload php-fpm → `horizon:terminate`).

## 2. Жизненный цикл: восемь шагов

| Шаг | Что происходит | Где руками | Где в коде |
|---|---|---|---|
| 1. Заявление | ученик пишет на `rusamskrtam@yandex.ru` (канал назван в оферте §11.1); mailto-шаблон из 5 полей §4.2: ФИО, услуга, дата и сумма оплаты, причина, реквизиты | почта; /vozvrat подставляет шаблон | [`vozvrat.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php) |
| 2. Полнота заявления | 5 полей на месте? «Корректно оформленное» = старт 10 рабочих дней (П1 §4.3) | первая линия | — |
| 3. Сверка доказательств | заявление ↔ Filament → Payments (сумма, статус paid, тариф/blocks, дубликаты) | куратор → Иван | [`PaymentResource.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php) |
| 4. Виза | расчёт суммы по формуле П1 §3.2.1; любую выплату согласует MG (виза — правило контура денег, см. [ранбук поддержки, триггер «Деньги»](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md)) | MG | — |
| 5. Исполнение | деньги возвращает платёжный канал (Точка-кабинет / PayPal вручную; расходы/комиссии — на школе, П1 §4.4) | Иван | — |
| 6. Запись в системе | Filament → Payments → новый платёж: тариф «Расход», затем сумма возврата, «Возврат за платёж» = исходная оплата, для частичного — «с блока»/«по блок»; статус сразу `paid` (D10-гард реагирует только на paid-строки возврата, [RefundAccessPolicy.php:174-178](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reconciliation/RefundAccessPolicy.php)). Порядок ввода: смена тарифа стирает поля блоков ([PaymentResource.php:151-161](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php)) — блоки заполняют после выбора «Расход» | Иван (админ) | [`PaymentResource.php:321-363`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/PaymentResource.php) |
| 7. Автоматика доступа | (только с флагом) полный возврат снимает остаток доступа, частичный — названные блоки; группы пересчитываются | — | [`RefundAccessPolicy.php:89-113`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reconciliation/RefundAccessPolicy.php), [`Payment.php:1772-1807`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php) |
| 8. Сверка и закрытие | факт возврата со стороны банка/провайдера сверяется с записью: `money:reconcile-daily`, исключения в `money:recon-exceptions`; кейс закрывают письмом ученику | Иван | [`MoneyReconcileDaily.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MoneyReconcileDaily.php), [`MoneyReconExceptions.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MoneyReconExceptions.php) |

Спор провайдера (чарджбэк, клейм PayPal) вставляется между шагами 4 и 5: решает провайдер, школа готовит доказательства и ведёт ответ, запись — после вердикта.

## 3. Юридическая база — только оферта

Приоритет текста оферты над этим ранбуком и над страницей `/vozvrat` — fence rule волны H1288. Выжимка для расчётов:

| Случай | Условие | Сколько возвращается |
|---|---|---|
| До начала услуги | уведомление до старта; для «живого» — ≥ 1 календарный день до первого занятия (П1 §3.1а,б) | 100% |
| «Только запись» | ≤ 7 календарных дней с выдачи доступа И система не зафиксировала открытие материалов (П1 §3.1в) | 100% |
| Существенные недостатки по вине школы | подтверждены в установленном законом порядке (П1 §3.1г) | 100% |
| После начала, до завершения | отказ в любой момент (П1 §3.2) | 100% − (стоимость одного модуля × пройденные модули); пройден = выдан доступ (запись) или проведён (живое), П1 §3.2.2 |
| Санкционные случаи | §12.3 (расторжение за нарушение учеником), §9.3 (передача аккаунта) — на `/vozvrat` сознательно не вынесены; полный текст в оферте | по оферте, не по этому ранбуку |

Срок: 10 рабочих дней с момента **корректно оформленного** заявления (П1 §4.3). Способ возврата — тот же, что оплата, если не договорились об ином; комиссии — на школе (П1 §4.4). Заявления пяти полей нет → запросить дополнение, срок не течёт.

> Разборы пяти типовых кейсов (К1 дубль заявления, К2 частичный возврат, К3 отказ, К4 расхождение доступа, К5 спор провайдера) — в приложении [`RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22_CASES.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22_CASES.md); каждое правило процесса ниже опирается на них.

## 4. Сверка и исключения — ежедневный контур

```bash
# read-only по вчерашнему дню (безопасно, ничего не пишет):
php artisan money:reconcile-daily
# конкретный день + JSON, вся история (read-only), плановый с persist — только планировщиком 04:35:
php artisan money:reconcile-daily --date=2026-11-21 --json=/tmp/r.json
php artisan money:reconcile-daily --all
# очередь исключений человека: сводка, фильтр, карточка с доказательством:
php artisan money:recon-exceptions
php artisan money:recon-exceptions --type=refund_without_blocks --limit=200
php artisan money:recon-exceptions --show=17
```

Прогон идемпотентен: повтор с теми же доказательствами не плодит движений и алертов. Отсутствующий источник (сегодня — `bank_statement` без импорта выписки) делает отчёт честно `incomplete`, не нулём. Состояния исключения: `open` → `resolved` (с пояснением) / `dismissed`; тихое закрытие запрещено. Типы: `reused_evidence`, `currency_amount_mismatch`, `unknown_purpose`, `refund_without_blocks`, `impossible_access`, `expired_terms`, `ledger_mismatch`, `unallocated_residue` ([`MoneyReconException.php:39-72`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MoneyReconException.php)). До включения `MONEY_DAILY_RECONCILIATION` ночных прогонов нет — сверка запускается вручную по день возврата.

## 5. Что НЕ делать

- Не возвращать деньги «на доверии» без заявления пяти полей и визы MG; шаблонные ответы первой линии не обещают сумм и сроков.
- Не менять статусы/суммы `payments` и `money_*` строк в БД напрямую — только через Filament/штатные команды; история строк не редактируется (append-only).
- Не удалять «лишние» строки возврата после выплаты — сторно, не delete.
- Не отзывать доступ группами руками при живом конвейере: правит модель по строке денег, а человек проверяет результат.
- Не запускать `--all --persist`-бэкфиллы сверки; исторические `impossible_access`-строки решает человек.
- ПД учеников (ФИО, карты, переписка) построчно наружу не выносить — только агрегаты и синтетические примеры.

## 6. Соответствие приёмке (evidence table)

| Критерий приёмки H8082 | Покрытие |
|---|---|
| Канонический ранбук + sibling meta, связаны из guidance поддержки/оплат | этот файл + [`RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22.meta.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22.meta.md); ссылка добавлена в раздел «Смежные плейбуки» [`RUNBOOK_SUPPORT_ESCALATION.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md) |
| Trace: заявление → доказательства → виза → возврат → спор → доступ → сверка → закрытие | §2, таблица восьми шагов |
| Задеплоенное vs флаги OFF | §1, таблица флага |
| 5 синтетических разборов с кодовыми путями, доказательствами, полномочиями, откатом/эскалацией и закрытием | приложение [`RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22_CASES.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22_CASES.md): К1 дубль, К2 частичный, К3 отказ, К4 расхождение доступа, К5 спор провайдера |
| Реюз политики/обратной логики/сверки, без нового платёжного кода | источники — §Источники; ни одна строка кода не изменена |
| Синтетика не режет ПД | приложение [_CASES](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_REFUND_PAYMENT_DISPUTES_2026-11-22_CASES.md): все имена/суммы вымышлены; §5 запрет выгрузки |

**Не проверено из кода (честная метка):** фактическое поведение включённого флага на проде — включение не проводилось (FENCE: мутация деньгам запрещена); все утверждения «после включения» опираются на тесты и [evidence H5445](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EVIDENCE_MONEY_RECONCILIATION_P3_H5445_24-09-2026.md) от 24-09-2026, дата сохранена.

_Гасунс_
