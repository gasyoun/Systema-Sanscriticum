# Кабинет самскрте — mobile app (Capacitor wrapper)

_Created: 12-07-2026 · Last updated: 05-09-2026_

Wave 1 of the [mobile-app roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md)
(H824). This is a **Capacitor hybrid wrapper**: a native shell (Android + iOS) around
the **existing responsive web cabinet** at samskrte.ru, shown in a WebView. Courses,
lessons, video, live chat and login are the web cabinet — **nothing is rebuilt
natively**. The native layer is only: shell, splash/status-bar, hardware back-button,
external-link routing, offline fallback, and a config-driven cabinet URL.

Android is the deliverable this wave; iOS platform is scaffolded but its build +
App Store are Wave 5.

## What is native vs. WebView

| Native shell (Capacitor) | WebView (existing web cabinet) |
|---|---|
| Splash, app icon, status-bar / safe-area | Login (email+password / Telegram / VK) |
| Hardware back-button → WebView history | Courses, lessons, **lesson video** |
| External links → system browser | Live support chat (Laravel Reverb) |
| Offline / load-error fallback screen | Everything the cabinet already renders |
| Config-driven base URL (staging/prod) | — |

**Payments (ruling D3):** any buy/upgrade action stays on **web** checkout (Tochka)
and opens in the **system browser**, never a native purchase — reader-app model, no
store 30 % cut. This is enforced by `server.allowNavigation` (below): only the cabinet
host stays in the WebView; Tochka and every other external host open externally.

## Prerequisites

| Tool | For | Notes |
|---|---|---|
| **Node ≥ 20** + npm | everything | this repo pins the Capacitor CLI locally |
| **JDK 21** | Android build | Capacitor 8 / Android Gradle Plugin 8.13 compile against Java 21 |
| **Android Studio** + Android SDK (**API 36**) | Android build & emulator | `minSdk 24`, `compile/targetSdk 36`; or standalone `cmdline-tools` + `platform-tools` with `ANDROID_HOME` set. Gradle 8.14.3 (via the wrapper) |
| Xcode + CocoaPods (macOS) | iOS build | **Wave 5** — not needed for the Android deliverable |

> The Android **debug APK build and on-device smoke** require the JDK + Android SDK
> above (and a device/emulator). The scaffold itself (`npm install`, `cap sync`,
> `cap add android`) needs only Node.

## Setup

```sh
cd mobile
npm install
cp .env.example .env         # then edit .env — set the real hosts
```

### Config-driven base URL (never hardcoded)

The cabinet host is selected by `CAP_ENV` and read from `.env` (see
[`.env.example`](.env.example)) — it is **not** hardcoded anywhere in source. Missing
config fails the build loudly rather than shipping a wrong host.

```sh
# .env
CAP_ENV=staging                       # staging | prod
CAP_URL_STAGING=https://staging.samskrte.ru
CAP_URL_PROD=https://samskrte.ru
```

Switch environments by changing `CAP_ENV` (or exporting it for one build):

```sh
CAP_ENV=prod npm run sync
```

`npm run sync` regenerates `www/generated/env.js` (the host injected into the local
loading/offline pages) and runs `cap sync`.

## Build the Android debug APK

```sh
# 1. add the native Android project (generated, gitignored — see below)
npm run add:android

# 2. build the debug APK (no signing keystore needed for debug)
npm run apk:debug
# → android/app/build/outputs/apk/debug/app-debug.apk
```

Install on a connected device or running emulator:

```sh
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
```

Or open the project in Android Studio and press Run:

```sh
npm run open:android
```

### Exit check (on-device smoke — human/device step)

On a real Android device or emulator: install the debug APK → log in
(email+password) → land on `/dvaram` → open a course → play a lesson video. The
hardware back-button navigates WebView history. A **Tochka** checkout link opens the
**system browser**, not the in-app WebView.

## iOS (Wave 5, macOS only)

```sh
npm run add:ios      # scaffolds ios/ ; requires macOS + CocoaPods to build
```

iOS build, App Store submission, and the **iOS email-only login toggle** (Apple
Guideline 4.8) are Wave 5 — do not build them here.

## App icon & splash

Wave 1 uses Capacitor's default generated icons. To drop in real branding, see
[`resources/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/resources/README.md) (`npm run icons`).

## Why `android/` and `ios/` are gitignored

Per the roadmap ruling (one-owner repo), the generated native projects are **not
tracked** — they are large and fully derived from this config. Regenerate them any
time with `npm run add:android` / `npm run add:ios`. Committed here: the Capacitor
config, the web shell (`www/`), env template, and docs. `node_modules/` and the real
`.env` are gitignored too.

## How the wrapper behaves (implementation notes)

- **WebView content** — `server.url` (from `.env`) points the WebView at the cabinet;
  `server.allowNavigation` = the cabinet host + its subdomains. Any navigation off
  that host (Tochka, external `http(s)`, `mailto:`, `tel:`) is opened by Capacitor in
  the **system browser** automatically. Asset loads (images/CSS/JS) from any host are
  unaffected — `allowNavigation` gates page navigations, not resources.
- **Back button** — with no custom `App.backButton` listener, Capacitor's default
  navigates WebView history back, then exits at the root. That is exactly the desired
  behaviour, so it is left as the default.
- **Session persistence** — the Android WebView cookie/localStorage store persists
  across app restarts by default (not cleared on exit), so a logged-in session
  survives.
- **Splash / status bar** — `@capacitor/splash-screen` + `@capacitor/status-bar`,
  configured in [`capacitor.config.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/capacitor.config.ts) (dark ground `#0b1020`,
  gold accent `#c9a24b`).
- **Offline / error** — `server.errorPath: 'error.html'` shows a local "Нет
  соединения" screen with a **Повторить** button when the cabinet is unreachable.

## Known limitations this wave

- **Safe-area / notch** handling is best-effort from the native shell; how content sits
  under a notch ultimately depends on the **cabinet's own responsive CSS** (a roadmap
  WebView-UX risk — a mobile-responsiveness pass on the core cabinet routes should
  precede store launch). If a cabinet page renders badly on a phone, log it against the
  roadmap risk section — **do not** fix it inside the wrapper.
- **Pull-to-refresh** is deferred: a native SwipeRefreshLayout edit would live in the
  gitignored `android/` project and be lost on regeneration; it is not part of the
  handoff's build steps. Revisit if it becomes a requirement.
- **Push notifications, `POST /api/v1/devices`, store submission, iOS build** — later
  waves (2/3/5), out of scope here.

_Dr. Mārcis Gasūns_
