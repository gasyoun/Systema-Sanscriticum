# 2026-09-08 — course calendar rhythm rules (MG rulings, chat 08-09-2026)

**Fact:** MG's standing calendar rules for samskrte.ru courses, ruled 08-09-2026 in chat:

1. **Грамматика (Кочергина, Бюллер) и грамматика хинди** — занятия только до середины июня (до 15-го, 16-е включительно); дальше — отдельное обсуждение (возобновление сентябрь/октябрь или распад группы). Исполнено 08-09: 52 schedules soft-deleted after 2027-06-16 (см. memory note «grammar schedule cut» + [H4375](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4375-OxAlpha_Systema-Sanscriticum_grammar-schedule-cut-june16-2027_08.09.26.md)).
2. **Продленка санскрита (2 курса Трефиловой)** — НЕ режется по июню (решение MG: «нет»); её календарь живёт своей ритмикой.
3. **Медленное чтение Йога-васиштхи и Синтаксис санскрита** — «почти круглогодичные, за исключением 2 недель новогодних каникул». Исполнено 08-09 ([H4378](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4378-OxAlpha_Systema-Sanscriticum_ny-holidays-cut-reading-syntax_08.09.26.md)): вырезаны 6 занятий (чтение: 31.12.2026, 07.01.2027; синтаксис: 03.01+10.01.2027, 02.01+09.01.2028); воскресенья 27.12 и 26.12 перед каникулами остаются.

**Why you care:**
1. При генерации будущих потоков (ScheduleGenerator / «Сгенерировать поток») НЕ создавать занятия: для грамматик — после 15–16 июня (до отдельного решения о возобновлении), для чтения/синтаксиса — в двух неделях, содержащих 31 декабря и 7 января (последняя неделя декабря + первая неделя января).
2. Restore-пути и id удалённых строк — в H4375/H4378 и JSON-backup'ах `storage/app/schedule_cut_backup_*_20260908_*.json`.
3. **Tech gotcha:** выборка по датам schedules — только `orWhereDate("start", …)`; сырой `whereIn("start", ["2026-12-31"])` не матчит, колонка хранит UTC.
4. Soft-delete — единственный-sanctioned способ: forceDelete каскадно стирает `webinar_attendances`/`schedule_join_clicks`; `lessons` для календаря не трогать (не читается публичными страницами, каскад student progress).
