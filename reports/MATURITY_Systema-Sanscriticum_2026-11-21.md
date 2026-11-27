# Maturity audit — Systema-Sanscriticum (full-repo re-audit)

_Created: 2026-11-21 · audit window: 2026-11-21T17:44–18:05Z (UTC) · model: opencode / hy4-flash-free_

**Report-only.** Zero mutations to prod, repo state or config during the audit. No handoffs were minted. Prod probes were read-only (HTTP GET, log `tail`, `--check`-class tooling). Findings are written down with evidence, not repaired.

**Frame (fixed at task start, before metrics were pulled).** Default `/maturity-audit` five axes, adapted to a Laravel/education platform:

1. **Backups / DR** — restic lane recency, dump/backup `overall_rc`, off-site legs, lock hygiene, prior restore-drill evidence.
2. **CI/CD health** — run-level green rate, workflow-level green rate, deploy cadence, review-gate health.
3. **Security surface** — `composer audit`, Dependabot/CodeQL/Secret-scanning open alerts, webhook/SAST gate presence.
4. **Docs truth** — changelog cadence, pending queue, agent-docs freshness headers, domain map presence.
5. **Operational observability** — external HTTP probes, uptime/journey workflows, soft-alert guard signal, issue/PR age pressure.

**Threshold rule (same for every axis):** 5 = all named metrics green, no open material finding; 4 = ≥80 % green, only low-severity open findings; 3 = core contract holds, ≥1 material gap; 2 = one core contract failing; 1/0 = multiple/systemic failures. Half-points mark a materially improved or degraded axis that has not crossed the next band.

## Verdict: **3.1 / 5**

| Axis | Score | One-line basis |
|---|---|---|
| Backups / DR | 3 / 5 | Live lane green today (`overall_rc=0`, sftp OK); S3 off-site leg still SKIP (4th+ month), lock cleanup permission errors |
| CI/CD health | 3.5 / 5 | main 2 h old; review gate 10/10 (was 37 %); CI workflow only 9/14 = 64.3 % green |
| Security surface | 2.5 / 5 | 1 open live Telegram-bot-token alert, Laravel v13.39.0 below patched 13.40.0, 1 high Dependabot advisory |
| Docs truth | 3 / 5 | Agent docs structured and current in body; CHANGELOG last cut 2026-10-04 with 102 queued entries; `.ai_state.md` header 31-10 vs body 12-11 |
| Operational observability | 3.5 / 5 | All three prod endpoints 200; uptime green; 4 duplicate soft-alerts today for one failed service unit; 64 open issues > 30 d |

Average = (3.0 + 3.5 + 2.5 + 3.0 + 3.5) / 5 = **3.1 / 5**.

---

## 1. Backups / DR — 3 / 5

Live read-only probe (`ssh root@193.232.229.92 'tail -20 /var/log/restic-backup.log'`, 2026-11-21T17:55Z):

```
2026-11-21T15:34:28Z lane=systema  dest=sftp dump=OK backup=OK
2026-11-21T15:34:30Z lane=samudra  dest=sftp backup=OK
2026-11-21T15:34:31Z lane=s3      dest=s3 status=SKIP reason=no-/root/.restic-s3.env
2026-11-21T15:34:31Z run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
```

Plus, on both sftp lanes: `Remove(<lock/...>) failed: … permission denied` (non-fatal, recurring). Snapshot `936fc157` (systema) and `a942521c` (samudra) saved; size_sanity OK.

Prior baseline (2026-10-09, byte-exact restore from freshest snapshot, off-site Yandex 100 % for two active groups) still stands as restore-proof evidence — **not re-run this audit**.

Gap: the S3 off-site leg has been unconfigured since 2026-08-23 (baseline flagged it as a 4th-month outage); the `@ rusamskrtam` Spatie zip-with-PII finding from the baseline was **not re-verified** and is carried forward as an unchecked assumption, not a claimed current state.

## 2. CI/CD health — 3.5 / 5

`gh api repos/gasyoun/Systema-Sanscriticum/actions/runs?per_page=100` (2026-11-21T17:56Z): 83 success, 10 skipped, 5 failure, 1 cancelled, 1 in-flight → 83 % raw green; hard-fail rate 5/90 completed = **5.6 %**.

Workflow-level (same command, grouped): CI **9/14 = 64.3 %**, Semgrep 14/14, CodeQL 10/10, F821 13/13, Changelog lint 13/13, OxAlpha review gate **10/10** (baseline 37.3 % — materially fixed), Env-inventory 8/10 (guard firing as designed), Dependabot auto-merge 0/10 (all `skipped` — not failures).

