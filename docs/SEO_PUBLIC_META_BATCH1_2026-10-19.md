# SEO batch 1 — публичные мета-теги samskrte.ru — 19-10-2026

_Created: 19-10-2026_

Продолжение аудита [SEO_AUDIT_SAMSKRTE_RU_05-10-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_AUDIT_SAMSKRTE_RU_05-10-2026.md). Аудит закрыл www/H1/sitemap-остатки; этот батч закрывает три класса, которых в нём не было.

## Живой обход 19-10-2026 (150 URL из sitemap)

| Проверка | Результат |
|---|---|
| HTTP-статус | 150/150 = 200 |
| `noindex` в sitemap | 0 |
| canonical ≠ URL | 0 |
| пустой/отсутствующий description | **39** |
| один title на 7 URL каталога | **7** (`/online` + 6 категорий) |
| один title на 18 колод + 3 хаба | **21** («Карточки — пробный режим» / «Колоды для запоминания…») |
| title > 60 символов | 53 (вне батча) |
| страниц < 300 слов | 4 (вне батча) |

## Что исправлено

| Семейство | Было | Стало |
|---|---|---|
| `/online/kategoriya/{slug}` (6) | title/description/H1 каталога, self-canonical | title «Курсы санскрита: {категория}», свой H1 и description |
| `/koloda/{slug}` (18) | один title, нет description, нет canonical | per-deck title/description, canonical на себя |
| `/koloda` + `/koloda/yazyk/*` (3) | один title/description/H1 на все хабы | свои title/description/H1; `yazyk/sanskrit` канонизируется на `/koloda` |
| `/koloda/*` «Level 2»…«Level 10» | индексируемые дубли уровня 1, в sitemap | `noindex, follow`, из sitemap исключены |
| `/k/{course}` без описания | `<meta name="description" content="">` | фолбэк: описание → «Курс санскрита «{title}»…» |
| лендинги/вебинары (promo builder) | нет description | `showcase_description` → `description` → фолбэк по title, лимит 160 |
| лендинги (promo legacy) | нет description | `hero_description` → `showcase_description` → фолбэк, canonical |
| `/online/put` | нет description | собственный description |
| прочие shop-страницы | нет description | fallback в `layouts/shop` (брендовый, не каталожный) |
| teacher/certificate/testimonials/hubs | description в `@push('head')` → второй тег рядом с layout-фолбэком | переведены на `@section('meta_description')` |

## Гейт

`tests/Feature/Seo/PublicMetaTest.php` — 8 тестов, 50 утверждений:

1. категория не переиспользует title каталога и имеет свой H1/description;
2. курс без описания всё равно получает непустой description;
3. колода получает per-deck title/description/canonical;
4. «Level 2» отдаёт `noindex, follow` и отсутствует в sitemap, «Level 1» — присутствует;
5. языковые хабы `/koloda` / `hindi` / `vse` имеют разные title, а `yazyk/sanskrit` канонизируется на `/koloda`;
6. лендинг получает description;
7. каждая публичная страница отдаёт ровно один `<meta name="description">` (иначе layout-фолбэк дублировал уже существующий тег);
8. в sitemap нет дублей `<loc>`.

Локально: `php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Seo` — 49 тестов зелёные (включая SRS public URLs).

## Приёмка после деплоя

```sh
curl -s https://samskrte.ru/online/kategoriya/grammatika | grep -E '<title>|name="description"'
curl -s https://samskrte.ru/koloda/memrise-6679375-level-2 | grep -E 'robots|canonical'
curl -s https://samskrte.ru/sitemap.xml | grep -c 'memrise-6679375-level-2'   # 0
```

Ожидание: у категории свой title/description; level-2 — `noindex, follow`; в sitemap level-2 нет.

## Остаток (не в этом батче)

- 53 title длиннее 60 символов — сокращать брендовый суффикс пакетно.
- 4 страницы < 300 слов.
- BreadcrumbList на категориях каталога.
- Medium-2 порция 2: deep-уникализация групп Кочергиной (аутлаер gr42/gr59 95,3%).
- GSC/Яндекс.Вебмастер — доступ у эстейта отсутствует; замеры после батча остаются ручными.

_Dr. Mārcis Gasūns_
