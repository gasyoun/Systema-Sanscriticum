# Roadmap: Content-Ops Inbox & Weekly Content AI 2026–2027 (Q3 2026 → Q2 2027)

_Created: 07-07-2026 · Last updated: 23-07-2026_

> Narrow roadmap for the **Postmypost-inspired** slice of support/marketing: unifying public
> social engagement (VK/Instagram comments, story replies) alongside the existing private
> support inbox, adding simple 2–3-person routing, and a weekly AI content-gap pipeline
> (FAQ drafts + social post drafts) with one-click publish. Sibling to
> [`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)
> (which owns support-DM deflection) and the general
> [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
> (which this document extends the **C — Content** stream of, without duplicating it).
> Ground truth on what the support/inbox code actually is —
> [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) —
> and product framing — [`docs/jivo.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md) —
> are companions to this file; read both before implementing any ticket below.

**Origin:** comparison request 07-07-2026 (postmypost.io/ru/inbox vs. Systema's existing
messenger-support-chat slice) → `/roadmap-interview` session (Sonnet 5 `claude-sonnet-5`),
8 rulings recorded in §2 below. MG does **not** want a standalone product — every ticket here
grows Systema in place, reusing its existing money/access/support infrastructure.

---

## 1. Résumé — what this adds that isn't already built

`docs/support-subsystem-map.md` (05-07-2026, ground truth) shows the **private** side is largely
done: `SupportConversation` (reopenable thread, `status` column already exists),
`UnifiedInboxReader`/`UnifiedMessage` (merges `ChatMessage` + `TelegramSupportMessage`),
`SupportReplyService`, `SocialAccount` as the canonical external-identity table, and
`SupportAiService` (`suggestReply`/`summarize`, draft-only, OpenRouter via `CuratorAi::chat()`).
None of that is rebuilt here.

What's genuinely missing, and what this roadmap adds:

