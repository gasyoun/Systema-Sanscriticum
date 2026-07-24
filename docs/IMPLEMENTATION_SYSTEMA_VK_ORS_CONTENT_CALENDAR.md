# IMPLEMENTATION — VK/ORS content calendar (Wave 1)

_Created: 24-07-2026 · Last updated: 24-07-2026_

Index: [`docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md).

**Scope:** Wave 1 only. Waves 2–5 checklists at end.

**Base:** worktree off `origin/main`. If `ContentCandidate` / content-engine
tables already exist from H1547, extend them — do not create a second content
store.

---

## Wave 1 — step order

### Step 0 — Prior art probe

```text
grep ContentCandidate, ContentCalendar, clip_marketing, content_from_lectures
```

Record findings in the handoff log.

### Step 1 — Feature flag

`config/features.php`: `content_calendar` ← `CONTENT_CALENDAR_ENABLED` default false.  
`.env.example` document path `VK_ORS_DATA_PATH` (optional absolute/relative to
storage).

### Step 2 — Migrations

- `content_calendar_slots` per architecture.
- `content_candidates` only if missing.

### Step 3 — Fixtures

Copy a **tiny** synthetic subset (not full 7k posts) into
`tests/Fixtures/vk_ors/`:

- `top_posts_by_likes.csv` (~5 rows)
- `topics_by_year.csv` (~10 rows)
- `activity_by_month.csv` (~12 rows)

### Step 4 — `VkOrsImporter`

- Read CSVs from fixture path or `storage_path('app/vk_ors')`.
- Normalize encodings UTF-8.
- Store raw rows in cache table **or** only use at seed time (prefer no second
  warehouse — seed directly into slots/candidates).

### Step 5 — `content:seed-month {YYYY-MM}`

- Create ~20–28 `empty`/`draft` slots across the month (density from
  `activity_by_month` same calendar month historically, fallback flat 20).
- Slot types placeholder mix: 50% evergreen-capable, 30% systema-bridge empty,
  20% forward empty (filled in later waves).

### Step 6 — Filament page

- Navigation: Маркетинг → **Календарь контента** (adminOnly + flag).
- Table: slot_date, type, status, excerpt, publish_at.
- Bulk actions: Keep (→ scheduled + assign publish_at if missing), Cancel, Edit body.
- Visible only when `content_calendar` true.

### Step 7 — Tests

- Import fixture does not throw.
- Seed month creates ≥20 slots.
- Flag OFF → navigation hidden / command no-op or safe.
- Keep/Cancel status transitions unit-tested.

### Step 8 — Filament smoke (D19)

- Prefer browser smoke; if unavailable: feature test that Livewire page renders
  200 for admin user + document in PR “human Filament smoke before flag ON”.
- DEPLOY_QUEUE row: enable `CONTENT_CALENDAR_ENABLED`, copy CSVs to storage,
  open month view once.

### Step 9 — Changelog + DEPLOY_QUEUE

Unreleased bullet; activation row for Ivan/admin.

---

## Waves 2–5 checklists

### W2 Evergreen

1. `EvergreenScorer` on imported top_posts + topic tags.
2. Fill evergreen slots with **verbatim** body + source_ref = vk id.
3. De-dupe 6 months; promo exclusion regex tests.
4. Spaced `publish_at` defaults.

### W3 Systema bridge

1. Query free LectureClips / published schedule / FAQ if present.
2. Create slots with links (not re-encoding video).
3. Tests with factories.

### W4 Forward drafts

1. `ForwardDraftGenerator` via CuratorAi fake client.
2. Only fills `forward` empty slots; status draft.
3. Cost cap / daily limit mirror SupportAi.

### W5 Auto-pilot

1. `content:publish-due` scheduled hourly.
2. n8n workflow JSON `docs/n8n/vk-calendar-post.workflow.json`.
3. Http::fake tests; no real VK.
4. Cancel deadline = publish_at − 24h enforced in action + service.
5. Flags: autopilot separate from calendar UI.

---

## Defaults log

| Fork | Default |
|---|---|
| ContentCandidate exists | extend |
| Slot count/month | max(20, historical month posts / 4) capped 40 |
| publish_at spacing | every 1–2 days at 12:00 Europe/Moscow |
| Promo exclude | regex on скидк\|руб\|\b₽\b\|запис.*марафон (tune in W2) |

_Dr. Mārcis Gasūns_
