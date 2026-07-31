# Implementation — GetCourse-parity Wave 1

_Created: 17-07-2026 · Last updated: 17-07-2026_

Ordered steps per wave-1 deliverable, each with its acceptance criterion. Design rationale
is not repeated here — see
[ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md).
Index:
[PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md).
Authored by Opus 4.8 (`claude-opus-4-8[1m]`).

---

## 0. Before any deliverable

Mandatory for every session, once:

1. `git -C <repo> fetch origin` — a stale clone is how [H919](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md) rebuilt already-merged work.
2. `git worktree add -b <branch> ../Systema-Sanscriticum-<slug> origin/main` — **never commit
   in the main tree**; a tracked `.githooks/pre-commit` blocks it, and Claude and Codex share
   these trees.
3. Systema runs a **watcher** — use [/watcher-safe-commit](https://github.com/gasyoun/claude-config/blob/main/commands/watcher-safe-commit.md) for all commits here.
4. Never `git pull` (autostash can swallow a concurrent session's uncommitted work).
5. One deliverable = one branch = one PR. Do not bundle.

**Do not plan from `.ai_state.md`'s H822/H962 rows** — both are merged
([PR #514](https://github.com/gasyoun/Systema-Sanscriticum/pull/514),
[PR #534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534)); the rows are stale.

---

## 1. W1-D2 — restore the `SRS_ENABLED` default *(do this first)*

Smallest deliverable, live R-6 violation, and it protects the R20 baseline that Front B
depends on. Ahead of W1-D1 despite the numbering
([ROADMAP §2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md)).

| # | Step |
|---|---|
| 1 | Confirm the defect still holds on fresh `origin/main`: [`config/srs.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/srs.php) line 18 reads `env('SRS_ENABLED', true)`. If it already reads `false`, **stop — someone fixed it**; close the deliverable as no-op and report. |
| 2 | Change the default to `false`. |
| 3 | Rewrite the line 16–17 comment: cite **R-6**, state the reason is *baseline protection* (R20), not engine doubt, and note the H447 August-pilot rationale is superseded by R-5. Do not delete the H447 provenance. |
| 4 | Verify the line 10 docblock ("ВЫКЛ по умолчанию (в проде тоже)") now matches the code. It already asserts the target state — no edit expected. |
| 5 | Add `tests/Feature/Srs/SrsFlagDefaultTest.php` pinning **two** facts with no `SRS_ENABLED` in env: `config('srs.enabled') === false`, and `GET /dvaram/koloda` → 404. The route assertion is the one that matters — it is the student-visible surface R-6 protects. |
| 6 | Run the existing SRS suite. Tests that *presume* the feature is on must set the flag explicitly rather than rely on the default — fix by setting the flag in the test, never by reverting step 2. |
| 7 | Add a DEPLOY_QUEUE note under №24: the default is now `false` in code, so no prod `.env` step is needed to keep SRS dark; an explicit `SRS_ENABLED=true` is now required to surface it. |

> **Acceptance:** with no `SRS_ENABLED` set in the environment, `config('srs.enabled')` is
> `false` and `GET /dvaram/koloda` returns 404, pinned by a passing test in
> `tests/Feature/Srs/SrsFlagDefaultTest.php`; the full suite is green.

**Escalate if:** any test failure suggests a non-SRS surface depends on the flag being on
(that would mean SRS leaked outside its flag — a bigger finding than this deliverable).

---

## 2. W1-D1 — the GetCourse-parity production spec

The R-1 deliverable. **Output is a document**; write no application code.

| # | Step |
|---|---|
| 1 | Read [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) end to end. It is the analysis of record — **consume it; do not re-audit the code for gaps that document already located.** Re-deriving it is a defect. |
| 2 | Read [STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md) as the **template** (its five R29-making properties are enumerated in [ARCHITECTURE §2.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)). |
| 3 | Verify each GC-* ticket's **current** state against the tree before writing its row. Known as of 17-07-2026: **GC-B2 done** ([PR #444](https://github.com/gasyoun/Systema-Sanscriticum/pull/444), flag `attendance_dashboard` exists in [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php)); **GC-B1 `@DECIDE`-blocked**. Verify, do not assume. |
| 4 | Write `docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md` with the eight sections of [ARCHITECTURE §2.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md). |
| 5 | §2 must state the **money-core boundary rule** verbatim from [ARCHITECTURE §2.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md). This is the section that makes wave 2 agent-executable — without it every wave-2 step escalates. |
| 6 | §3/§4 reach production depth for **GC-C1 + GC-C2 only**. Confirm `mokhosh/filament-kanban` is still in `composer.json` and specify **reuse** — H438 already ruled it. |
| 7 | §5 data bill: the additive `deals`/`deal_stages`/`deal_transitions` shape from [ARCHITECTURE §2.4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md). No changes to `leads`/`payments`/`tariffs`. |
| 8 | §7: list every fork found **without resolving any**. Carry GC-B1 forward. Naming forks is the job; resolving them is a human's (PLAN §2.2). |
| 9 | §8: one handoff per step, with tier + reason. GC-C1 sits next to the money core → Opus/Fable. |
| 10 | Add a sibling `docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.meta.md` (genre-named doc → metadoc required; hook-warned otherwise). |
| 11 | Cross-link: add the spec to [ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) as the production spec of record, and register per [/artifact-propagate](https://github.com/gasyoun/claude-config/blob/main/commands/artifact-propagate.md). |

> **Acceptance:** `docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md` exists with all eight
> sections; every GC-* ticket appears exactly once with a verified state and an assigned
> wave; §2 states the money-core boundary rule; §7 lists ≥1 named fork and resolves none;
> a metadoc sibling exists; a human can start GC-C1 from §3 without re-reading the H438 roadmap.

**Escalate if:** the spec pass concludes GC-C1 is *not* the right wave-2 head. That
contradicts H438 §5.6 (a settled ruling) — record the reasoning, do not re-rule it.

---

## 3. W1-D5 — Memrise export runner *(time-critical, R-4)*

Ahead of the email work: the Memrise sunset has **no published date** and the loss is
irreversible. Everything else in this wave can wait a week; this might not be able to.

| # | Step |
|---|---|
| 1 | Read [the destination README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md) — it **already specifies** the `manifest.json` contract. Consume it. Do not redesign it; the importer reads it. |
| 2 | Read [`ImportMemriseSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportMemriseSrsDeck.php) to confirm exactly what the runner must emit — it looks columns up **by name** through the manifest's `columns` map, so the runner reports real header names rather than normalising them. |
| 3 | Check [SERVER_OUTAGES.md](https://github.com/gasyoun/Uprava/blob/main/SERVER_OUTAGES.md) for a live Memrise entry before any probe. |
| 4 | Write `scripts/memrise_export.py` per [ARCHITECTURE §6.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md): stdlib only; credential from `MEMRISE_SESSION` env, **never argv**; `--course`, `--out`, `--dry-run`; emits `manifest.json` + `level_NN.csv` + media; `sys.stdout.reconfigure(encoding='utf-8')`; UTF-8, no BOM. **Never commit a credential.** |
| 5 | Write `scripts/memrise_export_validate.py`: manifest parses; every `levels[].file` exists; each CSV header contains every column named in `columns`; no empty levels. Must run with no network and no credentials. |
| 6 | Prove the validator against [`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) — the fixture the importer is already tested against. This is the only end-to-end proof available without a login, which is exactly why it is the acceptance criterion. |
| 7 | Update the destination README: document the runner as the primary path and CourseDump2022 as the fallback; keep the "agent cannot do this unattended" statement — it is still true. |
| 8 | File the human `@DO` in [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md): *run the export with a Memrise login*, with the literal two commands (export, then validate). Mark it time-critical + irreversible. |

> **Acceptance:** `python scripts/memrise_export_validate.py tests/fixtures/memrise_sample/`
> exits 0; the same validator exits non-zero on a deliberately corrupted copy (a removed
> level file **and** a renamed column, checked separately); `scripts/memrise_export.py --help`
> documents `MEMRISE_SESSION`; no credential appears anywhere in the diff; a human `@DO` row
> exists carrying both literal commands.

**Do not:** implement any Memrise trainer scope (R-4: "trainer scope stays out") — P1–P6 of
[ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md) stay untouched. Do not attempt to obtain
credentials. Do not commit exported data if the human runs the export in-session without a
rights check — publishing rights are unruled (PLAN §2.2).

---

## 4. W1-D3 — ESP transactional transport

| # | Step |
|---|---|
| 1 | Read [issue #504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504). The diagnosis is **complete** — consume it, do not re-diagnose. |
| 2 | Fix `.env.example` per [ARCHITECTURE §4.2(a)](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md): keep mailpit for local (correct there), add the commented production shape adjacent. This single line is the repo-side root cause — prod was provisioned from this file. |
| 3 | Add the ESP driver package to `composer.json` (`symfony/*-mailer`-class, matching the mailer block already present in [`config/mail.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/mail.php)). Vendor-selectable via `.env`; **hardcode no vendor** — R-3 names a class, and the choice is a human `@DECIDE`. |
| 4 | Write `app/Console/Commands/MailPreflight.php` (`mail:preflight`) with the four checks in [ARCHITECTURE §4.2(b)](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md). Non-zero exit on failure so it can gate a deploy. `--send=<addr>` is opt-in and the only check that touches the network. |
| 5 | Test `tests/Feature/Mail/MailPreflightTest.php`: fails on `mailpit` host in a non-local env; fails on an `example.com` sender; passes on a plausible ESP config; warns when `QUEUE_CONNECTION` is not `sync`. Use config overrides — no network. |
| 6 | Write `docs/mail-esp.md`: required `.env` keys per driver class; the **SPF+DKIM+DMARC** requirement on the sender domain (without it mail lands in spam — this is what makes the ESP a fix rather than a config change); the `mailing`-queue worker requirement (#504 step 4 — every Mailable here is `ShouldQueue` on `mailing`, so no worker means no mail even with a perfect ESP). |
| 7 | Add a DEPLOY_QUEUE row: ESP `.env` keys → `php artisan config:clear` → `php artisan mail:preflight` → `php artisan mail:preflight --send=<addr>`. Mark 🚀 — it unblocks #504 and every W1-D4 email. |
| 8 | File the human `@DECIDE` in [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md): pick the vendor, create the account, install the secret. Record R-3's accepted costs (new vendor, cost line, prod secret) so the decision is made with them in view. |

> **Acceptance:** `php artisan mail:preflight` exits non-zero against the current
> `.env.example` values under a non-local env and prints the mailpit host as the reason;
> exits zero against a plausible ESP config; `tests/Feature/Mail/MailPreflightTest.php`
> passes; `docs/mail-esp.md` documents the keys, the SPF/DKIM/DMARC requirement and the queue
> worker; a DEPLOY_QUEUE row and a human `@DECIDE` row exist. **No credential in the diff.**

**Explicitly NOT the acceptance criterion:** "mail is delivered". An agent cannot reach that
state (PLAN §2.2, ARCHITECTURE §4.3). #504 stays open. Do not close it, and do not describe
this deliverable as fixing the student's lockout — it makes the fix *installable*.

---

## 5. W1-D4 — the five marathon Mailables

| # | Step |
|---|---|
| 1 | Read [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) — five emails, subject + preheader + body each. **This copy is ruled (H1067). Do not rewrite, improve, shorten, or add emoji.** Register «вы», no urgency devices; that is a deliberate anti-urgency design for an anxiety-sensitive audience. |
| 2 | Read [`app/Mail/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/README.md) for the house recipe, and [`NewsletterMagnetsMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/NewsletterMagnetsMail.php) as the reference implementation (`ShouldQueue` + `onQueue('mailing')` in the constructor). |
| 3 | Create the five Mailables of [ARCHITECTURE §5.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) — `MarathonWelcomeMail`, `MarathonDay1Mail`, `MarathonDay2Mail`, `MarathonDay3Mail`, `MarathonRecordingMail`. Each `implements ShouldQueue`, each `onQueue('mailing')`. |
| 4 | Create the five blades under `resources/views/emails/marathon/`. Placeholders exactly as ruled: `{link}`, `{tg_link}`, `{date}`, `{host}`, `{coupon}`, `{recording_link}` — same vocabulary as [`config/marathon.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon.php). **Invent no new placeholder.** |
| 5 | Test `tests/Feature/Mail/MarathonMailablesTest.php` with `Mail::fake()`: each renders without error; each carries the ruled subject; each queues on `mailing`; no unresolved `{placeholder}` survives rendering with a full data set. |
| 6 | Update [`app/Mail/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/README.md) with the five new entries, matching the existing per-class format. |
| 7 | **Wire no dispatch.** Per [ARCHITECTURE §5.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) the Mailables stay inert: enqueuing mail that cannot be delivered (W1-D3's gate) is worse than not sending. Telegram remains primary. |
| 8 | Add a DEPLOY_QUEUE note under №27: the five emails now have code paths and are blocked **only** on the ESP gate, not on authorship. |

> **Acceptance:** `tests/Feature/Mail/MarathonMailablesTest.php` passes — all five render, carry
> their ruled subjects, queue on `mailing`, and leave no unresolved placeholder; the copy in
> each blade matches [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) with no editorial change; no send site is wired.

**Escalate if:** the copy is missing a placeholder value the template needs (e.g. `{coupon}`
with no coupon source). That is a copy gap → a human `@DECIDE`; do not invent the value.

---

## 6. Cross-cutting close-out

After each deliverable, in the same pass:

1. **CHANGELOG** — `[Unreleased]` bullet under the right heading (the threshold is any
   durable artifact), then [/cut-release](https://github.com/gasyoun/claude-config/blob/main/commands/cut-release.md).
2. **`.ai_state.md`** — move the item to Completed. Correct the stale H822/H962 rows while
   there (§0).
3. **Hub sweep** — [/artifact-propagate](https://github.com/gasyoun/claude-config/blob/main/commands/artifact-propagate.md).
4. **Findings** — W1-D2's flag/doc divergence is a reusable pattern (three docs asserting a
   default the code contradicts) → [/findings-append](https://github.com/gasyoun/claude-config/blob/main/commands/findings-append.md).
5. **PR** — one per deliverable. Per the org's handoff rule: commit → PR → merge without a
   confirmation ask once a handoff exists.

_Dr. Mārcis Gasūns_
