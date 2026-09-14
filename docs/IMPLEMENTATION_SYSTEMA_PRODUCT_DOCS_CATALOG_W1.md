# Реализация волны 1: каталог документации

_Created: 21-08-2026 · Last updated: 21-08-2026_

Пошаговая сборка волны 1 для [PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md). Компоненты — в [ARCHITECTURE_SYSTEMA_PRODUCT_DOCS_CATALOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_PRODUCT_DOCS_CATALOG.md), приёмка — в [VERIFICATION_SYSTEMA_PRODUCT_DOCS_CATALOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_PRODUCT_DOCS_CATALOG.md).

Worktree: `git worktree add -b feat/product-docs-catalog-hXXXX ../Systema-Sanscriticum-hXXXX-<pid> origin/main`. Коммиты watcher-safe. Существующие RoleGate-методы не править.

## Шаг 1 — схема и модель

Файлы:

- `database/migrations/2026_08_21_120000_create_product_docs_table.php`
- `app/Models/ProductDoc.php`
- `database/factories/ProductDocFactory.php` (для тестов)

Колонки как в архитектуре §3. Индекс unique на `slug`. `source_path` string 255 nullable.

Модель: `scopeActive`, `href()`, `faqHref()`, `quizHref()`, `normalizeSourcePath()`, boot saving.

Зависимость: нет.

## Шаг 2 — сидер

Файл: `database/seeders/ProductDocSeeder.php`. Вызов из `DatabaseSeeder` рядом с `AdminDocumentSeeder`, без wipe.

Восемь строк архитектуры §2. `firstOrCreate(['slug' => …], $row)`. Повторный прогон не меняет `title`/`description`, если они уже есть.

Якоря FAQ: открыть четыре GUIDE + homework guide, взять фактический id заголовка Части IV (как рендерит `Str::markdown` / `id` в HTML). Записать в сидер. Если якорь кириллический — так и хранить.

Зависимость: шаг 1.

## Шаг 3 — поиск

Файл: `app/Support/ProductDocSearch.php`.

Метод `search(?User $user, string $q): Collection` — не фильтрует по роли студента; вызывающий уже на adminOnly странице. Возвращает hits `{doc, field, heading, href}`.

Jail: `ProductDoc::assertSafeSourcePath($path): ?string` возвращает абсолютный путь или null.

Git meta: `ProductDocGitMeta::lastCommitDate(string $relative): ?string` — только для супер-админа, шаг 6.

Зависимость: шаг 1.

## Шаг 4 — страница каталога

Файлы:

- `app/Filament/Pages/DocumentationCatalog.php` — navigationGroup `Обучение`, label `Документация`, slug `documentation`, sort после гидов (например 5), icon `heroicon-o-queue-list`, `canAccess` = `RoleGate::adminOnly()`.
- `resources/views/filament/pages/documentation-catalog.blade.php`

Livewire: public `string $q = ''`. Список: `ProductDoc::query()->active()->orderBy('sort_order')->orderBy('title')`. Если `$q` ≥ 2 — `ProductDocSearch`.

Кнопка «FAQ» только при непустом `faq_fragment`. «Проверка» только при непустом `quizHref()` (teacher/accountant в волне 1 пустые — это ок).

Header action «Добавить» visible `RoleGate::isSuperAdmin()` → Resource create.

Шапка четырёх GUIDE (student/curator/accountant/teacher): одна строка «Все книги школы: `/admin/documentation`» — только в md, без HTML.

Зависимость: 2 + 3.

## Шаг 5 — скрытый Resource

Файлы по образцу AdminDocumentResource, но:

- `shouldRegisterNavigation(): false`
- `canViewAny` / `canCreate` / `canEdit` / `canDelete` = `RoleGate::isSuperAdmin()` (не adminOnly)
- `canDelete($record)` ещё и `! $record->is_seeded`
- slug `product-docs`
- форма: title, slug (readonly если seeded), audience select, route_name, url_path, faq_fragment, source_path, quiz_audience, sort_order, is_active. `is_seeded` не в форме у человека (остаётся как в БД).

Не копировать AdminOnly trait — он даёт admin create.

Зависимость: шаг 1.

## Шаг 6 — колонки супер-админа

В view страницы: `@if(RoleGate::isSuperAdmin())` путь + дата коммита + «PNG: N/M» если есть screenshot dir. В тесте админа `assertDontSee('docs/STUDENT_CABINET_GUIDE_RU.md')` в HTML каталога (или более узкий маркер `data-super-meta`). Лучше явный CSS-класс/атрибут `data-super-meta`, который тест админа не видит, супер-админ видит.

Зависимость: шаг 4.

## Шаг 7 — тесты

Файл: `tests/Feature/ProductDocsCatalogTest.php`.

Кейсы — в VERIFICATION. Гнать `php artisan test --filter=ProductDocsCatalog`. Не гонять весь suite, пока этот фильтр красный. Затем смежные: `AdminDocumentLibraryTest` (не сломали сидер), `CabinetMastery` не трогаем в волне 1.

Pint на новые PHP.

Зависимость: 4–6.

## Шаг 8 — журнал, CHANGELOG, PR

- `CHANGELOG.md` `[Unreleased] ### Added` одна пуля с полным URL плана.
- Журнал решений в теле PR, если сработал дефолт 11 (видимость студенческих URL).
- PR → мерж → деплой Systema → смоук.

Зависимость: шаг 7 зелёный.

## Что не делать в волне 1

Не писать FAQ. Не трогать `cabinet_mastery.php`. Не снимать Playwright каталога. Не добавлять флаги. Не рефакторить TeacherGuide.

_Dr. Mārcis Gasūns_
