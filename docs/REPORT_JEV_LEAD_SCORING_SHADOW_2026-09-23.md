# Jev Noul lead-scoring shadow — NO-GO: the frozen conversion cohort is empty

_Created: 23-09-2026 · Last updated: 23-09-2026_

_Generated: 2026-09-23 · model `jev-1.13.0` (Noul primitive, purchase probability) · H5280 · zero external API calls made, $0 spent_

**SHADOW ONLY** — no prod wiring, no CRM changes, no live writes. The mission: score pseudonymized
inquiry texts with Noul (purchase probability) against the frozen conversion cohort, then report
AUC / calibration / Brier / cost per 1000. The measurement never ran because its truth source has
no rows. This report is the durable NO-GO note (same verdict shape as the H5274 sufler bench).

## Verdict

**NO-GO — measurement impossible on current data.** The frozen conversion cohort
(Uprava `data/money/trial_cohort_*.tsv`, every weekly snapshot) is **header-only — zero trial
Deals exist in production**. No alternative inquiry-text surface carries a two-class conversion
outcome today. Revisit when the preconditions in §4 exist.

## 1. The frozen cohort has nothing to score

| Evidence | Result |
|---|---|
| `trial_cohort_21-09-2026.tsv` … `30-08-2026.tsv` (all 6 snapshots) | 1 line each = header only, 0 data rows |
| [TRIAL_COHORT_REPORT_21-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/reports/TRIAL_COHORT_REPORT_21-09-2026.md) | «**Zero trial Deals in production as of 21-09-2026.** … no free widget booking and no paid trial after the flip» |
| Live prod probe (read-only, 23-09-2026): `deals WHERE kind='trial'` | **0 rows** |

## 2. Every candidate inquiry-text surface, probed live (read-only SSH 193.232.229.92, 23-09-2026)

| Surface | Rows | Inquiry texts? | Conversion outcome? | Usable? |
|---|---|---|---|---|
| `waitlist_entries` (заявки на набор — the «листы обзвона» surface) | **0** | — (would have: `notes`, `prior_group_note`, `preferred_schedule`, `timezone_note`) | `status` incl. `enrolled` | **No — empty table** |
| `deals` kind=`trial` | **0** | no text field | `trial_outcome='converted'` | **No — empty** |
| `course_interest_requests` | **0** | — | — | **No — empty** |
| `leads` | 345 (35 `status='converted'`, 37 `converted_at`) | **no free-text inquiry field** (name/contact/email/social/UTM only — PII, fenced) | yes (CRM status) | **No — no text to score** |
| `lead_notes` | 69 | 26 manual/dm texts, **all on `status='new'` leads** (note/new=5, dm/new=21); email rows are outbound autologs | via parent lead | **No — single class: zero texts on converted leads → AUC undefined** |
| `chat_messages` | 267 | yes (support dialogs) | requires a join to the payments/access surface | **Not attempted — money-contour fence (§3)** |
| `course_waitlist_items` | 26 | no (course candidates, not person inquiries) | n/a | **No** |

Raw probe outputs: [`reports/jev-lead-scoring-cohort-probe-2026-09-23.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/jev-lead-scoring-cohort-probe-2026-09-23.json).

## 3. Fences respected this unit

- **152-FZ:** no names/phones/emails were ever SELECTed, let alone sent anywhere; **zero TypeSafe
  API calls were made** — the `~/.secrets/typesafe.env` key was not read this unit (it was probed
  live by H5275/H5274 earlier the same day). Stenogrammy surfaces untouched, as always.
- **Money contour:** two attempts to touch payment-linked data (a `payments`-joining SELECT, then a
  read of the frozen `payments_export_25-08-2026.tsv` from this repo worktree) were **both stopped
  by the repo `money-contour-write-confirm` guard** and were NOT retried or routed around. A
  chat-text→purchase join, if ever wanted, goes through the `/money-pr-land` discipline or an
  explicit human ruling — not through a shadow bench.
- **No CRM changes, no payout rows, no live writes** — every prod command in this unit was `SELECT`.

## 4. Preconditions for a rerun (any one path)

1. **Trial pipeline produces Deals** — the cohort builder
   ([Uprava `tools/trial_cohort_report.py`](https://github.com/gasyoun/Uprava/blob/main/tools/trial_cohort_report.py))
   regenerates monthly once rows exist; texts would still need a text-bearing booking field.
2. **An intake fills `waitlist_entries`** — the model already carries both texts and a status
   outcome (`enrolled`); ~60+ entries across both classes is the honest minimum for an AUC that
   isn't noise. This is the cheapest path: no schema change, no money surface.
3. **A human ruling authorizes a chat-text→purchase join** through `/money-pr-land` — then
   `chat_messages` (267 rows) becomes scorable and the bench driver from H5274
   ([`tools/bench_jev_sufler.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/bench_jev_sufler.py), reusing
   [Uprava `tools/jev_probe.py`](https://github.com/gasyoun/Uprava/blob/main/tools/jev_probe.py))
   is the pattern to extend — not rebuild.

## 5. Cost ceiling (theoretical — no calls made)

At the vendor rate $0.042/1M input tokens, a ~200–400-token lead-scoring Noul call prices at
**≈ $0.002–0.017 per 1000 inquiries**. The measured sibling anchor: H5274's sufler calls averaged
$1.825e-05/call (≈ $0.018/1000). Cost is not the blocker; **data is the blocker**.

## Delivery (five fields)

- **Changed:** nothing in code — this unit adds only this report + the probe evidence JSON + a
  changelog-queue entry. No schema, no prod, no wiring.
- **Unchanged:** every prod surface (all probes read-only); the Jev pilot verdicts from H5274
  (NO-GO) and H5275 (client wrapper) stand untouched.
- **Checks:** the probes above are the evidence; reproducible one-liners are embedded in
  [`reports/jev-lead-scoring-cohort-probe-2026-09-23.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/jev-lead-scoring-cohort-probe-2026-09-23.json).
- **Risks:** the empty cohort is today's state, not a permanent fact — the trial pipeline is live
  since 24-08-2026 and the next intake season can fill `waitlist_entries`; a rerun is cheap once
  either produces rows. Do not read this NO-GO as «Noul can't score leads» — it was never measured.
- **Inspect:** this file, §1–§2.

_Dr. Mārcis Gasūns_
