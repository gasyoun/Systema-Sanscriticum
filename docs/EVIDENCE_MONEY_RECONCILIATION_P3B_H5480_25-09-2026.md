# H5480 (P3b) — доказательства: источник `bank_statement` на НАСТОЯЩЕЙ выписке Точки

_Created: 25-09-2026 · Last updated: 25-09-2026_

Дополняет [EVIDENCE_MONEY_RECONCILIATION_P3_H5445_24-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/EVIDENCE_MONEY_RECONCILIATION_P3_H5445_24-09-2026.md);
контракт — [MONEY_RECONCILIATION_P3_CONTRACT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_RECONCILIATION_P3_CONTRACT_2026.md).
PR: [#2859](https://github.com/gasyoun/Systema-Sanscriticum/pull/2859).

## Что и где прогнано

Не фикстуры: настоящая выгрузка счёта Точки за 01.01.2026–04.07.2026
(3708 строк, UTF-8 BOM, разделитель `;`, заголовок `account`), та самая, на
которой H4645 измерял непривязываемость строк. Прогон — на ЛОКАЛЬНОЙ реплике
(свежий SQLite, `php artisan migrate`), потому что код ещё не в `main` и не на
проде; денежных строк в реплике нет, и это видно в числах ниже (`expected_payments: 0`).

## 1. Разбор совпал с независимым измерением H4645

`php artisan money:import-bank-credits <csv> --from=2026-01-01 --to=2026-07-04 --dry-run`

```
СУХОЙ ПРОГОН: выписка Tochka_01.01.2026-04.07.2026.csv: период 2026-01-01…2026-07-04 (explicit)
Разобрано зачислений: 1052, записано: 1052, дублей: 0, не разобрано строк: 0
Сумма зачислений: 6575502.28 ₽
  card_acquiring_aggregate     111 стр.  1079364.28 ₽
  other                          2 стр.   141999.00 ₽
  qr_settlement                939 стр.  5354139.00 ₽
```

Uprava [`tools/tochka_credits_paid_no_access_sensor.py`](https://github.com/gasyoun/Uprava/blob/main/tools/tochka_credits_paid_no_access_sensor.py)
(H4645) на этом же файле насчитал 1052 зачисления, **939** посделочных
QR-расчётов и **111** дневных агрегатов эквайринга на ₽1 079 364. Порт разбора
в [`TochkaCreditStatementParser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reconciliation/TochkaCreditStatementParser.php)
воспроизводит те же три числа до строки и до копейки, `не разобрано строк: 0` —
сенсор и импортёр видят одни и те же строки, как и задумано.

## 2. Идемпотентность на настоящем файле

Повторный импорт того же файла:

```
Этот файл уже импортирован (тот же sha256) — новых строк 0.
Разобрано зачислений: 1052, записано: 0, дублей: 1052, не разобрано строк: 0
```

## 3. Покрытый день → `present`, непокрытый → `missing` (никогда не ноль)

`php artisan money:reconcile-daily --date=2026-03-04 --dry-alert`

```
Сверка 2026-03-04 — incomplete, исход dry_run; строк 0 (0.00 ₽), находок 1, новых исключений 1
  источник bank_statement   present
  источник ledger           dark — P1 ledger is dark (MONEY_LEDGER_CORE off, no rows) …
  источник payout_packages  legacy — P2 package table teacher_payout_packages (H5444) not deployed …
  отпечаток входа  85a94924ea83844c5eeb876ebc7a96c5fa40a66e24505b6bdacb6ce0f9ed1f33
  контрольная сумма ef79c0c2019ff0d31b4e82d14466e5b7f54ed2a40f4a7112b4f72703f11b0359
```

`bank_statement` перестал быть намертво `missing` — это и была цель единицы.
День всё ещё `incomplete`, но уже **не из-за выписки**: остались `ledger` (P1,
флаг `MONEY_LEDGER_CORE` выключен) и `payout_packages` (P2, H5444 не на проде) —
обе дыры вне охвата H5480.

Непокрытый день:

```
Сверка 2026-08-01 — incomplete …
  источник bank_statement   missing — no imported bank statement covers 2026-08-01 (covered: 2026-01-01…2026-07-04)
```

## 4. Дневной контроль открыл ровно одно типизированное исключение

`--persist` первый раз → `recorded`, новых исключений 1; второй раз → `replay`,
новых исключений **0** (переигрывание не пишет).

```
exception_key : currency_amount_mismatch:bank-statement-day:2026-03-04:03fd8ac4ee27241b
type          : currency_amount_mismatch
source        : bank_statement
source_ref    : bank-statement-day:2026-03-04
amount_kopecks: 17362600
evidence      : {"detail":"daily_settlement_vs_acquiring_payments","business_date":"2026-03-04",
                 "settlement_kopecks":17362600,"expected_kopecks":0,"expected_payments":0,
                 "band_kopecks":[-100,100],"lag_days":1,
                 "payments_window":"2026-03-03…2026-03-04","row_hashes":[…22 хеша…]}
```

Разрыв ₽173 626,00 — это пустая реплика (`expected_payments: 0`), а не дефект:
контроль сравнивает QR-расчёты дня с оплатами `bank_acquiring` в окне T+1
(допуск и лаг — [`config/money_recon.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/money_recon.php)),
и при нулевых оплатах обязан открыть ровно одно исключение с хешами строк
выписки в доказательствах. Именно это и произошло.

## Чего здесь НЕТ

Пункт 2 приёмки хэндоффа — «одна настоящая выписка импортирована **на проде**» —
не закрыт и не мог быть закрыт этой единицей: код ещё не в `main`. Порядок
остатка: независимый вердикт `oxalpha-review-gate` на PR
[#2859](https://github.com/gasyoun/Systema-Sanscriticum/pull/2859) → мерж
человеком (денежный контур, авто-мерж запрещён) → [`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh)
→ `php artisan money:import-bank-credits` на хосте с `--from/--to` → прогон
`money:reconcile-daily` на покрытом дне. Цифры прода дописываются сюда же.

_Dr. Mārcis Gasūns_
