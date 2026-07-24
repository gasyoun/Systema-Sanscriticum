# ARCHITECTURE — VK/ORS content calendar

_Created: 24-07-2026 · Last updated: 24-07-2026_

Index: [`docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md).

---

## 1. Component map

```
IndologyScholars/vk-ors/data/processed/*.csv
        │  content:import-vk-ors (Systema)
        ▼
 storage/app/vk_ors/  (or committed fixtures)
        │
        ▼
 ContentCalendarSlot ──1:n── ContentCandidate
        │                      (body, source_type, source_ref, status)
        │
        ├── EvergreenScorer (W2)
        ├── SystemaBridge (W3: clips, schedule, FAQ)
        ├── ForwardDraftGenerator (W4: CuratorAi)
        └── Filament ContentCalendarPage (monthly Keep/Cancel/Edit)
        │
        ▼  content:publish-due (W5)
 n8n webhook → VK wall.post
```

---

## 2. Data model

### `content_calendar_slots`

| Column | Notes |
|---|---|
| `id` | PK |
| `channel` | `vk_wall` (extensible later) |
| `slot_date` | date |
| `slot_type` | `evergreen`, `clip_tease`, `event`, `schedule_note`, `faq_tease`, `forward`, `empty` |
| `status` | `empty`, `draft`, `kept`, `scheduled`, `published`, `canceled` |
| `publish_at` | datetime nullable |
| `source_kind` | `vk_ors_post`, `lecture_clip`, `schedule`, `faq`, `llm`, null |
| `source_ref` | string (vk post id, lesson_id, …) |
| `meta` | JSON (likes, topic tags, cancel_deadline) |

### `content_candidates` (reuse if lecture-content-engine W1 already shipped)

| Column | Notes |
|---|---|
| `type` | include `vk_post`, `social_post`, … |
| `body` | verbatim or draft text |
| `status` | draft/accepted/scheduled/published/discarded |
| `calendar_slot_id` | FK nullable |
| `quote` / rights fields | optional; recycle is full body per D17 |

If `ContentCandidate` does not exist yet, W1 creates a minimal table; if H1547
already landed it, **extend** rather than create a parallel store.

---

## 3. Status machine

```
empty → draft (generator attached body)
draft → kept (monthly Keep) → scheduled (publish_at assigned)
scheduled → canceled  (human, until publish_at - 24h)
scheduled → published (ticker + n8n success)
```

Skip-review (D12): at month start auto-job may promote **only**
`evergreen|clip_tease|event|schedule_note` from `draft`→`scheduled` if no Keep
was recorded for that month; `forward`/`faq_tease` NEW stay `draft`.

---

## 4. Build-vs-reuse

| Piece | Verdict |
|---|---|
| vk-ors ingest/insights | **Reuse in IndologyScholars** |
| CSV topic/top lists | **Import** |
| n8n VK wall.post | **Reuse** monthly-schedule-post pattern |
| LectureClip free flags | **Reuse** H1452 |
| CuratorAi | **Reuse** |
| ContentCandidate | **Reuse/extend** lecture content engine if present |
| Calendar + ticker | **Build** |

---

## 5. Flags

| Flag | Default | Role |
|---|---|---|
| `content_calendar` | OFF | Filament + seed/import |
| `content_calendar_autopilot` | OFF | Ticker + n8n publish |

---

## 6. File layout

```
app/Models/ContentCalendarSlot.php
app/Models/ContentCandidate.php          # if not exists
app/Services/Content/VkOrsImporter.php
app/Services/Content/EvergreenScorer.php
app/Services/Content/SystemaCalendarBridge.php
app/Services/Content/ForwardDraftGenerator.php
app/Services/Content/CalendarPublishService.php
app/Console/Commands/ImportVkOrsCommand.php
app/Console/Commands/SeedContentMonthCommand.php
app/Console/Commands/PublishDueContentCommand.php
app/Filament/.../ContentCalendarPage.php
docs/n8n/vk-calendar-post.workflow.json  # W5
tests/Fixtures/vk_ors/*.csv
tests/Feature/Content/Calendar*
```

_Dr. Mārcis Gasūns_
