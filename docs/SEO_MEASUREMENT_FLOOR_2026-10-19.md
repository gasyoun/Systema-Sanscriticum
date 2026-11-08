# SEO measurement floor — samskrte.ru — 19-10-2026

_Created: 19-10-2026_

Первый замер «до», без которого любой следующий SEO-шаг недоказуем. Продолжение
[SEO_AUDIT_SAMSKRTE_RU_05-10-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_AUDIT_SAMSKRTE_RU_05-10-2026.md)
и [SEO_PUBLIC_META_BATCH1_2026-10-19.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_PUBLIC_META_BATCH1_2026-10-19.md).

**Почему локально, а не через API.** GSC/Яндекс.Вебмастер у эстейта нет; PageSpeed Insights
отдаёт `429 Quota exceeded` (проверено 19-10-2026). Поэтому: drift-снимок через `claude-seo`
(без CWV) + Lighthouse локально через Unlighthouse (без квоты).

## 1. Drift-базлайн — 44 ключевых URL

- Инструмент: `claude-seo/scripts/drift_baseline.py --skip-cwv` (17 правил сравнения).
- Хранилище: `~/.cache/claude-seo/drift/baselines.db` (локальное, не в git).
- Долговечный снимок: [docs/seo/baseline-2026-10-19.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/seo/baseline-2026-10-19.json) —
  44 записи: `title`, `meta_description`, `canonical`, `robots`, `h1`, `schema_hash`, `html_hash`, `status_code`.
- Покрытие: главная, `/online` + 6 категорий, `/online/put`, `/prepodavately` + 4 анкеты,
  `/s` + 3 статьи + рубрика, 12 `/k/`, лендинги (`/sans`, `/geography`, `/philosophy`, `/hindi`,
  `/hanuman`, `/calendary`), 2 вебинара, `/konsultaciya-po-onlayn-kursam`, `/sanskritorium`, `/sertifikat`.
- Все 44 — HTTP 200, 0 ошибок. Смоук `drift_compare.py` на главной сразу после снятия:
  расхождений нет (ожидаемо), пайплайн живой.

Как сравнивать после следующего деплоя:

```sh
python3 scripts/drift_compare.py https://samskrte.ru/online --skip-cwv
```

(канонический вход — `bin/claude-seo run drift_compare.py …`; на машине замера managed-runtime
`/seo setup` ещё не собран, поэтому скрипты гонялись напрямую с venv: `requests`, `beautifulsoup4`, `lxml`.)

## 2. Lighthouse — 49 маршрутов, mobile, simulate

- Инструмент: Unlighthouse 0.13.5 (`npx`), mobile, 1 сэмпл на маршрут, 340 с.
- Сырьё: [docs/seo/lighthouse-2026-10-19.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/seo/lighthouse-2026-10-19.json).
- Медиана performance **0.78**; **15 из 49** маршрутов ниже 0.70; ниже 0.50 — один (`/webinar-osen-2026`, 0.50).
- Медиана LCP **4.1 с**, максимум **33.1 с** (`/hindi`).

| Маршрут | perf | LCP, с | CLS | Вес, МБ | Картинок |
|---|---|---|---|---|---|
| `/webinar-osen-2026` | 0.50 | 5.1 | **0.51** | 0.96 | 12 |
| `/hindi` | 0.55 | 33.1 | 0.00 | **7.44** | 30 |
| `/geography` | 0.57 | 15.6 | 0.00 | 4.81 | 22 |
| `/hanuman` | 0.58 | 11.6 | 0.00 | 4.30 | 23 |
| `/` | 0.60 | 11.9 | 0.00 | 2.66 | 23 |
| `/s/zachem-sanskrit` | 0.61 | 12.2 | 0.08 | 2.22 | 6 |
| `/s/sanskrit-mozg` | 0.62 | 19.7 | 0.00 | 3.70 | 6 |
| `/sans` | 0.63 | 30.8 | 0.00 | **7.06** | 18 |
| `/webinar-grammatika` | 0.64 | 5.0 | 0.15 | 0.96 | 12 |
| `/online` | 0.73 | 21.3 | 0.00 | 5.46 | 15 |
| `/koloda/yazyk/hindi` | 0.99 | 1.7 | 0.00 | 0.55 | 1 |