| Gap | Postmypost feature it mirrors | Existing Systema asset it builds on |
|---|---|---|
| **Public comment/story-reply ingestion** (VK wall comments now, Instagram Graph API later) | "all messages **and comments** from social accounts in one window" | VK Callback API webhook already exists (`VkController`, `VK_BOT_TOKEN`/`VK_GROUP_ID`/`VK_CALLBACK_SECRET`) — extend event types, don't rebuild the webhook |
| **Owner/claim routing for 2–3 people** | "multiple managers simultaneously... assign responsible" | `SupportConversation.status` column exists but has no claim UI (`support-subsystem-map.md` "Actually open" Phase-1 gap); `SupportResponderMapping` already resolves marker→user |
| **Weekly content-gap → FAQ + social-post drafts** | "Postmypost AI... writes copy for any topic/audience" | `SupportTopicRule`/`SupportAnswerSuggestion` + S2 deflection instrumentation ([`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) §3 S2) already classify *what students ask about* — reuse as the content-gap signal instead of a separate topic-research step |
| **One-click publish to VK/Telegram** | Postmypost is fundamentally a *publishing* tool | New surface — no prior art in the repo; smallest possible scope (VK `wall.post`, Telegram `sendMessage` to a channel) behind a flag |

---

## 2. Decisions taken (roadmap-interview 07-07-2026, MG — do not re-litigate)

1. **Aggregation scope: public comments + story replies, not just DMs.** Extends beyond
   `jivo.md`'s original DM-only recommendation. Rationale: the school actively wants public
   Instagram/VK engagement (comments, story replies) surfaced, not only private support threads —
   this is a marketing/community surface postmypost.io serves that Systema currently has zero
   coverage of.
2. **Platform sequencing: VK + Telegram now, Instagram later.** VK already has bot/webhook
   infrastructure (`VkController`, VK Callback API); Telegram already has bot DM infrastructure.
   Instagram requires a Meta Business/Graph-API app review — sequenced as a Q1 2027 stretch
   (ticket CAI9), not a Q3 blocker. Telegram itself has no public "post comment" surface
   comparable to VK wall comments / IG post comments — its contribution here stays DM-only,
   unchanged from the existing support inbox.
3. **Public-facing auto-reply: draft-only everywhere, no exception.** The house principle
   ("bots never answer students themselves — only pending drafts to a human", inherited from
   `docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md` §2) applies to public comments too, with **no**
   templated-auto-ack carve-out. Rationale (MG): public replies are higher-visibility/higher-risk
   than private DMs, not lower — the opposite of where an autopilot exception would be safe.
4. **Routing: 2–3 people sharing one inbox, simple owner+claim.** No skill-based queues or
   departments — `jivo.md`'s own recommendation to defer complex routing to 3–4+ operators still
   holds; "team will grow" means growing *to* 2–3, not past it. Builds `assigned_operator` +
   manual claim/reassign on the existing `SupportConversation.status` column — the smallest
   version of the Phase-1 gap already flagged in `support-subsystem-map.md`.
5. **Weekly content AI produces both FAQ drafts and social-post drafts**, sharing one
   gap-detection source (support-question categories/deflection data), not two separate
   pipelines.
6. **Workstream placement: this dedicated roadmap doc**, not folded into the main C-stream —
   justified because it spans Content (C), Support (SUP), and a new public-channel surface,
   mirroring the precedent set by `docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md` (H243) getting
   its own doc once it grew past a few C-stream tickets.
7. **Publish flow: one-click publish to VK/Telegram from the review page**, not manual
   copy-paste. This is the ticket that actually earns the "postmypost-like" comparison — MG chose
   the fuller build over the cheaper manual-copy option.
8. **Instagram deferred, not abandoned** — treated as a parallel human `@DO` track (Meta app
   review) starting whenever MG has time, not a Wave-1/Wave-2 gate.

---

## 3. Non-goals (considered, ruled out — don't re-propose)

- **A standalone product.** Everything here is a Systema feature behind flags, not a spin-out.
- **Full omnichannel platform** (WhatsApp, Avito, email, phone/voice) — postmypost supports these;
  Systema doesn't need them. `jivo.md` §"Что не стоит копировать сейчас" already rules out
  telephony/voice for the same reason.
- **Skill-based/department routing, capacity rules, custom roles** — explicitly deferred past
  2–3 operators (decision 4). Revisit only if the team genuinely grows past that.
- **Public-facing AI autopilot** (auto-answering comments without human approval) — explicitly
  ruled out (decision 3), including the narrow templated-ack carve-out MG was offered and
  declined.
- **A second LLM-driven content-strategy engine** independent of support data — content gaps are
  sourced from what students actually ask (S2 deflection categories), not a freestanding "generate
  content ideas" tool. Keeps LLM spend and hallucination risk bounded to grounded, LMS-fact-backed
  drafts, consistent with the house principle in `docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md` §2.
- **Merging the `ChatMessage`/`TelegramSupportMessage`/new public-comment store into one table.**
  `support-subsystem-map.md` already ruled this out for the existing two stores ("channels
  differ; build a read layer over both") — the same applies to the new public-comment store.

---

## 4. Waves

Format: **ID · quarter · title** — effort (S/M/L); what it reuses; agent steps; success metric.
🔒 = deploy-gated (waits on the main roadmap's X0 gate — migrations need `php artisan migrate`,
currently blocked on prod credential access — not hosting; the "FTP-only" framing was refuted 10-07-2026 (H478), see `docs/ROADMAP_2026_2027.md` §5 X0).

### Wave 1 · Q3 2026 — Foundation: claim routing + VK public-comment ingestion pilot

**CAI1 · Claim/assign UI on `SupportConversation`** — M.
- Reuses: `SupportConversation.status` (exists, unexposed), `SupportResponderMapping`
  (marker→user), `Helpdesk` Filament page (channel badges already there).
- Agent steps: (1) `assigned_operator_id` FK (nullable) on `support_conversations`; (2) claim/
  release/reassign actions in `Helpdesk`; (3) New/Claimed/Resolved status filter tabs (closes the
  exact gap flagged in `support-subsystem-map.md` "Actually open" table, Phase 1); (4) tests.
- Success: 2–3 curators can see "unclaimed" vs "mine" without stepping on each other; no DB
  migration blocks Wave 2 (additive, nullable — 🔒 only for the migration itself, code ships
  behind the existing `support_unified_reply`-style flag pattern).

**CAI2 · VK public wall-comment ingestion (pilot)** — M–L.
- Reuses: `VkController` + VK Callback API webhook already receiving events via `VK_BOT_TOKEN`/
  `VK_GROUP_ID`/`VK_CALLBACK_SECRET`; `UnifiedInboxReader`/`UnifiedMessage` DTO pattern.
- Agent steps: (1) confirm VK community settings enable `wall_reply_new` Callback event (human
  step — VK admin panel, not code); (2) new `PublicComment` model (`channel`, `external_post_id`,
  `external_comment_id`, `author_snapshot`, `body`, `status`) — deliberately **not** merged into
  `ChatMessage`/`TelegramSupportMessage` (same "build a read layer, don't merge tables" principle
  as `support-subsystem-map.md`); (3) extend `VkController` to store `wall_reply_new` payloads;
  (4) surface public comments as a distinct channel badge in `UnifiedInboxReader`'s merge, flagged
  `content_ops_public_comments` (off); (5) tests with a recorded VK webhook payload fixture.
- Success: new VK wall comments on the school's community appear in the unified view within one
  polling/webhook cycle, tagged "public — VK", read-only (no reply path yet — that's CAI7).
- Dependency: VK community admin (MG or a delegate) enables the Callback event type — `@DO`.

**CAI3 · Content-gap detector v1** — M.
- Reuses: `SupportTopicRule`/`SupportTopicAssignment` categories, S2 deflection instrumentation
  (`support:deflection-report`, `SupportAiReplyEvent` — [`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) §3 S2, must land first).
