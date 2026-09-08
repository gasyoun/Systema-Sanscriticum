# 2026-09-08 — grammar schedule cut after 2027-06-16 (H4375)

**Fact:** On 08-09-2026, 52 `schedules` rows for the 10 grammar courses (Bühler gr.27, Kochergina gr.53/55/57/60/61/62, Hindi gr.1/2/5) were **soft-deleted** on prod — everything strictly after 2027-06-16 (MG rule: grammar lessons run through June 15, June 16 inclusive; resumption in Sept/Oct is a separate discussion, groups may dissolve). The 16 June 2027 boundary lessons survive (gr.27 №50 = 15.06, gr.55 №52 / gr.62 №48 / hindi-1 №54 = 16.06). Non-grammar courses (Продленка, Медленное чтение, Синтаксис, Бхагавадгита, Йога-сутры, Напевный санскрит, Летний интенсив, Открытые занятия) were NOT touched.

**Why you care:**
1. If someone asks «почему у грамматики расписание заканчивается 15–16 июня 2027?» — это правило MG 08-09-2026, не баг.
2. **Restore path:** backups in `/var/www/html/storage/app/schedule_cut_backup_<slug>_20260908_1441*.json` (10 files, all rows incl. Zoom links); restore = `App\Models\Schedule::withTrashed()->whereIn("id", […52 ids…])->restore()`. Full id list in [H4375](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4375-OxAlpha_Systema-Sanscriticum_grammar-schedule-cut-june16-2027_08.09.26.md).
3. **Do NOT hard-delete `Schedule` rows to "clean up"** — `webinar_attendances`/`schedule_join_clicks` cascade on forceDelete; soft-delete is the house pattern (H3790, H4199).
4. `lessons` table was deliberately untouched: no public schedule page reads it, and hard delete cascades student progress (lesson_user, homework, views).
5. When the September/October 2027 resumption is decided, regenerate the chain with ScheduleGenerator (preserve mode) or «Сгенерировать поток» in Filament → Расписание — do not un-delete these 52 rows (their numbering/blocks will be stale).