Лёгкие страницы (≈0.55 МБ, 1 картинка) держат 0.80–0.99. Разброс объясняется почти целиком весом картинок.

## 3. Что нашлось

### F1 — вес картинок, а не JS (главный дефект производительности)

`/online`: LCP-элемент — карточка курса `storage/course-design/457/4x3-0NgWuRCf.png`:
**1448×1086 px, PNG, 2,1 МБ**, показывается в **378×283** CSS-px (3,8× oversize). Таких карточек
на странице несколько; всего 4,87 МБ картинок из 5,46 МБ страницы. Lighthouse оценивает экономию
`modern-image-formats` в **4 097 КиБ**.

Наблюдаемый LCP (быстрая сеть) — **0,66 с**, симулированный mobile — **21,3 с**: на 4G реальный
пользователь ждёт именно столько, потому что картинки съедают канал. Это же бьёт по поведенческим
факторам Яндекса, главному двигателю.

### F2 — CLS на промо/вебинарных страницах

`/webinar-osen-2026` — **0,51**, `/sanskritorium` — 0,18, `/sertifikat` — 0,17,
`/webinar-grammatika` и `/webinar-upanishady-2026` — по 0,15. Порог «good» — 0,1.
Промо-страницы собраны builder'ом: картинки/блоки без зарезервированных размеров.

### F3 — `/online` тяжёлый по DOM

3 732 узла (Lighthouse score 0.5 по `dom-size`), 101 `<img>` в DOM, 35 запросов (lazy-loading работает).
Это не блокер само по себе, но усложняет и LCP, и обслуживание.

### F4 — доступность проседает там же, где картинки

`/hindi` 0.78, `/philosophy` 0.80, `/hanuman` 0.81, `/geography` 0.82. На лёгких страницах — 0.91–0.96.
Корреляция с тяжёлыми hero-блоками; чинить вместе с F1 (alt/контраст/размеры), не отдельной волной.

### Что НЕ подтвердилось

- Рендер-блокирующих ресурсов нет (score 1), TBT ≈ 0 на всех маршрутах, mainthread-work ≤0.5 с:
  JS-регрессии нет, оптимизировать скрипты нечего.
- Битых/редиректящих URL в замере нет; sitemap и canonical чисты (см. батч 19-10).

## 4. Следующие шаги (с фальсифицируемостью)

1. **Конвейер картинок** (`/seo-images` + код): ресайз до 2× CSS-размера, WebP/AVIF, `srcset`,
   `fetchpriority="high"` на LCP-картинке, `width`/`height` у всех карточек.
   Ожидание: `/hindi`, `/sans`, `/online` — с 5–7 МБ до <1,5 МБ; медиана perf ≥0.85.
   Проверка: повторный Unlighthouse по тем же 49 маршрутам — сравнивать с `lighthouse-2026-10-19.json`.
2. **CLS на промо-страницах**: зарезервировать размеры медиа и шрифтовые метрики. Цель — 0 маршрутов >0.1
   (сейчас 6).
3. После деплоя — `drift_compare.py` по 44 URL и запись расхождений сюда же (append-only).

## 5. IndexNow (индексация без Вебмастера)

