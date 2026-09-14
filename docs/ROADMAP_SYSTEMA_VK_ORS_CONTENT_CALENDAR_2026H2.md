_Created: 24-07-2026 · Last updated: 05-09-2026_

# ROADMAP — VK/ORS content calendar (2026 H2)

_Created: 24-07-2026 · Last updated: 19-08-2026 (H1754 voice contract; earlier: H1566 Wave 3, H1567 Wave 4)_

> **Truth-pass 19-08-2026 (H3072, Opus 5 `claude-opus-5`):** у программы есть текущий план — [PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md).

Index: [`docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md).

Five sequential waves = five PRs = five handoffs.

---

## Non-goals

- Rebuilding `vk_ors_archive` ingest inside Systema (xlsx/openpyxl).
- Merging nagari closed-group mail into the auto-queue (different rights).
- Instagram / full omnichannel.
- Live public VK posts during build (D24).
- Weekly human review (monthly only — D5).
- Money/payment code.

---

## Wave 0 — IndologyScholars (thin, parallel)

**Owner:** IndologyScholars handoff (small).

1. Document `vk-ors` refresh: update xlsx → `ingest` → `insights` → commit
   `data/processed/*.csv` + `site_data.json`.
2. Optional: `tools/export_vk_ors_for_systema.py` that copies a stable subset
   (top_posts, topics_by_year, activity_by_month, hashtags) into a dated
   `exports/vk_ors_YYYY-MM/` if needed.
3. Systema never requires the raw xlsx.

---

## Wave 1 — Analytics feed + calendar skeleton

**Unblocks:** everything else.

1. Vendor or path-config import of fixture + optional `storage/app/vk_ors/*.csv`.
2. Models: `ContentCalendarSlot`, `ContentCandidate` (or extend if H1547 already
   landed `ContentCandidate` — reuse, don't fork).
3. Artisan `content:import-vk-ors` + `content:seed-month {YYYY-MM}`.
4. Filament **«Календарь контента»**: next 30 days, bulk Keep/Cancel/Edit.
5. Seed empty slots by seasonal density from `activity_by_month` + attach
   evergreen candidates when W2 not ready (optional stub list).
6. Flag `content_calendar` OFF; tests with fixtures; Filament smoke note.

---

## Wave 2 — Evergreen recycle ✅ DONE (H1565, 25-07-2026)

**Unblocks:** ≥ half of the ≥20/month bar without NEW copy.

1. Scorer: likes DESC, age≥12 months, topic ∈ {книга, словарь, pdf, текст},
   exclude promo regex (скидк, запис, марафон цена, … — tune list).
2. De-dupe: no re-use of same source post_id within 6 months.
3. Slot body = **verbatim** original text (D17) + preserve permalink in meta.
4. Status `scheduled` with `publish_at` spaced across the month (e.g. 3–4/week).

Built as `EvergreenScorer` + artisan `content:fill-evergreen {YYYY-MM}`; topic
keyword patterns ported from `IndologyScholars/vk-ors/vk_ors_archive/insights.py`
TOPICS (the source CSV has no per-post topic column). De-dupe window is checked
against `ContentCalendarSlot.source_ref` within ±6 months of the target month.
`publish_at` spacing reuses W1's seeded `slot_date` spread (no new spacing logic
needed) via `ContentCalendarSlot::markKept()`. DEPLOY_QUEUE №56.

---

## Wave 3 — Systema bridge ✅ DONE (H1566, 25-07-2026)

**Unblocks:** live product signal.

| Source | Slot type |
|---|---|
| Free `LectureClip` / ContentCandidate clip | `clip_tease` + link |
| Monthly schedule digest / course events | `event` |
| Published schedule changes | `schedule_note` |
| FAQ Accept (if CAI/content engine present) | `faq_tease` |

No duplicate of H1452 media cut — only **calendar rows** pointing at existing
artifacts.

Built as `SystemaCalendarBridge` (`bridgeClips()` + `bridgeScheduleDigest()`)
+ artisan `content:bridge-systema {YYYY-MM}`. Shipped: `clip_tease` fill from
free `LectureClip`s already mirrored into an accepted `ContentCandidate`
(H1547's `ContentCandidateSync`), deduped by clip id already used elsewhere,
VK permalink in `meta.link` when n8n has already set `vk_owner_id`/
`vk_video_id`; one monthly `event` digest slot for the **current** month,
reusing the existing `MonthlyScheduleDigest` service (the live
`schedule:post-monthly` poster's course/schedule/copy logic — prior art found
mid-build, not re-derived) rather than a second `Schedule` query, so the
calendar digest can never disagree with the live poster about which courses
are running. `MonthlyScheduleDigest` is current-month-only by design
(`Carbon::now()`); bridging a non-current target month no-ops. **Default log
(D22):** per-class `schedule_note` (change-tracking) and `faq_tease` deferred
— `Schedule` carries no diff primitive yet and neither is required by W3's
DoD (C1/C2 in
[VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md));
a monthly digest was chosen over per-change tracking. DEPLOY_QUEUE №58.

---

## Wave 4 — Forward drafts (NEW copy) ✅ DONE (H1567, 25-07-2026)

**Unblocks:** remaining empty slots.

1. CuratorAi templates: reading-group tease, dictionary tip, event promo,
   FAQ-style micro-answer (facts from LMS resolvers where available).
2. Status stays `draft` until monthly Keep → `scheduled`.
3. Skip-review rule: NEW never auto-publishes (D12).

Built as `ForwardDraftGenerator` + artisan `content:fill-forward {YYYY-MM}`.
"LMS resolvers" resolved to: `DictionaryWord` (dictionary tip), `Schedule`+`Course`
(event promo), and the "Новичкам" section of `resources/knowledge/faq.md` (FAQ
micro-answer — deliberately outside the money/policy FAQ sections, so a public
teaser never reads like payment/policy guidance); reading-group tease has no
LMS-backed fact source and stays templated. Cost cap:
`content.forward_draft_max_per_run` (default 10, mirrors
`ArticleDraftGenerator::WEEKLY_LESSON_LIMIT`). DEPLOY_QUEUE №57.

**Voice pass (H1754, 28-07-2026):** the four template families + CuratorAi
prompts rewritten to the measured editorial voice contract
[docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md)
(derived from the full 7,608-post vk-ors corpus, 2022+ slice n=2,799);
`EvergreenScorer::PROMO_PATTERN` retuned against real post text (78 → 196 hits,
strict superset, 0 lost exclusions).

---

## Wave 5 — Auto-pilot

**Unblocks:** min-touch operation.

1. `content:publish-due` hourly: select slots where
   `status=scheduled` and `now >= publish_at` and not canceled.
2. 24h cancel: Filament Cancel allowed while `now < publish_at - 24 hours`.
3. Outbound: n8n webhook (clone monthly-schedule-post shape) → VK `wall.post`.
4. Flag `content_calendar_autopilot` OFF until human activation + issue for Ivan
   if new n8n workflow.
5. Metric log: published count/month toward ≥20.

---

## Activation track (human)

| Step | Who |
|---|---|
| Refresh vk-ors CSVs in IndologyScholars | Editor/agent |
| NEW copy per the [voice contract](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VOICE_CONTRACT_ORS_VK_WALL_RU.md) | ✅ agent (H1754, 28-07-2026) |
| Import on Systema staging | Ivan/agent |
| Filament smoke month view | Admin |
| n8n VK post workflow + token | Ivan ([#666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666) sibling) |
| Flip `content_calendar` + autopilot flags | Admin |
| First monthly batch Keep | Admin/marketing |

_Dr. Mārcis Gasūns_
