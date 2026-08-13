# Offline cabinet Wave 0 — Windows 11 installed Edge PWA verification (H2618)

_Created: 12-08-2026 · Last updated: 13-08-2026_

Handoff: [H2618](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2618-Sonnet_Systema-Sanscriticum_offline-cabinet-w0-windows-edge-pwa-verification_12.08.26.md) ·
plan: [PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md) ·
verification contract: [VERIFICATION_SYSTEMA_OFFLINE_CABINET.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md) ·
parent spike: [OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md).

## Verdict

**STOP — this platform's V0 physical row is BLOCKED, not PASS.** Two independent,
compounding reasons, both evidenced below:

1. **Host OS mismatch.** R18 and this handoff's own title require **Windows 11**. The
   executor host is **Windows 10 Pro, build 19045** (`Get-ComputerInfo`). No Windows 11
   host or VM was available in this session.
2. **The site could not be genuinely installed as an Edge PWA on this host**, even setting
   the OS mismatch aside. Chromium's own installability engine (`Page.getAppManifest`,
   `Page.getInstallabilityErrors`) reports `no-manifest` and `beforeinstallprompt` never
   fires, despite a spec-valid, fetchable manifest and a present `<link rel="manifest">`
   tag. Five independent repair attempts (enumerated below) did not resolve it. Without a
   genuine install, there is no installed-PWA window to run the cold-start/restart/
   cross-profile proofs against — per this handoff's own guardrail, a normal Edge tab is
   never installed-PWA evidence, so those five proofs stay unattempted rather than faked.

No plaintext fallback was introduced. No deploy, flag, or pilot change was made. Nothing
in csl-orig/production was touched — all testing ran against a local-only worktree clone
with a local SQLite DB and a throwaway test account, torn down at the end of this session.

## Host inventory (discovery receipts)

| Field | Value | How obtained |
|---|---|---|
| OS | Windows 10 Pro, build 19045 (`WindowsVersion 2009`) | `Get-ComputerInfo -Property WindowsProductName,WindowsVersion,OsBuildNumber` |
| Edge | 151.0.4129.78 (current stable channel) | `(Get-Item "...\msedge.exe").VersionInfo.ProductVersion` |
| Edge policies | Only `ExtensionManifestV2Availability=2` (Platform/Device, unrelated to PWA install); no web-app-install policy set | `edge://policy/` read via CDP `Runtime.evaluate` |
| Node | 24.15.0 | `node -v` |
| PHP | 8.3.32 | `php -v` |
| Automation tooling present in repo | None (no Playwright/Puppeteer/WebDriver dependency; `claude-in-chrome` MCP tools not connected to this session) | `package.json` grep; `ToolSearch` for chrome-automation tools returned no matches |

No installed-PWA browser-automation harness pre-existed for this repo or this host. One was
built for this session from Node's built-in `WebSocket`/`fetch` (Node ≥22) talking raw
Chrome DevTools Protocol directly to Edge (`--remote-debugging-port`) — no npm dependency
added, nothing committed (throwaway scripts lived in the session scratchpad only).

## Test environment (local-only, torn down)

