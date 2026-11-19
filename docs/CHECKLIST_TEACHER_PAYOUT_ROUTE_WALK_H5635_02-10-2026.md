_Created: 02-10-2026 · Last updated: 02-10-2026_

# Чек-лист процесс «Проведение выплаты преподавателю» — агентский обход маршрутов (H5635)

Агентская приёмка по паттерну чек-лист-тестирования (AI Builders peer review, п.2.3 — кейс Андрея Токарева «вот чек-лист, протестируй все возможные маршруты»), исполнена **GLM 5.3 Flash** (`zai-individual-coding-plan/GLM-5.3-Flash`, ZCode) 02-10-2026, handoff **H5635 (OxAlpha)**.

## Выбор процесса и обоснование

**«Проведение выплаты преподавателю»** (record payout / advance / mirror в «Финансах»). Обоснование: это money-core с известной регрессионной историей (#271 — settled_amount молча отбрасывался mass-assignment'ом; H5007 audit H7 — зачёт авансов без cap по сумме выплаты), при этом процесс компактен: один сервис (`TeacherSalaryService::recordManualPayout`), один проводник (`TeacherPayoutPoster`), один Filament-экран (`TeacherSalaries`, роль accounting). Расчёт ЗП (`PayoutRunService::runForTeacher`) — отдельный, заведомо больший процесс и в этот обход не входит.

## Чек-лист как данные (маршруты × поля × кто проводит)

| Маршрут | Входные поля | Кто | Ожидание |
|---|---|---|---|
| R1 обычная выплата + проводка | `amount>0`, `post_to_finance=true` | accounting (Filament «Записать выплату» → `recordManualPayout`) | `TeacherPayout` (regular) + зеркало `Payment`: сумма `−amount`, `tariff=salary_payout`, `status=paid`, `transaction_id` c «ЗП: …», `payment_id` связан |
| R2 повторная проводка | тот же payout, `post()` ещё раз | система/агент | идемпотентно: тот же `Payment` обновлён, дублей нет |
| R3 нулевая сумма | `amount=0` + `post()` | агент | `post()=null`, зеркала нет, `payment_id=null` |
| R4 снятие проводки | `unpost()` | accounting | зеркало удалено, `payment_id=null` |
| R5 аванс | `type=advance`, `amount>0`, `post_to_finance=true` | accounting («Выдать аванс») | зеркало `−amount` (реальные деньги), аванс в `unsettledAdvances`, `settled_at=null` |
| R6 зачёт авансов FIFO с cap | 2 аванса (3000, 5000), выплата 4000, `settle_advances=true`, wave1 ON | accounting (одна транзакция, H5007) | FIFO: аванс1 погашен полностью (3000, `settled_at` проставлен), аванс2 частично (1000, `settled_at` **null** — частичный), total=4000, зеркало `−4000` |
| R7 replay-защита | два payout с одним `settlement_key` | система (unique index, money P0) | второй insert отклонён (QueryException/UNIQUE) |
| R8 выплата без курса | `course_id=null` + `post()` | accounting | зеркало на техническом курсе `system-expenses` (исключён из базы ЗП) |

**Ограждение:** прогон — на scratch-стенде (sqlite-фикстура, паттерн приёмки волны 2 H3084): `.env.testing` c `DB_DATABASE=/tmp/h5635-stand.sqlite`, `PAYMENT_FIX_WAVE1=true`; никаких реальных студентов, преподавателей и денег; прод не тронут. Правки прода — только после review отчёта MG.

## Как воспроизвести

```bash
cat > .env.testing <<'ENV'   # локальный scratch-стенд; в git НЕ входит (gitignore) — CI должен видеть свой mysql-контур
APP_KEY=base64:NcMT3r4txGwMAcBZSMDvhkf/io/wwBuPhWNTV1GNUhA=
DB_CONNECTION=sqlite
DB_DATABASE=/tmp/h5635-stand.sqlite
PAYMENT_FIX_WAVE1=true
MAIL_MAILER=log
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
ENV
php tools/h5635_payout_route_walk.php   # скрипт сам мигрирует и печатает журнал
```

## Журнал прогона агента (02-10-2026 13:33 MSK, verbatim)

```
H5635 route walk — 02.10.2026 13:33:55 (stand: sqlite scratch, PAYMENT_FIX_WAVE1=true)

PASS  R1 regular payout → finance mirror
      evidence: payout=1 payment=1 amount=-8000 tariff=salary_payout
PASS  R2 re-post idempotent
      evidence: payments=1 (unchanged), payment_id=1
PASS  R3 zero-amount → no mirror
      evidence: post()=null, payment_id=null
PASS  R4 unpost removes mirror
      evidence: payment 1 deleted, payment_id=null
PASS  R5 advance posted + outstanding
      evidence: advance=3 mirror=2 unsettled=1
PASS  R6 FIFO settle capped
      evidence: settled_total=4000 a1=3000/settled_at=2026-10-02 a2=1000/settled_at=null
PASS  R7 settlement_key unique
      evidence: second insert with key H5635-KEY-1 rejected
PASS  R8 no-course → technical course
      evidence: payment 4 course_id=1 (system-expenses id=1)

ALL ROUTES PASS
```

## Отчёт о расхождениях

**Расхождений нет** — 8/8 маршрутов PASS, каждый верифицируем по строке evidence выше (id сущностей и суммы на scratch-стенде). Регрессии #271 (settled_amount) и H5007 (FIFO cap) на стенде не воспроизводятся — фиксы держат.

Риски (не расхождения): (1) обход сервисного слоя, HTTP-слой Filament-форм (валидация `minValue(1)`, предупреждение о переплате) не покрывался; (2) ветка `PAYMENT_FIX_WAVE1=false` (легаси-зачёт всех авансов целиком) не обходилась — на проде флаг включён; (3) FX-снимки (`payout_currency`/`exchange_rate`) — отдельная ветка поблочной выплаты, не входит в ручной процесс.

## Вердикт

Чек-лист закрыт полностью: 8/8 PASS. Изменения прода не производились и без явного «да» MG производиться не будут.

_Гасунс_
