# Checkout / Purchase UX Audit — 2026

_Created: 08-07-2026 · Last updated: 08-07-2026_

**Scope:** the purchase flow a first-time visitor goes through to buy a course — tariff selection
→ `/checkout/{tariff}` → payment (Точка, or PayPal manual claim) → success/fail → first access.
Code-grounded against `resources/views/checkout/show.blade.php`, `CheckoutController`,
`PaymentController`, `partials/guest-purchase-warning.blade.php`, `partials/paypal-cta.blade.php`,
`docs/debtor-self-service-spec.md` (+ Phase 2), `tests/Feature/CheckoutPriceTest.php`,
`tests/Feature/Student/DebtSelfServiceTest.php`, and PR #353 (`/dvaram` Continue Learning block).
Document-only — no code, no money/access logic touched.

## 1. North star

A person who has never seen the platform should be able to, without asking a curator anything:

1. Pick a tariff and understand **what account gets created and what it unlocks**.
2. See the **final price** with every discount already applied, with no surprise step before payment.
3. Pay with confidence (what happens to my money, what if it fails, who do I contact).
4. Know **exactly what to do next** the moment payment succeeds — not "email arrives eventually."

Today the flow is functionally complete and money-safe (heavy commenting throughout
`PaymentController` about races, double-pending, refunds — this is a well-hardened checkout by
engineering standards). The gaps are almost entirely about **what the buyer is told, when** —
not about missing capability.

## 2. Current checkout journey (as coded)

1. **Landing / tariff page → `GET /checkout/{tariff}`.** `CheckoutController::show()` 404s
   inactive tariffs, applies a `?promo=` query param into the session if valid, computes final
   price (loyalty/personal discount, prana balance, applied promo) and renders `checkout.show`.
2. **Already-paid short-circuit.** If `$finalPrice == 0 && auth()->check()`, the whole form is
   replaced by a single "You already have access" card → link to the dashboard. Good: no
   redundant payment UI.
3. **Guest warning block** (`@guest`) — `partials/guest-purchase-warning.blade.php` — shown
   above the form: "Log in to see your discounts" + a **prominent amber warning**: *"if you
   bought before, don't self-register — message a curator, they'll create your account
   manually and your access will carry over."* Plus Telegram/VK contact buttons.
4. **PayPal CTA** (`partials/paypal-cta.blade.php`) — a single line, feature-flagged
   (`services.paypal.enabled`), pointing to a separate claim form
   (`paypal.claim.show`) — "pay abroad, tell us, we'll open access after reconciliation."
5. **Guest data form** (name/surname/city/email/optional birth year/announcements opt-in) shown
   only for guests — 5 fields, client + server validated.
6. **Prana slider** (auth only, if balance > 0) — Alpine-driven live discount slider, min 0 /
   max `pranaMaxSpend`, "Reset" / "Spend max" buttons, live-updates the price panel via a shared
   Alpine store.
7. **Promo code box** — AJAX apply/remove against `/checkout/{tariff}/promo`, inline error/flash,
   no page reload.
8. **Pay button** — single CTA, live total, footer links to oferta/privacy PDFs, trust row
   (SSL / card logos / "refund per oferta").
9. **Right column (sticky)** — tariff card (title/description/2 bullet benefits) + a price
   breakdown card (list price → loyalty/personal discount → promo → prana → total, with a
   "you save X ₽" line).
10. **Submit → `POST payment.create`.** `PaymentController::createPayment()`: validates,
    resolves user (existing-email guest → **hard rejection** with a message pointing to login —
    prevents account-takeover, does not silently merge), locks/serializes concurrent pending
    orders on the same course, applies promo/prana/referral-credit in a DB transaction, then
    (after commit) calls Точка and redirects to the bank's payment page. Network failure →
    `failed` status + "payment service temporarily unavailable, try again" flash.
11. **`payment.success` / `payment.fail` redirects.** Success: "Payment successful! Access will
    open within a couple of minutes" (auth) or "Log in to start learning" (guest, just
    auto-created). Fail: "Payment was cancelled or an error occurred, you can try again" —
    explicitly does NOT touch payment state (source of truth is the Точка webhook, by design,
    per the code comment on `fail()`).
12. **Debtor self-service, a parallel entry point** (`docs/debtor-self-service-spec.md` +
    Phase 2): from the dashboard's "Мои долги" tab, a debtor gets routed either to this same
    `/checkout/{tariff}` (flat "didn't renew" debt) or to a promise-specific payment path (next
    installment / pay-all, with its own prana slider, no promo/loyalty — price is
    curator-fixed). A successful real payment auto-fulfils the matching promise and notifies the
    curator — no manual close-out needed.

## 3. Friction points and risk points

