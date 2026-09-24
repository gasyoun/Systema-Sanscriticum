# H5444 (P2) — выплаты на версионированных условиях и неизменяемых пакетах

_Created: 24-09-2026 · Last updated: 24-09-2026_

Реализация решений D10–D17 из [DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md)
поверх денежного ядра P1 ([H5443](https://github.com/gasyoun/Systema-Sanscriticum/pull/2844)).
Флаг `features.money_payout_packages` — **OFF по умолчанию**, ни один экран зарплат
на новую модель не переключён.

## Что появилось

| Таблица | Решение | Инвариант, вшитый в БД |
|---|---|---|
| `teacher_compensation_terms` | D15 | условие неизменяемо; правка ставки = новая версия; `percent` несёт долю, `per_lesson`/`per_block`/`fixed` — копейки; подтверждение (`confirmed_by`+`confirmed_at`) обязательно |
| `teacher_compensation_assignments` | D17 | датированное назначение — единственный источник обязательства; правка запрещена (отзыв + новая строка); пересекающиеся действующие назначения на пару «преподаватель + область» отвергаются |
| `teacher_payout_packages` | D13, D14 | стабильный ключ; `draft → approved → paid → reversed` только вперёд; `approved` замораживает состав; `paid` требует доказательства; второй незакрытый пакет того же окна запрещён; итог = база + удержания и не может быть отрицательным; валютный снимок целостен или отсутствует |
| `teacher_payout_package_lines` | D11, D16, D17 | база обязана назвать назначение и версию условия; удержания отрицательны; `(refund_movement_id, obligation_id)` уникальна — возврат удерживается РОВНО один раз; оказанный блок не удерживается вовсе; погашение прямого получения связано со своим движением и не превышает полученного |

Код: [миграция](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_09_24_190000_create_teacher_compensation_and_payout_packages.php) ·
[PayoutPackageService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payout/PayoutPackageService.php) ·
[CompensationResolver](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payout/CompensationResolver.php) ·
[сверка](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/PayoutPackageCompareCommand.php).

## Рубль первым (D14)

Расчёт идёт в целых копейках рубля: база, авансы, взаимозачёты и удержания
суммируются в `total_kopecks`. Только после этого `pay()` снимает валютный
снимок — курс (8 знаков, строкой), его дата, источник и производная сумма.
Ни одной операции с плавающей точкой над деньгами: процент считается как
`intdiv($base * $ppm + 500000, 1000000)`, конвертация — как
`intdiv($total * 1e8 + $scaled/2, $scaled)`. Триггер не даёт поставить курс на
черновике и не даёт рублёвой выплате нести курс.

## Ставка никогда не выводится (D15/D17)

[`CompensationResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payout/CompensationResolver.php)
читает ТОЛЬКО `teacher_compensation_assignments`. Роли, `course_teacher`,
`group_reviewer`, доступ к курсу и `courses.salary_value` в нём не существуют —
по построению, а не по договорённости. Нет назначения на дату → `UnresolvedCompensation`,
а не подстановка. Триггер `payout: a base line needs a dated compensation assignment`
закрывает и обход сервиса сырым INSERT.

## H5250: исключение, а не догадка

[H5250](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5250-OxAlpha_Systema-Sanscriticum_backfill-offline-payouts-usha-trefilova-litvinenko-gornostaeva-tolchelnikov_22.09.26.md)
остановлен на @DECIDE: 13 строк с Δ чек-суммы 6–186%, потому что ставки в
`courses.salary_value` ≠ фактические у офлайн-преподавателей (Уша 20% против 30%
в базе, Горностаева со-преподавание ~9%, Толчельников implied 42–58%).

P2 **не разрешает** этот спор и не пытается: такие строки выходят из сверки
классом `unresolved_rate` с примечанием «нет датированного назначения компенсации
на дату — ставка НЕ выводится». Разблокировка — рулинг MG по ставкам, после
которого человек заводит датированные назначения, и те же строки переходят в
`tie` или `delta`. Историю `teacher_payouts` P2 не переписывает.

## Сверка со старым (только чтение)

```bash
php artisan money:payout-package-compare
php artisan money:payout-package-compare --teacher=42 --json
```

Работает при **выключенном** флаге — именно она решает, когда его можно включить.
Классы строк: `tie` (сошлось до копейки), `delta` (расхождение итога),
`missing_package` (назначение есть, пакет не построен), `unresolved_rate`
(ставки нет — ждёт человека), `orphan_package` (пакет без легаси-строки).

## Проверки

Тесты: [PayoutPackageTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Payout/PayoutPackageTest.php)
(переходы, идемпотентность, гонка на стабильном ключе, заморозка, рубль-первым,
отрицательный итог, версионирование условий, пересечение назначений) и
[PayoutRefundAndDirectReceiptTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Payout/PayoutRefundAndDirectReceiptTest.php)
(возврат один раз, оказанный блок не удерживается, пара прямого получения,
предел погашения, отчёт сверки, отказ записи без флага).

Каждый инвариант проверяется ДВАЖДЫ: через сервис (понятный отказ) и сырым
`DB::table()->insert/update` в обход сервиса (триггер БД). Прогон — CI
(в свежем worktree нет `vendor/`, локальный `php artisan test` не грузится;
статус см. в PR).

## Включение в проде (не в этом PR)

1. `php artisan money:payout-package-compare` — отчёт сходится или оставляет
   только названные исключения.
2. Человек заводит датированные назначения компенсации для тех, кому платим.
3. `MONEY_PAYOUT_PACKAGES=true` + `php artisan config:cache` — отдельный ops-шаг.
4. Читатели экранов зарплат переключаются в P4, не раньше.

_Гасунс_

_Dr. Mārcis Gasūns_
