---
metadoc: SEO_AUDIT_SAMSKRTE_RU_05-10-2026.md
Created: 05-10-2026
Last updated: 06-10-2026
genre: audit report
owner: OxAlpha (ZCode, account:zai-individual-coding-plan/GLM-5.3)
---

Полный SEO-аудит прод-сайта `https://samskrte.ru` (05-10-2026, скилл `/seo audit`): живые curl-пробы + разбор HTML/JSON-LD + Google `site:`-выдача; Health Score 83/100 (CWV исключены — PSI-квота). Верхние находки: www-хост дублирует сайт без 301 (High), H1 каталога /online — название организации вместо смысла страницы (High), noindex-URL `/slovar` в sitemap (Medium), страницы групп Кочергиной гр.42–63 похожи на 72 % (Medium), мёртвый `/klub` в индексе (Medium).

Потребители: GTD-строка аудита (реестр Uprava), будущие `/seo drift baseline|compare` проходы (этот файл — точка отсчёта дрейфа), правки nginx (www-301, /klub-301), шаблон каталога (H1), генератор sitemap (фильтр noindex). Зафиксированные «хорошие практики» в конце отчёта — список «не трогать» для будущих правок шаблонов.

Синхронизация: смена роута/шаблона каталога `/online`, генератора sitemap или серверного nginx-конфига должна перечитывать соответствующие секции отчёта (High-2, Medium-1, High-1/Medium-3); llms.txt и `/slovar`-хаб трогают секции AI-readiness и Medium-1.

История ревизий: 05-10-2026 — создание (первичный аудит, весь перечень проб этой сессии); ночь 05→06-10-2026 — ревью-фиксы верификатора (PR #3040); 06-10-2026 H6161 — секция «Правки исполнены»: nginx www→apex 301 (сервер .92, немедленно), H1 каталога + sitemap-хаб + path-ссылки фильтров (кодом), Medium-3 снят (тёмный запуск `/klub` за `features.club_membership`).

_Гасунс_
