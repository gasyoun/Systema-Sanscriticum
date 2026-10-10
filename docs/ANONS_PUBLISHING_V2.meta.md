---
metadoc: ANONS_PUBLISHING_V2.md
Created: 04-10-2026
Last updated: 04-10-2026
genre: operator manual
owner: OxAlpha (ZCode/z-ai/glm-5.3-flash)
---

Операторская подсистемы anons publishing v2 (H5049, 17-09-2026): манифест → сервис → платформенные адаптеры, плашка CTA вжарена в пиксели, идемпотентность `publication_key`, метрики с явными состояниями отсутствия, архив-каталог, тест-контур. Компаньон канонического скилла [anons](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md).

Потребители: `app/Console/Commands/Anons*.php` (CLI-обёртки), HTTP API `/api/anons*`, карта скиллов анонса ([SKILLS_MAP_COURSE_ANNOUNCEMENT_2026-10.md](SKILLS_MAP_COURSE_ANNOUNCEMENT_2026-10.md), этапы 5 и 8), плейбук запуска курса §7.2. Синхронизационное правило: правка `config/tracked_links.php` по каналу `vk` и секция «Senler manual lane» меняются вместе (ссылка `/ga/…-vk-…` в чек-листе живёт в конфиге).

История ревизий: 17-09-2026 H5049 — создание; 04-10-2026 [H5935](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H5935-OxAlpha_Systema-Sanscriticum_anons-senler-lane_04.10.26.md) — секция «Senler manual lane» ([PR #2995](https://github.com/gasyoun/Systema-Sanscriticum/pull/2995)): выбор manual-vs-адаптер по пробам доступа (токена Senler API нет нигде), чек-лист UI + smoke `/ga/m26-vk-*` + строка журнала; `AdapterRegistry` для `senler` отказывает с указателем на секцию. 04-10-2026 вечер [H5944](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H5944-OxAlpha_Systema-Sanscriticum_anons-senler-adapter_04.10.26.md) — секция «Upgrade path» переписана в «Why there is no adapter»: в API Senler нет send-метода (офиц. доки + 2 SDK), адаптер невозможен, UI-чеклист = постоянный путь отправки; токен = кандидат stats-lane.

_Гасунс_
