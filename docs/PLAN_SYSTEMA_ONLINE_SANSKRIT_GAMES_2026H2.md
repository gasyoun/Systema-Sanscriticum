# PLAN — Online Sanskrit Games (Systema assets) · 2026H2

_Created: 26-07-2026 · Last updated: 26-07-2026_

**Goal.** Turn Systema’s real pedagogical assets (static exercise engines, dictionary/SRS fixtures, frequency roots, lead-magnet funnel, Sanskrit-HUB ladder) into a **tiered portfolio of online games**: Wave 1 free A0 funnel on `/exercises` + csl-guides wrappers; Wave 2 cabinet skill drills + register→SRS onboarding; Wave 3 hub pedagogy and any net-new engines. This `/ask` pass authors the layered plan and invent catalogue only — **no product code ships in this session**.

**Execution index for future agents:** start here, then the four layer docs below.

| Layer | Doc |
|---|---|
| Roadmap (waves) | [ROADMAP_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md) |
| Architecture | [ARCHITECTURE_SYSTEMA_ONLINE_SANSKRIT_GAMES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ONLINE_SANSKRIT_GAMES.md) |
| Implementation (Wave 1 ordered) | [IMPLEMENTATION_SYSTEMA_ONLINE_SANSKRIT_GAMES_WAVE1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ONLINE_SANSKRIT_GAMES_WAVE1.md) |
| Verification + risks | [VERIFICATION_SYSTEMA_ONLINE_SANSKRIT_GAMES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ONLINE_SANSKRIT_GAMES.md) |
| Invent catalogue (≥15) | § Invent catalogue in the ROADMAP doc (three sections) |
| Metadoc | [PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.meta.md) |

---

## Decisions taken (interview 26-07-2026)

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| D1 | North star | **Balanced portfolio, tiered** | Funnel → retention → hub pedagogy across waves; no single KPI kills the others. |
| D2 | Wave-1 player | **A0 complete beginners** (primary market RU; UI RU+EN toggles per D12) | Matches Aug 2026 marathon cohort + lead-magnet ЦА. |
| D3 | This `/ask` deliverable | **Full multi-wave roadmap only — no build** | Docs + invent list + deferred handoffs; execution later behind Tier-0 money/GC. |
| D4 | Engine policy | **Extend existing engines first** | sort / match / cloze / ligatures / roots already ship offline. |
| D5 | Wave-1 fence | **No net-new engines · no audio · no multiplayer** | Strictest fence; needs-engine ideas park to Wave 3+. |
| D6 | Wave-1 success metric | **Play → register**, refined to **≥15% of CTA clickers complete registration** | Mid-funnel KPI; baseline still needed for full play→register. |
| D7 | Product surface | **Systema `/exercises` + csl-guides LM thin wrappers** | Dual acquisition; Systema is canonical (D11). |
| D8 | Free drills ↔ SRS | **On register: import “seen” lemmas into system Onboarding deck** | Magic continuity without merging game UX into FSRS. |
| D9 | Content SoT | **Commit fixtures in Systema** (`data.js` / TSV + generators) | Same pattern as roots frequency drills. |
| D10 | Guest identity | **localStorage UUID + `game_events`** | Stitch multi-session anonymous play; merge on auth. |
| D11 | Free plays | **5 free plays per drill family** | More generous than current 1-play `gate.js`; still scarcity. |
| D12 | Cabinet boundary | **Cabinet games = short skill drills; SRS = vocab memory** | Clear student mental model; Prana can reward both later. |
| D13 | UI language | **RU + EN toggles from day one** | Lead-magnet fleet bilingual; A0 pedagogy still Cyrillic/IAST-first on cards. |
| D14 | Invent catalogue shape | **Three sections** | Asset-pedagogy · viral LM · engine-fill packs. |
| D15 | Acceptance | **Funnel + smoke**; content **provenance + ≥20-row RU gloss sample** | Not scholar-full-review; not “generate unchecked”. |
| D16 | Needs-new-engine games | **Park Wave 3+ with `needs-engine` tag** | Catalogue may list them; Wave-1 must not build engines. |
| D17 | csl-guides quality | **Embed/link smoke + no data drift** (hash/version of pack) | Systema canonical. |
| D18 | Ambiguity | **Pick plan default, log, continue** | Unattended-safe. |
| D19 | Stop conditions | **Money contour · prod flag-on · data-integrity wipe risk** | Halt; do not press on. |
| D20 | Git authority | **Handoff commit → PR → merge**; always **session-unique worktree** | Systema main-tree guarded. |
| D21 | Fence | **No money/access/tariffs/webhooks-money · no prod secrets · no csl-orig · no force-push** | Games stay in exercises/telemetry/SRS-onboarding/docs. |
| D22 | When to run handoffs | **Auto-queue behind Tier-0 money/GC work**; human `/go` | Ambient plan is not a self-start task. |

