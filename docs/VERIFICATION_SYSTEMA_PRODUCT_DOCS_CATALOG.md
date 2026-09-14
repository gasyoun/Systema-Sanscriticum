# Приёмка: каталог документации продукта

_Created: 21-08-2026 · Last updated: 27-08-2026_

Доказательства готовности для [PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md). Сборка волны 1 — в [IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md).

## Волна 1

| Критерий | Команда / проверка | Pass |
|---|---|---|
| Страница 200 супер-админу | Livewire/HTTP GET Filament page под `Roles::SUPER_ADMIN` | 200, видит «Документация», видит student и accountant |
| Страница 200 админу | тот же GET под `Roles::ADMIN` | 200, видит те же книги, **нет** `data-super-meta` |
| 403 прочие | teacher, manager, accountant, student, guest | 403 или редирект логина, не 200 |
| Resource create только супер-админ | Livewire Create | admin 403; superadmin 200 |
| Посеянную строку нельзя удалить | canDelete seeded | false |
| Сидер дважды | `ProductDocSeeder` ×2 | 8 slug, без дублей |
| Поиск | `q=домашнее` | hit по студенческой книге или homework |
| Path jail | `source_path=../.env` при save | отказ, файл не читается |
| Pint | `vendor/bin/pint --test` на новых файлах | clean |
| AdminDocument не сломан | `--filter=AdminDocumentLibrary` | зелёный |
| Смоук после деплоя | GET `/admin/documentation` под staff-сессией | 200 или 302 на логин, если без сессии |

Playwright/Dusk каталога **не** требуется.

## Волна 2

Baseline Часть IV (замер 21-08-2026, не уменьшать):

| Книга | Вопросов сейчас |
|---|---|
| STUDENT_CABINET_GUIDE_RU Часть IV | 7 |
| CURATOR_ADMIN_GUIDE_RU Часть IV | 9 |
| ACCOUNTANT_CABINET_GUIDE_RU Часть IV | 7 |
| TEACHER_CABINET_GUIDE_RU § Частые вопросы | 9 |

| Критерий | Pass |
|---|---|
| Счёт ≥ baseline в каждом файле | grep `^\*\*` в Части IV |
| Новые строки без фамилий/рублей живых людей | запрещённые паттерны теста |
| `php artisan test --filter=CabinetMastery` (или текущий фильтр H3215) | зелёный |
| Teacher quiz 200 `seesTeacherSurfaces`, 403 чистому accountant без admin | |
| Accountant quiz 200 finance(), 403 чистому teacher | |
| Каталог ссылает все четыре проверки | |
| Журнал PR: сколько FAQ добавлено / 0 если helpdesk пуст | |

## Риски

| Риск | Что делать |
|---|---|
| Якорь FAQ не совпадает с HTML | Сверить один раз в сидере; тест `assertSee` href с fragment |
| Admin не открывает `/dvaram/help` (роль student) | Не блокер: каталог всё равно показывает ссылку (дефолт 11) |
| `cabinet_mastery_attempts` без audience | Волна 2: миграция с default, не ломая старые ряды |
| Watcher на main | Только worktree + один shell land+commit |
| Пустой helpdesk harvest | Не стоп; квизы из `why` |

## Шлюз автономности (фаза 4 `/ask`)

Каждый deliverable волны 1 имеет архитектуру, шаги, критерий, риск. Развилок без решения нет (дефолт 11 записан). Rebuild AdminDocument не запланирован. Контракт неоднозначности есть.

**Вердикт: PASS.** Можно минтить handoff волны 1.

_Dr. Mārcis Gasūns_