Deploy cadence: `git log origin/main -1` → `7e872d8e`, 2026-11-21T19:18:25+03:00 (~2 h before audit); server-side cron deploy path documented in `docs/deploy.md`. Baseline measured ~418 deploys/30 d.

18 workflow files present (CI, CodeQL, Semgrep, F821, deploy, journey-probes, uptime, scorecard-weekly added 2026-11-11, etc.). Local targeted test (`php artisan test --filter=CheckoutPriceTest`, 17:54–17:59Z): **4 passed / 26 assertions** — money-path price/promo/deposit invariants green locally (with PHPUnit-12 doc-comment-metadata deprecation warnings). Companion `--filter=TochkaWebhookTest` (completed after the initial window): **32 passed / 151 assertions** (75 s) — webhook status parsing, signature/fail-closed and replay invariants green locally. CI telemetry remains the primary scoring evidence.

## 3. Security surface — 2.5 / 5

`composer audit --locked` (2026-11-21T17:57Z): 1 advisory — `laravel/framework` **v13.39.0** installed, affected `>=13.0.0,<13.40.0`, CVE-2026-128083 / PKSA-yxbx-3c9s-cjt9, **low** severity (Task Output Injection — Concurrency Process), reported 2026-11-13. `composer.json` constraint `^13.24.0` already admits the patched 13.40.0 — a lock refresh within constraint is sufficient.

`gh api …/{code-scanning,secret-scanning,dependabot}/alerts?state=open&per_page=100` (17:56Z):

- **Secret scanning: 1 open — Telegram Bot Token** (value deliberately not read or reproduced). Live credential requires rotation; the audit did not rotate it.
- Code scanning: 7 open — 5 are Scorecard repository-level "error" meta-alerts (Best-Practices, Code-Review, Fuzzing, SAST, Vulnerabilities), 1 `Pinned-Dependencies` in `ops/migrate/debian13/01-bootstrap.sh`, 1 `js/incomplete-multi-character-sanitization` warning in vendored `resources/js/vendor/sanskrit-util.js`.
- Dependabot: 1 open — **high** `symfony/yaml` unbounded recursion in YAML inline lexer.

Mitigating controls are present and green: CodeQL, Semgrep (PHP SAST), F821 gate, Dependabot auto-merge, and a dispatch-only `composer-security-update.yml` repair lane (manual trigger — it does not auto-fire on the new advisory).

## 4. Docs truth — 3 / 5

- `head -12 .ai_state.md` (17:54Z): header `_Last updated: 31-10-2026_`, while the WIP body carries entries dated 12-11-2026 and 11-11-2026 — body is fresher than its own header.
- `CHANGELOG.md`: `[Unreleased]` plus last cut **`[1.90.64] - 2026-10-04`**; `changelog_queue/` holds **102 pending entries** — roughly seven weeks of shipped work not promoted to a release section.
- `PROJECT_MAP.md`, `AGENTS.md`, `CLAUDE.md`, domain cards and `tests/README.md` are present and actively routed (this audit used that routing).
- Predecessor product-core audit (2026-10-21) recorded `agent_docs_sla` and `megabook_s16` failsweep gates as FAIL; those gates were **not re-measured** in this run — carried as unchecked, not as a current verdict.

## 5. Operational observability — 3.5 / 5

Read-only prod HTTP probes with `curl --ssl-no-revoke` (17:56Z; plain curl hits local schannel `CRYPT_E_REVOCATION_OFFLINE` — an environment TLS/CRL issue, not a prod defect):

```
samskrte.ru /                => 200 (6.92 s first hit)
samskrte.ru /admin/login     => 200
samskrte.ru /api/public/schedule => 200
```

