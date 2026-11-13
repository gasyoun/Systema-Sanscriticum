# Route audit: non-panel mutation surfaces vs 2FA gate — H7830 (08-11-2026)

_Audit executor: OxAlpha (zai-coding-plan/glm-5.3-flash). Ruling base: H6352-r7 (MG 07-11-2026) — «2FA гейтит только панели /admin и /editor»; surfaces of data change stay gated even when registered outside panel stacks (H7830, findings F1/F2 of the 08-11-2026 estate code-risk review). Gate middleware: `App\Http\Middleware\RequireTwoFactor` (role-scoped: SUPER_ADMIN, ADMIN, MANAGER, ACCOUNTANT; session-scoped proof via `TwoFactorService::SESSION_PASSED`)._

_Method: full read of `routes/web/01..07`, `routes/api.php`, `routes/web.php` (no `php artisan route:list` on this box — no PHP; static audit cross-checked against controller self-gates). Every route carrying POST/PUT/PATCH/DELETE outside the Filament panel stacks is listed with its gate ruling._

## Verdict table

### `routes/web/06-editor-and-misc-public.php` — admin/editor staff surfaces

| Route | Method | Mutates | Gate status | Ruling |
|---|---|---|---|---|
| `/editor/lectures/{draft}/patch` | POST | Rewrites lecture draft `data.json` (+ optional HTML rebuild) | **RE-GATED (H7830)** — `RequireTwoFactor` added on the route | The defect: panel functionality («Редактор лекций», Filament `/editor`) registered outside the panel stack; lost the gate when r7 dropped it from group `web` |
| `/editor/lectures/{draft}/preview` | GET | read (draft HTML) | open | r7: reads stay open |
| `/editor/lectures/{draft}/asset/{path}` | GET | read (draft asset) | open | r7: reads stay open; path traversal guarded in controller |
| `/admin/surveys/{slug}/export` | GET | read (CSV export of survey respondents) | open | r7: reads stay open. **Watch-item for MG:** staff-facing personal-data export sits outside panels; self-gated admin/manager in controller. Gating is a read-surface decision beyond H7830's mutation scope |
| `/admin/surveys/{slug}` (funnel) | GET | read (aggregates) | open | r7: reads stay open |
| `/teacher-calendar/feed/regenerate` | POST | rotates the user's own iCal feed token | open | staff-facing but **self-service** (own token, own schedule); r7 cabinet-open branch applies. Gating would only bite admin-role users (TEACHER is not in `twoFactorRequired()`) doing self-service — same class as a student regenerating their feed token |
| `/gift/activate` (POST), `/anketa/{slug}` (POST), `/chat/message`, `/support/presence`, `/partners/register`, `/chat/*`, `/cc/{slot}/{sig}` | POST | public intake | open | public surfaces; guests/students; never admin-only |

### `routes/web/01-checkout-and-storefront.php` — storefront (guest/student)

| Route | Method | Ruling |
|---|---|---|
| `/checkout/{tariff}/promo`, `/promo/remove`, `/installments` | POST | open — checkout self-service, r7 |
| `/online/zhdun/vote`, `/unvote` | POST | open — public poll |
| `/favorites/toggle` | POST | open — cabinet self-service |
| `/online/konsultaciya*`, `/online/Janvar-27*` (register/pay/complete/quiz) | POST | open — public marathon flows (token-signed completion links) |
| `/k/podgotovitelnaya-gruppa-sanskrita/start` | POST | open — public enrollment |
| `/api/games/round`, `/api/games/event`, `/api/games/srs-onboarding-import` | POST | open — public game APIs (guest/student) |

### `routes/web/02-auth-and-public-pages.php` — auth & public

`/login`, `/logout`, `/shop/login`, `/shop/logout`, `/register`, `/forgot-password`, `/reset-password`, `/institut/zayavka`, `/mecenaty/donate` — all open. Auth surfaces MUST stay reachable for an un-proofed admin (the gate redirects to `two-factor.show`); logout is passed through by `RequireTwoFactor` by design.

### `routes/web/03-student-cabinet.php` — student cabinet (auth self-service)

