# Architecture — GetCourse-parity Wave 1

_Created: 17-07-2026 · Last updated: 17-07-2026_

The design for the five wave-1 deliverables. Index:
[PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md).
Authored by Opus 4.8 (`claude-opus-4-8[1m]`) against `origin/main` at `16ef950`
(fetched 17-07-2026). Every path below was read before being specified against.

**Standing constraints.** Laravel 12 app; one Filament admin; feature flags in
[`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php);
additive migrations only; the money core
([`PaymentObserver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentObserver.php),
`Tariff`, revenue recognition) is untouchable in this wave. Agents hold no prod access —
anything needing the server becomes a
[DEPLOY_QUEUE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) row.

---

## 1. Deliverable map

| ID | Touches | New vs. modified |
|---|---|---|
| W1-D1 | `docs/` only | 1 new doc |
| W1-D2 | [`config/srs.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/srs.php), `tests/` | 1-line default + 1 new test |
| W1-D3 | [`config/mail.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/mail.php), `.env.example`, `app/Console/Commands/`, `docs/` | new command + config wiring |
| W1-D4 | `app/Mail/`, `resources/views/emails/marathon/`, `tests/` | 5 Mailables + 5 templates |
| W1-D5 | `scripts/` or `app/Console/Commands/`, `tests/` | new runner + validator |

None share a file. All five are independently landable.

---

## 2. W1-D1 — the GetCourse-parity production spec

The R-1 deliverable. **A document, not code.** R-1 requires an "R29-equivalent", so the
design question is: what makes R29 an R29?

### 2.1 What R29 actually is — the template

Reading
[STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md),
five properties make it production-grade where a roadmap is not:

1. **A settled composition table** (§1, R29.0–R29.8) — each organ named, sourced, and
   described as it will exist, not as an option.
2. **A precedence rule that resolves conflicts between organs** (§1, offer precedence) — the
   thing a roadmap never has and a builder always needs.
3. **A page/surface map expressed as deltas against a reference implementation** (§2).
4. **A data/engineering bill** (§3) — the concrete predicates and stores implied.
5. **A sequence where each step is its own handoff** (§6).

### 2.2 The output

`docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md`, mirroring those five properties over the
GC-* tickets. **Scoping ruling inside the design:** the spec covers the parity *programme*
composition at table level, but reaches production depth for the **wave-2 head only** —
GC-C1 (`Deal` + kanban) and GC-C2 (manager attribution). Specifying quizzes and marketing to
production depth now would be speculative: they sit two waves out and their inputs will have
moved. R29 did the same thing (R29.6 explicitly scopes to "this one ladder only — no full
curriculum graph").

Required sections:

| § | Content | Source consumed |
|---|---|---|
| 1 | Composition table — every GC-* ticket → wave, with state (done / blocked / specced) | H438 roadmap §3 |
| 2 | **Precedence + boundary rules** — the R29-equivalent of offer precedence (§2.3) | new judgment |
| 3 | GC-C1 production detail — `Deal` model, stages, kanban, `PaymentObserver` bridge | H438 §3 Domain C |
| 4 | GC-C2 production detail — attribution surface | H438 §3 Domain C |
| 5 | Data/engineering bill | new |
| 6 | Flag plan — one flag per ticket, all default OFF | [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) |
| 7 | **Open `@DECIDE` forks** — named, not resolved | new |
| 8 | Sequence — one handoff per step | R29 §6 shape |

### 2.3 The boundary rule the spec must state

R29's load-bearing invention was offer precedence. The parity equivalent — the rule that
makes GC-C1 safe next to the money core:

> **The `Deal` layer observes the money core and never authorises it.** `Payment` success
> closes a `Deal` (read by `PaymentObserver`'s existing hook point, additively). A `Deal`
> never grants access, never sets a price, never reverses a payment. If a `Deal` and a
> `Payment` disagree, the `Payment` is right and the `Deal` is stale. `Lead` keeps its
> current meaning (person/interest, 5 statuses, auto-convert on payment) untouched.

This mirrors GC-D3's already-ruled money-core rule ("педагогический гейт проверяется ПОСЛЕ
денежного и никогда не расширяет доступ, только сужает"), generalised. Stating it once in
the spec is what lets wave 2 be executed by an agent without escalating every step.

### 2.4 Data shape sketch (spec-level, not migration-level)

```
deals
  id, lead_id -> leads.id, user_id -> users.id NULL, course_id -> courses.id NULL,
  amount, currency, stage_id -> deal_stages.id, assigned_to -> users.id NULL,
  closed_at NULL, closed_reason NULL ('won'|'lost'|...), timestamps
deal_stages
  id, key, name, position, is_won, is_lost      -- stages in data, per H438 §3 GC-C1
deal_transitions
  id, deal_id, from_stage_id NULL, to_stage_id, user_id, created_at
```

All additive; nothing alters `leads`, `payments`, or `tariffs`. Per H438: open `Lead`s are
**not** mass-converted — `Deal` is created going forward only.

---

## 3. W1-D2 — SRS default restoration

### 3.1 The defect, precisely

[`config/srs.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/srs.php):

```php
// line 10 (docblock):  `enabled` ВЫКЛ по умолчанию (в проде тоже)
// line 18:
'enabled' => (bool) env('SRS_ENABLED', true),
```

The docblock and the code disagree **in the same file**. The default is `true`.

### 3.2 Blast radius — why this is a wave-1 defect and not a nit

`config('srs.enabled')` is read at five sites:

| Site | Effect when `true` |
|---|---|
| [`routes/web.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) line 261 | registers `/dvaram/koloda` + `/dvaram/koloda/stats` |
| [`SrsController::review()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/SrsController.php) line 24 | serves instead of 404 |
| `SrsController::stats()` line 38 | serves instead of 404 |
| [`app/Livewire/SrsReview.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Livewire/SrsReview.php) line 29 | serves instead of 404 |
| [`resources/views/layouts/student.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/layouts/student.blade.php) line 104 | **renders the SRS menu item to every student** |

The last one is the R-6 violation in the literal sense: absent an explicit
`SRS_ENABLED=false` in prod `.env`, the next deploy puts an SRS entry in the student nav —
"surfacing it mid-baseline", which R-6 forbids because it corrupts the R20 measurement.

Three documents assert the opposite of the code:

- the same file's docblock, line 10;
- [`routes/web.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) line 260 — "в проде OFF";
- [DEPLOY_QUEUE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) №24 — "SRS-движок в целом всё ещё за `SRS_ENABLED=false`".

Provenance: flipped by H447 Phase 1 ([PR #442](https://github.com/gasyoun/Systema-Sanscriticum/pull/442), commit `6267d70`) with the in-code justification "движок протестирован, пилот август-2026" — an August pilot that R-5 has since moved and R-6 has since darkened. The flip was reasonable when made and is wrong now.

### 3.3 The fix

```php
'enabled' => (bool) env('SRS_ENABLED', false),
```

Plus: correct the line 16–17 comment to record R-6 and *why* (baseline protection, not
engine doubt — the engine is fine and stays fine), and pin the default with a test so it
cannot silently flip again. **No other site changes** — all five readers already behave
correctly when the flag is false; that is the whole point of a flag.

**Not in scope:** deleting SRS code, touching the FSRS engine, the seeder, or `kosha_srs`
(already correctly `false`). R-6 darkens the surface; it does not retract the work.

---

## 4. W1-D3 — ESP transactional transport

### 4.1 What is actually broken

[Issue #504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) diagnosed it: prod
`.env` carries `MAIL_HOST=mailpit` — a dev mail-catcher with no outbound relay, so every
mail is swallowed. A paying student cannot reset her password.

The repo-side root cause is one line in **`.env.example`**:

```
MAIL_MAILER=smtp
MAIL_HOST=mailpit          # <- prod inherited this
MAIL_PORT=1025
MAIL_FROM_ADDRESS="hello@example.com"
```

Prod was provisioned from this file. **This is the part an agent can actually fix**, and it
is why the same failure would recur on the next server rebuild even after a human patches
prod `.env` by hand.

### 4.2 Design

R-3 says "external ESP", and names a *class* of vendor (Unisender/SendGrid/Mailgun-class) —
not one vendor. So the code must be vendor-selectable, and the vendor choice stays a human
`@DECIDE` (PLAN §2.2).

Laravel's mail layer already supports this: [`config/mail.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/mail.php)
ships `mailgun`, `ses`, `postmark` mailer blocks and a `failover` transport. **The transport
abstraction needs no invention** — only a driver package, a documented `.env` shape, and a
guard so the dev default can never reach prod again.

Three parts:

**(a) `.env.example` — stop shipping the bug.** Keep local dev on mailpit (it is correct for
local), but make the production shape explicit and adjacent, so provisioning cannot silently
inherit dev:

```
# Local dev: mailpit catches everything, nothing leaves the machine.
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_FROM_ADDRESS="hello@example.com"
# PRODUCTION: must NOT be the above. See docs/mail-esp.md.
# MAIL_MAILER=<esp-driver>
# MAIL_FROM_ADDRESS="<verified sender on an SPF+DKIM+DMARC domain>"
```

**(b) `MailPreflightCommand` — the guard.** New `app/Console/Commands/MailPreflight.php`,
signature `mail:preflight`. Deterministic, no network by default:

| Check | Fails when |
|---|---|
| dev host in non-local env | `app()->environment() !== 'local'` and `config('mail.mailers.smtp.host')` matches `mailpit`/`localhost`/`127.0.0.1` |
| placeholder sender | `config('mail.from.address')` ends in `example.com` |
| queue reachable | `QUEUE_CONNECTION` is not `sync` — warn that a worker must consume `mailing` (per #504 step 4; all Mailables here are `ShouldQueue` on the `mailing` queue) |
| `--send=<addr>` (opt-in) | performs one real `Mail::raw` send |

Exit non-zero on failure so it can gate a deploy. This is the durable form of #504's manual
tinker checklist — it turns a one-off diagnosis into a standing check.

**(c) `docs/mail-esp.md`** — the vendor-agnostic setup contract: required `.env` keys per
driver class, the SPF/DKIM/DMARC requirement (mail without it lands in spam — this is what
makes the ESP an actual fix rather than a config change), and the `mailing`-queue worker
requirement. Plus a DEPLOY_QUEUE row.

### 4.3 The honest boundary

An agent delivers (a), (b), (c) and can prove all three in tests. It **cannot** create the
vendor account, cannot hold the secret, cannot run `config:clear` on prod. #504 closes when a
human does that. The deliverable's acceptance criterion is written to that boundary
([IMPLEMENTATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_GETCOURSE_PARITY_WAVE1.md) §4) — it does not claim mail is delivered.

---

## 5. W1-D4 — the five marathon Mailables

### 5.1 What exists

The copy is **written and ruled**:
[marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md)
(H1067, Fable 5 `claude-fable-5`) — five emails, each with subject, preheader, body.
That file also specifies its own delivery contract:

> по одному Mailable-классу на письмо (`app/Mail/Marathon*`, шаблоны
> `resources/views/emails/marathon/`, очередь `mailing`)

Verified against the tree: **no `app/Mail/Marathon*` exists** and **no
`resources/views/emails/marathon/` exists**. The copy has no code path. That is the gap.

### 5.2 Design

Follow the house recipe in [`app/Mail/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/README.md) exactly — this is a well-trodden pattern in the repo (19 existing Mailables), so nothing here is novel:

| Class | Template | Trigger (copy source) |
|---|---|---|
| `MarathonWelcomeMail` | `emails/marathon/welcome.blade.php` | on enrolment |
| `MarathonDay1Mail` | `emails/marathon/day1.blade.php` | Day 1 anchor |
| `MarathonDay2Mail` | `emails/marathon/day2.blade.php` | Day 2 anchor |
| `MarathonDay3Mail` | `emails/marathon/day3.blade.php` | Day 3 / consultation |
| `MarathonRecordingMail` | `emails/marathon/recording.blade.php` | after the broadcast |

Each: `extends Mailable implements ShouldQueue`, `$this->onQueue('mailing')` in the
constructor — matching [`NewsletterMagnetsMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/NewsletterMagnetsMail.php) and [`PasswordResetMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PasswordResetMail.php).

**Placeholders** are fixed by the copy doc and must not be reinvented: `{link}` (personal day
page via `magnet_token`), `{tg_link}`, `{date}`, `{host}`, `{coupon}`, `{recording_link}` —
the same vocabulary as [`config/marathon.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon.php).

### 5.3 Wiring — deliberately inert

The Mailables are **built but not dispatched** in wave 1. The Telegram drip
([`DeliverDueMarathonContent`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DeliverDueMarathonContent.php))
remains the primary channel, exactly as the copy doc states ("до починки единственный
рабочий канал доставки это Telegram... Письма — дублирующий канал на будущее").

Reason: dispatching mail that cannot be delivered (W1-D3's gate) would enqueue jobs that fail
or silently vanish — worse than not sending. Wiring the send is a wave-2 step, taken *after*
a human closes the ESP gate. The wave-1 deliverable is **five renderable, tested,
queue-correct Mailables** ready to wire.

---

## 6. W1-D5 — Memrise export runner

### 6.1 What exists — nearly everything except the pull

| Layer | State |
|---|---|
| Importer `srs:import-memrise` | **built + tested** — [`ImportMemriseSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportMemriseSrsDeck.php), manifest-driven, `--dry-run` |
| Manifest contract | **specified** — [the destination README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md) |
| Test fixture | **exists** — [`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) |
| Destination dir | **exists, empty on purpose** — `database/seeders/data/memrise_6679375/` (README only) |
| **The export itself** | **missing** — never run |

The README states the blocker in writing: the pull needs
[CourseDump2022](https://github.com/Eltaurus-Lt/CourseDump2022) driven by a human with a
Memrise login, "or a scripted pull with Memrise credentials. Neither is something an agent
session can do unattended."

**So the deliverable is not "export" — an agent cannot export.** It is: *make the human's
step as small and as verifiable as possible, right now, because the window may close.*

### 6.2 Design

**(a) `scripts/memrise_export.py`** — stdlib-only (no new dependency), takes a session
cookie or credentials from the environment, never from argv (argv leaks into shell history):

```
MEMRISE_SESSION=<cookie>  python scripts/memrise_export.py --course 6679375 \
    --out database/seeders/data/memrise_6679375
```

Emits exactly the contract the importer already reads: `manifest.json` + `level_NN.csv` per
level + media alongside. **The manifest contract is consumed, not redesigned** — the importer
reads columns by name via the manifest's `columns` map, so the runner's job is to *report*
the real column names it found, not to normalise them. `--dry-run` prints the discovered
level/column inventory and writes nothing.

Per the README: **keep media** (audio/images) even though audio is deferred (D4) —
re-fetching after sunset may be impossible. Irreversibility is the whole justification (R-4).

Windows/encoding house rule: `sys.stdout.reconfigure(encoding='utf-8')`, UTF-8 without BOM.

**(b) `scripts/memrise_export_validate.py`** — validates a directory against the manifest
contract *without* Memrise access: manifest parses, every `levels[].file` exists, each CSV's
header row contains every column named in `columns`, no empty levels. Runnable by the human
immediately after the pull, and runnable in CI against the fixture.

Value of (b): it closes the loop the human cannot otherwise close. Without it, a botched
export is discovered at import time — possibly after the course is gone.

### 6.3 The honest boundary

The runner is **untestable against live Memrise** by an agent (no credentials) and **may need
adjustment** on first human run if Memrise's response shape differs from the documented API.
Mitigation, and the reason this is still worth shipping now: the validator + the existing
fixture prove the *output* contract without any network, so a first-run mismatch shows up as
a clear validator error at a known step — not as silent data loss. If the scripted pull
fails outright, CourseDump2022 remains the fallback and the validator still checks its
output, so the deliverable retains its value either way.

---

## 7. What this wave does not touch

`app/Services/Payment*` · `PaymentObserver` · `Tariff` · revenue recognition ·
[`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php) defaults other than W1-D2's ·
`RQ4_STUDY` (R-5) · R29 phases (Front B, separate handoffs) · the FSRS engine ·
`app/Models/Lead.php` · H1068 copy surfaces (D1 open) · anything on prod.

_Dr. Mārcis Gasūns_
