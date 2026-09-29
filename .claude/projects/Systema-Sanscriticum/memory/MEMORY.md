# Memory index — Systema-Sanscriticum

_Created: 16-09-2026 · Last updated: 24-09-2026_

Committed memory store for **Systema-Sanscriticum** per the org Memory-routing rule ([`/danger-memory`](https://github.com/gasyoun/claude-config/blob/main/commands/danger-memory.md)): dangerous and durable facts land here **and** in the org store. One file per fact; one line per file below (`- [Title](file.md) — hook`). Scaffold: H4547.

- [Sqlite-тесты не проверяют длину varchar — деньги ловит только прод-MySQL](2026-09-06-sqlite-tests-no-varchar-length-money-columns.md) — RefreshDatabase на sqlite не enforced'ит varchar(N): лишний символ = SQLSTATE[22001] на прод-MySQL посреди записи (H4200 ip_expense_audits.action).
- [Настя всегда только куратор (manager) — не предлагать смену роли](2026-09-07-nastya-always-manager-role.md) — MG 07-09-2026: user_id 6598 остаётся manager всегда; не повышать, не менять, не «дарить видимость» ролью.
- [2026-09-08 — course calendar rhythm rules (MG rulings, chat 08-09-2026)](2026-09-08-course-calendar-rhythm-rules-mg.md) — Fact: MG's standing calendar rules for samskrte.ru courses, ruled 08-09-2026 in chat:
- [2026-09-08 — grammar schedule cut after 2027-06-16 (H4375)](2026-09-08-grammar-schedule-cut-june16-2027.md) — Fact: On 08-09-2026, 52 schedules rows for the 10 grammar courses (Bühler gr.27, Kochergina gr.53/55/57/60/61/62, Hindi gr.1/2/5) were so...
- [2026-09-09-course-access-windows-h4456-mg-ruling](2026-09-09-course-access-windows-h4456-mg-ruling.md)
- [Junction/symlink ВНУТРИ worktree: `git worktree remove --force` вычищает СОДЕРЖИМОЕ цели](2026-09-09-worktree-junction-removes-target-contents-danger.md) — Джанкшен/симлинк, созданный ВНУТРИ git-worktree на цель ВНЕ worktree, при
- [2026-09-10 — H4519 кнопка отмены по анонсу + памятка «ник → id» (TG-линки 19/23)](2026-09-10-h4519-announce-cancel-button-and-teacher-tg-links.md) — 1. TG-линки учителей: 19/23 (teachers:link-telegram) — команды бота H4253
- [2026-09-10 — TG-линки учителей 19/23 + техника резолва «ник → численный id»](2026-09-10-teacher-tg-links-19of23-getinfo-username-resolution.md) — teachers:link-telegram — 19 из 23 карточек teachers теперь связаны с Telegram
- [Две живые строки расписания в одном слоте — класс «задвоений», который не ловит ни одна прежняя гвардия](2026-09-11-slot-collision-double-reminder-class.md) — Перенос занятия на слот, где уже жила другая строка серии (1489 «#76» + 1490 «#77», оба 11.09 20:00) → обе строки вошли в T-60 окно → два...
- [GUARD_BRIEF_DIR isolation + MG «never again» ruling — 12-09-2026 (danger fact)](2026-09-12-guard-sandbox-brief-isolation-mg-never-again.md) — MG ruling 12-09-2026, verbatim: «Remove deploy lane if it fucks up my work. Never again, remember, fuck up my work.»
- [Worktree phpunit-гочи: PendingCommand-мок съедает expectsOutputToContain; эмодзи без U+FE0F ломает byte-матч](2026-09-12-pendingcommand-expectsoutputtocontain-mockery-bug-and-fe0f-emoji-bytes.md) — Два дефекта, найденных в H4629 (payout:run три режима), при тестировании командного вывода в worktree-прогонах:
- [Systema deploy = workflow_dispatch с human environment approval](2026-09-18-deploy-workflow-dispatch-human-approval.md) — merged ≠ live: deploy.yml только workflow_dispatch; агент может dispatch'ить, ставит в `waiting` до клика MG в Actions UI.
- [Рулинг MG 18-09-2026: кабинет тегируется Метрикой — webvisor в кабинете навсегда выключен](2026-09-18-metrika-cabinet-tagging-mg-ruling.md) — Кабинетный init только {webvisor:false, clickmap:false}; PII в Метрике запрещён; условие, без которого рулинг не работает.
- [Symlink/junction-vendor (не копия!): корень битого $baseDir — artisan мёртв до dump-autoload](2026-09-24-vendor-copy-between-trees-poisons-autoload-basedir.md) — Любой composer в дереве с symlink/junction-vendor пишет через ссылку в общий main/vendor и меняет $baseDir у всех шареров; копия лишь размножает яд; висячая цель лишает autoload сразу все деревья; лечится локальным vendor + dump-autoload на месте.

_Гасунс_