Uptime workflow `44931160489` success at 17:38Z; `journey-probes.yml` (hourly, external) present. GitHub Actions: 88 open issues (64 older than 30 d), 3 open PRs (1 older than 30 d — #2587, 2026-09-15).

Signal quality issue: **four** issues opened today (#4379–#4382, fingerprint `6fd69840`) for the same `telegram-daily.service` failed unit. Issue #4382 carries a playbook link and a suggested `ops:soft-remediate --dry-run --json`, with `H2148 — do not auto-apply hard fuse clears`. The guard *detects and pages correctly*; resolution/dedup is missing — repeated pages for one known failure are alert fatigue, not improved coverage.

---

## Diff vs predecessors (frame caveat)

The 2026-10 baseline was **four domain audits** (backups-DR, CI/deploy, payments circuit, product core), not this single five-axis frame. Therefore the headline 3.1/5 is **not** directly comparable with any one predecessor; per-axis deltas are the valid comparison:

| Predecessor | Then | Now | Direction |
|---|---|---|---|
| CI/deploy (09-10) | 3.5/5 | 3.5/5 | review gate 37 %→100 % (+), CI workflow 79.7 %→64.3 % (−) — net flat |
| Backups/DR (09-10) | 2/5 GREEN (+1 AMBER) | 3/5 | live lane green today, but S3 leg still SKIP — modest, conditional |
| Payments circuit (09-10) | 4/5 | not re-audited | carry 4/5, no current claim |
| Product core (10-21) | 3/5 | not re-audited | carry 3/5, no current claim |

## Top-3 levers (cheapest first; evidence row per lever)

1. **Close the security backlog.** Rotate the open Telegram Bot Token secret; dispatch `composer-security-update.yml` (or `composer update laravel/framework` within the existing `^13.24.0` constraint) to reach ≥13.40.0; triage the high `symfony/yaml` Dependabot alert. Evidence: secret-scanning open=1; `composer audit` PKSA-yxbx-3c9s-cjt9 on v13.39.0; dependabot alert summary. Expected axis move: 2.5 → 4.
2. **Activate the second off-site leg.** Provision the documented `/root/.restic-s3.env` on .92 and re-run the existing restore drill; also fix the `<lock/...>` removal permission. Evidence: live log `lane=s3 dest=s3 status=SKIP reason=no-/root/.restic-s3.env`, 2026-11-21T15:34Z; baseline 4-month outage. Expected axis move: 3 → 4. **Human credential action required** — not performed in the audit.
3. **Bring the visible red surfaces to green.** Fix the dominant CI red classes (Pint style / HTML assertions per baseline) and resolve or explicitly allowlist `telegram-daily.service` with a reason, then dedup the soft-alert fingerprint. Evidence: CI 9/14 green; issues #4379–#4382 same fingerprint. Expected axis moves: CI/CD 3.5 → 4, observability 3.5 → 4.

## Claims & evidence checklist (workspace pre-feedback contract)

| # | Claim | Evidence | Confidence |
|---|---|---|---|
| 1 | Deploy cadence is healthy (main ~2 h old) | `git log origin/main -1` | High |
| 2 | Review gate fixed vs baseline | gh api last 100 runs: review-gate 10/10 vs baseline 37.3 % | Medium (100-run sample) |
| 3 | CI workflow still materially red | gh api: CI 9/14 = 64.3 % | Medium (small workflow sample) |
| 4 | Backups run nightly with one off-site leg missing | live restic log `overall_rc=0`, `s3 SKIP` | High |
| 5 | Live secret + vulnerable framework are open | gh api secret-scanning=1; composer audit v13.39.0 | High |
| 6 | Changelog is ~7 weeks stale with 102 queued entries | `head CHANGELOG.md`; `ls changelog_queue | wc -l` | High |
| 7 | Prod endpoints healthy | curl `--ssl-no-revoke` 200 ×3 | High (single point in time) |
| 8 | Soft-alert duplication is a real signal-quality defect | issues #4379–#4382, same fingerprint, issue body | High |
| 9 | Money-path invariants green locally | `CheckoutPriceTest` 4 passed/26 assertions; `TochkaWebhookTest` 32 passed/151 assertions | High (targeted suites only) |

## Limitations / unchecked assumptions

- Only two targeted money-path suites were run locally (`CheckoutPriceTest` 4 passed/26 assertions; `TochkaWebhookTest` 32 passed/151 assertions); the full suite was not run — overall test health is inferred from CI run telemetry.
- No independent re-verification of the 2026-10 restore drill or the Spatie PII zip finding; carried from baseline, explicitly not asserted as current.
- Code-scanning/secret-scanning counts are capped at `per_page=100` (actual counts are far below the cap).
- Two of five axes (payments, product core) were not re-audited this run; their baseline scores are carried, not measured.
- The working tree is dirty with mixed WIP (8 modified tracked files, 2 untracked). No repo state was altered; local WIP is unrelated to the audit and was left untouched.

_The next audit uses the top-3 levers above as its diff baseline._