- Fresh worktree `Systema-Sanscriticum-h2618-110877` off `origin/codex/offline-cabinet-roadmap`
  (the branch carrying H2597's spike + PWA shell).
- `vendor/` via `composer install` (fresh — composer.lock had diverged from the main
  clone), `node_modules/` via `npm install`, `npm run build` (Vite; spike.ts entry per
  `vite.config.js:12`).
- `.env`: `DB_CONNECTION=sqlite`, a throwaway `database/h2618_verify.sqlite`,
  `APP_URL=http://127.0.0.1:8123`. `php artisan migrate` (full schema, clean run).
- One throwaway local user (`h2618-pwa-verify@example.test`) created via
  `User::factory()->create()` directly in this local SQLite DB — no production data, no
  production credentials, no payment/entitlement path touched.
- `php artisan serve --host=127.0.0.1 --port=8123` (local dev server only, never exposed).
- Isolated Edge profile: `msedge.exe --remote-debugging-port=9333
  --user-data-dir=<fresh temp dir> --no-first-run --no-default-browser-check`. This profile
  touched nothing in the user's real Edge profile/history/installed-apps list.
- All of the above — sqlite file, `.env`, temp Edge profile, worktree — are local-only and
  were torn down (server stopped, Edge processes stopped) at the end of this session. The
  worktree itself is removed once the PR opened by this handoff is merged, per the standing
  worktree-isolation contract.

## What was proven — installability (BLOCKED)

Genuine login (`AuthController@login` form POST via CDP `Runtime.evaluate`, real session
cookies) reached `/dvaram`, which extends `layouts.student.blade.php`
(`<link rel="manifest" href="http://127.0.0.1:8123/manifest.webmanifest">`, confirmed
present via `document.querySelector` and via `outerHTML`). `sw.js` registered and reached
`active` state (`navigator.serviceWorker.ready`).

Five independent repair attempts, each targeting a different plausible cause, all left
`Page.getInstallabilityErrors` reporting `{"errorId":"no-manifest"}` and
`Page.getAppManifest` returning an empty `url` with only page-derived defaults (not the
manifest's actual `name`/`display`/icons):

1. Reload the page after the service worker reached `active` (in case Chromium's manifest
   fetch is gated on an active controller) — no change.
2. Fresh top-level navigation plus a 3-second settle before querying installability (in
   case the manifest fetch is async and was queried too early) — no change.
3. Checked for a `Content-Security-Policy` header that could block Blink's internal
   `manifest-src` fetch while still allowing a page-level `fetch()` to succeed (which would
   explain the split: `fetch('/manifest.webmanifest')` returned `200
   application/manifest+json` throughout) — no CSP header present on either `/login` or
   `/dvaram`.
4. Checked `edge://policy/` for an enterprise policy disabling web-app installation — only
   one policy is set org-wide (`ExtensionManifestV2Availability`), unrelated to PWA install.
5. Checked `navigator.webdriver` (Chromium suppresses some engagement/install heuristics
   under `--enable-automation`) — `false`. This session's CDP launch used only
   `--remote-debugging-port`, not `--enable-automation`, so this was already unlikely; ruled
   out directly rather than assumed.

`beforeinstallprompt` never fired in a 5-second poll after each attempt. Per the handoff's
guardrail this is where the physical-install proofs stop — I did not fabricate a PASS from
in-tab crypto results, and I did not try `--app=<url>` "app mode" as a substitute, since
Chromium's app-mode window is explicitly a normal browser window skin, not a real
WebAppProvider-registered install (no Start Menu entry, no `chrome://apps`/`edge://apps`
registration, no isolated profile partition) — treating it as installed-PWA evidence would
be exactly the "normal Edge tab" fabrication the handoff bans.

**Open question this STOP leaves for a human:** whether `no-manifest` here is a genuine
product defect reachable by a real (non-automated) user clicking "Install" in Edge's
omnibox, or an artifact specific to CDP-driven navigation on this exact host — I could not
distinguish the two without a manual, non-automated click-through, which needs a human at
the keyboard rather than a shell session. Recommended follow-up either way: (a) a human
manually visits the local or a deployed build of this branch in Edge and reports whether the
install icon appears in the omnibox, and (b) once a Windows 11 host/VM is available, rerun
this same harness there — the CDP scripts used in this session are reproducible (see
methodology below) and can be handed to that follow-up directly.

## What was proven — crypto/storage capability (supplementary, real browser, non-substitutive)

These ran in the same real Windows 10/Edge 151 tab (not an installed PWA — explicitly
**not** a substitute for the blocked V0 gate above, kept only as evidence that the
underlying platform primitives the crypto design depends on genuinely hold on this OS/
browser combination, beyond the `fake-indexeddb` unit tests). All five ran against the
actual built `spike.ts` bundle (`public/build/assets/spike-DtFIeg4D.js`, loaded via a real
dynamic `import()` in the page — not re-typed equivalent code):

| Proof | Result |
|---|---|
| Non-exportable key (`extractable:false`) persists across a fresh `IndexedDbDeviceKeyVault` open (restart simulation) and cannot be exported | `extractable1:false`, `exportRejectedWith:"InvalidAccessError"`, `sameKeyAfterReopen:true` |
| AAD tampering (chunk index changed) rejects decryption | `tamperedIndexRejected:true` |
| Ciphertext encrypted under one device key fails to decrypt under a different device key | `crossDeviceRejected:true` |
| Resumable chunked download via real `Cache Storage`: 3×1 MiB chunks downloaded once, a second `resumeEncryptedAsset` call against the same identity reuses all 3 with zero network calls; cache inspected for plaintext | `firstDownloaded:[0,1,2]`, `secondReusedAll:true`, `secondTriggeredNetworkCalls:0`, `cacheEntryCount:3`, `anyPlaintextDetectedHeuristically:false` |
| Encryption overhead + timing (8 MiB) | `encryptMs:30.5`, `decryptMs:23.7`, `storageOverheadPercent:0.0027` — well inside the ≤20% budget |
| `navigator.storage.estimate()` reachable and populated | `quota:10740607662`, `usage:3189422` (caches/indexedDB/serviceWorkerRegistrations broken out) |
| Offline-fallback shell (existing H1488 `sw.js`, network-first + `offline.html`) under CDP `Network.emulateNetworkConditions({offline:true})` | Reload of `/dvaram` served `offline.html` (`title:"Нет сети — ОРС LMS"`) instead of failing — confirms the already-shipped shell degrades correctly on real Edge, independent of the new crypto spike |

All of the above ran against the real browser's `crypto.subtle`, `indexedDB`, and
`caches` APIs — not a Node/fake-indexeddb harness. They corroborate (do not replace) the
6 passing cases in `npm run test:offline-spike`.

## Methodology (reproducible)

No Playwright/Puppeteer/WebDriver dependency was added. A ~120-line raw-CDP client
(`WebSocket`/`fetch`, both built into Node ≥22) drove Edge over
`--remote-debugging-port`: page navigation, form-fill-and-submit via `Runtime.evaluate`,
service-worker registration/await, `Page.getAppManifest`/`Page.getInstallabilityErrors`
for installability diagnostics, `beforeinstallprompt` capture with `userGesture:true`
`Runtime.evaluate` (the same technique Lighthouse's install-prompt audit uses) for the
install attempt itself, and `Network.emulateNetworkConditions` for the offline-fallback
check. None of this was committed — it is throwaway session tooling, not a shipped test
harness; a future physical-verification handoff on a genuine Windows 11 host can reuse the
same technique from scratch in a few minutes.

## Decision

Do not mark the Windows row PASS. Do not start Wave 1 on the strength of this row alone —
per the plan, H2597 stays open until all three physical rows (Android/H2617, Windows/H2618,
iPhone/H2619) return evidenced PASS or platform-scoped STOP, and parent consolidation
updates the aggregate report (out of scope for this handoff — the aggregate report was not
edited here). Two concrete unblocks for a human: (1) provision or borrow a Windows 11
host/VM and rerun this harness, (2) independently of the OS question, have a human manually
attempt "Install" in Edge's omnibox against this branch to settle whether `no-manifest` is a
real product defect or a CDP-navigation artifact — if it turns out to be a real defect, it
blocks Windows PWA installability for every user on every Windows version, not just this
verification pass, and deserves its own bug regardless of R18's OS pin.

---

_Dr. Mārcis Gasūns_