**F1 — The guest warning is a wall of text competing with the actual form, not a decision aid.**
The amber "if you bought before, don't self-register" block is the *first* thing a guest reads,
before they've even seen the price or the tariff. It's necessary (real account-collision risk —
see `resolveUser()`'s hard rejection on existing email) but it's phrased as "important" with no
way for a first-time buyer to quickly self-classify "this doesn't apply to me" and move on. Risk:
elevated bounce right at the top of a page whose only job is to convert.

**F2 — Rejection-on-existing-email has no recovery path from the form itself.** If a guest types
an email that already has an account, `resolveUser()` throws a validation error routed back to
the same page: *"you already have an account with this email — log in and order from there."*
There is no inline "log in" link or modal *from that specific error* — the general guest warning
above has a "Войдите" button, but the error message itself doesn't link anywhere. A confused
buyer has to scroll back up to find the login trigger. Minor friction, but it's exactly the
moment (a validation error, mid-purchase) where friction costs the most.

**F3 — PayPal path is a single unexplained line with no expectation-setting.** "Pay abroad via
PayPal and tell us — we'll open access after reconciliation" gives no timeframe, no explanation
of *why* it's manual (dodges what a first-time foreign buyer will worry about: "did my payment
just vanish?"), and sits visually subordinate to the primary card-payment CTA below it. For the
audience it serves (foreign / diaspora buyers who can't use Точка), this is probably their
*only* viable path, yet it reads like a footnote.

**F4 — No "what you get" clarity beyond two generic bullets.** The tariff card's benefit list is
static and generic — "access to materials right after payment" / "learning in your cabinet" —
for every tariff regardless of whether it's a single block, a bundle, live vs. recorded, VIP.
A buyer comparing a block tariff vs. a full-course tariff gets no differentiated explanation of
what the *difference* actually is on this page (they'd have to go back to the landing page).

**F5 — Prana and promo/loyalty stack silently with no combined-savings explanation until the
very end.** Each discount source appears/disappears in the breakdown as it activates
(`x-show="... > 0"`), which is good incrementally, but nothing on the page explains *why* prana
+ promo + loyalty can combine (or whether they always can — the debtor promise-checkout
explicitly disables promo/loyalty, which a buyer moving between the two flows would never know).

**F6 — Success message under-promises on timing without saying why.** "Access will open within a
couple of minutes" is honest (webhook-driven, async) but gives no indication of *what to do*
while waiting, and no fallback ("if you don't see access after 10 minutes, contact us") — a
buyer who refreshes their dashboard once and sees nothing has no next action and may assume the
payment failed, generating an avoidable support ping.

**F7 — Payment-fail page is a dead end with a generic message.** "Cancelled or an error occurred,
try again" doesn't distinguish *why* — this is intentional per the code comment (webhook is the
source of truth, GET-return from the bank isn't trusted), but the user-facing cost is that a
buyer whose card was actually charged (edge case, bank-side delay) sees the same message as one
who simply closed the tab, with no reassurance ("if you were charged, don't pay twice — contact
us") and no easy retry CTA (it redirects to `/`, not back to the tariff checkout).

**F8 — Debtor self-service and guest/first-time checkout are visually and conceptually disjoint
paths that happen to share a controller.** A returning debtor lands on a differently-styled
dashboard tab with its own CTAs ("Внести N ₽" / "Погасить всё"), while a first-time buyer lands
on the marketing-styled checkout page — reasonable given the different contexts, but there is no
cross-link from checkout back to "already a debtor? go here instead" for a confused returning
user who navigates to checkout directly (e.g., an old bookmarked link).

**F9 — Multi-block "didn't renew" debt still requires per-block checkouts in the common case.**
Per `debtor-self-service-phase2-spec.md` §2, the "pay everything owed in one checkout" bundle is
explicitly *scoped into* Phase 2, meaning the current live behavior for a flat multi-block debt
is N separate Точка checkouts. This is a real, documented, code-confirmed friction point for
exactly the highest-value returning-buyer segment (someone who owes for multiple blocks).

**F10 — Guest form asks for city and birth year with no stated reason.** Both fields read as
account-attribution data collection (confirmed by `AttributionService::applyBirthYear` in
`resolveUser()`) rather than something the buyer needs to give to complete a purchase. City is
required; birth year is optional but unexplained. Neither field has a "why do you need this?"
affordance — a small trust tax on a payment form, where every unexplained required field is a
minor abandonment risk.

## 4. Recommended screen hierarchy

Reordered by what a first-time buyer actually needs, in the order they need it:

1. **Tariff summary** (what am I buying, what's included) — currently 2nd column, sticky; should
   arguably lead for a first-time visitor, since "what do I get" precedes "how do I pay."
2. **Price breakdown + total** — keep as-is (already clear, already live-updating).
3. **Payment method choice** — card (default) vs. PayPal (foreign) should be presented as a
   genuine **choice** at the top of the payment section, not a card CTA + a subordinate PayPal
   footnote. A simple two-tab or two-button "Оплата картой РФ" / "Оплата из-за рубежа" affordance
   would let foreign buyers self-route immediately instead of reading past the whole RF-payment
   form first.
4. **Guest data form** (only if paying by card, only if not logged in) — keep, but move the
   account-collision warning to be *contextual to the email field* (see Copy Recommendations)
   rather than a blanket top-of-page block.
5. **Promo / prana** — keep as-is (already low-friction, AJAX, no page reload).
6. **Submit + trust row** — keep.
7. **Post-payment expectations, moved earlier** — add a one-line "what happens after you pay"
   note *before* the pay button (not just on the success page), so the "couple of minutes" wait
   is expected, not discovered.

## 5. Copy recommendations

- **Guest warning (F1):** split into two tiers instead of one uniform amber block: a quiet
  "already bought before? → message a curator" *link* (not a full warning card) for the common
  case, reserving the bold amber treatment for the actual moment it matters — i.e., trigger it
  contextually when the email field loses focus and the backend would reject it (progressive
  disclosure), rather than showing it unconditionally to every guest.
- **Account-collision error (F2):** append an inline "→ Войти" link/button directly to the
  validation error message itself: *"У вас уже есть аккаунт с этим email. [Войти и оформить
  заказ оттуда →]"* instead of relying on the guest warning block above to carry the CTA.
- **PayPal CTA (F3):** expand from one line to two: what to expect (*"Оплата вручную — доступ
  откроем в течение 1 рабочего дня после сверки платежа"*) + why (*"PayPal не поддерживает
  автосписание на нашей платформе — поэтому проверяем вручную"*). Gives foreign buyers a
  timeframe to hold onto instead of silence.
- **Success page (F6):** *"Доступ откроется в течение пары минут. Если через 10 минут доступа
  всё еще нет — напишите куратору в Telegram, мы разберемся."* — turns an anxious wait into a
  bounded one with an escape hatch.
- **Fail page (F7):** *"Оплата не прошла или была отменена. Если деньги списались — не платите
  повторно, напишите нам, мы проверим и либо вернем доступ, либо деньги."* + a direct
  "Попробовать снова" button back to the same tariff checkout, not just to `/`.
- **Guest form fields (F10):** one small helper line under city/birth-year:
  *"Город и год рождения помогают нам точнее считать статистику курса — не влияют на цену
  и доступ."*

## 6. Implementation tickets

1. **Contextual account-collision warning** — replace the unconditional top-of-page guest
   warning card with a quiet link + a field-level trigger on email blur (client-side check
   against a lightweight "email exists" endpoint, or defer entirely to the existing server-side
   rejection with an inline login CTA in the error partial). *Effort: small.*
2. **Payment-method chooser** — a two-option toggle (card / PayPal) above the guest form,
   replacing the current card-form-then-footnote ordering. *Effort: small-medium* (pure
   Blade/Alpine, no controller change — `paypal-cta.blade.php` already isolates the PayPal
   branch behind a feature flag).
3. **Fail-page retry CTA** — change `PaymentController::fail()`'s redirect target from `/` to
   `route('checkout.show', $tariff)` when the tariff can be resolved from the failed payment's
   session/referrer, with the expanded copy from §5. *Effort: small* (redirect target + copy
   only — the doc's "don't touch payment state" invariant per the existing code comment on
   `fail()` stays untouched).
4. **Success-page bounded wait + escape hatch** — copy-only change to `PaymentController::success()`'s
   flash message. *Effort: trivial.*
5. **PayPal expectation-setting copy** — expand `partials/paypal-cta.blade.php`'s single line
   per §5. *Effort: trivial.*
6. **Field helper text on city/birth_year** — add the one-line rationale under both fields in
   `checkout/show.blade.php`. *Effort: trivial.*
7. **Cross-link checkout → debtor self-service** — if a logged-in user with an open debt lands
   on `/checkout/{tariff}` directly (not via the dashboard's debt CTA), show a small banner:
   *"У вас есть незавершенная договоренность по оплате — перейти в «Мои долги»"* linking to the
   dashboard tab. *Effort: small* (needs `StudentDebtsService::forUser()` check in
   `CheckoutController::show()` — read-only, no logic change to debt calculation itself).
8. **Bundle multi-block debt into one checkout** — already scoped as Phase 2 §2 in
   `docs/debtor-self-service-phase2-spec.md`; this audit does not re-derive it, just confirms
   it's the correct fix for F9 and should stay prioritized. *Effort: already estimated in that
   spec.*

None of tickets 1–7 touch money or access logic — they are template/copy/routing-target changes
only, consistent with this audit's document-only mandate.

## 7. Acceptance metrics

- **Checkout completion rate**: `checkout.show` views → successful `payment.create` submissions
  (pending) → `paid` status, segmented guest vs. authenticated. No current instrumentation found
  in the read files — would need a lightweight funnel log (page view → form submit → paid) to
  measure any of tickets 1–7 against a baseline.
- **Payment-fail recovery rate**: of payments reaching `failed` status, what fraction of the
  same user+tariff pair reach `paid` within 24h. `Payment::status` history already has what's
  needed; this is a query against existing data, no new tracking required.
- **Post-payment support requests**: count of curator-channel (Telegram/VK) messages referencing
  "оплатил, доступа нет" in the first 15 minutes after a `paid` payment — proxy for whether F6's
  fix actually reduces anxious pings. Requires tagging/counting in whatever support-inbox system
  already exists (`docs/debtors-manual.md` implies a curator-facing queue elsewhere in the repo;
  out of scope for this audit to specify further).

_Dr. Mārcis Gasūns_
