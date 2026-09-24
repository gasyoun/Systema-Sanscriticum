_Created: 24-09-2026 · Last updated: 24-09-2026_

# Денежная сверка P3 — ежедневный срез, очередь исключений и правило возврата D10

Исполнение: [H5445 (Opus 5) — daily reconciliation, exception queue, refund access rules and control totals](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5445-Opus_Systema-Sanscriticum_money-p3-daily-reconciliation-access_24.09.26.md), Opus 5.5 (`claude-opus-5-5`).
Доказательства (MariaDB 11.8.6, верификатор, прод-прогон): [EVIDENCE_MONEY_RECONCILIATION_P3_H5445_24-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EVIDENCE_MONEY_RECONCILIATION_P3_H5445_24-09-2026.md).
Решения-источники: [DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md) — раздел P3; D1, D4, D8, D9, D10, D16, D17.
Ядро, на котором стоит сверка: [MONEY_LEDGER_CORE_P1_CONTRACT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_LEDGER_CORE_P1_CONTRACT_2026.md).

## Что это и чего это НЕ делает

1. Сверка **читает** доказательства и **пишет только** свои таблицы `money_recon_runs`, `money_recon_exceptions`, `money_recon_exception_events`. Денег, доступа и строк `payments`/`money_*` она не создаёт и не меняет — это доказывает счётчик SQL-записей в самой команде (любая чужая запись = FAIL).
2. Исключение не разрешается автоматически никогда. Даже если условие пропало из данных, исключение остаётся открытым, пока человек не закроет его с основанием (D9, D17).
3. Официальный бухучёт не копируется (D1): это операционная сверка школы.
4. Всё за флагами, по умолчанию выключено:
   1. `MONEY_DAILY_RECONCILIATION` — плановый прогон 04:35 (иначе no-op с warning в логе);
   2. `MONEY_REFUND_ACCESS_RULES` — правило D10 на легаси-возвратах `payments`.

## Источники дня

| Источник | Откуда | Статус |
|---|---|---|
| `bank_acquiring` | `payments` (Точка/эквайринг) | present |
| `paypal` | `payments`, provider=paypal, `claim_meta` | present |
| `manual` | `payments`, счёт/SEPA, ручные подтверждения | present |
| `teacher_direct` | `payments`, received_account ≠ school | present |
| `refund` | `payments` `Расход` с `refund_of_payment_id` | present |
| `webhook_journal` | `payment_webhook_events` | present |
| `bank_statement` | выписка зачислений банка | **missing** — импорта зачислений нет (парсеры H4200 читают только расходы) |
| `ledger` | ядро P1 `money_*` | **dark**, пока ядро пустое и флаг выключен; present, когда в ядре есть строки |
| `payout_packages` | таблица P2 (H5444), настраиваемая `MONEY_RECON_PAYOUT_PACKAGES_TABLE` | **legacy** — пока P2 нет, суммы из `teacher_payouts` |

Отсутствующий или тёмный источник делает прогон `incomplete` и называется в отчёте. Он никогда не превращается в ноль: в итогах каналов его строки просто нет.

## Классы строк

Каждая строка окна получает ровно один класс; тождество `rows_classified == rows` и `kopecks_classified == kopecks` проверяется в каждом прогоне.

1. `matched` — доказательство согласовано.
2. `unallocated` — деньги честно получены, но не разложены по блокам (явный остаток D2).
3. `exception:<тип>` — первый по приоритету тип из списка ниже; открываются все найденные нарушения, не только первое.

Вне сверки, но посчитаны в `excluded`: нулевые access-only строки (siblings `access_grant_#`), расходы без связи с оплатой и выплаты зарплаты.

## Типы исключений (в порядке приоритета)

1. `reused_evidence` — один `transaction_id`/PayPal txn/ref у нескольких оплаченных строк (D16).
2. `currency_amount_mismatch` — PayPal вне допуска 5 % или без ожидаемой цены; валютная сумма без валюты; вебхук `rejected_amount_mismatch`; P0-метка `supplement_amount_beyond_tolerance`.
3. `unknown_purpose` — нет курса у нетарифной оплаты, отрицательное поступление, возврат несуществующей оплаты; вебхуки `unmatched`, `rejected_resurrection`, `rejected_charge`.
4. `refund_without_blocks` — частичный возврат без блоков или вне оплаченного диапазона (D10).
5. `impossible_access` — курс без групп доступа; P0-метка `no_access_groups`; вебхук `breaker_refused`; доступ остался после полного возврата.
6. `expired_terms` — оплата после истечения ссылки, промокод просрочен на момент оплаты, лимит промокода превышен. Просроченные условия никогда не применяются молча — они видны здесь.
7. `ledger_mismatch` — строка не в ядре или сумма расходится; нарушение инвариантов ядра (только когда ядро present).
8. `unallocated_residue` — остаток семьи движений ядра без распределения.

