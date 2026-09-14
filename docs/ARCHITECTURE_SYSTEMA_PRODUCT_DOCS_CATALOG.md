# Архитектура: каталог документации продукта

_Created: 21-08-2026 · Last updated: 21-08-2026_

Слой «как устроено» для [PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md). Сборка волны 1 — в [IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md).

## 1. Две полки

| Полка | Таблица | Для кого | Что лежит |
|---|---|---|---|
| Документация продукта | `product_docs` (новая) | admin + superadmin смотрят; CRUD только superadmin | Живые книги кабинета |
| Важные файлы | `admin_documents` (H2570) | admin + superadmin, строки `super_admin_only` | Sheets, Drive, регламенты |

Каталог **не** наследует AdminDocument. Одна посеянная строка `important-files` ведёт на `filament.admin.resources.admin-documents.index` (или эквивалент ListAdminDocuments).

## 2. Посеянные строки волны 1

| slug | title | audience | route / path | source_path | faq_fragment | quiz_audience |
|---|---|---|---|---|---|---|
| student | Как пользоваться кабинетом | student | `student.help` | `docs/STUDENT_CABINET_GUIDE_RU.md` | `часть-iv-частые-вопросы` | student |
| teacher | Руководство преподавателя | teacher | Filament teacher-guide | `docs/TEACHER_CABINET_GUIDE_RU.md` | `частые-вопросы` | teacher (волна 2) |
| curator | Руководство куратора | curator | Filament curator-guide | `docs/CURATOR_ADMIN_GUIDE_RU.md` | `часть-iv-частые-вопросы` | curator |
| accountant | Как работать бухгалтеру | accountant | Filament accountant-guide | `docs/ACCOUNTANT_CABINET_GUIDE_RU.md` | `часть-iv-частые-вопросы` | accountant (волна 2) |
| homework | Как сдавать домашнее задание | student | `faq.dz` | `docs/STUDENT_HOMEWORK_GUIDE_RU.md` | `7-частые-вопросы` (проверить якорь в файле) | — |
| prana | Почему баланс праны уменьшился | student | `help.prana-balance` | blade, **source_path пустой** | — | — |
| payout-guide | Как размечать выплаты | accountant | Filament payout-attribution-guide | нет md (живая очередь) | — | — |
| important-files | Важные файлы | ops | AdminDocument list | нет | — | — |

Якоря FAQ: сверить фактический slug `Str::slug` / GitHub-стиль кириллицы по заголовку в каждом файле в шаге 1.2 реализации. Если тест поиска не попадает — чинить fragment в сидере, не выдумывать второй FAQ.

`is_seeded = true` на всех восьми. Человек может добавить девятую строку (произвольный URL) через Resource.

## 3. Модель `ProductDoc`

Поля: `slug` unique, `title`, `description` nullable, `audience` (student|teacher|curator|accountant|ops), `route_name` nullable, `url_path` nullable (когда route_name нет), `faq_fragment` nullable, `source_path` nullable, `quiz_audience` nullable, `access_gate` (см. ниже), `sort_order`, `is_active`, `is_seeded`.

Нормализация `source_path` в `saving`: пусто ок; иначе только относительный путь `docs/....md`, `realpath` строго внутри `base_path('docs')`, иначе ValidationException. **Никогда не брать путь из HTTP.**

`href()`: если `route_name` зарегистрирован — `route($name)` плюс `#fragment` для FAQ-кнопки; иначе `url_path`.

`quizHref()`: map audience → существующие имена маршрутов (`filament.admin.pages.cabinet-mastery`, `student.cabinet-mastery`, и после волны 2 teacher/accountant). Пусто, если банка ещё нет.

## 4. Гейты

Страница каталога и чтение списка: `RoleGate::adminOnly()` (admin + superadmin через `any()`).

Resource:

- `canViewAny`: `adminOnly()` (админ видит форму? нет — админ **не** видит Resource в меню и `canCreate` false; прямое `/admin/product-docs` 403 для admin, 200 список только superadmin **или** Resource доступен superadmin-only целиком).

Зафиксированный дефолт, чтобы не спорить с «админ read-only каталога»: **Resource целиком `RoleGate::isSuperAdmin()`**. Админ работает только со страницей `/admin/documentation`. Superadmin на той же странице видит «Добавить» → Resource create.

`canDelete`: false если `is_seeded`.

Существующие гиды свои гейты не меняют.

## 5. Поиск

Класс `App\Support\ProductDocSearch`:

1. Trim, минимум 2 символа Unicode.
2. Сначала LIKE по `title`, `description`, `audience`, `slug` среди `is_active`.
3. Для строк с валидным `source_path` — `file_get_contents` (уже jail), разобрать строки `^#{1,3} ` и блок после заголовка Часть IV / Частые вопросы. Совпадение — hit с `heading` + `href#slug`.
4. Без кэш-таблицы. Семь файлов.

Git-метаданные (только супер-админ на странице): `git log -1 --format=%cs -- docs/...` через `Process` с `cwd` = base_path, путь аргументом массива (не shell). Нет git — показать «—», не 500.

Coverage: для гидов с `docs/screenshots/<dir>/` — сосчитать PNG vs упоминания в md, как coverage-тесты гидов. Пусто для payout/prana/important-files.

## 6. Страница

Filament Page `DocumentationCatalog`, view Blade: поле поиска (GET query `q` на той же странице Livewire), таблица/карточки: название, аудитория, «Открыть», «FAQ» (если fragment), «Проверка» (если quiz), для супер-админа ещё путь и дата.

Не рендерить полный MarkdownGuide на этой странице — это указатель.

## 7. Волна 2 квизы

Не новый движок. `CabinetMastery::AUDIENCE_TEACHER` / `AUDIENCE_ACCOUNTANT`. Тонкие страницы копируют `CabinetMasteryQuiz` с другим audience и своим `canAccess`. Попытки в ту же `cabinet_mastery_attempts` (колонка audience уже должна быть — проверить; если нет — **не** ломать старые ряды, добавить nullable audience default curator только если колонки нет; иначе использовать существующее поле).

Перед кодом: прочитать миграцию `cabinet_mastery_attempts`. Если audience уже есть — писать туда. Если нет — добавить колонку с default `curator` для старых строк.

## 8. Безопасность

- Неэкранированный HTML книг **не** появляется на каталоге (нет MarkdownGuide::html на этой странице).
- Hits поиска — `e()` текст заголовка.
- source_path jail.
- FAQ волны 2: запрещённые подстроки ФИО из прод-очереди не копировать; только обезличенный шаблон («ученик», «преподаватель курса»).

_Dr. Mārcis Gasūns_
