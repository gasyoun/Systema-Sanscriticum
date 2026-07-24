# PLAN — VK/ORS content calendar from vk-ors archive (Systema, 2026 H2)

_Created: 24-07-2026 · Last updated: 24-07-2026_

Cover index for a layered `/ask` plan. Question answered: **from the
IndologyScholars `vk-ors` archive (and adjacent Systema signals), what content
work can be automated, what does a content calendar look like, and what is
possible with minimum human interaction?**

Provenance: `/ask` interview 24-07-2026 (5 rounds), Grok 4.5 (`grok-4.5`); audit
of [`IndologyScholars/vk-ors`](https://github.com/gasyoun/IndologyScholars/tree/main/vk-ors)
(7,610 posts, PR #130) + Systema n8n/clip/content-engine plans.

---

## 1. Honest answer (one page)

### What `vk-ors` already is

A **reproducible analytics archive** of the public wall
[vk.com/wall-88831040](https://vk.com/wall-88831040) («Общество ревнителей
санскрита»): xlsx → SQLite+FTS → topics/hashtags/engagement CSVs → HTML
retrospective. It is **not** a publisher. Re-ingest is still manual.

**Signals already computed (inputs for automation):**

| Signal | Use for automation |
|---|---|
| `activity_by_month` / heatmaps | Seasonal density (e.g. denser Sep–Nov historically) → how many slots/month |
| `topics_by_year` (словарь, учебник, книга, pdf, …) | Slot-type mix ratios |
| `hashtags` (`#bookzealots`, `#читаем_с_орс`, …) | Tag templates + recycle filters |
| `top_posts_by_likes` / `reposts` | Evergreen queue seeds |
| `book_posts` | Book/dict announcement patterns |
| `engagement_rate_by_year` | Later KPI (not wave-1 gate) |

### What can be automated (ranked by human-touch)

| Class | Automation | Human residual |
|---|---|---|
| **A. Analytics refresh** | Re-run ingest/insights when xlsx refreshes; commit CSVs | Export xlsx occasionally |
| **B. Evergreen recycle** | Score top posts → calendar slots → auto-queue | Monthly Keep/Cancel; 24h cancel |
| **C. Systema bridge** | Free lecture clips, monthly schedule digest, course events → slots | Editorial free-clip policy (existing) |
| **D. Forward drafts** | LLM/templates fill empty slots (events, reading-group teases, FAQ teases) | Monthly review; NEW copy does **not** auto-proceed if review skipped |
| **E. Auto-pilot publish** | Hourly ticker → n8n `wall.post` after `publish_at` if not canceled | Incidents only; target ≥20 posts/month |

### Minimum human interaction (locked)

- **Cadence:** **one monthly Filament batch** (Keep / Cancel / Edit next 30 days).
- **Mid-month:** cancel allowed until **24h before** `publish_at`.
- **If monthly review skipped:** only **evergreen + Systema-scheduled** slots
  proceed; **NEW copy stays draft** (protects brand while still filling the wall).
- **Success metric:** **≥20 published slots/month** (volume bar; intervention not
  subtracted).

### What is *not* free lunch

- Verbatim recycle (ruling) can re-surface **stale prices/links** — risk register
  + later link-health spike; wave-2 ships verbatim as ruled.
- Full mix (archive + clips + FAQ teases + events) needs five waves, not one PR.
- `vk-ors` stays in **IndologyScholars**; Systema **imports derived CSVs only**.

---

## 2. Goal for this span

Land a **flag-gated content calendar** in Systema that (1) imports vk-ors
derived data, (2) seeds evergreen slots, (3) bridges live Systema signals, (4)
drafts forward copy, (5) auto-queues VK posts via n8n with a 24h cancel window —
all mergeable behind flags with **no live public posts during build**.

---

## 3. Decisions taken (do not re-litigate)

| # | Decision | Ruling |
|---|---|---|
| D1 | Goals | **All five**, sequenced: analytics feed → evergreen → Systema bridge → forward drafts → auto-pilot |
| D2 | Code home | **Systema-Sanscriticum only** |
| D3 | Human gate | **Auto-queue scheduled**; cancel until 24h before publish |
| D4 | Wave-1 content mix target | **Full mix** eventually; W1 seeds skeleton + evergreen-capable import |
| D5 | Min-touch bar | **Monthly** batch review (not weekly) |
| D6 | Deliverable | Full layered plan + **five handoffs** |
| D7 | Sequence | Analytics → Evergreen → Bridge → Forward → Auto-pilot |
| D8 | vk-ors → Systema | **Periodic import of committed CSVs/JSON** from IndologyScholars |
| D9 | Data model | **`ContentCalendarSlot` + `ContentCandidate`** (extend content-engine shape) |
| D10 | Publish path | **Laravel ticker + n8n VK wall.post** (monthly-post pattern) |
| D11 | Monthly UX | **Filament** next-30-days bulk Keep/Cancel/Edit |
| D12 | Skip-review default | **Evergreen + Systema only**; NEW copy stays draft |
| D13 | W1 deliverable | Import job + schema + Filament month view + evergreen-seeded slots; flag OFF |
| D14 | Evergreen policy | Top-N likes, age≥12m, topic ∈ {книга,словарь,pdf,текст}, exclude pure promo; de-dupe 6m |
| D15 | 24h window | `publish_at = scheduled_at`; cancel while `now < publish_at - 24h` |
| D16 | Forward drafts | **CuratorAi / OpenRouter** + templates |
| D17 | Recycle body | **Verbatim original text** (risk: stale links — see VERIFICATION) |
| D18 | IndologyScholars | Thin export/README refresh; commit CSVs Systema imports |
| D19 | W1 merge bar | PHPUnit + Pint + flag OFF + **manual Filament smoke** (human or CI note) |
| D20 | Success metric | **≥20 published slots/month** |
| D21 | STOP | Money code; live public VK during build; unmockable secret for tests |
| D22 | Ambiguity | Plan default + log + continue |
| D23 | Git | Commit → PR → merge when green + flag OFF (worktree) |
| D24 | Fence | No money; no prod secrets; no live public wall posts in CI/build |

---

## 4. Autonomy contract

- **Ambiguity (D22):** marked default → log in handoff → continue.
- **STOP (D21):** money paths; any real public VK post in agent session; secrets
  required to compile tests without stubs.
- **Staging / Filament smoke (D19):** agent ships green tests; if no browser,
  PR describes artisan/feature test as proxy + DEPLOY_QUEUE row for human
  Filament smoke before flag ON.
- **Commit (D23):** worktree → PR → merge at D19 bar; flags default OFF.
- **Fence (D24):** no Payment/Tochka edits; no live wall.post to production
  community during build.

---

## 5. Layer docs

- Roadmap: [`docs/ROADMAP_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md)
- Architecture: [`docs/ARCHITECTURE_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md)
- Implementation W1: [`docs/IMPLEMENTATION_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md)
- Verification: [`docs/VERIFICATION_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_VK_ORS_CONTENT_CALENDAR.md)
- Metadoc: [`docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.meta.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.meta.md)

Siblings: lecture content engine plan; Anton clip pipeline; Content-AI roadmap;
[`vk-ors/README.md`](https://github.com/gasyoun/IndologyScholars/blob/main/vk-ors/README.md).

---

## 6. Autonomy-readiness gate

**PASS.** Each wave has architecture hooks, ordered steps (W1 detailed),
acceptance criteria, and risks. Prior art: reuse n8n monthly-post, clip
pipeline, CuratorAi; **do not** reimplement vk-ors ingest inside Systema.
Residual: Filament smoke is human-activation-adjacent (D19 proxy allowed).

_Dr. Mārcis Gasūns_
