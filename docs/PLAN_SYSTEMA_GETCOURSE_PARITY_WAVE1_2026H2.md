# Plan — GetCourse-parity Wave 1 (Systema-Sanscriticum, 2026H2)

_Created: 17-07-2026 · Last updated: 19-07-2026_

> **R-5 timing superseded 18-07-2026 (MG ruling, [H1261](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1261-Sonnet_Systema-Sanscriticum_rq4-study-go-live_18.07.26.md)):
> RQ4 recruitment does NOT wait for autumn — MG ruled GO now.** Only the *timing* clause of R-5
> below is superseded; the contamination guard is unchanged and still fully binding: the
> 28-08 R20 marathon cohort remains off-limits as an RQ4 recruitment source, RQ4 recruits a
> separate approved Kochergina-stage cohort, and no arm-aware segmentation may leak into R20
> analytics. §2.2's "must escalate" rule for flipping `RQ4_STUDY` is unchanged — GO is a
> product ruling, not a credential grant; the flag flip itself still requires the established
> deploy authority. See [DEPLOY_QUEUE №26](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) for the exact activation contract.

The index of the `/ask` Phase-3 plan set for the getcourse-parity wave. Authored by
Opus 4.8 (`claude-opus-4-8[1m]`) against human rulings taken 17-07-2026. This doc is the
entry point; the other four are linked in §5.

**What this plan is.** A wave-1 execution contract shaped to six human rulings (§1). It does
**not** re-open those rulings and does not re-derive the existing parity analysis — the
gap-analysis of record is
[ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md)
(H438, 4 domains, tickets GC-A1…GC-D4, 7 rulings already settled), and this plan **consumes**
it.

---

## 1. Decisions taken

Every ruling below is human, taken 17-07-2026, and binding on execution. The
**Consequence** column records what each ruling costs — including where a ruling went
against the analysis it was given.

