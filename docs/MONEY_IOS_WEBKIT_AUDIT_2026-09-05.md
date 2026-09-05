# MONEY IOS WEBKIT AUDIT 2026-09-05

_Created: 05-09-2026 · Last updated: 05-09-2026_

H4115 — Money-surface iOS WebKit audit (H1391 bug class). Executor: OxAlpha (z-ai/glm-5.3-flash), 05-09-2026.

## What ran

- Runner: [scripts/money_ios_webkit_audit.mjs](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/money_ios_webkit_audit.mjs) — Playwright **WebKit** (26.5, playwright v2336) with real iPhone device descriptors (iPhone 14 390×844, iPhone SE 3rd gen 375×667) — same engine as iOS Safari, not a resized Chromium.
- Mode: GET-only, prod-safe, zero charge risk. POST interactive checks exist behind `--local` (local dev only).
- Target: prod `https://samskrte.ru`, 2026-09-05 02:46 UTC. 9 surfaces × 2 devices = **18 audits**.
- Discovery: two-hop — seeds (`/`, `/online`, `/mecenaty`) → `/k/{course-slug}` pages → `/checkout/{tariff_id}` links (shop cards link to course pages, checkout lives in the `#tariffs` section).

## Results (18/18 reachable, all HTTP 200)

| Check | Verdict |
|---|---|
| Horizontal overflow (iPhone 14 / SE) | **clean on all 18** |
| CSRF `_token` in server-side POST forms | **present everywhere** |
| Cookie-bar occlusion of CTA (H1391 class) | **none — the H1391 fix holds** |
| WebKit console errors | **none** |
| Dead form fields (focus lost) | **none** |
| Pay-CTA tap target ≥ 44 pt | **FAIL — systematic, see below** |

### Finding: CTA tap targets below Apple's 44 pt minimum

- 40 px «Записаться» — `shop/partials/free-intro-banner.blade.php` (`py-2.5` + `text-sm`), banner served on `/mecenaty` and `/online/konsultaciya`.
- 42 px «Оплатить через PayPal» — `partials/paypal-cta.blade.php`, on **every** `/checkout/{tariff}` page.

### Fix landed (same pass)

`min-h-[44px]` added to the three money-surface CTAs: `free-intro-banner`, `paypal-cta`, `company-invoice-cta` (same defect class, same one-token fix). Verified: `npm run build` compiles and the built `app-*.css` bundle contains `min-h-\[44px\]`.

**Deploy residual:** the class exists only after a Vite rebuild — a CSS build must ride the next deploy; then re-run the audit and expect `dirty=0`:

```
BASE_URL=https://samskrte.ru node scripts/money_ios_webkit_audit.mjs
```

Same class exists in `student/partials/referral.blade.php` and `partners/registered.blade.php` — outside money-surface audit scope, not changed.

## What this runner cannot test

Real-device-only flows: Tochka 3-D Secure SMS confirmation, Apple Pay (no Secure Element in a simulator/emulator), PayPal login walls. Those stay on the [H3926 `/payment` playbook](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3926-OxAlpha_claude-config_payment-diagnosis-skill_02.09.26.md) manual checklist. The `ios-simulator-skill` route (full Xcode) was evaluated and skipped: Systema is a Laravel web app — the `xcodebuild` half of that skill is dead weight, and WebKit emulation already covers the H1391 bug class.

## Evidence

- Machine report: [MONEY_IOS_WEBKIT_AUDIT_2026-09-05.report.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MONEY_IOS_WEBKIT_AUDIT_2026-09-05.report.json)
- Full-page PNGs per surface/device: `storage/app/money-ios-audit/*.png` (local, not git — per house evidence rules).

_Dr. Mārcis Gasūns_
