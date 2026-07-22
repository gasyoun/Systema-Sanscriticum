# PLAN — Closing the Anton operational gaps (Systema-Sanscriticum, 2026 H2)

_Created: 22-07-2026 · Last updated: 22-07-2026_

This is the cover/index for a layered `/ask` execution plan. It answers one question —
**"what does Anton (a yoga/movement online school) have that we don't, and what should we
do about it?"** — and pins every decision a builder needs so four waves can be executed
unattended. The four layer docs are linked in §6.

Provenance: authored from two Anton interview transcripts (09-09-2025, `cf1N7Vh3tFE` /
`vxazfvVqXvw`) + a full repo audit, by Opus 4.8 (`claude-opus-4-8`), via `/ask` — interview
of 12 rulings across three rounds (22-07-2026).

---

## 1. The honest gap analysis (the answer)

Anton runs an Airtable→BaseRow + Collabs + Prodamus + Tilda + n8n + Kinescope "коленка-
комбайн". Grounding both stacks against each other, **Systema-Sanscriticum is far ahead of
Anton on almost everything** — real acquiring, a tariff/block access model, installments +
receivables governance, accrual P&L, LTV/CAC dashboards, a Filament admin, a Telegram
helpdesk. Anton is *behind* Systema on infrastructure and knows it (he was courting our team
as paid contractors, not the reverse).

Anton's genuine, transferable advantages reduce to **three operational capabilities plus one
meta-lesson**:

| # | Anton has | Systema's real state (verified) | Wave |
|---|---|---|---|
| 1 | **Email as a working, reliable primary channel** + a homegrown campaign engine: open-pixel, click-tracking (n8n webhook + user_id), a "статус рассылки" who-got-what ledger, and a "догон" resend to non-openers | Prod email **dead** — `#504`, `mailpit` swallows all mail, students can't reset passwords (`DEPLOY_QUEUE.md`). 27 `Mailables` exist but **no campaign/tracking engine** at all (no `Campaign` model, no open/click columns) | **W1** |
| 2 | **In-video resume** — Kinescope remembers where each student stopped and restores it | iframe-only (`VideoEmbed`: YouTube/RuTube/VK/Vimeo); playback position **not persisted** — `LessonView` has no position column, a returning student restarts from 0 | **W2** |
| 3 (host) | **A real video host** (Kinescope) giving native resume/chapters/analytics/DRM | Raw iframes only; Kinescope is *supported as an embed host* (`LandingPageResource`) but nothing runs on it | **W3** (pilot) |
| 4 | **Clip-extraction marketing** — cuts a 2 h lecture into ~50 standalone fragments (ChatGPT-written `ffmpeg` batch scripts), publishes ~3 free per video; they **self-feed organic traffic on VK Video** as lead-gen | Rich *in-video* timecodes/transcripts (`seekTo()`, AI-verified timecodes, searchable transcript) but **no physical clip cutting + standalone-clip distribution** | **W4** |
| — | **Ships-and-activates** on a light stack; reacts to events, grows incrementally | **Over-built and stalled at the deploy/activation gate** — dozens of merged, migration-bearing, flag-OFF features are not live; email is dead; VPS creds sit only with a contractor | meta |

**What Anton does NOT have that we already do** (so the roadmap doesn't chase parity we've
passed): real card acquiring + fiscalization (Anton reads Sber-check *screenshots* by OCR in
a grey zone — we have Tochka), the block/half-block tariff access model, installments +
`ReceivablesGovernance`, accrual revenue recognition (`FinanceCockpit`), LTV/CAC/unit-
economics (`StudentUnitEconomics`), a Filament admin (Anton wants *off* Airtable/Collabs), a
Telegram helpdesk (`MadelineProto`), CRM Kanban, attribution, and a per-lesson AI-verified
**timecode+transcript** system that is *more* advanced than Anton's Yandex-timecode workflow.

> The uncomfortable takeaway, and the meta-lesson worth more than any single feature:
> **Systema's bottleneck is not missing product — it is that "built ≠ running."** Anton's
> whole story is proof that the cheap, *activated* thing (email, free clips, timecodes) beats
> the sophisticated, *inert* thing. Wave 1 is deliberately the dead channel; every wave lands
> behind a flag with a one-line activation row, because activation — not construction — is
> where this repo loses.

The "Kinescope timecode/clip" story that might look like our biggest gap is **not** a gap:
we already generate AI-verified timecodes synced to a searchable transcript with clickable
`seekTo()` navigation (`docs/LEARNING_EXPERIENCE_UX_AUDIT_2026.md:152`,
`app/Services/Lecture/LectureAiClient.php` `verifyTimecodes`). What we lack is only the
*physical clip cut + standalone distribution* half — captured as W4.

---

## 2. Goal for this span

