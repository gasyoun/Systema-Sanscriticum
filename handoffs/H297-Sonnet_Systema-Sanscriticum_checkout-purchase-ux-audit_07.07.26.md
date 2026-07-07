# H297 — Systema checkout purchase UX audit

_Created: 07-07-2026 · Last updated: 07-07-2026_

> **Recreation note (07-07-2026, Sonnet 5 `claude-sonnet-5`):** originally minted in `Uprava/handoffs/` as part of a 7-item batch; MG closed the PR ([Uprava#13](https://github.com/gasyoun/Uprava/pull/13)) — *"these handoff files belong in Systema-Sanscriticum, not Uprava. Recreating them in the correct repo."* Recreated here verbatim (content unchanged) with only the Start-instruction path updated.

**Status:** 🟡 Queued
**Intended executor:** Sonnet (`claude-sonnet-5`)
**Repo:** `C:\Users\user\Documents\GitHub\Systema-Sanscriticum`
**Start instruction:** `Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\handoffs\H297-Sonnet_Systema-Sanscriticum_checkout-purchase-ux-audit_07.07.26.md and execute it.`

## Mission

Create an audit document that makes the purchase flow self-service for a person who has never seen the platform: choose tariff, understand what account/access will be created, pay, and know what happens next.

Deliverable: `docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md`.

## Read First

- PR #353 summary, because `/dvaram` now has a Continue Learning block.
- `resources/views/checkout/show.blade.php`
- `app/Http/Controllers/CheckoutController.php`
- `app/Http/Controllers/PaymentController.php`
- `resources/views/partials/guest-purchase-warning.blade.php`
- `resources/views/partials/paypal-cta.blade.php`
- `docs/debtor-self-service-spec.md`
- `docs/debtor-self-service-phase2-spec.md`
- `tests/Feature/CheckoutPriceTest.php`
- `tests/Feature/Student/DebtSelfServiceTest.php`

## Audit Focus

- Guest checkout clarity: "your cabinet account already exists / will be created".
- Trust and payment anxiety.
- Promo/prana clarity.
- PayPal/manual foreign payment path.
- Payment success/fail next steps.
- Purchase of live course vs recorded course.
- Bundles/subscriptions readiness from a UX perspective, without changing access logic.

## Required Document Shape

1. North star for purchase.
2. Current checkout journey.
3. Friction points and risk points.
4. Recommended screen hierarchy.
5. Copy recommendations.
6. Implementation tickets.
7. Acceptance metrics: checkout completion, payment fail recovery, support requests after payment.

## Guardrails

- Document-only PR. Do not change money/access code.
- Start from latest `origin/main`; PR #353 is already merged.
- Use watcher-safe commits in Systema; pathspec-limit staging.
- Do not include unrelated local files.

## Branch And PR

- Branch: `codex/checkout-purchase-ux-audit`
- PR title: `Add checkout purchase UX audit`
- Open a draft PR unless MG explicitly asks for ready-for-review.

## Validation

- Static review of headings and links/paths.
- `git diff --check origin/main...HEAD`
- Confirm PR diff contains only `docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md` unless a targeted `.ai_state.md` note is explicitly needed.

_Dr. Mārcis Gasūns_