- Agent steps: (1) `content:detect-gaps` artisan command — categories with high incoming volume
  but low deflection (`accepted` rate) or no matching `MessageTemplate`/FAQ entry; (2) output: a
  ranked list (category, volume, deflection %, existing-content-or-none) written to a new
  `ContentGapSuggestion` model; (3) tests against seeded `SupportTopicAssignment`/
  `SupportAiReplyEvent` fixtures.
- Success: running the command against a full month of seed data produces a ranked list matching
  the known top gap categories from `Uprava/telegram-zabota-export/ANALYSIS.md` (D "оплата/цена",
  I "доступ", H "кто в группе").
- Dependency: **S2** (support-automation roadmap) — this ticket reads S2's output, don't
  duplicate its instrumentation.

### Wave 2 · Q4 2026 — Weekly cadence + FAQ publishing

**CAI4 · Weekly content digest — Filament review page** — M.
- Reuses: CAI3's `ContentGapSuggestion`, `Helpdesk`-style Filament page pattern, `MessageTemplate`
  admin UI (H221) as the closest existing "draft review" precedent.
- Agent steps: (1) `ContentDigest` Filament page, one row per open `ContentGapSuggestion` +
  its generated draft(s) (FAQ text, social post text) once CAI5/CAI6 exist; (2) Accept/Edit/
  Discard actions per draft (same three-state pattern as `ReminderSuggestion`/support-answer
  suggestions); (3) weekly cron trigger (`content:weekly-digest`) that runs CAI3 + queues drafts;
  (4) tests.
- Success: a curator/marketer opens one page every week and sees exactly what's new to review —
  no manual trawling of support logs.

**CAI5 · FAQ draft generation + cabinet knowledge-base publish** — M–L.
- Reuses: `SupportAiService`/`CuratorAi::chat()` (OpenRouter, same LLM plumbing as S5's D/E/F
  drafts — same daily-cap and `support_ai_include_telegram` privacy gate apply), existing
  `resources/knowledge/faq.md` (cabinet-bot knowledge source, `docs/cabinet-bot.md`) as the
  precedent for "faq content the bot already reads."
