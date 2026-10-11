# MATURITY_PRODUCT_CORE_2026-10-10 — Systema-Sanscriticum

_Created: 2026-10-11 · Dr. Mārcis Gasūns_

_Handoff: [H6395](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6395-OxAlpha_Systema-Sanscriticum_maturity-product-core_10.10.26.md) · Scope: the application product core (tests, data model, student surface, docs truth, error observability) — NOT the operational circuits audited 09-10 (backups-DR H6286, payments H6287, CI-deploy H6288)._

**Provenance:** OxAlpha (opencode/z-ai/glm-5.3-flash), GLM 5.3 Flash model id `zai-coding-plan/glm-5.3-flash`, executed 2026-10-11 09:00–09:45 +0300 in worktree `Systema-Sanscriticum-h6395-drain` off `origin/main` @ `8b58cea6`.

**Method contract:** report-only maturity audit. Zero mutations to prod, repo state or config; prod probes strictly read-only (HTTP GET/HEAD + SSH root read-only shell: `grep`, `ls`, `php artisan migrate:status`, `php artisan db:show`). Machine metrics from git CI telemetry only; every metric row carries its source command and capture timestamp. Any metric without source command + timestamp is INCONCLUSIVE, never PASS (docs/PLAYBOOK_EVIDENCE_OF_DONE_2026.md).

**Report-only proof:** failsweep gate states recorded BEFORE the metrics (captured 2026-10-11 09:02:57 +0300) and re-recorded AFTER the audit pass (captured in the Evidence section below) — unchanged gate states prove no repair was attempted.

## FRAME — five axes and their threshold rules (written BEFORE any metric was read)

1. **Test-suite health** — PASS if (a) the main `CI` workflow green rate ≥ 90% over its last 14 main-branch runs, (b) no workflow red window > 24 h inside the last 14 days, (c) ≥ 20 registered money-contour test files (`TeacherSalary|CheckoutPrice|TochkaWebhook` families in tests/Feature). PARTIAL (1–2 of 3), FAIL (0 of 3).
2. **Code & data-model integrity** — PASS if (a) prod pending-migration count = 0 (no drift), (b) database census exists and is sized plausibly (tables > 100), (c) repo migration-file count and prod ran-set consistent (latest prod migration ≤ 2 days old). PARTIAL (1–2 of 3), FAIL (0 of 3).
3. **Student-facing surface** — PASS if landing, `/login`, `/admin`, `/cabinet` all return healthy codes (200/301/302 — i.e. no 5xx) with response times < 2 s each. Deduct one point for any missing machine-readable health endpoint. PARTIAL = healthy but 5xx or >2 s anywhere; FAIL = any 5xx or timeout.
4. **Docs truth** — PASS only if BOTH failsweep gates `agent_docs_sla` and `megabook_s16` PASS at audit time; FAIL otherwise.
5. **App-error observability** — PASS if (a) daily error logs exist and are current (entries within last 24 h), (b) 24 h error volume ≤ 100 and top error classes identifiable by class, (c) external uptime/journey probe workflows green in CI history. PARTIAL (1–2 of 3), FAIL (0 of 3).

Headline verdict = axes fully PASSing out of 5. Levers must cite the metric row that justifies them.

---
<!-- METRICS_APPENDED_BELOW_AFTER_FRAME_COMMIT -->
