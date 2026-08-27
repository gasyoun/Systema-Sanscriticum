# Plan — Systema-Sanscriticum interconnection, 2026-08

_Created: 26-08-2026 · Last updated: 26-08-2026_

Systema-Sanscriticum's slice of the spine-interconnection programme. Programme index:
[PLAN_SPINE_INTERCONNECTION_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SPINE_INTERCONNECTION_2026H2.md).

Architecture and verification are **not** restated here (ruling F13) — they are identical for
all fourteen repos and live once in Uprava:

- [ARCHITECTURE_SPINE_INTERCONNECTION.md](https://github.com/gasyoun/Uprava/blob/main/docs/ARCHITECTURE_SPINE_INTERCONNECTION.md) — the five attachment points and the rules governing them
- [IMPLEMENTATION_SPINE_INTERCONNECTION_W1.md](https://github.com/gasyoun/Uprava/blob/main/docs/IMPLEMENTATION_SPINE_INTERCONNECTION_W1.md) — execution order, per-handoff steps, isolation, risks
- [VERIFICATION_SPINE_INTERCONNECTION.md](https://github.com/gasyoun/Uprava/blob/main/docs/VERIFICATION_SPINE_INTERCONNECTION.md) — the five gates and what "done" means

**Nothing here has executed.** The handoff below is 🟡 queued and runs only when a human
launches it.

## Why Systema-Sanscriticum is in scope

The thinnest-wired Tier-0 repo by a wide margin: 499 authored commits in thirty days against 8 PROJECT_INTERLINKS rows and 2 SHARED_CODE rows, and zero spine back-links in its README. It carries 469 documents, almost none of them visible to any other repo.

## Measured baseline and target

| | Value |
|---|---|
| Wiring score, 26-08-2026 | **52** / 100 |
| Target after this plan | **74** / 100 |
| How the target is reached | +2.5 for the local FINDINGS, +8 for README hub links, +6 for three SHARED_CODE rows, ~+6 for the corpus feed rows. |

Measured by [`tools/interconnection_audit.py`](https://github.com/gasyoun/Uprava/blob/main/tools/interconnection_audit.py); full row in
[data/interconnection_audit_2026-08-26.json](https://github.com/gasyoun/Uprava/blob/main/data/interconnection_audit_2026-08-26.json);
report [AUDIT_REPO_INTERCONNECTION_2026-08-26.md](https://github.com/gasyoun/Uprava/blob/main/docs/AUDIT_REPO_INTERCONNECTION_2026-08-26.md).

The score counts artefacts, not whether they are true. It is **report-only** by ruling F2 and no
handoff closes on it — verification Gates 2 to 4 are what actually decide, and Gate 4 is read by
a human.

## Rulings that apply here

| Fork | Ruling |
|---|---|
| F3 | Systema registers its reusable engines as SHARED_CODE families plus the frozen ORS corpora as rights-noted feeds. |
| F1 | Local `FINDINGS.md` in exactly four repos; the other eight get a `CLAUDE.md` pointer line. No repo gains the other seven registries. |
| F11 | Every repo with no spine back-links gains a "How this repo is wired" README section. |

Full rulings table with every fork:
[ASK_BATCH_STAGING_REPO_INTERCONNECTION_2026-08.md](https://github.com/gasyoun/Uprava/blob/main/ASK_BATCH_STAGING_REPO_INTERCONNECTION_2026-08.md) Phase 2.

## What this plan does

1. Register the three reusable engines as SHARED_CODE families — the message-intent-classifier package, the teacher-payout formula service, the CabinetMastery book engine (F3). Product docs stay out.
2. Register the frozen ORS eval/train dialogue corpora as consumer-side PROJECT_INTERLINKS feeds, each row carrying its rights and masking note **inline**, masked snapshot only, no sample quoted (F3, Gate 5).
3. Create a local `FINDINGS.md` with at least **two real findings** back-filled from Systema's own history (F1). If two cannot be produced, drop the file and take the pointer line — and record that choice.
4. Add the "How this repo is wired" README section (F11).

## Handoff

- [H3562 (Opus 5) — interconnect systema engines corpora findings](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3562-Opus_Systema-Sanscriticum_interconnect-systema-engines-corpora-findings_26.08.26.md) · hard · 🟡 queued

## Autonomy contract

The launching agent may create the files named above, add hub rows, open and merge its PR,
remove its worktree and close its handoff row — without asking.

It must stop and ask if a local `FINDINGS.md` cannot be given two genuine findings (the
documented fallback is to drop the file and take the pointer line, recorded not silent), if a
corpus row would carry an unmasked snapshot or quote a sample, or if a second speculative edge
becomes necessary. It must never turn the wiring score into a failing gate, commit to
`csl-orig`, or add the seven non-FINDINGS registries.

## Open @DECIDE

None. Every fork touching Systema-Sanscriticum was ruled in sitting 1 on 26-08-2026, so the autonomy gate
passes and nothing in the wave-1 path stalls on a human.

_Dr. Mārcis Gasūns_