Calendar feed regenerate (student), attendance notices, dvaram SRS/practice/agent, reading packs, grammar lab, visual DCS, RQ4 study, support callback, membership cancel/resume, course quizzes/notes/homework (incl. file move/delete), heartbeat, telemetry, debt promise/course payments, prana transfer/redeem, telegram/vk connect, profile password/email-link/notifications/deletion-request/timezone — **all open**: r7 explicitly keeps the cabinet usable for admin-role users without the second factor («админ-роль читает публичные страницы и кабинет без второго фактора»). Re-gating these would re-break the cabinet for admins — exactly what r7 removed.

### `routes/web/04-technical-and-marketing.php` — public lead surfaces

`/leads/store`, `/leads/one-click`, `/subscribe`, `/otpiska` (token-signed), `/interest/{course}`, `/support/link/{token}` (token-signed) — open.

### `routes/web/05-payments-and-money.php` — payment initiation

`/payment/create`, `/deposit/{course}`, `/trial/{course}`, `/rung-placement`, `/paypal/{tariff}`, `/bank/{tariff}`, `/teacher-pay/{tariff}`, `/gasuns-pay/{tariff}`, `/invoice/{tariff}` — open: student/payer self-service checkout initiation. Admin-role users paying as customers are ordinary users (r7). Webhook/money-confirmation side is on `routes/api.php` signatures, not sessions.

### `routes/api.php` — machine endpoints (out of the session-2FA model)

`/sync-lessons`, `/lessons/from-zoom`, `/lessons/{lesson}/transcript`, `/telegram/webhook`, `/vk-webhook`, `/webhooks/*` (tochka, paypal, zoom, magnets, lead-step, exam-scores, lecture-clip, telegram-zapisi, telegram-business, inbound-email), `/partner-bot/*`, `/anons-api/*`, `/lesson-banners/{banner}/delivered`, `/public/schedule/book`, `/public/waitlist/vote`, `/api/auth/*` — **unchanged**: webhook surfaces are signature-gated, token surfaces are Sanctum/token-gated. `RequireTwoFactor` is a web-session gate — it cannot and must not apply to non-session API traffic. API mutations of admin dignity (`sync-lessons` etc.) are authorized by token + controller checks; flagging any gap there is a separate lane.

### Livewire vendor route — the residual (H7830)

`POST /livewire/update` (Livewire v3.8.3, `src/Mechanisms/HandleRequests/HandleRequests.php`) carries **only group `web`** — no panel gate runs on it. Any Filament panel component whose update POSTs to the global endpoint was therefore un-gated at the update level.

**Resolution (gated branch):** `Livewire::addPersistentMiddleware(RequireTwoFactor::class)` in `AppServiceProvider::boot`. Livewire v3 persistent middleware (`src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php`) re-runs a route's middleware during the update request **only for components whose original render route carried it** (snapshot `memo.path`/`memo.method` → route match → middleware intersected with the persistent allowlist). Effects:

- panel components (rendered via `/admin`, `/editor` stacks carrying `RequireTwoFactor`) → gate re-checked on every update; admin-role user without proof gets the `two-factor.show` redirect;
- public/cabinet components (`App\Livewire\Shop`, `SrsDeckEditor`, `SrsReview`, `StudentChat`, `StudentDictionary`, `StudentPayments` — rendered through gate-less routes) → middleware not in their route's list → **not re-run, stay open** per r7;
- the same persistence automatically covers any future component rendered on a route we gate (e.g. Livewire components mounted on gated pages).

Chosen over payload/snapshot-name parsing (brittle across Livewire/Filament versions) and over a blanket gate on the endpoint (would bounce admins using public/cabinet components — contradicts r7).

## Post-H7830 gate placement (canonical)

1. Filament `/admin` stack — `AdminPanelProvider` middleware (unchanged, r5).
2. Filament `/editor` stack — `LectureEditorPanelProvider` middleware (unchanged, r5).
3. Non-panel data-mutation routes — per-route `RequireTwoFactor` (H7830: `editor.lecture.patch`).
4. Livewire update flow for panel components — persistent-middleware registration (H7830).
