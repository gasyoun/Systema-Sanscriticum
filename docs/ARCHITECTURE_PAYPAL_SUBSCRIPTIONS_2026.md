# Architecture — PayPal Subscriptions API (H2027)

_Created: 31-07-2026 · Last updated: 31-07-2026_

**Status:** design of record + Phase 0 stubs (flags OFF; no live Subscriptions)  
**Handoff:** [H2027](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2027-Grok_Systema-Sanscriticum_paypal-subscriptions-api_31.07.26.md)  
**Sibling (separate lane):** [H2026 Tochka multi-mode recurring](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md) — **not** in this PR wave  
**Executor / provenance:** Grok 4.5 (`grok-4.5`), 31-07-2026  

PayPal product docs: [Subscriptions](https://developer.paypal.com/docs/subscriptions/) · Product → Plan → Subscription · webhooks `BILLING.SUBSCRIPTION.*` / payment completed · pause / cancel / revise.

---

## 1. Why this exists

**Today:** diaspora pays via **manual claim** — student sends money to `paypal.me` / personal recipient, submits `/paypal/{tariff}` (`PaypalClaimController`), admin confirms in Filament. Copy states auto-debit is not on our platform ([money-diaspora-paypal-buyer-path](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-diaspora-paypal-buyer-path.md)). H2017 improved claim fields; that path stays the default.

**Need:** optional **auto-bill abroad** (monthly course / club / multi-cycle) without mixing into the Tochka domestic spine (H2026). Different KYC, currency, failure modes, and merchant eligibility — one PR wave must not block the other.

User ask (31-07-2026): separate handoff for PayPal Subscriptions.

---

## 2. Non-goals

- Replacing or deleting the manual claim path.
- Implementing Tochka modes A–E (→ **H2026** only).
- YooMoney / own KKT.
- Enabling Subscriptions in production in this handoff (human `@DO` only).
- Assuming a Russian / local PayPal Business account can always receive international Subscriptions — **verify on the live merchant first**; if blocked, stop with a findings note + GTD `@DO`, do not invent green.

---

## 3. Shared billing model (reuse H2026 entities)

H2026 defines the platform spine. PayPal is a **second provider** on the same tables, not a parallel access algebra.

```
┌─────────────────────────────────────────────────────────────┐
│  Product UX: diaspora monthly / club / multi-cycle offer     │
├─────────────────────────────────────────────────────────────┤
│  BillingCommitment  (what we intend to collect)              │
│  BillingSubscription (1 PayPal Subscription / plan binding)  │
│  BillingChargeAttempt → Payment (existing access path)       │
├─────────────────────────────────────────────────────────────┤
│  PaypalSubscriptionsService (REST Product/Plan/Subscription) │
│  PaypalSubscriptionsWebhookController (ledger like H1359)    │
├─────────────────────────────────────────────────────────────┤
│  Tariff::accessKey / grantAccess / BlockAccessMaterializer   │
└─────────────────────────────────────────────────────────────┘
```

**Invariant (same as H2026):** a successful provider charge always materialises (or extends) a normal `payments` row and reuses `fireOnPaid` / access keys. Do **not** invent a second access system keyed only by PayPal subscription id.

### 3.1 `billing_subscriptions.provider = paypal`

Reuse the H2026 column set ([§4.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md)):

| Column | PayPal meaning |
|--------|----------------|
| `provider` | `paypal` |
| `provider_subscription_id` | PayPal Subscription id (`I-…`) |
| `mode` | same product modes that matter abroad (below) |
| `status` | map from PayPal status (APPROVAL_PENDING → ACTIVE → SUSPENDED → CANCELLED → EXPIRED) |
| `period` / `tranche_count` | from Plan billing cycles |
| `amount_*` | Plan fixed price (currency may be USD/EUR — store currency + minor units in meta until multi-currency Payment is explicit) |
| meta | `paypal_plan_id`, `paypal_product_id`, `payer_id`, last webhook event id |

`billing_commitments` and charge → Payment allocation rules are identical to H2026 §4.3–4.4 with `provider=paypal` on resulting payments.

### 3.2 Payment provider constants

| Use | `payments.provider` | Notes |
|-----|---------------------|-------|
| Manual claim (today) | `paypal` (`Payment::PROVIDER_PAYPAL`) | stays in `MANUAL_CLAIM_PROVIDERS`; human Filament confirm |
| Subscription auto-charge (future P1+) | `paypal_subscription` (`Payment::PROVIDER_PAYPAL_SUBSCRIPTION`) | **not** manual-claim; webhook-paid; never reaped as abandoned bank link |

Claim and Subscriptions **coexist**: flag OFF ⇒ only claim; flag ON ⇒ both surfaces allowed.

---

## 4. Product modes abroad (map from H2026 A–E)

| H2026 mode | Abroad priority | PayPal mapping |
|------------|-----------------|----------------|
| **A** per-course monthly | **High** | Plan: MONTH × open or N cycles; one subscription per course |
| **D** club | **High** | Separate Product/Plan; never mixed into course bag by default |
| **C** multi-month / multi-cycle | Medium | Plan with `total_cycles = N` or higher fixed price one cycle |
| **E** installments | Medium if product wants diaspora parts | Fixed N-cycle plan (curator still owns schedule creation) |
| **B** consolidated multi-direction | **Lowest** abroad | Currency mix + variable amount poorly fit fixed PayPal plans; defer |

Ship order (this lane): **P0 stubs → P1 sandbox A → P2 student cancel UX (A/D) → P3 prod checklist**. Do not start P1 until H2026 webhook → Payment pattern is reviewed for reuse (ledger + amount guards).

---

## 5. PayPal API sketch

| Step | API | Our side |
|------|-----|----------|
| Catalog | Create Product | once per course/club product (sandbox artisan) |
| Offer | Create Plan | amount, interval, total_cycles |
| Checkout | Create Subscription + approve URL | student redirects to PayPal |
| Active | `BILLING.SUBSCRIPTION.ACTIVATED` | `billing_subscriptions.status=active` |
| Charge | payment completed / sale events | commitment → `Payment` paid + access |
| Fail / suspend | `…SUSPENDED` / payment failed | `past_due`, dunning copy |
| Cancel | Cancel Subscription API + student UX | status cancelled; stop new charges |

**Webhook:** `POST /api/webhooks/paypal-subscriptions`  
Verify with PayPal **Webhook signature** (transmission id + cert URL + webhook id). Mirror H1359 discipline: append-only ledger keyed by event id / body hash; never double-grant on replay.

**Config (Phase 0, all empty secrets):**

| Env | Default | Role |
|-----|---------|------|
| `PAYPAL_SUBSCRIPTIONS_ENABLED` | `false` | master flag (`features.paypal_subscriptions` + `services.paypal.subscriptions.enabled`) |
| `PAYPAL_CLIENT_ID` | empty | REST app |
| `PAYPAL_CLIENT_SECRET` | empty | REST secret — never commit |
| `PAYPAL_WEBHOOK_ID` | empty | Dashboard webhook id for signature verify |
| `PAYPAL_API_MODE` | `sandbox` | `sandbox` \| `live` |
| `PAYPAL_API_BASE_URL` | empty | optional override; else mode picks api-m.sandbox / api-m.paypal.com |

Claim path keeps `PAYPAL_CLAIM_ENABLED` / `PAYPAL_ME_LINK` / `PAYPAL_RECIPIENT` unchanged.

---

## 6. Phase 0 deliverables (this PR)

| Artifact | Purpose |
|----------|---------|
| This architecture + metadoc | design of record |
| Feature + services config stubs | dark flag |
| `PaypalSubscriptionsService` | enabled/configured checks; no live calls when OFF |
| `PaypalSubscriptionsWebhookController` | 404 when OFF; signature gate stub when ON |
| Route `POST /api/webhooks/paypal-subscriptions` | inert until flag ON |
| `Payment::PROVIDER_PAYPAL_SUBSCRIPTION` constant | future webhook-paid rows |
| Tests | flag OFF ⇒ webhook 404; claim regression still green |

**Not in Phase 0:** sandbox Product/Plan create, student approve UX, Payment materialisation, prod enable.

---

## 7. Money-contour safety

- Flag default **OFF**; merge is prod-inert.
- Money PR **without** auto-merge (`<!-- money-contour: no-auto-merge -->`).
- Never enable prod in the same change as code without a human `@DO`.
- Claim path remains default for diaspora until product deliberately turns Subscriptions on.
- STOP condition if Business account cannot sell Subscriptions internationally: document in FINDINGS/GTD, do not ship a fake green path.

---

## 8. Production enable checklist (P3 — human only)

1. Confirm PayPal Business can create Products/Plans/Subscriptions for target markets/currencies.
2. Create live REST app; set `PAYPAL_CLIENT_ID` / `SECRET` / `WEBHOOK_ID`.
3. Register webhook URL to `/api/webhooks/paypal-subscriptions`; verify signature in sandbox then live.
4. `PAYPAL_SUBSCRIPTIONS_ENABLED=true` + `php artisan config:cache`.
5. Smoke: one sandbox (then live) subscription → paid `Payment` → access; cancel path.
6. Keep claim CTA available as fallback until confidence is high.

---

## 9. Related code (today + Phase 0)

| Piece | Role |
|-------|------|
| `PaypalClaimController` / claim tests | manual diaspora (default) |
| H2026 architecture | shared `billing_*` + mode vocabulary |
| `WebhookController` / H1359 ledger | pattern to mirror for PayPal events |
| `PaypalSubscriptionsService` | Phase 0 stub |
| `PaypalSubscriptionsWebhookController` | Phase 0 stub |
| `config/services.php` `paypal.subscriptions` | credentials + flag |
| `config/features.php` `paypal_subscriptions` | master feature flag |

---

## 10. Metadoc pointer

Companion: [ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.meta.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.meta.md).

_Dr. Mārcis Gasūns_
