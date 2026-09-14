# Дорожная карта: каталог документации продукта (2026H2)

_Created: 21-08-2026 · Last updated: 27-08-2026_

Слой «волны и поставки» плана [PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md). Решения там, в разделе 3.

## Волна 1 — каталог + поиск (Grok 4.6)

Один PR. Внутренний порядок — в [IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md).

| № | Поставка | Разблокируется |
|---|---|---|
| 1.1 | Миграция `product_docs` + модель `ProductDoc` (slug, title, audience, route_name, path, faq_fragment, source_path, quiz_audience, access_gate, sort_order, is_active, is_seeded) | ничем |
| 1.2 | Сидер восьми посеянных строк, `firstOrCreate` по slug, не затирает title | 1.1 |
| 1.3 | `ProductDocSearch` — фильтр полей + заголовки/FAQ из md; path jail под `docs/` | 1.1 |
| 1.4 | Filament Page «Документация» `/admin/documentation`, группа Обучение, `RoleGate::adminOnly()` | 1.2 + 1.3 |
| 1.5 | Скрытый Resource CRUD, create/edit/delete только `RoleGate::isSuperAdmin()`; `is_seeded` нельзя удалить | 1.1 |
| 1.6 | Колонки git-path / last-commit / coverage — только HTML супер-админа | 1.4 |
| 1.7 | `ProductDocsCatalogTest` + сидер идемпотентность + поиск «домашнее» | 1.4 |
| 1.8 | CHANGELOG; одна строка в шапках четырёх GUIDE на `/admin/documentation` | 1.4 |

**Волна 1 поставлена 27-08-2026 (H3243).** Готовность: `php artisan test --filter=ProductDocsCatalog` (11 tests / 41 assertions); Pint на новых PHP. После мержа — деплой и смоук GET `/admin/documentation`.

## Волна 2 — FAQ + квизы преподавателя и бухгалтера (Grok 4.6)

После мержа волны 1 (каталог уже умеет прыгать в Часть IV).

**Волна 2 поставлена 27-08-2026 (H3244).** Журнал harvest: [HARVEST_SYSTEMA_PRODUCT_DOCS_CATALOG_W2_27-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/HARVEST_SYSTEMA_PRODUCT_DOCS_CATALOG_W2_27-08-2026.md). Готовность: `php artisan test --filter=CabinetMastery` и `--filter=ProductDocsFaqMastery`; Pint на новых PHP.

| № | Поставка | Разблокируется |
|---|---|---|
| 2.1 | Снять baseline счёт Части IV (записан в PLAN §1: 7 / 9 / 7 / 9) | мерж волны 1 |
| 2.2 | Собрать кандидатов: accepted `SupportAnswerSuggestion` + `why` из `cabinet_mastery` curator/student | 2.1 |
| 2.3 | Дедуп с текущей Частью IV; append недостающих Q тем же регистром; без ФИО и сумм | 2.2 |
| 2.4 | Банк `teacher` в `config/cabinet_mastery.php` (≥8 вопросов из FAQ преподавателя) + страница `/admin/teacher-mastery`, гейт `RoleGate::seesTeacherSurfaces()` | 2.3 |
| 2.5 | Банк `accountant` (≥6 из FAQ бухгалтера) + `/admin/accountant-mastery`, гейт `RoleGate::finance()` | 2.3 |
| 2.6 | Каталог: колонка «Проверка» на живые маршруты (curator, student, teacher, accountant) | 2.4 + 2.5 |
| 2.7 | Тесты: Часть IV не короче baseline; старые H3215 фильтры зелёные; новые страницы 200/403 | 2.3–2.6 |

## Вне рамок (обеих волн)

- Браузер `docs/` целиком (money-access-core, PLAN/ARCHITECTURE).
- Слияние с таблицей `admin_documents`.
- Отдельные FAQ-файлы или DB FAQ.
- Scout/Meilisearch.
- Перенос TeacherGuide/CuratorGuide/AccountantGuide в Filament cluster.
- Правка существующих методов RoleGate и `canViewAny`.
- Автогенерация квиза из заголовков без человеческих дистракторов.
- Продуктовая переработка кабинета вместо мануала (H301).
- Обучающие туры, видео, второй язык.
- Вливание leftover-мануалов (debtors-manual целиком, n8n clips) в книги — это не этот план.

_Dr. Mārcis Gasūns_