## Идемпотентность, дрейф и журнал

1. Прогон ключуется парой (операционный день, отпечаток входа). Отпечаток — SHA-256 канонического JSON всех фактов окна, статусов источников и среза ядра; дробные числа в каноническом JSON запрещены (только копейки).
2. Повтор с тем же отпечатком ничего не пишет (`replay`) и не шлёт алерт — шторма нет.
3. Тот же отпечаток, но другая контрольная сумма итогов — `drift`, команда падает (exit 1), heartbeat уходит в /fail.
4. Ключ исключения — `тип:строка:sha256(доказательство)[0:16]`. Новое доказательство по той же строке — новое исключение; то же — только отметка `last_seen_run_id`.
5. Триггеры БД (SQLite и MariaDB): прогоны и события не меняются и не удаляются; у исключения заморожены ключ, тип, источник, доказательство, сумма, первый прогон; закрытое исключение не переоткрывается; закрытие требует `resolved_at`; удаление запрещено.

## Контрольные суммы среза

`totals` каждого прогона: строки и копейки окна, по каналам (с валютными минорными единицами), по классам, `findings_by_type`, `excluded`, итоги ядра (`controlTotals` P1 — движения, распределения, обязательства), пакеты выплат (legacy/present), дайджест классификации и тождества. `totals_checksum` — SHA-256 канонического JSON итогов.

## Правило возврата D10

**Легаси (`payments`, флаг `MONEY_REFUND_ACCESS_RULES`):**

1. Частичный возврат (сумма оплаченных возвратов меньше исходной оплаты) без «с блока / по блок», с перевёрнутым диапазоном или вне оплаченных блоков — отказ при сохранении; в админ-форме это ошибка поля «Возврат за платёж».
2. Частичный возврат снимает access-only siblings названных блоков; собственный ключ оплаты (`block_N`) перестаёт открывать уроки, если N в диапазоне возврата.
3. Полный возврат (одной строкой или суммой нескольких) снимает все siblings, исходная оплата перестаёт давать доступ, группы курса отсоединяются, если у студента нет другой дающей доступ оплаты курса.
4. Черновик возврата (не paid) ничего не меняет.

**Ядро P1 (`RefundAccessPolicy::refundLedger`):**

1. Проведённый блок уменьшается только сторно (P1), поэтому полный возврат = всё, что не занято проведёнными блоками. Он сам называет неоказанные обязательства семьи и отменяет те, что остались без денег; проведённые не отменяются.
2. Частичный возврат при любых деньгах на неоказанных блоках обязан назвать обязательства — строже P1, который пускает возврат из свободного остатка.

## Как запускать

```bash
php artisan money:reconcile-daily --date=2026-09-23 --json=/tmp/recon.json
```

```bash
php artisan money:reconcile-daily --all
```

```bash
php artisan money:recon-exceptions --type=refund_without_blocks
```

```bash
php artisan money:recon-exceptions --resolve=17 --by=1 --reason="доступ снят вручную"
```

Плановый прогон: `money:reconcile-daily --persist --scheduled`, ежедневно 04:35 (Europe/Moscow), `withoutOverlapping`, `onOneServer`, сбой — `ScheduleFailureSignal`. Heartbeat — `MONEY_RECON_PING_URL`; Telegram-алерт — новые исключения, FAIL или смена статуса полноты; повтор без изменений алерта не шлёт.

## Известные пробелы

1. Нет импорта выписки зачислений банка — `bank_statement` всегда missing, прогоны `incomplete` до [H5480 (Opus 5) — bank_statement source: Tochka credit import + daily aggregate control](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5480-Opus_Systema-Sanscriticum_money-p3b-bank-statement-credits-source_24.09.26.md). Сверять по строкам нельзя: [H4645](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4645-OxAlpha_Systema-Sanscriticum_tochka-statement-import-paid-no-access-sensor_13.09.26.md) измерил, что в выписке счёта Точки нет идентификатора ученика (1051 из 1052 поступлений — от самого банка), поэтому H5480 сверяет дневные агрегаты.
2. Ядро тёмное до P4 — строки сверяются по согласованности доказательств, сверка с ядром включится сама, когда в ядре появятся строки.
3. Пакеты выплат P2 (H5444) ещё не существуют — используется легаси `teacher_payouts`; P2 подключается через `MONEY_RECON_PAYOUT_PACKAGES_TABLE` без второй модели.

_Гасунс_
