# lead-magnet-article-first-sentence-ru.meta.md — метадок о `lead-magnet-article-first-sentence-ru`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Метадок-компаньон для [lead-magnet-article-first-sentence-ru.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lead-magnet-article-first-sentence-ru.md): фиксирует контекст, происхождение и план сопровождения, не повторяя содержимое самой статьи.

## Subject

- **Ссылка на документ:** [lead-magnet-article-first-sentence-ru.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lead-magnet-article-first-sentence-ru.md)
- **Назначение:** черновик статьи-лидмагнита для samskrte.ru — русская локализация английской статьи о первом санскритском предложении, ведущая читателя к CTA бесплатного вводного занятия.
- **Аудитория:** русскоязычные новички без знания санскрита; воронка привлечения витрины samskrte.ru.
- **Формат / контракт:** маркетинговая статья на русском языке с CTA-лестницей «бесплатный первый вебинар, трипваер, флагман»; третий из четырех ЛМ флота H312–H315 (H314). Не перевод, а локализация; данные частотности должны совпадать с исходным английским материалом.

## Provenance

- **Subject created:** 09-07-2026
- **Metadoc authored:** 13-07-2026, H891, Opus 4.8 `claude-opus-4-8`
- **Next hardening:** сверка счетчиков корпуса с актуальным [corpus-frequency.json](https://github.com/sanskrit-lexicon/csl-guides/blob/main/src/data/corpus-frequency.json) перед публикацией на витрине.

## Ranked improvement backlog

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Верстка и публикация на samskrte.ru | Черновик помечен @DO; лидмагнит не работает, пока не на витрине | parked (верстка на стороне витрины) |
| 2 | Сверить все счетчики и проценты покрытия с текущим corpus-frequency.json | Данные могут устареть при обновлении корпуса | parked (нет триггера обновления корпуса) |
| 3 | A/B-тест заголовка и первого абзаца | Конверсия лидмагнита зависит от первого экрана | parked (нужны метрики витрины) |
| 4 | Проверить рабочую форму сбора почты и последовательность писем | CTA обещает приглашение плюс гид — цепочка должна существовать | parked (зависит от инфраструктуры рассылки) |
| 5 | Синхронизация с английским оригиналом при его правках | Локализация не должна расходиться с источником | parked (нет сигнала об изменении PR #102) |

## Known limitations / caveats

- Документ — черновик, а не опубликованный материал; финальная верстка и стиль определяются витриной samskrte.ru.
- Частотные цифры взяты из внешнего источника (Digital Corpus of Sanskrit через VisualDCS) и являются снимком на момент написания.
- CTA-лестница и обещания (бесплатный урок, гид) предполагают наличие соответствующей инфраструктуры на витрине, которая находится вне этого репозитория.
- Русская версия — локализация, а не дословный перевод; расхождения с английским текстом намеренны.

## Intended use / known misuse

- **Назначение:** источник для верстки статьи-лидмагнита на samskrte.ru и опорный текст для воронки привлечения.
- **Неверное использование:** не считать опубликованным материалом; не использовать счетчики как научно точные без сверки с corpus-frequency.json; не менять CTA без согласования с утвержденной лестницей монетизации.

## Maintenance & sunset plan

- Пересматривать при обновлении корпуса частотности или правках английского оригинала.
- После публикации на samskrte.ru обновить статус (черновик → опубликовано) и добавить ссылку на живую версию.
- Sunset: если флот лидмагнитов H312–H315 будет заменен новой стратегией привлечения, пометить как deprecated с указателем на преемника.

## Deprecation status

active

## Related documents

- [lead-magnet-article-first-sentence-ru.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lead-magnet-article-first-sentence-ru.md) — субъект этого метадока
- [ROADMAP_LEAD_MAGNETS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_LEAD_MAGNETS_2026.md) — дорожная карта флота лидмагнитов H312–H315
- Английский оригинал: How Long Until You Read Your First Sanskrit Sentence? (csl-guides, [PR #102](https://github.com/sanskrit-lexicon/csl-guides/pull/102))

## Revision history

| Дата | Событие | Модель |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
