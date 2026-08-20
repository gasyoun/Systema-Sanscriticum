# Дорожная карта: иллюстрированные мануалы трёх аудиторий (2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Слой «волны и поставки» плана [PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md). Решения там, в разделе 3.

## Волна 1 — студент (H3212, Grok 4.6)

Один PR. Внутренний порядок — в [IMPLEMENTATION_SYSTEMA_AUDIENCE_CABINET_GUIDES_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_AUDIENCE_CABINET_GUIDES_W1.md).

| № | Поставка | Разблокируется |
|---|---|---|
| 1.1 | `App\Support\MarkdownGuide` — константный путь, `Str::markdown`, подстановка `src` картинок (git-raw для студента) | ничем |
| 1.2 | `docs/STUDENT_CABINET_GUIDE_RU.md` — семь сценариев + справочник по вкладкам + FAQ | ничем, параллельно 1.1 |
| 1.3 | Страница `/dvaram/help` («Как пользоваться»), ссылка из кабинета | 1.1 + 1.2 |
| 1.4 | `scripts/capture-guide-screenshots.mjs` + `playwright` в devDependencies + манифест шагов | 1.2 |
| 1.5 | Кадры `docs/screenshots/student-guide/*-{1440,390}.png` по манифесту, вставка в md | 1.4 |
| 1.6 | `docs/build-student-guide.php` — PDF из того же md | 1.2; картинки — 1.5 |
| 1.7 | `StudentCabinetGuideCoverageTest` + freshness-скрипт (warn) | 1.3 + 1.5 |
| 1.8 | Шапка `student-manual.md` указывает на `/dvaram/help`; CHANGELOG; метадок | 1.3 |

Готовность: тест покрытия зелёный **или** в журнале PR явно «Chrome не был, кадры отложены» и тогда покрытие кадров не блокирует текст. Страница открывается студенту, гостю 403/302. PDF собирается. Живой ученик — после мержа.

## Волна 2 — куратор (H3213, Grok 4.6)

Стартует после мержа волны 1 (нужен `MarkdownGuide` и Playwright-скрипт).

| № | Поставка | Разблокируется |
|---|---|---|
| 2.1 | Перепись меню куратора (`manager`) по образцу `teacher:nav-census` | мерж волны 1 |
| 2.2 | `docs/CURATOR_ADMIN_GUIDE_RU.md` — сценарии + справочник; влиты magic-link и zabota-bot; сценарии должников (не весь debtors-manual) | 2.1 |
| 2.3 | Filament-страница «Руководство куратора», `RoleGate` как у helpdesk (не преподаватель) | 2.2 |
| 2.4 | Кадры шагов: фикстура, в git; денежные колонки должников — кроп без сумм или пропуск по списку-забору | 2.2 + скрипт волны 1 |
| 2.5 | `admin-manual.md` становится редиректом на новый файл | 2.2 |
| 2.6 | PDF куратора; coverage-тест по переписи; freshness | 2.3 + 2.4 |

## Волна 3 — бухгалтер (H3214, Grok 4.6)

После мержа волны 2 (тот же рендерер; другой контур кадров).

| № | Поставка | Разблокируется |
|---|---|---|
| 3.1 | `docs/ACCOUNTANT_CABINET_GUIDE_RU.md` — сценарии (проводка, зарплата, расход, штурвал, потоки, разметка выплат) без живых ФИО | мерж волны 2 |
| 3.2 | Filament-страница «Как работать бухгалтеру», `RoleGate::finance()`, кнопка с рабочих финэкранов | 3.1 |
| 3.3 | Кадры в `storage/app/guide-shots/accountant/` (gitignore). Страница отдаёт их с диска. В git — нулевой PNG | 3.1 |
| 3.4 | PDF собирается на стенде из storage, **не** коммитится в публичный репо | 3.3 |
| 3.5 | `accountant-guide.md` — карта меню + «открой в кабинете». `finance-manual.md` остаётся картой ролей | 3.2 |
| 3.6 | PayoutAttributionGuide остаётся; книга ссылается, не копирует живую очередь | 3.2 |

## Вне рамок (всех волн)

- Правка существующих гейтов меню (как в гиде преподавателя).
- Рефакторинг TeacherGuide / Dusk преподавателя на Playwright.
- Слияние всего debtors-manual в гид куратора.
- Снимки прод-БД со студентами.
- Переписывание money-access-core-manual (инженерный).
- Продуктовая «починка UI вместо мануала» (аудитор H301) — сниппеты уже частично живут; этот план их не выкидывает и не заменяет полной переработкой кабинета.
- Обучающие туры, видео, второй язык.

_Dr. Mārcis Gasūns_
