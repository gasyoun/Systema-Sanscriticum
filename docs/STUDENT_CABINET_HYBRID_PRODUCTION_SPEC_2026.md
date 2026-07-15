# Student cabinet — HYBRID production spec (R29): B-chassis + organs of A/C/D

_Created: 15-07-2026 · Last updated: 15-07-2026_

The production ruling of the cabinet-remake programme
([H822](https://github.com/gasyoun/Uprava/blob/main/handoffs/H822-Fable_Systema-Sanscriticum_student-cabinet-custdev-ux-remake_12.07.26.md)).
M.G. ruled 15-07-2026 (**R29**, four-part interview after all four direction mockups shipped):
the production cabinet is a **hybrid** — direction B «Курс как дом» is the chassis; specific
organs of A «Сегодня», C «Библиотека», D «Путь» graft onto it. Authored by Fable 5
(`claude-fable-5`), handoff [H961](https://github.com/gasyoun/Uprava/blob/main/handoffs/H961-Fable_Systema-Sanscriticum_student-cabinet-hybrid-production-spec_15.07.26.md).

Mockup evidence base (all browser-verified, one shared design system):
[B v1](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace) ·
[B v2](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace-v2) ·
[A](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/today-first-coach) ·
[C](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/learning-library) ·
[D](https://github.com/gasyoun/Systema-Sanscriticum/blob/feat/h822-mockup5-journey-hub/docs/mockups/student-cabinet-remake/journey-membership-hub/README.md)
(D — [PR #526](https://github.com/gasyoun/Systema-Sanscriticum/pull/526), open at spec time).
Rulings R1–R28: [STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md).

## 1. R29 — the composition (settled, not open)

| # | Organ | Source | What it is in the hybrid |
|---|---|---|---|
| R29.0 | **Chassis** | B «Курс как дом» | Job-named shell («Сегодня / Календарь / Записи / Прогресс / Оплата и доступ / Помощь»), course workspace with hash-addressable tabs, mobile bottom bar, editorial-academism visual system of B v2 (R14–R16/R23) |
| R29.1 | «Сегодня»-лента + домашнее | A | The home's composite band (continue + nearest live, B v2) gains a third element: «доработать домашнее» when homework is returned — the top three plan items of A without a separate plan page |
| R29.2 | Recovery-режим главной | A | On payment/access failure the home switches modes: problem banner leads, ALL offers unconditionally suppressed, owned/live access explicitly keeps working ([index-debt.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/today-first-coach/index-debt.html) is the reference) |
| R29.3 | Полки + ленты срока | C | The «Записи» page is rebuilt as ownership shelves (Идут сейчас / Мои записи / Истёкшие-с-продлением / Завершённые) with expiry ribbons; membership card keeps its shelf-level slot (R8/R24) |
| R29.4 | Рельса прогресса | C | Recording courses without homework get the Khan-pattern progress rail instead of a lesson list (in «Записи» subject view and the course workspace's Уроки tab for such courses) |
| R29.5 | Оффер расширения владения | C | After real progress in a recording course: «продолжение — навсегда на вашей полке» (R2/R17-compliant) |
| R29.6 | Путь внутри «Прогресса» | D | The station map of the MAIN grammar ladder (письмо → грамматика I/II → тексты) tops the «Прогресс» page. v1 scope: this one ladder only — no full curriculum graph (D's heaviest cost deferred) |
| R29.7 | Загорание после завершения | D | **The hybrid's master offer rule:** the ladder offer appears only when the current station is fully completed; no countdown timers ever; «станция подождёт» wording |
| R29.8 | Вехи в доме курса | D | Attestation/contour dates as station landmarks in the course workspace («место курса на пути» block) — orientation, never payment deadlines |

Offer precedence (unifies R2/R17/R29.2/R29.5/R29.7): recovery state → zero offers;
normal state → at most ONE primary offer per page, eligibility in order: (1) completion-lit
ladder offer, (2) progress-gated next-block offer, (3) ownership-expansion for recordings;
membership card is the only persistent slot and only in «Записи» (R8).

## 2. Page map (deltas against B v2 — the reference implementation)

| Page (B v2 file) | Hybrid delta |
|---|---|
| Главная ([index.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/index.html)) | + homework item in the «Сегодня» band (R29.1); + recovery mode (R29.2); course rows unchanged |
| Дом курса ([course.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/course.html)) | + «место курса на пути» block with вехи (R29.8); offer block obeys the master rule (R29.7 wording) |
| Урок ([lesson.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/lesson.html)) | unchanged (hybrid progress R7 already in) |
| Записи ([library.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/library.html)) | rebuilt as C's shelves + ribbons (R29.3); subject view gets the rail (R29.4) + ownership offer (R29.5); membership slot stays |
| Календарь ([calendar.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/calendar.html)) | unchanged |
| Прогресс ([progress.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/progress.html)) | + station map of the grammar ladder on top (R29.6/R29.7); certificates section unchanged |
| Оплата и доступ ([access.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/access.html)) | unchanged (already offer-suppressed); feeds the R29.2 trigger |
| Помощь ([messages.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mockups/student-cabinet-remake/course-workspace-v2/messages.html)) | unchanged |

## 3. Data / engineering bill

1. **Lapse detector** (expired access as a state, not a silent query filter) — needed by
   R29.3 ribbons, the home's expired row, and recovery mode. (B's bill, unchanged.)
2. **Recovery-state resolver** — one server-side predicate: declined payment / access fault →
   home mode switch + offer suppression flag (R29.2).
3. **Owned-recordings catalog** — C's bill (today recordings are lessons inside groups);
   scoped to the «Записи» page in v1.
4. **Grammar-ladder mini-graph** — a hardcoded/config ladder for ONE trajectory (R29.6),
   `TrajectoryPaths`-style; the full curriculum graph stays deferred.
5. **Completion events** — station/block completion signals feeding R29.7 lighting; builds on
   the existing `LessonView` heartbeat + manual «усвоено» (R7).
6. Workspace routes with sub-tabs, job-named nav, Vite migration of the cabinet — B's bill
   as in the B v2 promotion path.

## 4. Instrumentation-first plan (R20 gate)

R20 (большой релиз после утверждения) is measurable only if events ship BEFORE the switch.
Phase 0 (handoff [H962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H962-Sonnet_Systema-Sanscriticum_student-cabinet-remake-instrumentation-phase_15.07.26.md),
Sonnet 5 `claude-sonnet-5` executor) instruments the **current** cabinet to capture the
baseline, then the same events carry into the hybrid:

`cabinet.home.view` (mode: normal/recovery) · `cabinet.continue.click` ·
`cabinet.homework.rework.click` · `cabinet.live.zoom.click` · `lesson.view.heartbeat`
(exists) · `lesson.mark.mastered` · `course.tab.view` (tab) · `library.shelf.view` (shelf) ·
`library.rail.jump` · `offer.impression` / `offer.click` / `offer.purchase`
(kind: ladder/next-block/ownership/membership; state-eligibility recorded) ·
`access.renewal.start` / `.complete` · `support.topic.pick` (topic) · `path.station.view` ·
`path.station.lit.impression`.

KPI baselines→targets (ledger §6 of the research doc): continue-CTR, time-to-first-action,
lesson completion, offer CTR/attach by kind, renewal rate, access self-resolution,
support-start topics, **выручка на активного ученика** (north star, R1).

## 5. Rollout

Per R20: one big release after M.G. approves the hybrid build, preceded by (a) Phase 0
events live ≥2 weeks for baseline, (b) the hybrid built behind a flag in worktrees, (c) a
review-sheet walkthrough of the built pages against this spec. No canary (ruled), but the
flag allows an instant revert. Watcher guard and `/watcher-safe-commit` apply to all
implementation sessions.

## 6. Sequence (each step = its own handoff at execution time)

1. **Phase 0 — instrumentation** ([H962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H962-Sonnet_Systema-Sanscriticum_student-cabinet-remake-instrumentation-phase_15.07.26.md), queued).
2. Phase 1 — chassis: workspace routes + job-nav + home band (+homework item, recovery mode).
3. Phase 2 — «Записи» shelves + rail + ownership offer; lapse detector.
4. Phase 3 — «Прогресс» path + completion lighting + вехи in course home.
5. Phase 4 — flag-flip release per §5; post-release KPI readout vs baseline.

_Dr. Mārcis Gasūns_
