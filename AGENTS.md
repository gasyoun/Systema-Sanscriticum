# AGENTS.md — Systema Sanscriticum

Repo-local instructions for Codex/Claude agents working in this Laravel app.
Org-level Sanskrit Lexicon rules still apply, but this repository is **not** a
CDSL dictionary source repo; do not apply csl-orig correction workflows here.

## Current Product Priorities

Use the public roadmap in `README.md` and the working journal in `.ai_state.md`.
As of 2026-06-30 the active order is:

1. **Telegram support analytics to production** — still in development, not done.
2. **Salary / finance** — teacher payouts, late payments, PayPal conversion,
   finance ledger/export.
3. **Hardening / technical debt** — payment/access tests, webhook signatures,
   Laravel 10 casts, dead configs.
4. **UX / polish** — cabinet, helpdesk, Telegram support analytics, salary pages,
   landing pages.

Keep README public/product-facing. Keep `.ai_state.md` as the concise agent
handoff with concrete next steps, blockers, validation, and deploy notes.

## High-Risk Areas

- **Money and access:** `Payment`, `Tariff`, checkout, promo/deposit/loyalty,
  referral credit, teacher salary/payouts, finance exports.
- **Course access:** group grants, `Payment::PAID_STATUSES`, tariff keys
  `full`, `block_N`, `block_N_hH`.
- **Webhooks:** Tochka, Telegram, VK, MAX, Zoom. Check signature/fail-policy and
  secret rotation before changing behavior.
- **Telegram support analytics:** MadelineProto session/login, flood limits,
  contact auto-linking, responder mappings, sync state resets.
- **Laravel version trap:** this project is Laravel 10. Use `protected $casts`
  properties. Do not use Laravel 11-style `casts()` methods.

## Validation Defaults

Use the XAMPP PHP binary locally when plain `php` is not available:

```sh
C:\xampp\php\php.exe artisan test --filter=TelegramSupportAnalyticsTest
C:\xampp\php\php.exe artisan test --filter=TeacherSalary
C:\xampp\php\php.exe artisan test --filter=CheckoutPriceTest
C:\xampp\php\php.exe artisan test --filter=TochkaWebhookTest
C:\xampp\php\php.exe artisan pint --dirty
```

For web/Blade feature tests, build Vite assets first if `public/build/manifest.json`
is missing:

```sh
npm install
npm run build
```

## Deployment Notes To Preserve

- Telegram support production enablement requires real `TELEGRAM_SUPPORT_API_ID`,
  `TELEGRAM_SUPPORT_API_HASH`, `TELEGRAM_SUPPORT_SESSION`, and an interactive
  first `php artisan telegram-support:sync` by the user. Codex cannot complete
  QR/phone login.
- Keep `TELEGRAM_SUPPORT_DIALOG_LIMIT` conservative on early production runs.
- Manual Telegram support contact links override auto-linking.
- Run `php artisan telegram:backfill-usernames-from-notes --apply` only when the
  user agrees to normalize legacy notes into `users.telegram_username`.
- Salary/finance changes need targeted tests around late payments, closed periods,
  advances, PayPal rate dates, and `salary_payout` mirror transactions.

## Working Style

- Do not stage unrelated local changes. This repo often has mixed WIP.
- Prefer small, explicit commits for roadmap/handoff edits and money/access fixes.
- Update `.ai_state.md` when a logical milestone is completed or when a blocker
  changes the next agent's path.
- README roadmap should use `Now / Next / Later`; do not reintroduce stale
  P0/P1/P2/P3 active planning.
