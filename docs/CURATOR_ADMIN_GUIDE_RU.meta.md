# Метадок: иллюстрированный гид куратора

_Created: 21-08-2026 · Last updated: 21-08-2026_

Спутник к [CURATOR_ADMIN_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CURATOR_ADMIN_GUIDE_RU.md).

## Зачем этот гид существует

Куратор не читает месячную дельту `admin-manual.md`. Текст живёт в панели по `/admin/curator-guide` («Руководство»), тот же файл собирается в PDF. Исторический `admin-manual.md` — редирект.

## Кому адресован

Куратору и администратору в `/admin`. Преподаватель получает 403. Бухгалтерский контур — волна 3.

## Происхождение

- **Волна 2 плана:** [PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md)
- **Handoff:** [H3213 (Grok 4.6) — Wave 2: full curator/admin handbook in /admin replacing admin-manual delta](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3213-Grok_Systema-Sanscriticum_curator-admin-guide-illustrated_21.08.26.md)
- **Исполнитель:** Grok 4.6 (`grok-4.6`), 21-08-2026

## Что перепроверено, чтобы не строить заново

| Кандидат | Вывод |
|---|---|
| TeacherGuide / MarkdownGuide | страница тонкая, TeacherGuide не рефакторим |
| `teacher:nav-census` | скопирован в `manager:nav-census` |
| magic-link и zabota-bot manuals | тексты влиты в часть I; файлы остаются |
| `debtors-manual.md` | только сценарии куратора; файл канон массовых админ-операций |
| PayoutAttributionGuide | не трогаем |

## Ограничения

- Перепись — роль manager, не супер-админ.
- Кадры снимает `scripts/capture-guide-screenshots.mjs --guide curator` локально. Нет Chrome — текст и манифест, PNG не выдумываются.
- Денежные экраны (Финансы, суммы долга) в git-PNG не кладём.

## План сопровождения

Ревизия: смена меню manager. Freshness: `scripts/curator_guide_freshness.py` (warn, код 0).

_Dr. Mārcis Gasūns_