- Agent steps: (1) LLM-drafts an FAQ entry from a `ContentGapSuggestion` + the LMS facts already
  resolved by `SupportAnswerFactResolver` (reuse, don't re-derive tariff/schedule/access facts);
  (2) curator Accept in CAI4 appends to the cabinet FAQ source (`resources/knowledge/faq.md` or a
  DB-backed FAQ model if `/slovar`-style structured storage is preferred — `/decision-record` if
  genuinely 50/50); (3) tests.
- Success: ≥1 real content gap (e.g. a D-category pricing question with no FAQ match) closes the
  loop from "detected" to "published FAQ entry" within one week, cutting repeat questions in that
  category (measured via S7's quarterly deflection report).

**CAI6 · Social post draft generation** — M.
- Reuses: same `SupportAiService`/`CuratorAi::chat()` plumbing as CAI5, `ContentGapSuggestion`
  categories as prompt themes, existing `MarketingSetting` flag pattern for cap/prefilter (same
  discipline as S5: prefilter first, LLM only where needed, daily cost cap).
- Agent steps: (1) `SocialPostDraft` model (`channel_target`, `body`, `status`, `source_gap_id`);
  (2) LLM prompt template per platform tone (VK community vs Telegram channel — different length/
  register conventions); (3) surfaced in CAI4's digest alongside FAQ drafts; (4) tests with a
  fake LLM client (same pattern as S5's tests).
- Success: weekly digest includes ≥1 usable social post draft per content-gap category with
  volume above a configurable threshold; curator acceptance rate tracked the same way as
  `SupportAiReplyEvent`'s `accepted`/`edited`/`discarded`.

### Wave 3 · Q1 2027 — Publish integration + Instagram ingestion

**CAI7 · One-click publish: VK + Telegram** — L (money/external-API surface — careful review).
- Reuses: `VK_BOT_TOKEN`/`VK_GROUP_ID` (already used for the community bot — verify it also has
  `wall`/`manage` scope, or a separate community-admin token is needed — confirm before building,
  don't assume), Telegram bot token (already used for `SendTelegramChatMessageJob` DMs — posting
  to a *channel* needs the bot added as a channel admin, a human step).
- Agent steps: (1) `PublishSocialPostJob` — VK `wall.post` / Telegram `sendMessage` to a
  configured channel ID; (2) one-click "Publish" button in CAI4's digest on an Accepted
  `SocialPostDraft`; (3) failure handling — API errors surface back in the digest as
  `publish_failed` with the raw error, no silent retry loop; (4) rate-limit/backoff per platform
  API docs; (5) tests with mocked VK/Telegram HTTP clients (no live posts in CI).
- Success: a curator accepts a draft and it's live on the school's VK community / Telegram
  channel within the same session, with a durable log of what was published when.
- Dependency: confirmed VK/Telegram posting credentials + scopes — `@DO` if the existing bot
  tokens don't already cover posting (verify first, don't request new creds speculatively).

**CAI8 · Instagram Graph API — comment/story-reply ingestion** — L, 🔒-adjacent (external
approval, not deploy).
- Reuses: CAI2's `PublicComment` model/read-layer pattern (same "new channel, don't merge tables"
  shape), CAI4's digest for surfacing.
- Agent steps: (1) `@DO` — Meta Business/Graph-API app review (human, can run in parallel with
  Wave 1–2, doesn't block them per decision 8); (2) once approved: webhook subscription for
  `comments`/`mentions`/story-reply events; (3) extend `PublicComment` with `channel = 'instagram'`;
  (4) tests with recorded Graph-API webhook fixtures.
- Success: Instagram comments/story replies appear in the same unified public-comment view as VK,
  still draft-only reply (no auto-post to Instagram — decision 3 applies here too).
- **Do not start the Meta review** until Wave 1–2 code ships; the review is slow and speculative
  early credentials risk expiring/needing re-verification before the code catches up.

### Wave 4 · Q2 2027 — Measurement, closes the loop

**CAI9 · Content-gap deflection report** — M.
- Reuses: S12's retro-analysis pattern (`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md` §6),
  CAI3's `ContentGapSuggestion` history.
- Agent steps: (1) compare a content-gap category's support-question volume before/after its
  FAQ/post published; (2) report: which published content measurably reduced repeat questions,
  which didn't; (3) feed back into CAI3's ranking (deprioritize categories where content didn't
  move the needle).
- Success: at least one documented case of "published FAQ → category volume down" or an honest
  "no measurable effect" — either is a valid, data-backed outcome.

**CAI10 · Public-comment backlog/SLA dashboard** — S–M.
- Reuses: CAI1's claim UI, CAI2/CAI8's `PublicComment` model.
- Agent steps: unresolved-public-comment-after-N-hours KPI, same shape as the support-DM
  unresolved-after-N-hours KPI from `docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md` §8 (S10).
- Success: curators/marketer can see backlog age without opening every platform separately.

---

## 5. Quarterly layout

| Wave | Q3 2026 | Q4 2026 | Q1 2027 | Q2 2027 |
|---|---|---|---|---|
| **1** | CAI1 · CAI2 (pilot) · CAI3 | — | — | — |
| **2** | — | CAI4 · CAI5 · CAI6 | — | — |
| **3** | — | — | CAI7 · CAI8 (@DO parallel) | — |
| **4** | — | — | — | CAI9 · CAI10 |

Dependency chain: **S2 (SUP roadmap) → CAI3 → CAI4 → CAI5/CAI6 → CAI7**; **CAI2 → CAI8** (same
`PublicComment` shape); **CAI1** is independent, can land anytime in Wave 1.

---

## 6. Risks and dependencies

| Risk / dependency | Affects | Mitigation |
|---|---|---|
| X0 deploy gate (no prod credentials for MG/agents — *not* FTP-only hosting, refuted H478) | CAI1/CAI2 migrations | Additive nullable migrations, same fallback-SQL pattern as the main roadmap's X0; code ships behind flags and doesn't wait for the human deploy step |
| VK Callback event (`wall_reply_new`) not yet enabled on the community | CAI2 | Human step in VK community admin settings — `@DO`, cheap, do first |
| VK posting scope unverified (`wall.post` needs community-admin token, may differ from the existing bot token) | CAI7 | Verify scope before building; don't assume the existing `VK_BOT_TOKEN` covers posting |
| Telegram bot not yet added as channel admin | CAI7 | Human step, cheap — `@DO` alongside VK |
| Meta Graph-API app review — slow, requires business verification | CAI8 | Sequenced as parallel `@DO`, not a Wave 1–2 blocker (decision 2, 8) |
| LLM cost creep (CAI5/CAI6 add two new draft types on top of S5's D/E/F) | CAI5, CAI6 | Same daily-cap + prefilter discipline as S5; content-gap categories are naturally low-frequency (weekly, not per-message) so volume stays bounded |
| Public-comment visibility risk if draft-only discipline slips | CAI2, CAI7, CAI8 | Decision 3 is a hard rule, not a default — no code path should allow auto-post without an Accept action logged to an event table (mirror `SupportAiReplyEvent`) |
| Three unreconciled-identity lesson (support-subsystem-map.md §"External identity") | CAI2, CAI8 | `PublicComment.author_snapshot` stores raw external identity, resolved to `SocialAccount` only if a match exists — don't invent a 4th mapping table |

---

## 7. Wiring

- Wave-1 agent-doable work → handoff:
  `Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H310-Sonnet_Systema-Sanscriticum_content_ops_inbox_wave1_07.07.26.md and execute it.`
  (CAI1 + CAI3 first — both are pure-code, no external credentials; CAI2 needs the VK Callback
  `@DO` done first, noted in the handoff as a prerequisite check.)
- Human actions: VK Callback event enablement, VK posting-scope verification, Telegram
  channel-admin add, Meta Graph-API app review — mirrored to
  [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md)
  same session as this roadmap.
- Pointer added to `docs/ROADMAP_2026_2027.md`'s specialized-roadmaps table (§ header) alongside
  the SUPPORT_AUTOMATION/SRS/SEO/SECURITY/PRANA rows.
- **Related (not this roadmap's tickets):** Anton ops-gaps Wave 4 clip pipeline (H1452) —
  n8n/ffmpeg lecture fragments → VK Video/Clips, recorded as `LectureClip`, free-3 staff
  surface. Implementation:
  [`docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE4.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE4.md);
  n8n JSON:
  [`docs/n8n/lecture-clip-extract.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/lecture-clip-extract.workflow.json).
  Distinct from the weekly content-gap AI drafts above — that stream drafts FAQ/social
  *text*; H1452 reuses existing lecture *video* timecodes as cut boundaries.

_Dr. Mārcis Gasūns_
