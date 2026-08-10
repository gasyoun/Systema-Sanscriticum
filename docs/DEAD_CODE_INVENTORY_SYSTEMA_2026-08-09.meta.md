# Metadoc — DEAD_CODE_INVENTORY_SYSTEMA_2026-08-09.md

_Created: 09-08-2026 · Last updated: 10-08-2026_

Companion record for [DEAD_CODE_INVENTORY_SYSTEMA_2026-08-09.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DEAD_CODE_INVENTORY_SYSTEMA_2026-08-09.md).

## Purpose

Onboarding pack for the subject document, so a future session does not rediscover its shape by
trial and error. The subject is a **report-only** dead-code inventory of the whole
Systema-Sanscriticum Laravel application: 2,431 files scanned across 13 subsystems, 41
confirmed-dead items, ~8,700 LOC nominally recoverable (~1,881 LOC once the vendored
`lecture-ui` Jinja templates are set aside). Nothing was removed and nothing outside the
subject document and this metadoc was edited.

**Corrected before commit (09-08-2026).** The synthesis agent's first draft reported the
`lecture-ui/templates/Old/` directory as 133,091 LOC and the templates total as 136,691 LOC —
a ~41× inflation. The six files there are 401+417+417+436+610+938 = **3,219 LOC** (verified
with `wc -l` against `git ls-files`), making the templates total 6,819 and the grand total
~8,700. Per-item LOC for the PHP/Blade/config findings spot-checked correct
(`MigrateBuilderMedia.php` = 190 as reported), so the defect was isolated to that one
directory aggregate. A reader of the original draft would have over-weighted a vendored
template cleanup by two orders of magnitude against real application findings.

Its second, arguably more durable purpose is section 6 — the catalogue of dynamic-dispatch
surfaces in this repo that mislead a grep-based audit (Filament auto-discovery, Artisan
signature strings, Eloquent table-name convention, `hasColumn`-guarded migrations, wholesale
`config('x', [])` reads, the generated env inventory, runtime-transitive Composer packages,
`.claude/worktrees/` and `.repowise/` contamination, compiled Blade). That section is written
to be reusable by any future audit of this repo, independent of whether the individual findings
are still current.

## Audience

- **A human deciding what to delete.** Section 3 is ranked so the cheapest, safest volume comes
  first; section 7 lists what must never be touched; section 8 batches the work so 531 test
  files can localise a breakage.
- **A future agent session** asked to re-run or extend the audit — read section 6 before
  writing any grep, and section 9 before trusting any count.
