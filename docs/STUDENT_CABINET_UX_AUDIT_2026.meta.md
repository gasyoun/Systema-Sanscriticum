# STUDENT_CABINET_UX_AUDIT_2026.meta.md — метадок о `STUDENT_CABINET_UX_AUDIT_2026`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Компаньон-метадок к [STUDENT_CABINET_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md): описывает не содержание аудита, а его назначение, происхождение и план сопровождения.

## Subject

- **Документ:** [STUDENT_CABINET_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md)
- **Назначение:** превратить продуктовый UX-аудит личного кабинета студента (`/dvaram`) в очередь agent-ready тикетов, ориентированных на самообслуживание уже пришедшего студента.
- **Аудитория:** продуктовая и инженерная команда Systema Sanscriticum, исполнители тикетов; косвенно — база знаний поддержки.
- **Формат/контракт:** описательный аудит-документ с North star, экранным разбором, фазовыми тикетами и acceptance-критериями; не спецификация БД и не изменение денежно-доступного ядра.

## Provenance

- **Subject created:** 07-07-2026 (git `--diff-filter=A`).
- **Metadoc authored:** 13-07-2026, H891, Opus 4.8 `claude-opus-4-8`.
- **Next hardening:** привязать тикеты к реальным GitHub issue/H###- id по мере старта реализации и отметить, какие фазы отгружены.

## Ranked improvement backlog

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Привязать каждый из 7 тикетов к конкретному GitHub issue / H### | Сейчас тикеты живут только внутри документа, без трекинга исполнения | parked (нет назначенных issue) |
| 2 | Добавить статус-колонку по фазам (в работе / отгружено) | Документ не отражает, какие рекомендации уже реализованы | parked (реализация не начата) |
| 3 | Приложить скриншоты/эскизы текущего и целевого `/dvaram` | Текстовый аудит без визуала труднее исполнять фронтенду | parked (нет макетов) |
| 4 | Указать инструмент аналитики для метрик из §7 | Список событий есть, но канал сбора не назван | parked (стек аналитики не выбран) |
| 5 | Синхронизировать словарь понятий с `docs/onboarding-student.md` и `docs/student-manual.md` | English-ready термины должны совпадать между аудитом и мануалами | parked (сверка мануалов не проведена) |

## Known limitations / caveats

- Аудит — рекомендательный: он не меняет денежно-доступное ядро, `Tariff` и `PaymentObserver::grantAccess()`, и это явно вне объема (§8).
- Порядок исполнения (§9) — предложение приоритизации, а не зафиксированный план релизов.
- Метрики (§7) заявлены как желательная разметка, не обязательная часть первого UI PR.
- Документ описывает целевое состояние UX, а не текущую реализацию; расхождение с кодом кабинета возможно.

## Intended use / known misuse

- **Использовать для:** планирования итераций UX кабинета, декомпозиции работы на тикеты, согласования acceptance-критериев.
- **Не использовать как:** источник изменений денежно-доступной логики, спецификацию новой модели подписки/bundles, или основание для редизайна публичной витрины `/online` — всё это прямо исключено §8.

## Maintenance & sunset plan

- Обновлять по мере старта и отгрузки тикетов: помечать реализованные фазы и связывать с PR/issue.
- Пересматривать при существенном изменении архитектуры кабинета или при переходе к продаже записей (`recorded library`).
- Sunset: когда все 7 тикетов отгружены и проверены, документ переводится в статус `superseded` с указателем на итоговую реализацию или на новый аудит.

## Deprecation status

`active`

## Related documents

- [STUDENT_CABINET_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_UX_AUDIT_2026.md) — субъект метадока
- [onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) — короткие пользовательские тексты онбординга
- [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) — полная карта кабинета для команды

## Revision history

| Дата | Изменение | Модель |
|---|---|---|
| 13-07-2026 | metadoc created (H891) | Opus 4.8 claude-opus-4-8 |

_Dr. Mārcis Gasūns_
