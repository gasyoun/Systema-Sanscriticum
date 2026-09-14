# Инвентарь SEO-агентов и SEO-скиллов (Grok / claude-seo)

_Created: 02-08-2026 · Last updated: 02-08-2026_

**Назначение.** Краткая справка «что у нас есть по SEO-инструментарию агентов», чтобы не искать по `~/.grok` и `claude-seo` заново. Охватывает только слои **1** (spawnable-агенты) и **2** (пакет скиллов + состояние установки).

**Связанные Systema-доки (не дублировать):**

| Документ | Роль |
|---|---|
| [docs/SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md) | Продуктовый SEO-роадмап samskrte.ru (Yandex-first) |
| [Uprava SEO_STRUCTURED_DATA_PLAYBOOK](https://github.com/gasyoun/Uprava/blob/main/SEO_STRUCTURED_DATA_PLAYBOOK.md) | Орг-рецепт JSON-LD (не скилл) |

**Источник правды пакета:** репозиторий [gasyoun/claude-seo](https://github.com/gasyoun/claude-seo) (локальный клон: `Documents/GitHub/claude-seo`).

**Модель-провенанс этой инвентаризации:** Grok 4.5 (`grok-4.5`), H2220.

---

## 1. SEO-агенты Grok (spawnable)

Определения лежат в [`~/.grok/agents/seo-*.md`](https://github.com/AgriciDaniel/claude-seo/tree/main/agents) (зеркало агентов из `claude-seo/agents/`). Их можно вызывать как subagent-типы `seo-*` в сессии Grok Build.

| Агент | Зачем |
|---|---|
| **seo-technical** | Технический SEO: crawlability, indexability, security, URL, mobile, Core Web Vitals, JS-rendering |
| **seo-content** | Качество контента: E-E-A-T, читаемость, глубина, thin content, готовность к AI-цитированию |
| **seo-schema** | Schema.org: обнаружение, валидация, генерация JSON-LD |
| **seo-sitemap** | XML-sitemap: валидация, генерация, quality gates (в т.ч. location-страницы) |
| **seo-performance** | Измерение Core Web Vitals и скорости загрузки |
| **seo-visual** | Скриншоты, mobile-rendering, above-the-fold (Playwright) |
| **seo-backlinks** | Профиль бэклинков из нескольких источников (Moz, Bing WMT, Common Crawl и др.) |
| **seo-cluster** | Кластеризация ключей по SERP-overlap, hub-and-spoke, матрица внутренних ссылок |
| **seo-local** | Локальный SEO: GBP, NAP, цитаты, отзывы, local schema, multi-location |
| **seo-maps** | Карты: geo-grid ранги, аудит GBP, review intelligence, радиус конкурентов |
| **seo-geo** | GEO / AI-поиск: доступность AI-краулеров, `llms.txt`, citability, AI Overviews / ChatGPT / Perplexity / Copilot |
| **seo-google** | Google API-слой: CrUX (CWV), GSC (индексация), GA4 (органика) |
| **seo-dataforseo** | Live SERP, ключи, бэклинки, on-page, AI-visibility через DataForSEO |
| **seo-ecommerce** | E-commerce SEO: product schema, Shopping/Amazon, product-page рекомендации |
| **seo-sxo** | Search Experience Optimization: SERP-backwards, mismatch page-type/intent, persona-scoring |
| **seo-flow** | FLOW-framework: stage-промпты против URL |
| **seo-drift** | Регрессии SEO-элементов vs сохранённый baseline (нужен baseline) |
| **seo-image-gen** | Аудит OG/social-картинок + план генерации (**не** генерирует картинки сам) |

**Практика для Systema / samskrte.ru.** Для разового аудита URL — `seo-technical` + `seo-schema` + `seo-content`. Для коммерческих страниц курсов — дополнительно `seo-ecommerce`. Для словаря/long-tail — `seo-cluster` + `seo-geo` (Google-second; Yandex-first остаётся в [SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md)).

**Не путать с агентами.** Агенты — это роль/промпт; CLI-хелперы (`bin/claude-seo`, `scripts/*.py`) живут в пакете скиллов (слой 2) и вызываются агентами, когда установка живая.

---

## 2. Пакет SEO-скиллов (имена, установка, разрыв)

### 2.1. Что входит в пакет

В `claude-seo/skills/` и зеркальных junction-именах под `~/.grok/skills/`:

**Оркестрация / планирование**

| Скилл | Зачем |
|---|---|
| **seo** | Корневой SEO-скилл (framework, quality gates, ссылки на sub-skills) |
| **seo-audit** | Полный/составной SEO-аудит |
| **seo-page** | Аудит одной страницы |
| **seo-plan** | SEO-план по шаблонам vertical (agency, ecommerce, saas, local, publisher, …) |
| **seo-programmatic** | Programmatic SEO |
| **seo-content-brief** | Бриф на контент (плотность, page-type templates) |
| **seo-competitor-pages** | Разбор страниц конкурентов |

**Специализации (зеркало агентов)**

| Скилл | Пара к агенту |
|---|---|
| **seo-technical** | seo-technical |
| **seo-content** | seo-content |
| **seo-schema** | seo-schema |
| **seo-sitemap** | seo-sitemap |
| **seo-cluster** | seo-cluster |
| **seo-backlinks** | seo-backlinks |
| **seo-local** | seo-local |
| **seo-maps** | seo-maps |
| **seo-geo** | seo-geo |
| **seo-google** | seo-google |
| **seo-dataforseo** | seo-dataforseo |
| **seo-drift** | seo-drift |
| **seo-ecommerce** | seo-ecommerce |
| **seo-flow** | seo-flow |
| **seo-sxo** | seo-sxo |
| **seo-image-gen** | seo-image-gen |
| **seo-images** | (без отдельного agent-файла в agents/) |
| **seo-hreflang** | hreflang / i18n-валидация |

**Расширения (отдельные installers в `claude-seo/extensions/`)**

| Расширение | Скилл | Внешняя зависимость |
|---|---|---|
| Ahrefs | **seo-ahrefs** | API Ahrefs |
| Bing Webmaster | **seo-bing** | Bing WMT |
| DataForSEO | **seo-dataforseo** | DataForSEO API / MCP |
| Firecrawl | **seo-firecrawl** | Firecrawl |
| Profound | **seo-profound** | Profound |
| SE Ranking | **seo-seranking** | SE Ranking |
| Unlighthouse | **seo-unlighthouse** | Unlighthouse |
| Banana (image) | **seo-image-gen** (extension) | Gemini / image pipeline |

Плюс CLI: `claude-seo/bin/claude-seo` и набор `claude-seo/scripts/` (sitemap discovery, schema generate, pagespeed, GSC, CrUX, drift, moz, …).

### 2.2. Состояние установки на этой машине (02-08-2026)

| Поверхность | Статус |
|---|---|
| **Агенты** `~/.grok/agents/seo-*.md` | Живые — spawnable |
| **Исходники** `Documents/GitHub/claude-seo` | Полный репозиторий (skills + agents + scripts + extensions) |
| **Junctions** `~/.grok/skills/seo*` → `~/.claude/skills/seo*` | Имена есть, **цели пустые / отсутствуют** |
| **Тела скиллов в `~/.claude/skills/`** | **Не установлены** — skill package «по имени», без `SKILL.md` |
| **CLI `claude-seo` в PATH** | Ожидается после `install.ps1`; до reinject надёжнее вызывать из репо |

**Следствие.** Агенты из слоя 1 работают как промпты. Полные skill-тела, references и helper-скрипты, на которые агенты ссылаются (например `"$HOME/.claude/skills/seo/bin/claude-seo" run sitemap_discovery.py …`), **не гарантированы**, пока не прогнан installer.

### 2.3. Как починить установку

Из клона [claude-seo](https://github.com/gasyoun/claude-seo):

```powershell
cd C:\Users\user\Documents\GitHub\claude-seo
.\install.ps1
# при необходимости: extensions\*\install.ps1
```

После install проверить:

1. Существует `C:\Users\user\.claude\skills\seo\SKILL.md` (и соседние `seo-*`).
2. Junction/symlink `~/.grok/skills/seo` резолвится в непустое дерево.
3. `claude-seo` (или `bin\claude-seo`) отвечает на `--help` / smoke `run`.

Обратная операция: `uninstall.ps1` в корне и в extensions.

### 2.4. Что **не** входит в слои 1–2 (чтобы не смешивать)

| Нужда | Куда |
|---|---|
| samskrtam.ru shell/funnel ladder (P0–P3) | skill **`/ors-seo`** (ORS-FAQ), не claude-seo |
| SEO landing Cologne dict на GitHub Pages | **`/cologne-pages-landing`** |
| Продуктовый SEO samskrte.ru (Yandex-first, P0–P2) | [docs/SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md) |
| Multi-site JSON-LD recipe | [SEO_STRUCTURED_DATA_PLAYBOOK](https://github.com/gasyoun/Uprava/blob/main/SEO_STRUCTURED_DATA_PLAYBOOK.md) |

---

## Краткая шпаргалка

| Вопрос | Ответ |
|---|---|
| «Запусти SEO-аудит URL» | Spawn `seo-technical` (+ `seo-schema` / `seo-content` по нужде) |
| «Где тела скиллов?» | `Documents/GitHub/claude-seo/skills/` — или reinject через `install.ps1` |
| «Почему skill name есть, а SKILL.md нет?» | Broken junction: `~/.grok/skills/seo*` → пустой `~/.claude/skills/seo*` |
| «Systema-специфика Yandex» | Не этот файл — [SEO_ROADMAP_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SEO_ROADMAP_2026.md) |

_Handoff: [H2220](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2220-Grok_Systema-Sanscriticum_seo-agents-skills-inventory-ru_02.08.26.md) (Grok 4.5) — Russian inventory of SEO agents and skill package._

_Dr. Mārcis Gasūns_
