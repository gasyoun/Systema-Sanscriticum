# Mobile App (Student Cabinet) Roadmap — 2026–2027

_Created: 12-07-2026 · Last updated: 12-07-2026_

Android + iPhone app for the samskrte.ru student cabinet (личный кабинет). This
roadmap is **decision-locked**: every fork below was ruled by MG in a two-round
interview on 12-07-2026 (see [§ Decisions taken](#decisions-taken)). Waves are
ordered so each states what unblocks it; agent-doable Wave 1 is minted as an
executable handoff (see [§ Wiring](#wiring)).

Sibling roadmaps: [ROADMAP_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md) (platform-wide) ·
[ROADMAP_GETCOURSE_PARITY_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_GETCOURSE_PARITY_2026.md) ·
[SECURITY_ROADMAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SECURITY_ROADMAP.md).

---

## Why now / what already exists (audit, 12-07-2026)

The mobile app is **not greenfield** — the reuse surface is large, which is what
makes a wrapper (not a native rebuild) the right call:

| Layer | State on 12-07-2026 | Consequence for this roadmap |
|---|---|---|
| Web cabinet | Full responsive Blade cabinet, post-login lands at `/dvaram`. | The app **wraps this** in a WebView — courses, lessons, video, chat come "for free". |
| Mobile API | `/api/v1` on Sanctum personal-access tokens ([routes/api.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/api.php), PR #167): `auth/login`, `auth/me`, `auth/logout`, `courses`, `courses/{slug}/lessons`. | Auth token channel + optional future native screens already exist. Push-token + progress-write endpoints are the gap. |
| Live support chat | Server-side native chat on **Laravel Reverb** (WS push) — [PublicChatController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicChatController.php), [ChatMessageSent](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Events/ChatMessageSent.php) — built (Phases 0–3), widget pending. | Chat ships in-app via the WebView once the widget lands; no separate mobile chat build. |
| Payments | Tochka Bank web checkout ([WebhookController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/WebhookController.php)); access via `PaymentObserver` → `Group`. | Buying stays on **web** (see payments ruling) — no store 30% cut, no IAP reconciliation. |
| Mobile client | **None** — no Capacitor / React Native / Flutter / Expo / PWA manifest in the repo. | Wave 1 is a from-scratch Capacitor scaffold; everything above is reused, not rebuilt. |
| Deploy | Beget VPS `31.129.104.252`, Ubuntu, nginx + php8.3-fpm; **credential-gated** (contractor Ivan deploys; MG/agents have no prod creds). | API changes reach prod only through Ivan's deploy — the standing throughput gate (see [§ Risks](#risks)). |

---

## Architecture — Capacitor hybrid wrapper

The chosen shape (MG ruling): a **native shell (Capacitor) around the existing
responsive web cabinet**, not a native-UI rebuild against the API.

```
┌─────────────────────────────────────────────┐
│  Native shell (Capacitor)  — iOS + Android    │
│  · splash / icon / safe-area / back-button    │
│  · Push plugin (FCM Android; APNs iOS later)  │
│  · Deep-link router → cabinet route           │
│  · Offline fallback screen                    │
│  ┌───────────────────────────────────────┐    │
│  │  WebView → samskrte.ru cabinet         │    │
│  │  (Blade, responsive)                   │    │
│  │  · courses / lessons / video (reused)  │    │
│  │  · live support chat (Reverb, reused)  │    │
│  │  · login page (email+pw / TG / VK)     │    │
│  └───────────────────────────────────────┘    │
└─────────────────────────────────────────────┘
        │ Sanctum token (native login → push assoc)
        ▼
   Laravel /api/v1  (+ new: POST /devices, progress-write)
```

**What is native vs. WebView:** the shell, push, deep-linking, offline fallback,
and store packaging are native; **all content UI (courses, lessons, video, chat,
login) is the existing web cabinet in a WebView.** This is the entire ROI of the
wrapper choice — we ship the app the LMS already has, plus a native envelope.

The `/api/v1` course/lesson endpoints are **not on the MVP critical path** (the
WebView already renders those pages); they remain for an optional future
native-home-screen. The API work that *is* required is narrow: device-token
registration for push, and a token-auth variant of progress/heartbeat if we ever
render lessons natively.

---

## Waves

### Wave 0 — Prerequisites (human, no code) · unblocks everything

| Item | Owner | Note |
|---|---|---|
| Enroll **Google Play Console** ($25 one-time) | MG | Gates the Android store launch (Wave 3). Do first — Android ships first. |
| Create a **Firebase project** for FCM (Android push) | MG (agent can prep config once the account exists) | Gates push (Wave 2). |
| App **name + icon + splash + store-listing copy/screenshots** | MG (branding) | "Systema Sanscriticum" / «Кабинет самскрте» — MG's call. Blocks store submission, not the build. |
| **Signing keystore** (Android) generated & safely stored | MG / Ivan | Agents cannot hold signing secrets. |
| **Privacy policy URL** + Google Play *Data safety* answers | MG | Required by Play; the app collects account + device-token data. |

### Wave 1 — "It runs on a phone": Capacitor scaffold (agent, PR) · unblocked, start now

Delivered as one executable handoff (see [§ Wiring](#wiring)). No store account
needed to produce an internal Android debug build.

- Capacitor project (Android + iOS platforms) under `mobile/` (or a sibling repo — decided in the handoff), loading the cabinet URL in a WebView.
- Native chrome: splash, app icon placeholders, status-bar/safe-area handling, hardware back-button → WebView history, external links (Tochka, mailto, tel) open in the system browser **not** the WebView.
- Session persistence (cookie/token survives app restart); pull-to-refresh; loading + error states.
- `config`-driven base URL (`staging` vs `prod`) so it never hardcodes the host.
- **Deliverable:** installable Android debug APK for on-device smoke testing + a short `mobile/README.md` build doc.
- **Exit check:** a student logs in and reaches `/dvaram`, opens a course, plays a lesson video, on a real Android device.

### Wave 2 — Push notifications end-to-end (agent, PR) · needs Wave 0 Firebase + Wave 1 shell

- Capacitor Push plugin wired to **FCM** (Android). APNs deferred to the iOS wave.
- New endpoint `POST /api/v1/devices` — register/refresh a device push token per authenticated user (dedup by token; revoke on logout).
- Server-side send: fan the **existing** notification triggers out to device tokens — group start-date ([groups:notify-forming-shortfall](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)), debt/payment reminders (`debts:remind`), new-lesson-published — reusing the notification jobs, **not** a parallel notifier.
- Deep-link: a push taps through to the correct cabinet route inside the WebView.
- **Exit check:** a test push for "new lesson" opens that lesson on a real device.

### Wave 3 — Android store launch (human + agent) · needs Wave 0 account + Wave 1/2

- Signed release build; Play *internal testing* → *closed testing* → *production*.
- Store listing, Data-safety form, content rating, privacy policy live.
- **Payments compliance:** app is a "reader" — enrolled content viewable; any *buy/upgrade* action routes to **web** checkout in the system browser (hybrid ruling). No native purchase button.
- **Exit check:** app live on Google Play, a real student installs and uses it.

### Wave 4 — Live support chat + in-app polish (agent, PR) · needs Wave 1; best after the Reverb widget lands

- Verify the Reverb chat widget behaves inside the WebView (WS over the app's network; reconnect on foreground).
- Optional: tie a new-support-message push into Wave 2's channel.
- Offline fallback screen; verify the "paid on web" flow (Tochka in system browser) round-trips access back into the app.

### Wave 5 — iOS build + App Store (human + agent) · needs Wave 1–4 proven on Android

- Apple Developer Program enrollment ($99/yr); App Store Connect; TestFlight; review.
- **4.8 compliance (the load-bearing iOS task):** the iOS build must present **email+password only**. Because login is the *web* page in the WebView, that page must **hide the Telegram/VK buttons when running inside the iOS wrapper** (detect via a Capacitor-set query param / custom user-agent). Ship this server-side toggle before iOS submission — otherwise the visible social buttons re-trigger Guideline 4.8 and force Sign in with Apple.
- Reader-app wording: no external "buy" links Apple objects to; content-only framing.
- **Exit check:** app live on the App Store, TestFlight → production approved.

---

## Decisions taken

Ruled by MG, 12-07-2026 (two-round `/roadmap-interview`). Recorded verbatim so
future sessions do not re-litigate them.

| # | Fork | Ruling | Rationale |
|---|---|---|---|
| D1 | Platform strategy | **Capacitor hybrid wrapper** | Reuses the full responsive web cabinet + Reverb chat + existing API; real store apps on both platforms from one web codebase. Native rebuild rejected as disproportionate for this LMS's scale. |
| D2 | MVP scope | courses+lessons+progress · **lesson video/content** · **push** · **live support chat** | All four are delivered by the WebView + a native push layer; none requires a native-UI rebuild. |
| D3 | In-app purchasing | **Hybrid — free/enrolled content in app, paid actions on web** | Avoids the Apple/Google ~30% cut, keeps Tochka + `PaymentObserver` access grants intact, sidesteps IAP↔Tochka reconciliation. |
| D4 | Login methods | Email+password **+ Telegram + VK** (Android/web) | Telegram is the primary RU channel (bots already wired); VK matches the `social_accounts` canon; email+password already built in [Api\AuthController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/AuthController.php). |
| D5 | iOS login (Apple 4.8) | **iOS = email+password only** | Apple Guideline 4.8 forces Sign in with Apple if any third-party social login is offered. Making iOS email-only removes that obligation entirely — no SIWA integration needed. |
| D6 | Build owner | **Claude/agent via PRs** | Scaffold + API extensions ship as PRs off `origin/main` (repo's normal flow); humans do store signing/submission (agents are not permitted to). |
| D7 | Store sequencing | **Google Play / Android first, App Store later** | Faster + cheaper review, RU audience skews Android, and iOS carries the extra 4.8 toggle work — prove the model on Android first. |

---

## Non-goals (considered and ruled out — do not re-propose)

- **Fully native (Swift + Kotlin)** — two codebases, ~2× maintenance; rejected (D1).
- **Flutter / React Native native-UI rebuild** — would require fleshing out the whole API and re-implementing the lesson player; rejected (D1).
- **Native in-app purchases (IAP)** — rejected (D3); the ~30% cut and Tochka reconciliation are not worth it.
- **Sign in with Apple** — avoided by making iOS email-only (D5); do not add it "for completeness".
- **PWA-only (no store presence)** — rejected; MG wants true App Store + Play Store apps.
- **A parallel mobile notification stack** — Wave 2 reuses the existing notification jobs, not a new notifier.

---

## Risks

- **WebView UX depends on cabinet responsiveness.** If any cabinet page renders poorly on a phone, it shows up in the app. A quick mobile-responsiveness pass on the core cabinet routes should precede Wave 3 launch.
- **Push infra ops** — FCM (Android) and later APNs (iOS) need certificates/keys, token lifecycle, and a send path; the Wave-2 endpoint is easy, the ops setup is the real work.
- **Apple review risk** — reader-app framing + email-only iOS mitigates 4.8 and external-purchase objections, but review is never guaranteed; budget a revision cycle.
- **Deploy gate (standing bottleneck).** API changes (`POST /api/v1/devices`, progress-write) reach prod only through the credential-gated deploy (contractor Ivan). Client-only Capacitor work is unaffected, but any server dependency inherits this gate — see [project memory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md) and Uprava's deploy-gate facts doc.

---

## Wiring

- **Wave 1 handoff:** minted atomically via `mint_handoff.py` — see [§ Wiring](#wiring) commit / the Uprava handoffs registry. Executes the Capacitor scaffold (agent, PR).
- **Human actions** (Wave 0 accounts/branding, signing, store submissions) mirrored to [Uprava/GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) as `@DO`/`@DECIDE`.
- `.ai_state.md` Next Steps points here.

---

_Dr. Mārcis Gasūns_
