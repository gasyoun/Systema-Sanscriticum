# FINDINGS.md — Systema-Sanscriticum

_Created: 28-08-2026 · Last updated: 28-08-2026_

Local registry for **product, money-contour and in-app-tooling gotchas** that belong to this
repo and to no hub. Created under ruling **F1** of the spine-interconnection programme
([ASK_BATCH_STAGING_REPO_INTERCONNECTION_2026-08.md](https://github.com/gasyoun/Uprava/blob/main/ASK_BATCH_STAGING_REPO_INTERCONNECTION_2026-08.md)
Phase 2), which gives a local `FINDINGS.md` to exactly four repos and a `CLAUDE.md` pointer line
to the other eight. This repo is one of the four.

## Routing — what lands here and what does not

| Kind of finding | Home |
|---|---|
| Product, payout/money-contour, cabinet or in-app-engine gotcha | **this file** |
| Sanskrit data, encodings, transliteration, dictionary text | [SanskritLexicography/FINDINGS.md](https://github.com/gasyoun/SanskritLexicography/blob/master/FINDINGS.md) |
| Org infrastructure — hooks, worktrees, handoff machinery, CI runners, servers | [Uprava/FINDINGS.md](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md) |
| A reusable helper, engine or masker | [github-spine/SHARED_CODE.md](https://github.com/gasyoun/github-spine/blob/main/SHARED_CODE.md) |

No other registry lives here: ruling F1 explicitly withholds the seven epistemic registries
(ASSUMPTIONS · CONTRADICTIONS · GAPS · DEAD_ENDS · RECIPES · STALENESS · GLOSSARY) from the
middle tier. Append with [`/findings-append`](https://github.com/gasyoun/claude-config/blob/main/commands/findings-append.md).

**No personal data here.** Findings about payouts describe the *mechanism* only — no recipient
name, no rate, no sum. Those live in the generated config and in the private hub, and a finding
that needs one to be understood is written wrong.

---

## §1 — the payout canon is era-scoped: a flat bank slice silently rewrites history

**Back-filled 28-08-2026 from H3531/H3532
([PR #2105](https://github.com/gasyoun/Systema-Sanscriticum/pull/2105)).**

[`app/Services/Payroll/PayrollRateCalculator.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payroll/PayrollRateCalculator.php)
is the single canonical implementation of «на руки». Three properties of it are easy to lose and
each loss is silent — the numbers still come out, they are just wrong:

1. **The bank slice is a property of the rate *period*, not of the formula.** It applies only
   when the period being evaluated carries a `bank_slice_pct`. Periods from the pre-October-2025
   era carry none, deliberately. Hard-coding the reference slice into a report, a forecast or a
   backtest re-prices every historical period that predates it.
2. **The self-employment tax (НПД) is an annotation on the payout *step*, never a term inside
   gross.** Folding it into the calculator's output double-counts it the moment the payout is
   posted, because the posting layer annotates it again.
3. **Rate-period resolution is last-`from`-wins, with a concrete fixed amount beating an open
   percentage era at an equal `from`.** A naive "first match" or "widest range" lookup picks a
   different period for exactly the dates where a recipient's terms changed.

The calculator is pure — no DB, no network — precisely so backtest, forecast and the weekly
calendar feed it identical inputs and cannot drift apart. Call it; do not re-derive the formula
in a command, an exporter or a Filament page.

**And do not hand-edit the config.**
[`config/teacher_rates.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/teacher_rates.php)
is *generated* from an Uprava source-of-record timeline by
[`tools/gen_teacher_rates_config.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/gen_teacher_rates_config.py)
and carries the source hash in its `meta` block. A hand-edit survives until the next regeneration
and then vanishes without a conflict — fix the upstream timeline and regenerate.

---

## §2 — a green `mic-vendor-drift` does **not** mean the vendored tree matches its pin

**Back-filled 28-08-2026 from
[`.github/workflows/ci.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/ci.yml)
job `mic-vendor-drift` +
[`tools/check_mic_vendor_drift.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/check_mic_vendor_drift.py).**

The upstream [message-intent-classifier](https://github.com/gasyoun/message-intent-classifier) is
a **private** repo, and the workflow's `GITHUB_TOKEN` cannot read it. The job is therefore built
in two halves and only one of them runs today:

| Leg | Runs when | Effect when it cannot run |
|---|---|---|
| Upstream tree byte-parity against `PINNED_SHA` | only if the `MIC_UPSTREAM_TOKEN` Actions secret is set | prints `::warning::upstream tree parity NOT verified` and continues |
| Generated-JSON freshness + pin format sanity | always | — |

The trap is the success line. When the parity leg is skipped the job still finishes with

> `vendored snapshot matches pin <sha12>; generated JSON fresh (N rule files)`

— a sentence that asserts exactly the thing that was never checked. As of 28-08-2026 the secret
is not set, so **every green run of this job to date has verified JSON freshness only**. Treat
"drift check passed" as "the JSON twins are fresh"; to actually verify the pin, check the tree
out by hand and run the script with `--upstream`.

Setting the fine-grained read-only PAT as `MIC_UPSTREAM_TOKEN` (contents:read on that one repo)
turns the full gate on with no code change.

---

## §3 — `support:rules-sync` is a partial sync, and the runtime rule set is a union

**Back-filled 28-08-2026 from H3529
([`app/Console/Commands/SupportRulesSync.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SupportRulesSync.php)).**

Two things about the command are counter-intuitive and both are deliberate:

1. **It reads `rules/v1/*.json`, never the `*.yaml` upstream calls canonical.** `symfony/yaml` is
   not a direct dependency of this app — it arrives only transitively through a `require-dev`
   package — so a production `composer install --no-dev` has no YAML parser at all. The JSON
   twins are generated by
   [`tools/gen_mic_rules_json.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/gen_mic_rules_json.py).
   Editing a vendored `.yaml` without regenerating ships nothing: the sync globs `*.json` and the
   stale twin is what reaches the database.
2. **It never touches legacy rows.** Rows with `pattern_hash IS NULL` predate the package and are
   left alive by design; rules that vanish from the package are *disabled*, not deleted. So the
   live rule set is **the pinned package ∪ an untouched legacy tail**, and a re-run is idempotent
   over the package half only. Reading `support_topic_rules` and concluding "these are the
   vendored rules" is wrong in both directions — there is a tail the package does not explain,
   and disabled rows the package no longer contains.

The sync writes `support_topic_rules` and nothing else: auto-replies, templates, payments and
webhook code are outside its fence (H3529). Contract of record:
[`docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md)
§ Потоки данных.

_Dr. Mārcis Gasūns_
