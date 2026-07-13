# jivo.meta.md — метадок о `jivo`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Метадок-компаньон к исследованию [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md): он описывает не содержание документа, а его назначение, происхождение, границы применимости и то, чем его нельзя пользоваться.

## Subject (Предмет)

- **Ссылка на документ:** [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md)
- **Назначение:** декомпозировать Jivo как продукт (channels / inbox / operator / CRM / automation / AI / analytics / API) и вывести из него фазовую дорожную карту EdTech Support Inbox для `Systema-Sanscriticum` — какие inbox-паттерны перенимать, какие пока не копировать.
- **Аудитория:** продуктовый и архитектурный слой команды `Systema-Sanscriticum`, планирующий эволюцию «Чата с куратором» в единый Support Inbox поверх Laravel/Filament.
- **Формат / контракт:** длинный аналитический research-документ на русском с внешними цитатами (`citeturn…`) на справку Jivo и рыночные аналоги; несёт сравнительные таблицы, набор виджетов side-panel и семифазный roadmap. Это стратегический разбор, а не техническая спецификация БД.

## Provenance (Происхождение)

- **Subject created:** 2026-07-01 (первый коммит `docs/jivo.md`).
- **Metadoc authored:** 13-07-2026, H891 (metadoc sweep III), Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** при появлении реальной схемы БД / технического дизайна Support Inbox вынести её в отдельный документ и сослаться отсюда; переразметить колонку current-state под ссылки на [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) как источник правды.

## Ranked improvement backlog (Ранжированный бэклог улучшений)

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Переписать колонку «Как у нас сейчас» так, чтобы каждое утверждение ссылалось на support-subsystem-map.md, а не на справку Jivo | Сейчас current-state взят из внешних Jivo-страниц и признан ненадёжным | parked (нет H### на переработку) |
| 2 | Заменить `citeturn…`-маркеры на устойчивые внешние ссылки или снять их | Маркеры непереносимы и нечитаемы вне исходной сессии | parked (косметика, низкий приоритет) |
| 3 | Вынести предполагаемые доменные сущности (`conversation`, `channel`, `external_identity` и т.д.) в отдельный тех-дизайн | Раздел «Открытые вопросы» уже упирается в архитектуру, но документ намеренно не БД-дизайн | parked (ждёт продуктового решения) |
| 4 | Датировать сравнение с рынком (Intercom/Zendesk/Crisp/tawk.to) | Ценовые и функциональные ярусы конкурентов дрейфуют и устаревают | parked (обновлять при пересмотре roadmap) |

## Known limitations / caveats (Известные ограничения)

- **Колонка «Как у нас сейчас» ненадёжна.** Компаньон-документ [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) прямо предупреждает, что claims о текущем состоянии в jivo.md собраны из справочных страниц Jivo и общего описания, а не из самого репозитория, поэтому им нельзя доверять как фактам о системе.
- **Внешние цитаты непроверяемы.** Все `citeturn…`-ссылки указывают на разовый веб-контекст Jivo/рынка; они не воспроизводимы и могли устареть.
- **Не технический дизайн.** Документ сознательно останавливается на product boundary и не даёт финальной схемы БД, миграций или API-контрактов.
- **Снимок во времени.** Продуктовые ярусы Jivo, Intercom, Zendesk, Crisp, tawk.to описаны по состоянию на дату создания и дрейфуют.

## Intended use / known misuse (Как применять и как не применять)

- **Применять для:** декомпозиции Jivo-продукта и формы фазового roadmap — какие inbox-паттерны (единый inbox, статусы, owner, темы, быстрые фразы, follow-up, AI-assist, EdTech side-panel) перенимать и в какой последовательности.
- **НЕ применять для:** вывода о том, что у `Systema-Sanscriticum` есть или нет прямо сейчас. Любой current-state-вопрос («уже есть ли у нас X») решается по [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — это источник правды о репозитории; колонка current-state в jivo.md для этого ненадёжна.

## Maintenance & sunset plan (Сопровождение и вывод из эксплуатации)

- Документ живёт как стратегический ориентир, пока Support Inbox не перейдёт в фазу технического проектирования. По мере реализации фаз (Phase 0…6) отмечать сделанное и заменять «планируемое» ссылками на реальные PR/спеки.
- Когда появится отдельный тех-дизайн Support Inbox, jivo.md переводится в статус исторического обоснования (rationale), а актуальность current-state полностью уходит в support-subsystem-map.md.
- Sunset наступает, если Jivo перестаёт быть релевантным образцом или продукт уходит от inbox-модели; тогда — пометить `deprecated` и сослаться на преемника.

## Deprecation status (Статус устаревания)

`active`

## Related documents (Связанные документы)

- [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — **источник правды** о текущем состоянии подсистемы поддержки; именно он, а не jivo.md, отвечает на вопрос «что у нас есть сейчас».
- [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md) — сам предмет этого метадока.

## Revision history (История ревизий)

| Дата | Изменение | Модель |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