Close the three operational capabilities above as four flag-gated waves, each merged with
green tests and an activation-checklist row, **without touching money code and without any
live send during the build**. "Done" for the span = all four waves merged and inert behind
flags, with a single [`MARATHON_ACTIVATION_CHECKLIST.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md)-style
activation list a human can walk in one sitting.

---

## 3. Decisions taken (interview 22-07-2026, MG — do not re-litigate)

| # | Decision | Ruling |
|---|---|---|
| D1 | Deliverable | **Internal build roadmap** — full layered autonomous-build spec |
| D2 | Scope | All four: email campaign engine, in-video resume, Kinescope pilot, clip-marketing pipeline |
| D3 | Video-host direction | **Stay iframe + player-API resume**; no forced migration |
| D4 | Kinescope role | **Pilot ONE flagship course** to evaluate native resume/analytics/DRM; broader migration deferred |
| D5 | Email model | **Homegrown** send + tracking (own open-pixel/click/resend), like Anton — not an ESP campaign SaaS |
| D6 | Email transport | **Mailbox SMTP (mail.ru / Yandex 360)**, mirroring Anton's 1500-list setup |
| D7 | Clip pipeline | **n8n-driven** `ffmpeg` + VK upload; cut boundaries **reused from the existing AI timecodes**; staff approve which ~3 clips are free |
| D8 | Resume host scope | **All API-capable hosts** — YouTube + RuTube + VK + Kinescope + Vimeo; degrade gracefully where no API |
| D9 | Wave order | **Email → Resume → Kinescope → Clips** (revive the dead channel + fix a live student break first) |
| D10 | "Done" bar | Merged PR + green tests + **feature-flag OFF** + a row appended to `DEPLOY_QUEUE.md` / activation checklist |
| D11 | On ambiguity | **Pick the plan's marked default, log it in the handoff, press on** |
| D12 | Fence (hard) | **No money/payment code.** Applied defaults: no prod deploy/creds, no live outbound sends during build, additive schema only |

Two rulings deliberately reconcile an apparent contradiction in the interview: D3 (no
migration) with the earlier "Kinescope-class host" gap-pick → resolved as **D4, a one-course
pilot** whose native resume is still captured through the same host-agnostic resume layer
(W2). And D5/D6 honour "homegrown like Anton" over the installed-but-unwired Postmark/Mailgun
— see the deliverability risk register in the VERIFICATION doc before a human flips the flag.

---

## 4. The autonomy contract (verbatim — the execution agent obeys this)

An agent executing any wave of this plan runs unattended for hours; this contract fixes its
behaviour when reality diverges from the plan.

- **On an unplanned ambiguity** (D11): choose the option this plan marks as the default,
  **write the decision + rationale into the wave handoff's log**, and continue. Do not stall,
  do not ask, do not improvise a third path.
- **Stop conditions**: halt the wave and leave the branch for a human only if (a) a step would
  require touching money/payment code (D12), (b) a test regression cannot be resolved without
  changing existing money/user table shapes, or (c) an external secret the plan assumed present
  (SMTP creds, VK API app, Kinescope account) is genuinely required to make *code* compile/test
  — in which case stub it behind the flag and note the human prerequisite, don't block.
- **Commit authority**: per the handoff-scoped autonomy rule, each wave handoff authorises
  commit → PR → merge with no confirmation ask, provided the "done" bar (D10) is met.
- **The fence** (D12, hard): never edit `PaymentObserver`, the Tochka webhook
  (`WebhookController`), checkout, installments, receivables, prana wallet, or any money path;
  never deploy or touch prod credentials/the VPS; **no real emails or VK posts to real users
  during the build** — test/staging targets only; new tables/columns only, no destructive
  migrations.

---

## 5. Human prerequisites (do NOT block the build — captured for activation)

These are secrets/decisions only a human can supply; the build stubs around each behind its
flag and the activation checklist collects them:

- **W1**: a sending mailbox + SMTP creds (mail.ru or Yandex 360) and its SPF/DKIM/DMARC
  records; the from-address; a first campaign segment to test against (staging only).
- **W3**: a Kinescope account + one flagship course chosen for the pilot.
- **W4**: a VK app with Video/Wall API scope + a service token; the editorial call on which
  ~3 fragments per lecture are free.

---

## 6. The layer docs

- **Roadmap (waves + non-goals):** [`docs/ROADMAP_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_ANTON_OPS_GAPS_2026H2.md)
- **Architecture (components, data model, build-vs-reuse):** [`docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md)
- **Implementation (Wave 1, file-level, step-ordered):** [`docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE1.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE1.md)
- **Verification (acceptance per deliverable + risk register):** [`docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md)

---

## 7. Autonomy-readiness gate verdict

**PASS.** Every wave-1 deliverable has an architecture spec, ordered implementation steps, an
acceptance criterion, and a risk entry. Zero blocking forks remain inside the wave-1 path (the
ESP fork was ruled D5/D6; the Kinescope fork D4). Nothing scheduled rebuilds an existing asset
— the timecode/transcript system is reused, not rebuilt; the heartbeat endpoint is reused for
resume; n8n and the AI timecodes are reused for clips. The autonomy contract (§4) covers the
plausible ambiguities the audit surfaced.

_Dr. Mārcis Gasūns_
