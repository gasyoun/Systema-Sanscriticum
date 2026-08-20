# Метадок: иллюстрированный гид студента

_Created: 21-08-2026 · Last updated: 21-08-2026_

Спутник к [STUDENT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md).

## Зачем этот гид существует

Ученик не читает GitHub. Текст живёт в кабинете по `/dvaram/help` («Как пользоваться»), тот же файл собирается в PDF. Командная карта остаётся [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md).

## Кому адресован

Ученику в продукте. Исполнителю волн 2–3 — как образец каналов (md + страница + PDF + Playwright), не как шаблон Filament-гейта.

## Происхождение

- **Волна 1 плана:** [PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md)
- **Handoff:** [H3212 (Grok 4.6) — Wave 1: illustrated student cabinet guide in /dvaram with Playwright screenshots](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3212-Grok_Systema-Sanscriticum_student-cabinet-guide-illustrated_21.08.26.md)
- **Исполнитель:** Grok 4.6 (`grok-4.6`), 21-08-2026

## Что перепроверено, чтобы не строить заново

| Кандидат | Вывод |
|---|---|
| TeacherGuide | модель каналов да, рефактор нет |
| `/faq/dz`, `/help/prana-balance` | ссылки, не дубль |
| `student-manual.md` | шапка указывает ученику на `/dvaram/help` |

## Ограничения

- Кадры снимает `scripts/capture-guide-screenshots.mjs` локально. Playwright в GitHub Actions нет. Нет Chrome — текст и манифест всё равно сдаются, PNG не выдумываются.
- Гость на `/dvaram/help` получает редирект на вход (как весь `/dvaram`), не 403.

## План сопровождения

Ревизия: смена вкладок `/dvaram` или кнопок части I. Freshness: `scripts/student_guide_freshness.py` (warn, код 0).

_Dr. Mārcis Gasūns_