Ключ добавлен в `public/3d8b1b068ee599d1f5aa9ae9c63d0968.txt` (тело файла = ключ).
Скрипт — [`scripts/indexnow_submit.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/indexnow_submit.py):
stdlib, без `requests`, **dry-run по умолчанию**; 24 теста —
`python3 -m unittest scripts/test_indexnow_submit.py -v`. После деплоя:

```sh
curl -s https://samskrte.ru/3d8b1b068ee599d1f5aa9ae9c63d0968.txt   # должен вернуть ключ

# 1. Проверить, что ключ опубликован и совпадает.
python3 scripts/indexnow_submit.py --host samskrte.ru \
  --key 3d8b1b068ee599d1f5aa9ae9c63d0968 \
  --key-location https://samskrte.ru/3d8b1b068ee599d1f5aa9ae9c63d0968.txt \
  --verify-only

# 2. Сухой прогон: печатает payload, в сеть не ходит.
URLS=/tmp/urls.txt
curl -s https://samskrte.ru/sitemap.xml | grep -o '<loc>[^<]*' | cut -c6- > "$URLS"
python3 scripts/indexnow_submit.py --host samskrte.ru \
  --key 3d8b1b068ee599d1f5aa9ae9c63d0968 \
  --key-location https://samskrte.ru/3d8b1b068ee599d1f5aa9ae9c63d0968.txt \
  --urls-file "$URLS"

# 3. Отправка (--apply обязателен; без него сеть не трогается).
python3 scripts/indexnow_submit.py ... --urls-file "$URLS" --apply
```

Google IndexNow не поддерживает — сигнал идёт в Яндекс, Bing, Seznam, Naver (и Copilot-цитаты через Bing).
Это единственный доступный сейчас канал «свежести» без Вебмастера.

## 6. Остаток (человеческое)

- Доступ к Яндекс.Вебмастеру (запросы, ИКС, ошибки индексации) и GSC — без них замеры остаются лабораторными.
- PSI-квота: keyless 429; либо ключ Google API, либо оставить Lighthouse как рабочий инструмент.
## 7. После конвейера картинок (19-10-2026, тот же прогон 49 маршрутов)

Конвейер: [#3394](https://github.com/gasyoun/Systema-Sanscriticum/pull/3394) (варианты WebP + `srcset`),
[#3395](https://github.com/gasyoun/Systema-Sanscriticum/pull/3395) (нативные ширины в `srcset`).
Backfill на проде: 187 исходников → 438 вариантов, 107,13 МБ → 22,40 МБ производных.
Сырьё после: [docs/seo/lighthouse-2026-10-19-after.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/seo/lighthouse-2026-10-19-after.json).

| Маршрут | Вес, МБ | LCP, с | perf |
|---|---|---|---|
| `/sans` | 7,06 → **1,36** | 30,8 → **6,5** | 0,63 → 0,67 |
| `/online` | 5,46 → **1,59** | 21,3 → **6,5** | 0,73 → 0,70 |
| `/hindi` | 7,44 → **5,49** | 33,1 → **10,0** | 0,55 → 0,60 |
| `/geography` | 4,81 → **2,86** | 15,6 → **9,0** | 0,57 → 0,70 |
| `/hanuman` | 4,30 → **2,40** | 11,6 → **6,3** | 0,58 → 0,66 |
| `/philosophy` | 3,29 → **1,35** | 11,3 → **6,2** | 0,66 → 0,69 |
| `/webinar-osen-2026` | 0,96 → 0,42 | 5,1 → 2,6 | **0,50 → 0,96** |
| медиана (41 общий маршрут) | 0,96 → 0,94 | 5,0 → 4,1 | 0,75 → 0,78 |

**Что ещё тяжело (следующий шаг, тот же приём):**

- `/online`: 0,48 МБ — аватары отзывов `storage/testimonials/*.jpg` по 110–130 КБ при показе 40 px.
  Варианты для них уже сгенерированы, не хватает только обёртки `<x-responsive-image>` в
  `shop/partials/{testimonials,featured-testimonials,testimonials-library}.blade.php`,
  `auth/partials/testimonials-marquee.blade.php` и `promo/blocks/reviews_block.blade.php`.
- `/hindi`: промо-медиа `storage/media/*.JPG` (0,38 / 0,32 / 0,18 / 0,15 МБ) из блоков отзывов/галереи +
  внешний VK-thumbnail 0,24 МБ, который мы не контролируем.
- Мелкие просадки (`/koloda/yazyk/hindi` 0,99 → 0,88, `/online/put` 0,96 → 0,84) — при тех же
  ресурсах и том же весе это разброс одиночного сэмпла, а не регрессия конвейера.

**Оговорка о методе:** Lighthouse тут — simulate-throttling, 1 сэмпл на маршрут; сравнение по 41 общему
маршруту. Полевых CWV по-прежнему нет (PSI 429).

_Dr. Mārcis Gasūns_