| # | Ruling (verbatim) | Consequence — including the trade-off accepted |
|---|---|---|
| **R-1** | **Wave 1 = getcourse parity (H438).** ✱ This went AGAINST the recommendation. The audit rated H438 the LEAST specified of the four Tier-0 programmes (4 [ROADMAP_INDEX](https://github.com/gasyoun/Uprava/blob/main/ROADMAP_INDEX.md) rows, no R29-equivalent spec). Therefore **wave 1 must OPEN with a design/spec pass that produces the missing spec** — an R29-equivalent for getcourse parity — before any implementation deliverable. Do not pretend it is build-ready. The other three programmes do NOT hard-freeze. | **Accepted cost, stated plainly:** wave 1 buys a spec, not a shipped parity feature. The recommendation was to lead with a better-specified programme; the ruling prefers parity anyway, and pays for that with W1-D1 — a full design pass ([ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) §2) whose output is a document, not code. The honest read: **no getcourse-parity *feature* ships in wave 1** — W1-D1 makes wave 2 build-ready. The four other wave-1 deliverables (§3) are deploy-independent items that ride alongside, which is what keeps the wave from being one doc. The countervailing benefit is real: H438's "Now" head (GC-B1) is already @DECIDE-blocked and GC-C1 was never specced past a roadmap paragraph, so a build started today would have stalled on exactly the spec this ruling buys. |
| **R-2** | **R20 gates RELEASE only, not authorship.** The cabinet hybrid chassis + non-telemetry organs may be BUILT NOW behind a flag; release still requires ≥2 weeks of prod baseline, whose clock starts only when [DEPLOY_QUEUE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) item №25 deploys. This runs as a SECOND front behind wave 1. | Unblocks cabinet authorship immediately — [H962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H962-Sonnet_Systema-Sanscriticum_student-cabinet-remake-instrumentation-phase_15.07.26.md) Phase 0 shipped ([PR #534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534), [v1.14.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.14.0), MERGED) so Phases 1–3 of [R29](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md) §6 are authorable. **Cost:** the baseline clock is **not ticking** — №25 is merged-but-undeployed and needs a human on prod. Every week of deploy delay is a week added to the release date, and wave-1 work cannot shorten it. Second front, not wave 1 — see [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md) §3. |
| **R-3** | **SMTP → move transactional email to an external ESP** (Unisender/SendGrid/Mailgun-class). Chosen because it is the only option an agent can deliver without prod shell. It must fix BOTH the login-link P0 (a student cannot log in — the email never arrives) AND the H1067 [marathon 5-email sequence](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md). Accept: new vendor, cost line, prod secret. | Splits into **W1-D3** (transport + preflight + `.env.example` fix) and **W1-D4** (the five marathon Mailables). **Cost accepted explicitly by the ruling:** a new vendor, a recurring cost line, and a prod secret. **Honest limit:** an agent delivers the *code path and the preflight*; it cannot create the vendor account or install the prod secret — [issue #504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) stays open until a human does. So W1-D3 ends "provably correct, not provably delivering". |
| **R-4** | **Memrise export = export-only sprint NOW** (applied as a dominant default, not asked). Course 6679375 «Продлёнка по санскриту». The ONLY irreversible item in the repo — Memrise is sunsetting community courses with no published shutdown date. Export→JSON→commit. Trainer scope stays out. | **W1-D5.** Irreversibility justifies pre-empting better-specified work. **Cost:** none to other waves (trainer scope explicitly out, so [ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md) P1–P6 stay untouched). **Honest limit:** the export needs a Memrise login, which an agent does not have — the repo already says so in writing ([the destination README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md): "Neither is something an agent session can do unattended"). So W1-D5 delivers the *runner + validator* that shrinks the human step to one command; it cannot deliver the CSVs. |
| **R-5** | **August window: SERIALISE** — the R20 cabinet baseline owns it, THEN RQ4. RQ4 recruitment moves to autumn. The marathon cohort is NOT used as an RQ4 recruitment source. No arm-aware segmentation work. | Protects the R20 measurement from RQ4 contamination. **Cost:** RQ4 slips a season although its harness is **already shipped and merged** ([PR #536](https://github.com/gasyoun/Systema-Sanscriticum/pull/536)/[#540](https://github.com/gasyoun/Systema-Sanscriticum/pull/540)/[#539](https://github.com/gasyoun/Systema-Sanscriticum/pull/539), [v1.16.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.16.0), DEPLOY_QUEUE №26) — built capability sits idle behind `RQ4_STUDY=false` for a season. Consequence for execution: **no wave may touch arm-aware segmentation**, and `RQ4_STUDY` stays OFF. The 28-08 marathon cohort is off-limits as a recruitment pool. |
| **R-6** | **SRS stays dark** — `SRS_ENABLED=false` until the hybrid ships. R29 untouched, no SRS station. Surfacing it mid-baseline would corrupt the measurement R20 exists to protect. | **This ruling is currently VIOLATED IN CODE — it generates wave-1 work (W1-D2), not just a prohibition.** [`config/srs.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/srs.php) line 18 reads `'enabled' => (bool) env('SRS_ENABLED', true)` — the default is **`true`**, flipped by H447 Phase 1 ([PR #442](https://github.com/gasyoun/Systema-Sanscriticum/pull/442)). Three documents still describe it as off: the same file's own docblock (line 10, "ВЫКЛ по умолчанию (в проде тоже)"), [`routes/web.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) line 260 ("в проде OFF"), and DEPLOY_QUEUE №24 ("SRS-движок в целом всё ещё за `SRS_ENABLED=false`"). Unless prod `.env` carries an explicit `SRS_ENABLED=false`, SRS surfaces to students on the next deploy — exactly the baseline corruption R-6 forbids. See [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) §3. |

### 1.1 Two briefed candidates that the code refutes

The brief asked to fold in two deploy-independent items. Both were checked against the tree
and **neither is wave-1 work**. Recording this rather than planning it:

| Briefed candidate | Verdict | Evidence |
|---|---|---|
| **PR #428 merge conflict** (H445/H447 both extended `MarathonEnrollment.php`; ~13 files conflict) | **STALE — no work exists.** [PR #428](https://github.com/gasyoun/Systema-Sanscriticum/pull/428) is **CLOSED, not open**; its content landed instead via [PR #442](https://github.com/gasyoun/Systema-Sanscriticum/pull/442) (commit `6267d70`, "feat(srs): H447 Phase 1"). There is nothing to merge and no conflict to resolve. | `ab_arm` is present in [`MarathonEnrollment::$fillable`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MarathonEnrollment.php) on `main`; migration `2026_07_10_190000_add_ab_arm_to_marathon_enrollments_table.php`, [`SrsSanskritDeckSeeder`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/SrsSanskritDeckSeeder.php), and `resources/views/student/srs-stats.blade.php` all exist on `main`. **This is how W1-D2 was found:** #428 also flipped the SRS default to `true`, and that flip landed with the rest. |
| **H1068 copy defects** (16 located, awaiting rulings) | **No agent-executable slice — correctly out of wave 1.** The brief says "scope only what is ruled"; **nothing is ruled** — all four forks D1–D4 are open, and D1 blocks every header/footer/about edit. The plan's R1–R2 remainder is delivered through "Systema admin + ручной WP" — a human at a Filament panel and a WordPress install, not a repo change. | [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) Tier-0 `@DECIDE` ×4 row. Note the one genuinely code-shaped defect from that audit — proof-number drift (20 vs 21+ years, three crowdfunding figures) — **is already fixed**: [PR #546](https://github.com/gasyoun/Systema-Sanscriticum/pull/546) single-sourced them into [`config/trust.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/trust.php) (H1085, DEPLOY_QUEUE №28). Re-planning it would be a defect. |

### 1.2 Stale inputs deliberately not planned from

`.ai_state.md` lists H822 and H962 as PR-open. Both are **merged** —
[PR #514](https://github.com/gasyoun/Systema-Sanscriticum/pull/514) (14-07) and
[PR #534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534) (15-07). No plan step
derives from those rows. Ground truth for this plan is `origin/main` at commit `16ef950`,
fetched 17-07-2026.

---

## 2. Autonomy contract

What an executing agent may settle alone, and what it must stop for.

### 2.1 Decide alone — no escalation

- Internal naming: classes, methods, migrations, blade template paths, test names.
- Test structure and fixture shape, provided [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)'s acceptance command still passes.
- Refactors **inside** a file already being edited for a listed deliverable.
- Wording of code comments, docblocks, commit messages, PR bodies.
- Which ESP-class driver package to add **as the code path**, provided the transport is
  selected by `.env` and no vendor is hardcoded (R-3 names a class of vendor, not one).
- Ordering **within** a deliverable's steps where [IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) does not pin it.
- Adding a `docs/` note or a DEPLOY_QUEUE row for work it just shipped.

### 2.2 Must escalate — stop and ask a human

- **Any feature flag's default value** other than the one W1-D2 exists to fix. Flag defaults
  are release policy (R-2/R-5/R-6), not an engineering choice.
- **Flipping `SRS_ENABLED`, `RQ4_STUDY`, or any cabinet-remake flag to on.** R-5/R-6 forbid it.
- **Any change under `app/Services/Payment*`, `PaymentObserver`, `Tariff`, or revenue
  recognition.** The money core is out of scope for every wave-1 deliverable; a wave-1 step
  that appears to need it is a mis-scope — stop.
- **Choosing the actual ESP vendor, creating the account, or handling a live secret.**
  R-3 accepts the vendor + cost line; a human picks and pays. Never commit a credential.
- **Any of the four H1068 forks D1–D4**, or any header/footer/about copy edit (D1 blocks them).
- **GC-B1** (per-schedule Zoom auto-create) — a standing `@DECIDE`; the roadmap records that
  this was built and deliberately removed in commit
  [`eda8059`](https://github.com/gasyoun/Systema-Sanscriticum/commit/eda8059e8ae16086ee0ef22f6b78c4a91def3b71). Implementing it blind would silently revert a considered decision.
- **Any prod action** — deploy, `.env`, migration on prod, cron. Agents hold no prod access;
  the delivery path is a DEPLOY_QUEUE row for Ivan.
- **A fork surfaced by W1-D1's spec pass.** The spec's job is to *name* forks, not resolve
  them. Write them into the spec's `@DECIDE` list and continue.
- **Publishing anything** derived from the Memrise export beyond committing it to this
  private-by-default path — rights are unruled.

### 2.3 The standing rule

When a step cannot proceed without a human, the agent **writes the blocker down** (a
`@DECIDE`/`@DO` row in the spec or DEPLOY_QUEUE) and **moves to the next deliverable**. It
does not guess, and it does not idle.

---

## 3. Wave-1 deliverables

Five. Ordered; W1-D1 gates nothing else in wave 1 but gates all of wave 2 (R-1).

| ID | Deliverable | Tier | Human gate |
|---|---|---|---|
| **W1-D1** | GetCourse-parity production spec (the R29-equivalent R-1 requires) | Opus 4.8 (`claude-opus-4-8`) | none to author; the spec *surfaces* forks |
| **W1-D2** | Restore `SRS_ENABLED` default to `false` (R-6 enforcement) | Sonnet 5 (`claude-sonnet-5`) | none |
| **W1-D3** | ESP transactional-email transport + preflight + `.env.example` fix (R-3a) | Sonnet 5 (`claude-sonnet-5`) | **yes** — vendor account + prod secret (#504) |
| **W1-D4** | Marathon 5-email Mailables + templates (R-3b) | Sonnet 5 (`claude-sonnet-5`) | none to author; delivery waits on W1-D3's gate |
| **W1-D5** | Memrise export runner + validator (R-4) | Sonnet 5 (`claude-sonnet-5`) | **yes** — Memrise login |

Sequencing, the R20 second front, and what freezes: [ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md).

### 3.1 Is wave 1 thin?

Partly, and the reason is structural rather than accidental. R-1 spends the wave's headline
slot on a document. Of the remaining four, two (W1-D3, W1-D5) can be *built and proven in
tests* but **cannot be proven to work in the world** without a human — one needs a vendor
account, one needs a Memrise login. Only W1-D2 and W1-D4 are fully closable by an agent
alone. That is an honest description of a repo whose Tier-0 surface is prod-gated and whose
agents hold no prod access; it is not padded to look otherwise.

---

## 4. Assets consumed, never rebuilt

Prior art the plan **uses**. Re-deriving any of these is a defect.

| Asset | Location | Used by |
|---|---|---|
| GetCourse gap-analysis + 7 settled rulings | [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) | W1-D1 (input; the spec refines, never re-audits) |
| R29 hybrid spec — the *shape* an R29-equivalent must have | [STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md) | W1-D1 (template) |
| Kanban package `mokhosh/filament-kanban` — already in `composer.json` | `composer.json` | W1-D1 (GC-C1 spec assumes reuse) |
| Baseline telemetry — already shipped | [PR #534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534), `cabinet:baseline` | R20 second front |
| Memrise importer + manifest contract + fixture | [`ImportMemriseSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportMemriseSrsDeck.php), [`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) | W1-D5 (runner targets the existing contract) |
| Marathon email copy — 5 drafted emails | [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) | W1-D4 (copy source; not rewritten) |
| Mailable house recipe + queue convention | [`app/Mail/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/README.md) | W1-D4 |
| Prod SMTP diagnosis — root cause already found | [issue #504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) | W1-D3 (no re-diagnosis) |
| Proof-number single-sourcing | [`config/trust.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/trust.php), [PR #546](https://github.com/gasyoun/Systema-Sanscriticum/pull/546) | H1068 R3 — **already done**, do not re-plan |

---

## 5. The plan set

| Doc | Answers |
|---|---|
| [ROADMAP_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md) | waves, sequencing, the R20 second front, what freezes |
| [ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) | the design — real paths, classes, data shapes |
| [IMPLEMENTATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) | ordered steps per deliverable + acceptance criteria |
| [VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) | how each deliverable is proven; exact commands |

## 6. Open human items this plan generates

Routed to [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md); none is agent-closable.

1. **`@DO` — deploy DEPLOY_QUEUE №25** ([PR #534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534)). The R20 baseline clock does not start until this runs on prod. Highest-leverage item in the set: it is the only thing standing between a merged deliverable and a ticking two-week clock (R-2).
2. **`@DECIDE` — ESP vendor** + account + prod secret (R-3). Gates #504 and all five W1-D4 emails.
3. **`@DO` — Memrise login** for the W1-D5 export pull (R-4). Time-critical and irreversible.
4. **`@DECIDE` ×4 — H1068 forks D1–D4.** D1 blocks all header/footer/about copy.
5. **`@DECIDE` — GC-B1**: per-schedule Zoom auto-create vs. the single-link model of commit [`eda8059`](https://github.com/gasyoun/Systema-Sanscriticum/commit/eda8059e8ae16086ee0ef22f6b78c4a91def3b71).
6. **`@DECIDE` — the `lead_id`-hash A/B-arm substitution** in [`MarathonEnrollment::computeArm()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MarathonEnrollment.php). H447's design said "hash of `user_id`", but `marathon_enrollments` has no `user_id`; the code shipped hashing `lead_id` and asked a human to confirm. That confirmation is not recorded anywhere, and the code is **on `main`** — this is an open question about live code, not a proposal. R-5 keeps it inert (no arm-aware work), so it is not urgent, but it should not be lost.

_Dr. Mārcis Gasūns_
