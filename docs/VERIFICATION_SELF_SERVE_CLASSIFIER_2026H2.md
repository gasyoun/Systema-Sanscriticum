# Верификация: self-serve классификатор, волна 1

_Created: 25-08-2026 · Last updated: 25-08-2026_

План: [PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md).
Контракт приёмки (решение MG #15): каждый deliverable доказывается командой + артефактом + гейтом.
«Принято» = критерий выполнен; иначе — не закрыто, независимо от объёма проделанной работы.

## Критерии по deliverables

| Deliverable | Команда | Артефакт | Гейт |
|---|---|---|---|
| Пакет scaffold | `python -m pytest engine_py/tests -q` и PHP parity `vendor/bin/phpunit --filter MessageClassifierParity` (в Systema) | зелёные прогоны в CI обоих языков | golden-vectors 100% pass Py↔PHP |
| Правила seed | `python harness/precision_report.py --rules rules/v1 --corpus corpora/eval/2026-07-05-masked.jsonl` | `reports/precision-eval.md` с таблицей per-category p/r/n | precision ≥0.93 на категорию при n≥30; при n<30 категория помечена `insufficient evidence` и НЕ идёт в автоответы |
| Маскировка | `python tools/mask_corpus.py validate corpora/` + ручная проба 50 сообщений | чеклист пробы, 0 попаданий PII-regex | 0 утечек; любое подозрение = стоп-условие контракта |
| Офлайн-прогон | `python harness/run_corpus.py --corpus train … --out results/` | `results/*.jsonl` + `reports/2026-08-25-wave1-baseline.md` | coverage% опубликован; uncategorized топ-50 приложен |
| Seeder | `php artisan support:rules-sync --dry-run` дважды подряд | второй прогон: diff пуст | идемпотентность; исчезнувшие правила disabled, count совпадает |
| Filament-панель | скриншот `/admin/telegram-support/telegram-support-analytics` после сидирования | coverage%>0 по каналам | числа панели сходятся с SQL-счётом assignments за тот же день |
| Регрессии Systema | `php artisan test --parallel` | зелёный полный сьют | 0 failed |

Регрессионный гейт правил: любой новый PR в YAML сравнивает precision-report с предыдущим
закоммиченным отчётом пакета; падение любой категории ниже 0.93 или появление нового
false-positive из 9 именованных регресс-кейсов ClassifierPrecisionTest блокирует merge.

## Реестр рисков и спайков

1. **Утечка PII через маскированные корпуса** (высокий). Спайк до массового прогона: 50-сообщений
   ручная проба + валидатор. Стоп-условие автономного контракта.
2. **Семантический дрейф Py↔PHP** (высокий). Golden-vectors parity с первого дня; никакой
   реализации «по памяти» во втором языке без общего вектора.
3. **Самообман одного снапшота** (средний): eval и train разведены датами (05-07 vs 22-08) —
   правила адаптируются по train, отчитываются только по eval.
4. **Ложные срабатывания приоритетов** (средний): уроки FINDINGS §456/§499 — консервативные
   правила, reason-строки обязательны, precedence-комментарии переносятся в YAML как поля.
5. **Кириллическая пракрит-детекция для `sanskrit_term`** (низкий, v1 out): sanskrit-util даёт
   IAST/Devanagari-половину; русские транслитерации («карма», «чакра») v1 ловит частотным
   списком, полноценный детектор — отдельный спайк В3.
6. **Исчезновение untracked исходников ORS** (средний): локальная копия снимается первым
   действием шага 3 (danger-fact: untracked в общем дереве живут ≤часа).
7. **Дрейф таксономии между канонами ORS/Systema** (низкий): единый YAML — единственный источник;
   DB-правила прода — производные, sync обязателен перед любым деплоем.

_Dr. Mārcis Gasūns_
