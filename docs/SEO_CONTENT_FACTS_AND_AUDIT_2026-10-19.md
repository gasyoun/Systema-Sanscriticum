# Факт-лист и аудит описаний курсов — H7094 — 19-10-2026

_Created: 19-10-2026_

Инструменты для Move 2 ([пакет](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_MOVE2_DUPLICATE_CONTENT_PACKET_2026-10-19.md)):
17 из 98 страниц `/k/` в sitemap имеют пустое `courses.description`, 4 короче 400 символов,
5 групп делят побайтово одинаковый текст. Canonical запрещён — у семейств живые оплаты,
поэтому лечится уникальным текстом. Эти две команды убирают research-часть у редактора и
не дают списку отрасти заново.

## `catalog:seo-content-facts` — факт-лист для редактора

```sh
php artisan catalog:seo-content-facts ioga-sutry-patandzali-1-potok-2025
php artisan catalog:seo-content-facts --limit=5            # очередь на редактуру
php artisan catalog:seo-content-facts --limit=20 --format=json
```

Отдаёт **только факты из БД**: заголовок, slug, URL, преподаватель, формат, часы, число занятий,
блоки с датами (по `lessons.lesson_date`), тарифы с ценами, длину текущего описания и число оплат.
Ни одной сочинённой фразы; отсутствующее поле печатается как «нет данных».

**Очередь** (`--limit`): visible + `PrivateArchiveEligibility::scopePublic` + `withOwnCatalogCard`
(ровно скоуп sitemap), описание пустое или короче 400 символов, сортировка по числу оплат desc.
Так первым идёт `ioga-sutry-patandzali-1-potok-2025` (790 оплат).

Заканчивается блоком «Что написать (120–200 слов)» — четыре пункта, которые редактор заполняет
по таблицам выше, а не по памяти.

## `catalog:audit-seo-content` — детектор

```sh
php artisan catalog:audit-seo-content            # таблицы + итог, exit 0
php artisan catalog:audit-seo-content --json     # машинный вывод
php artisan catalog:audit-seo-content --strict   # exit 1 при любой находке
```

Ловит три класса: пустое описание, описание короче 400 символов, побайтово одинаковые описания
(MD5, одна строка на группу — не на пару). Читает только БД, ничего не меняет. `--strict` —
гейт для CI/расписания; без него exit 0 всегда, чтобы ночной лог не краснел.

## Приёмка

```sh
php artisan catalog:audit-seo-content --strict; echo $?          # ожидается 1 и 17 пустых
php artisan catalog:seo-content-facts ioga-sutry-patandzali-1-potok-2025
```

Вторая команда обязана показать преподавателя, занятия, блоки с датами и 790 оплат — без
единой выдуманной строки.

## Границы

Не меняет `courses.description`, `is_visible`, тарифы, оплаты и доступы. Не индексирует и не
деиндексирует ничего. Тексты пишет человек; команда только собирает факты и следит за регрессией.

_Dr. Mārcis Gasūns_
