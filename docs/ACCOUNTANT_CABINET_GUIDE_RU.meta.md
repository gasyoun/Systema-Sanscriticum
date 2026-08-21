# Метадок: иллюстрированная книга бухгалтера

_Created: 21-08-2026 · Last updated: 21-08-2026_

Спутник к [ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md).

## Зачем эта книга существует

GitHub-файл `accountant-guide.md` никто не читал как рабочую памятку. Текст живёт в панели по `/admin/accountant-guide` («Как работать бухгалтеру»), кадры — в `storage/app/guide-shots/accountant/` (не git). Публичный `accountant-guide.md` после волны 3 — карта меню + «открой в кабинете».

## Кому адресован

Бухгалтеру и администратору с финансовым гейтом. Преподаватель и куратор без этой роли получают 403. Живая очередь выплат остаётся на [PayoutAttributionGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/PayoutAttributionGuide.php).

## Происхождение

- **Волна 3 плана:** [PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md)
- **Handoff:** [H3214 (Grok 4.6) — Wave 3: accountant operational book in /admin, screenshots from storage not git](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3214-Grok_Systema-Sanscriticum_accountant-cabinet-guide-illustrated_21.08.26.md)
- **Исполнитель:** Grok 4.6 (`grok-4.6`), 21-08-2026

## Что перепроверено, чтобы не строить заново

| Кандидат | Вывод |
|---|---|
| MarkdownGuide + CuratorGuide | страница тонкая, свой canAccess |
| PayoutAttributionGuide | не удалять, не копировать очередь в md |
| TeacherGuide / Dusk | не рефакторить |
| `docs/screenshots/` | нулевой accountant PNG |
| MANUAL_EXPENSE / MANUAL_ACCOUNTANT_COURSE_STREAMS | сценарии влиты без живых ФИО; файлы остаются |

## Ограничения

- Кадры снимает `scripts/capture-guide-screenshots.mjs --guide accountant` в storage. Нет Chrome — текст и манифест, PNG не выдумываются.
- PDF: `php docs/build-accountant-guide.php` пишет в storage, не в публичный репо.
- В md нет живых фамилий и сумм выплат.

## План сопровождения

Ревизия: смена финэкранов. Freshness: `scripts/accountant_guide_freshness.py` (warn, код 0).

_Dr. Mārcis Gasūns_