---

## Autonomy contract (verbatim for execution agents)

1. **On ambiguity:** apply the marked default in this plan / IMPLEMENTATION step; append a one-line log under the handoff’s Dev Notes; continue. Do not invent a fourth option.
2. **Stop (halt the handoff):** about to change payments, tariffs, access grants, money webhooks; about to enable `SRS_ENABLED` / games flags on production without a DEPLOY_QUEUE row; about to overwrite reviewed fixtures without a `--check` generator path; about to touch `csl-orig` or secrets in `.env`.
3. **Commit authority:** standard handoff autonomy — worktree off `origin/main`, commit, PR, merge. Flag defaults **OFF**. Production enable is human via DEPLOY_QUEUE.
4. **Fence:** only `public/exercises/**`, `public/exercises/gate.js`, `telemetry.js`, games API/telemetry/SRS onboarding import paths, `docs/**`, related tests/config. No money contour. No multiplayer. No audio/TTS jobs in Wave 1.
5. **Worktree:** always `git worktree add -b <branch> ../Systema-Sanscriticum-h###-<pid> origin/main`; remove worktree after PR lands.
6. **Queue order:** do not self-start from ambient GTD; run only when a human names the handoff / `/go`, and only after money/GC Tier-0 capacity is free (D22).

---

## Prior-art verdict (build vs reuse)

| Piece | Verdict | Evidence |
|---|---|---|
| Sort / match / cloze engines | **Reuse** | `public/exercises/{sort,match,cloze}/engine.js` |
| Ligatures + roots frequency drills | **Reuse + pack** | H1281 / H1356 families; `build_root_drill_data.py` |
| Gate + funnel telemetry | **Extend** | `gate.js`, `game_events`, H1360 `games:funnel` |
| SRS FSRS stack | **Reuse** | Saraswati; onboarding deck is new *content + import*, not new scheduler |
| Dictionary / Kochergina / roots fixtures | **Reuse** | `DictionaryWord`, memrise CSVs, `roots_frequency_ru.tsv` |
| Lead magnets H312–H315 | **Align, don’t rebuild strategy** | [ROADMAP_LEAD_MAGNETS_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_LEAD_MAGNETS_2026.md) |
| Sanskrit-HUB asset→pedagogy map | **Consume** | [SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md) |
| Audio / karaoke | **Gap — out of Wave 1** | No audio layer; park sound-tap games |
| Sandhi Heritage/vidyut live API | **Later** | Static sandhi *tables* may ship on cloze/match; live splitter = needs-engine / API wave |

---

## Autonomy-readiness gate (Phase 4)

| Check | Status |
|---|---|
| Every Wave-1 deliverable has arch + ordered steps + acceptance + risks | **Pass** (see layer docs) |
| Zero blocking `@DECIDE` on Wave-1 path | **Pass** (all forks ruled above) |
| No rebuild-what-exists | **Pass** (prior-art table) |
| Autonomy contract covers ambiguities | **Pass** |
| This session builds product code | **N/A by D3** — gate applies to *future* Wave-1 handoffs |

**Gate verdict: PASS** for deferred execution.

---

## Handoffs (minted deferred — queue behind Tier-0)

| ID | Scope | Status |
|---|---|---|
| [H1678](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1678-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w1-platform-p0_26.07.26.md) | Wave 1 platform + P0 packs G-C01–C03 | 🟡 QUEUED |
| [H1679](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1679-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w1-p1-packs_26.07.26.md) | Wave 1 P1 packs G-C04–C06 | 🟡 QUEUED |
| [H1680](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1680-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w2-srs-onboarding_26.07.26.md) | Wave 2 SRS onboarding + cabinet skill strip | 🟡 QUEUED (after H1678) |

### Starters (when Tier-0 frees — human `/go` only)

```text
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H1678-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w1-platform-p0_26.07.26.md and execute it.
```

```text
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H1679-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w1-p1-packs_26.07.26.md and execute it.
```

```text
Read C:\Users\user\Documents\GitHub\Uprava\handoffs\H1680-Sonnet_Systema-Sanscriticum_online-sanskrit-games-w2-srs-onboarding_26.07.26.md and execute it.
```

---

_Dr. Mārcis Gasūns_