- **Whoever owns the four open human decisions** the report surfaces: the ESP mail gate
  (H1147 / issue #504), GC-B3 WebinarProvider, the May-2026 import-command cohort, and
  H2317's attendance-notice helpers.

## Provenance

- **Workflow:** fan-out scan (one agent per subsystem, 13 subsystems, `git ls-files`-scoped so
  `vendor/` and `node_modules/` were never in play) followed by an adversarial verification
  pass whose brief was to *prove each candidate alive* and report it as a false positive on
  success. Seven candidates were rejected as alive; two could not be settled; nine leads were
  never verified and are carried as leads only.
- **Model:** Opus 5 (`claude-opus-5`) for both the scan fan-out and the report write-up. One
  subsystem agent (Database) self-reported as Claude Opus 4.5 (`claude-opus-4-5`); its
  findings were re-verified in the same adversarial pass as the rest.
- **Repo state:** worktree `Systema-Sanscriticum-deadcode-1636032`, branch
  `deadcode-inventory-1636032`, off `main` at HEAD `736c3b3d` (09-08-2026), release 1.88.19.
- **Read-only:** helper scripts were written outside the repo under the OS temp dir and
  deleted; `git status --porcelain` was verified empty by each subsystem agent before handoff.
- **Formatting note:** the report was first written with working-dir-relative links per the task
  brief, then rewritten to full `blob/main` URLs (171 links) because a committed `.md` in this
  org renders relative links as dead text on GitHub. The org rule was taken as the higher
  authority; if the document is ever consumed only inside a working checkout, that decision is
  the one to revisit.

## Ranked improvement backlog

1. **Install a static-analysis tool and re-derive this mechanically.** No phpstan, psalm,
   rector, deptrac or knip config exists in the repo — CI runs Pint and PHPUnit only. Method-level
   reachability, which is where nearly all the *application* dead code turned out to live, is
   exactly what phpstan finds for free. Highest-leverage item by a wide margin: it converts a
   one-off audit into a check.
2. **Sweep enum cases one-by-one.** All 10 enums are live at class level (real model casts), but
   a declared-and-never-constructed case inside a live enum was not caught by either pass. Named
   in the report as the most likely remaining miss.
3. **Finish the three unexamined Filament scope hints** — resources whose `$model` no longer
   exists (the highest-value remaining sweep and the only one likely to yield a whole-file
   finding), pages behind a permanently-off flag, duplicated page-local widget logic. The
   Filament subsystem currently reports zero findings across 270 files, which is a *coverage*
   statement as much as a cleanliness one.
4. **Verify the nine low-confidence leads**, especially the five `lecture-ui` Python scripts.
   Their liveness turns on a single question no grep can answer — whether the lecture editors
   still run them by hand — so this needs one human answer, not more scanning.
5. **Audit the eight observed-but-unreported test-only service methods** as their own pass;
   `GroupMembershipManager::syncForCourse()` is the notable one, since group-membership sync is
   a live subsystem.
6. **Split section 6 out into a reusable reference** once a second audit confirms the trap list
   generalises. Right now it is embedded in a dated report and will be lost when the findings
   age out.
7. **Re-check the two Uncertain rows and the four human-decision items** and record the
   outcomes here, so the next audit starts from resolved state rather than re-litigating.
8. **Add per-file LOC measurement.** One view was not measured at all, and figures are
   declaration-line-to-next-declaration approximations — fine for ranking, wrong for any claim
   about exact recovered volume.

## Limitations

- **A grep-based audit cannot prove the absence of dynamic references.** Variable method names,
  `call_user_func` with a computed string, concatenated class names, runtime-assembled view or
  command names are invisible to every search behind this report. Each subsystem swept for such
  constructs and found none reaching its candidates — that is not proof of absence.
- **Static only.** No `php artisan route:list` (the checkout has no `vendor/`), no `config:show`,
  no `model:show`, no DB introspection, no access logs, no production filesystem. Column and
  table claims rest on migration files, and several migrations use `hasColumn` guards, implying
  past manual drift. "Reachable but never called by any client" is not answerable from source.
- **Off-repo callers are unfalsifiable from here**: n8n workflows live on a separate host, the
  production crontab was not read (only tracked `@@TEMPLATE@@` forms), and `deploy.sh` lives on
  the box untracked. Every operator-CLI candidate is capped accordingly.
- **Human invocation leaves no repo trace.** For a hand-run script, zero inbound references is
  the *expected* state; this accounts for three of the seven false positives and both Uncertain
  rows.
- **Not audited at all:** private/protected methods and class constants in `app/Support`, the 21
  framework/package config files key-by-key (including `config/services.php`), Filament closures
  that build a method name by concatenation, `mobile/package.json` Capacitor plugin usage (the
  generated native projects are untracked), and `composer.lock`-vs-`composer.json` drift.
- **Findings decay.** Eleven confirmed items are less than three weeks old at time of writing
  and several belong to in-flight, flag-OFF features; a re-read after any of those features
  ships will produce different verdicts. Treat the report as a dated snapshot, not a standing
  list.
- **One concurrent-session caveat:** the worktree's `.ai_state.md` carried unrelated in-flight
  H2059 WIP when these two files were written. Only new files under
  [docs](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs) were added; no existing
  tracked file was touched.

## Revision history

| Date | Change | Model |
|---|---|---|
| 09-08-2026 | Initial inventory written from the 13-subsystem fan-out plus adversarial verify: 41 confirmed dead, 3 partial, 2 uncertain, 7 false positives, 9 leads. Metadoc created alongside. Links converted to full `blob/main` URLs; "Last updated" bumped to 10-08-2026 per the dated-header rule. | Opus 5 (`claude-opus-5`) |

_Dr. Mārcis Gasūns_
