# Доказательный пакет готовности P4 (H5446) — H7263

_Created: 25-10-2026 · Last updated: 25-10-2026_

Модель: DeepSeek Flash (`deepseek-flash`). Задание: [H7263](https://github.com/gasyoun/Uprava/blob/main/handoffs/H7263-DeepSeek_Systema-Sanscriticum_money-p4-readiness-evidence-package_25.10.26.md). Основной объект: [H5446](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5446-Opus_Systema-Sanscriticum_money-p4-evidence-migration-cutover_24.09.26.md); H5446 **не захвачен**, исполнитель Opus не подменялся.

Независимое заключение: Codex Astra (`gpt-6-astra`) — **PENDING**. Отсутствие заключения не является PASS.

## 0. Вердикт

**Пакет доказательств готов; P4 к запуску НЕ готов.** Три предшественника не имеют приёмки, подтверждающей их registry-статус ✅, а у P4 нет источника получателя (ИП) для периода перекрытия и нет начального денежного остатка.

| Ось | Вердикт | Основание |
|---|---|---|
| Свежие SHA источников и прода | **PASS** | §1 |
| H5442: PR → deploy → config → приёмка | **UNVERIFIED** | T+24h квитанции нет; позднее наблюдение дало FAIL-сигналы (§2.1) |
| H5443: PR → deploy → config → приёмка | **PASS** | verifier PASS; 57 тестов; таблицы пусты; флаг off (§2.2) |
| H5444: PR → deploy → config → приёмка | **FAIL** | 0 версий условий; 105/105 выплат `unresolved_rate`; сверка не достигает `tie` (§2.3) |
| H5445: PR → deploy → config → приёмка | **UNVERIFIED** | фактический флаг `MONEY_DAILY_RECONCILIATION=true`, в evidence — unset; 22 прогона, все `incomplete` (§2.4) |
| P1 read-only отчёт | **PASS** (report-only) | exit 0; `db_writes_legacy=0`; `money_*` не изменились; drift 0 (§3) |
| P2 read-only отчёт | **PASS** (0 записей) / **FAIL** (полнота) | exit 0; 0 writes; 105/105 `unresolved_rate` → источник неполон (§3) |
| P3 read-only отчёт | **PASS** (0 записей) / **INCOMPLETE** | exit 0; writes 0; `incomplete`: bank_statement missing, ledger dark (§3) |
| Расхождение повторных доказательств P1/P3 | **PASS** (объяснено) | 2 001 = 2 000 меток импорта + 1 разбиение; реальных повторов 0 (§4) |
| Манифест P4 | **FAIL** | нет источника получателя в перекрытии; нет cash-остатка; граница D3 противоречит миссии H5446 (§5) |
| Независимые контрольные суммы | **FAIL** | 10 отобранных движений перекрытия не атрибутированы; bucket-контроль падает (§6) |
| Сценарии на изолированной БД | **PASS** | 15/15 тестов; новые платежи/повтор/прерывание проверены (§7) |
| Premortem | **PASS** | пять режимов, сигналы/остановка/откат/точка невозврата (§8) |
| Заключение Astra | **PENDING** | §10 |

Точный перечень блокеров — §9. Граница соблюдена: `--apply` на проде не запускался, флаги не переключались, выплат и восстановления backup не было, D21 не пересматривался.

## 1. Свежие SHA и фактическая конфигурация

Снимок 25-10-2026 12:11 MSK (09:11Z). Все чтения — только SELECT/read-only команды.

| Объект | SHA / факт | Как получено |
|---|---|---|
| `Systema-Sanscriticum` origin/main | `13073df8515b01111f22ea10f8da2301bcf9661b` | `git rev-parse origin/main` |
| Рабочая база отчёта | `13073df8…` (worktree `h7263-p4-readiness`) | `git worktree add … origin/main` |
| Прод HEAD | `b7e01ea0aad286bf15b9eddea79b6d2782406a4c` (2026-10-24) | `ssh … git rev-parse HEAD` |
| Прод отстаёт от origin/main | да, на коммиты после 24-10 | сравнение SHA |
| `Uprava` origin/main (mint H7263) | `3e6b834ccfb821a5abf7e07a06d74358728c8cde` | `git log origin/main -1` |
| Прод-движок | MariaDB 11.8.6; `log_bin=0`; REPEATABLE-READ | evidence P1 §1 |
| Миграции | 227 `add_money_p0_replay_keys` Ran; 228 `money_ledger_core` Ran; 229 `money_reconciliation` Ran; 241 `teacher_compensation_and_payout_packages` Ran; 233 `bank_statement_credit` Ran | `php artisan migrate:status` |
| Config cache | `bootstrap/cache/config.php`, 24-10-2026 18:33 | `ls -l` |

Фактические флаги в прод-`.env` (значения — не секреты):

| Флаг | Факт | Что это значит |
|---|---|---|
| `PAYMENT_FIX_WAVE1` | **true** | P0-волна H5442 включена |
| `MONEY_BANK_STATEMENT_CREDITS` | **true** | банковские зачисления читаются как доказательство |
| `MONEY_DAILY_RECONCILIATION` | **true** | ежедневная сверка P3 пишет `money_recon_*` |
| `MONEY_LEDGER_CORE` | отсутствует → false | ядро P1 не пишет |
| `MONEY_REFUND_ACCESS_RULES` | отсутствует → false | правило D10 выключено |
| `MONEY_PAYOUT_PACKAGES` | отсутствует → false | P2 выключен |

Фактические счётчики прод-БД: `money_movements=0`, `money_allocations=0`, `money_obligations=0`, `teacher_compensation_terms=0`, `teacher_payout_packages=0`, `money_recon_runs=22`, `money_recon_exceptions=4`, `payments=9 712`, `teacher_payouts=105`, `bank_statement_credits=826`.

## 2. Цепочка H5442–H5445: PR → deploy → config → приёмка

Все merge-SHA предшественников — предки прод-HEAD (`git merge-base --is-ancestor` = ANCESTOR): `fe417d9d`, `7d807e5b`, `75a77158`, `e7d80922`, `08b4f2a7`, `9bd4325f`.

### 2.1 H5442 — P0, волна исправлений

| Шаг | Факт | Вердикт |
|---|---|---|
| PR | [#2819](https://github.com/gasyoun/Systema-Sanscriticum/pull/2819) `fe417d9d`, merged 24-09 12:45:48Z | PASS |
| Deploy | `deploy.sh` `ba4dff00 → fe417d9d`, миграция 227 Ran, smoke 200 | PASS |
| Config | `PAYMENT_FIX_WAVE1=true` с 24-09 13:22:51Z; baseline/after JSON на проде | PASS |
| Приёмка | T0-наблюдение 13:47:53Z есть; **T+24h квитанции нет** ни в docs, ни в storage, ни в registry | **UNVERIFIED** |
| Позднее наблюдение | 25-10 12:17 MSK, 739.92 ч после включения, `scripts/ops/h5442_wave_observe.php` | **FAIL-сигналы** |

Позднее наблюдение (не заменяет T+24h, но показывает состояние после волны): 1 PayPal-claim с вердиктом `beyond_5` **оплачен** (платёж 14613, 2026-10-13, 18 000 ₽, `full`); 23 оплаты на курсах без групп; 6 оплат без членства в группе; 52 `RuntimeException`; 2 `payout_run`; дублей и replay-key дублей 0. Критерий H5442 «no paid while beyond ±5%» в наблюдаемом окне **не выполнен**.

Registry пометил H5442 ✅ 09-10-2026 через backlog-sweep H6210 («rollout evidence + read-only wave observation»), но T+24h-квитанции в записи нет. Registry-статус не равен приёмке.

### 2.2 H5443 — P1, ядро журнала

| Шаг | Факт | Вердикт |
|---|---|---|
| PR | [#2844](https://github.com/gasyoun/Systema-Sanscriticum/pull/2844) `7d807e5b`, merged 24-09 18:50:46Z | PASS |
| Deploy | прод `7d807e5b`, миграция 228 Ran, 9 триггеров, 3 таблицы пусты, `payments` 9 452 строки не тронуты | PASS |
| Config | `MONEY_LEDGER_CORE` не задан → false; запись запрещена | PASS |
| Приёмка | независимый verifier PASS (5 раундов); 57 тестов / 567 утверждений на MariaDB; rollback/re-migrate byte-identical | PASS |

Единственная оговорка: shadow-прогон на реальных прод-данных не выполнялся (evidence P1 §6.1), но это не входило в приёмку P1.

### 2.3 H5444 — P2, версионированные условия и пакеты

| Шаг | Факт | Вердикт |
|---|---|---|
| PR | [#2855](https://github.com/gasyoun/Systema-Sanscriticum/pull/2855) `9bd4325f`, merged 29-09 08:09:32Z | PASS |
| Deploy | SHA — предок прод; миграция 241 Ran | PASS |
| Config | `MONEY_PAYOUT_PACKAGES` не задан → false | PASS |
| Приёмка | `teacher_compensation_terms=0`, `teacher_payout_packages=0`; `money:payout-package-compare`: **105/105 `unresolved_rate`**, 0 `tie`, 0 `delta` | **FAIL** |

Приёмка H5444 требует «отчёт сходится или оставляет только названные исключения». Отчёт не сходится ни по одной строке: датированных назначений компенсации нет, ставка не выводится (D15/D17), ждёт рулинга MG/H5250. P2 тёмный — это ожидаемо; **непринятым** его делает отсутствие версионированных условий, а не выключенный флаг.

### 2.4 H5445 — P3, ежедневная сверка

| Шаг | Факт | Вердикт |
|---|---|---|
| PR | [#2848](https://github.com/gasyoun/Systema-Sanscriticum/pull/2848) `75a77158`; [#2851](https://github.com/gasyoun/Systema-Sanscriticum/pull/2851) `e7d80922`; [#2856](https://github.com/gasyoun/Systema-Sanscriticum/pull/2856) `08b4f2a7` | PASS |
| Deploy | все SHA — предки прод; миграция 229 Ran | PASS |
| Config | evidence P3: «`MONEY_DAILY_RECONCILIATION` и `MONEY_REFUND_ACCESS_RULES` не заданы»; **факт 25-10: `MONEY_DAILY_RECONCILIATION=true`** | **UNVERIFIED** (config drift без записи) |
| Приёмка | 22 плановых прогона 28-09…24-10, **все `incomplete`**; 4 открытых исключения (3 `currency_amount_mismatch`, 1 `unknown_purpose`) | **UNVERIFIED / FAIL** |

Причина `incomplete` устойчива: `ledger` dark (P1 не пишет) и `bank_statement` отсутствует для всей истории. Плановая сверка честно работает, но не даёт готовности. Включение флага без обновления evidence-записи — отдельный дефект цепочки.

## 3. Датированные read-only отчёты P1, P2, P3

Все команды запущены на проде 25-10-2026 09:03–09:04Z под `www-data`, `HOME=/tmp`, только чтение. JSON-файлы сохранены вне git (содержат id/суммы), SHA-256 — в §11.

| Отчёт | Режим | Exit | Записи | Полнота источников | Итог |
|---|---|---|---|---|---|
| P1 `money:ledger-backfill-report` | plan (без `--shadow`) | 0 | `db_writes_legacy=0`; `money_*` до/после равны | легаси `payments` 9 712/9 518 семей; банк не используется | PASS как отчёт |
| P2 `money:payout-package-compare --json` | compare, read-only | 0 | обёртка `DB::listen`: **0 пишущих SQL** | 105 легаси-выплат; 0 условий; 0 пакетов | FAIL по полноте |
| P3 `money:reconcile-daily --all --json` | manual, read-only | 0 | `writes.money_recon=0`, `writes.other=0` | 7 источников present (включая `payout_packages`); `bank_statement` missing; `ledger` dark | INCOMPLETE |
| P3 `--date=2026-09-23` | manual, read-only | 0 | `writes` 0 | `bank_statement` present (день покрыт); `ledger` dark | INCOMPLETE |

**P1.** 9 518 семей, нетто 41 470 738.47 ₽, план 41 470 738.47 ₽, распределено 41 017 738.78 ₽, остаток 452 999.69 ₽, вне P1 −15 277 845.10 ₽, drift 0 коп. Аномалии: `evidence_reused` 2 001; `outflow_without_source_link` 412; `nonpositive_receipt` 757; `direct_receipt_without_evidence` 11; `purpose_unknown_left_unallocated` 34; `deposit_application_target_unknown` 2; `received_account_not_teacher_personal` 10.

**P2.** `{"unresolved_rate": 105}`, 105 строк, 0 `tie`/`delta`/`missing_package`/`orphan_package`. Это не «зелёный» результат: сверка не может подтвердить ни одну выплату, пока нет датированных условий.

**P3, вся история.** 8 537 строк, 43 052 783.47 ₽; `matched` 7 073 (35 947 147.80 ₽), `unallocated` 31 (417 737.69 ₽), `exception:impossible_access` 1 429 (6 650 097.98 ₽), `exception:currency_amount_mismatch` 4 (37 800.00 ₽); вне сверки 757 нулевых access-only и 492 расходов; `identity_ok=true`; вход `f1ae88eb…`; контрольная сумма `d1baf917…`; записано 0.

**P3, день 2026-09-23.** 6 строк (61 600.00 ₽), 5 `matched` (41 600.00 ₽), 1 `unallocated` (20 000.00 ₽), 3 находки (2 `currency_amount_mismatch`, 1 `unknown_purpose`), `bank_statement=present`, `ledger=dark`, статус `incomplete`, вход `c8d66b6b…`, сумма `bd97a2cb…`, записей 0.

`exit 0` при `incomplete` — не готовность: P3 сам печатает `incomplete`, отсутствие `bank_statement` за всю историю и `ledger=dark` не превращаются в ноль.

## 4. Повторные доказательства P1/P3

Кратко: 2 001 флаг P1 = 2 047 строк в 46 группах минус 46 первых строк; 2 045 строк — метки импорта, 2 строки — одно разбиение платежа; реальных повторов 0, неизвестных 0. Подробный разбор — [часть 2, §4](EVIDENCE_MONEY_P4_READINESS_H7263_PART2_25-10-2026.md#4-повторные-доказательства-p1p3-на-одном-снимке).

_Манифест, независимые контрольные суммы, сценарии на изолированной БД и premortem — [часть 2](EVIDENCE_MONEY_P4_READINESS_H7263_PART2_25-10-2026.md)._

## 9. Блокеры (точный перечень)

1. **H5442 не принят.** T+24h-квитанции нет; позднее наблюдение нашло оплаченный PayPal-claim 14613 с `beyond_5`, 23 оплаты на курсах без групп и 6 без членства. Registry ✅ не подкреплён приёмкой.
2. **H5444 не принят.** 0 версий условий; 105/105 выплат `unresolved_rate`; сверка P2 не достигает `tie` ни по одной строке. Источник ставок (чаты/старый реестр) вне системы.
3. **H5445 config drift.** `MONEY_DAILY_RECONCILIATION=true` на проде, в evidence — unset; 22 прогона, все `incomplete`; 4 открытых исключения.
4. **Нет источника получателя для B.** `payments` без ИП; `bank_statement_credits` без счёта/ИП и покрывает только 2026-08-01…2026-09-28; перекрытие 23-12-2025…27-02-2026 — 0 доказательств. Дата в B недопустима по D22.
5. **Нет начального денежного остатка.** Банковского баланса по ИП нет; cash `missing_source`; обязательства преподавателей `unverified`. Zero-unexplained-delta недостижим.
6. **Граница D3 ≠ миссия H5446.** 4 279 движений / 20 484 589.53 ₽ (49.4 %) до 08-08-2025 вне D22; полная миграция истории противоречит D3, а игнорирование теряет перенос остатков. Нужен явный рулинг MG.
7. **Банковское покрытие 2 месяца.** P3 `--all` всегда `incomplete`; банк не сверен с платежами ни в перекрытии, ни до мая 2026.
8. **Ежедневная сверка не даёт готовности.** 22/22 `incomplete` из-за `ledger=dark`; P3 не может быть gate для P4, пока ядро не пишет.
9. **Независимого заключения Astra нет.** PENDING; PASS без него запрещён.

## 10. Передача Astra

Пакет для независимого заключения: [MONEY_P4_ASTRA_VERIFIER_BRIEF_25-10-2026.md](MONEY_P4_ASTRA_VERIFIER_BRIEF_25-10-2026.md). В нём: отчёт, diff, команды, ожидаемые числа и вопросы, на которые должен ответить Astra. Заключение — только `PASS` / `FAIL` / `DISAGREE` с указанием перепроверенного контроля; отсутствие заключения — `PENDING`.

## 11. Доказательства и воспроизведение

SHA-256 сырых отчётов (не публикуются: id/суммы; воспроизводятся командами ниже):

| Файл | SHA-256 |
|---|---|
| `p1_plan_20261025T090342Z.json` | `0b09231648ab275bb4a96e570f0d8bea7ddaa50d3de80a1c93865df8643d551e` |
| `p1_full_20261025T091213Z.json` | `82601efe9a3383c8ec102752e8726f56e488e6c4149b1ebcb2daa3cd2887d492` |
| `p2_compare_20261025T090350Z.json` | `912b83648ee5e31deb2de3d4d117f128c7174d7a8f09a522a7cfc27670dea5ff` |
| `p3_all_20261025T090354Z.json` | `845784ca50daeac02d438dab8faafbf3792e9798920ecb2b84a4cc744bf020a3` |
| `p3_day_20261025T090423Z.json` | `63d0c64140f8f55fe03f5573279fe07a25925439f0dbb65fdd603821237411ac` |
| `p4_snapshot_2026-10-25.json` | `ee72a47f62334a774152e97c20edcf4888a672150464a6f9280bcb291487be89` |
| `manifest_full.json` | `d6a9b0a9677dff3fa69835769920cf97f80ce455e01060445ead8da164ede406` |
| `h5442_late_observe_2026-10-25.json` | `6c1c259b7647120798231a9543c2170c6b668d2873796835fe0a5515a4f7c2f8` |

Воспроизведение (read-only, прод):

```bash
ssh root@193.232.229.92 'cd /var/www/html && sudo -u www-data HOME=/tmp \
  php artisan money:ledger-backfill-report --json=/tmp/p1.json'
ssh root@193.232.229.92 'cd /var/www/html && sudo -u www-data HOME=/tmp \
  php artisan money:payout-package-compare --json'
ssh root@193.232.229.92 'cd /var/www/html && sudo -u www-data HOME=/tmp \
  php artisan money:reconcile-daily --all --json=/tmp/p3.json'
```

Локальная проверка (изолированная БД, без прода):

```bash
python3 -m unittest discover -s tests/p4 -v
python3 scripts/p4/readiness.py plan --snapshot snap.json --p1 p1.json --out manifest.json
python3 scripts/p4/readiness.py apply --manifest manifest.json --db /tmp/p4.sqlite --apply --run-id r1
python3 scripts/p4/readiness.py verify --manifest manifest.json --db /tmp/p4.sqlite
```

_к.ф.н. М.Ю. Гасунс_
