# Автооткрытие ДЗ: exclude-правила для лекционных курсов — вердикт MG 05-11-2026

_Created: 05-11-2026 · Last updated: 05-11-2026_

## Контекст

- MG 05-11-2026 (пост «Приём ДЗ открыт» в чате группы): «В этой группе это точно не нужно» — чат `-1002750145526` = группа #152 «Традиции толкования упанишад (2 поток, 2026)» → курс #455, слаг `tolkovaniia-upanisad-2-potok-2026`.
- Механика (H3078, инверсия охвата): в авто-открытие приёма ДЗ попадает **каждый** курс с первым уроком не раньше `min_course_start` (2026-06-01) минус exclude-правила. После появления записи урока: +12 ч, выравнивание на 09:00 МСК → пуш студентам (telegram/vk) + пост «Приём ДЗ открыт» в `groups.telegram_chat_id` (звено C, H3068).
- Упанишады — лекционный курс без домашек: у 3 уроков приём был открыт автоматом (21-10, 28-10, 04-11), сдач 0, в чате группы 21 участник.

## Что сделано на проде (.92) 05-11-2026

1. `.env`: добавлено `HOMEWORK_AUTO_OPEN_EXCLUDE_PREFIXES=ctenie-,tolkovaniia-upanisad-` (до этого жил только дефолт `ctenie-`), `config:clear`. Проверка: `HomeworkAutoOpenScope::courseIdsInScope()` — курс 455 больше **не** в охвате (79 курсов в охвате, было 80).
2. Закрыты 3 авто-открытых урока 455 — `L#2065`, `L#2081`, `L#2100`: `homework_enabled=false`, `homework_auto_opened_at` **сохранён** — урок невидим автомату (`scopeAutoOpenCandidates` и `backfillCandidates` требуют `IS NULL`) и не может быть воскрешён.
3. Открытых уроков на курсе — 0; hooks #78/#84/#94 (посты в истории чатов) не тронуты — история не переписывается.

## Перепись открытого приёма ДЗ (прод, 05-11-2026)

Группы сдач = non-draft `homework_submissions` за всю историю курса.

| Курс | Слаг | Уроков открыто | Авто / руками | Сдач | Чат привязан |
|---|---|---|---|---|---|
| 401 Грамматика хинди гр.1 | `hindi-1-sr800-2026` | 14 | 8 / 6 | 72 | да |
| 402 Грамматика хинди гр.2 | `hindi-2-sb1300-2026` | 15 | 9 / 6 | 42 | да |
| 366 Грамматика хинди гр.5 | `hindi-5-vt1500-2026` | 6 | 4 / 2 | 2 | да |
| 369 Кочергина гр.60 | `grammatika-po-kocerginoi-gr60` | 5 | 5 / 0 | 25 | да |
| 434 Кочергина гр.61 | `grammatika-po-kocerginoi-gr61` | 6 | 4 / 2 | 24 | скоординирована |
| 435 Кочергина гр.62 | `grammatika-po-kocerginoi-gr62` | 5 | 5 / 0 | 0 | да |
| 296 Кочергина гр.50 | `grammatika-po-kocerginoi-gr50` | 5 | 5 / 0 | 0 | да |
| 288 Кочергина гр.42 | `grammatika-po-kocerginoi-gr42` | 2 | 2 / 0 | 0 | нет |
| 459 Философия санкхьи | `filosofiya-sankhi` | 3 | 3 / 0 | 1 | да |
| 455 Упанишады (2 поток) | `tolkovaniia-upanisad-2-potok-2026` | 3 | 3 / 0 | 0 | да — **исключён** |
| 449 Костина вт 13:00 | `hindi-s-kostinoi-vt-1300-1430-2026` | 3 | 3 / 0 | 0 | нет |
| 438 Летний интенсив хинди | `letnii-intensiv-hindi-2026` | 8 | 8 / 0 | 0 | да (курс закончился 19-09) |
| 436 Летняя Продленка | `letnij-intensiv-prodlenka-sanskrita-2026` | 6 | 6 / 0 | 0 | да (курс закончился 28-08) |
| 447 Продленка чт 19:00 | `prodlenka-sanskrita-cht-19-2026` | 5 | 5 / 0 | 0 | да |
| 448 Продленка пт 9:00 | `prodlenka-sanskrita-pt-9-2026` | 8 | 8 / 0 | 0 | да |
| 381 Патанджали вт 15:00 | `napevnyi-sanskrit-gimn-sutr-patandzali-vt-15-2026` | 6 | 6 / 0 | 0 | да |
| 433 Патанджали вс 10:00 | `napevnyi-sanskrit-gimn-sutr-patandzali-vskr-10-2026` | 7 | 7 / 0 | 0 | да |
| 451 Напевный (3/3) | `napevnyi-sanskrit-gimn-bxagavadgity-3-cast-2026` | 7 | 7 / 0 | 0 | да |
| 460 Подготовительная группа | `podgotovitelnaya-gruppa-sanskrita` | 5 | 0 / 5 | 3 | нет |
| 266 Синтаксис санскрита | `sintaksis-sanskrita` | 1 | 0 / 1 (2025-03) | 0 | да |
| 446 [служебный] probe sandbox | `cabinet-probe-sandbox` | 1 | 0 / 1 | 0 | нет |

Реальное ДЗ (сдачи есть): хинди гр.1/2/5, Кочергина гр.60/61, Подготовительная (3), санкхья (1). Ноль сдач при открытом приёме: гр.62/50/42, Костина, летние, Продленка, Патанджали, Напевный, Синтаксис, sandbox.

## Парк на вердикты MG (не исполнено 05-11-2026)

- **Санкхья 459**: та же недельная пара с упанишадами, 3 урока авто-открыты, но есть 1 сдача (`sub#6557`, user 6952, «Лекция 1», 28-10, `submitted`, не проверена) — при снятии приёма её стоит разобрать руками или оставить открытой.
- **Патанджали 381+433** (рецитация): 13 уроков, 0 сдач.
- **Костина 449 + Летний интенсив хинди 438**: 11 уроков, 0 сдач; 438 закончился 19-09 (чистая гигиена).
- **Продленка 447/448 + Летняя 436**: 19 уроков, 0 сдач на платформе; рулинг H3081 давал продлёнке «приём без проверки» — снятие приёма меняет рулинг.
- **Напевный 451**: 7 уроков, 0 сдач; H3081 в unreviewed-префиксах — пока не тронут.
- Гигиена: 266 (ручной урок 2025-03), 446 (probe sandbox) — закрываются одним UPDATE при общем прогоне.

## Как вернуть (обратимо)

- Убрать `tolkovaniia-upanisad-` из `HOMEWORK_AUTO_OPEN_EXCLUDE_PREFIXES` в `.env` прода + `config:clear`.
- Уроки 455 открыть руками в админке (автомат их не вернёт — `homework_auto_opened_at` уже стоит).

## Файлы

- [`config/homework.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/homework.php) — `auto_open.exclude_course_prefixes`
- [`app/Support/HomeworkAutoOpenScope.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/HomeworkAutoOpenScope.php)
- [`app/Services/HomeworkAutoOpener.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HomeworkAutoOpener.php)
- Смежный: [ARCHITECTURE_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_HOMEWORK_AUTO_OPEN_KOCHERGINA.md)

_к.ф.н. М.Ю. Гасунс_
