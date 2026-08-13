# Offline cabinet Wave 0 — iPhone Safari PWA and Capacitor WKWebView evidence

_Created: 13-08-2026 · Last updated: 13-08-2026_

Handoff: [H2619](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2619-Opus_Systema-Sanscriticum_offline-cabinet-w0-iphone-wkwebview-verification_12.08.26.md)
(Opus 5, `claude-opus-5`) — the iPhone row of
[H2597](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2597-Codex_Systema-Sanscriticum_offline-cabinet-wave0-spike_12.08.26.md).
Branch `claude/h2619-offline-iphone` off `codex/offline-cabinet-roadmap` at commit
`e50dd06767f02ef27a2462d650ee31c10e28b3d7`, targeting open PR
[#1609](https://github.com/gasyoun/Systema-Sanscriticum/pull/1609).
Baseline: [OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md) ·
gates: [VERIFICATION_SYSTEMA_OFFLINE_CABINET.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md) ·
plan: [PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md).

> The filename carries the Wave-0 series date `2026-08-12` because H2619 names that exact
> path. The run itself happened 13-08-2026; the header dates are the real ones.

## Verdict — two separately adjudicated lanes

| Lane | Verdict | Basis |
|---|---|---|
| A — installed Safari PWA on iPhone | **BLOCKED — host missing** | No macOS host, no physical iPhone; nothing on this executor can install or cold-start an iOS PWA. Additionally **STOPPED** on tracked defect **B1** below, which a device would not have cleared. |
| B — Capacitor remote-origin WKWebView on iPhone | **BLOCKED — host missing** | No macOS/Xcode/CocoaPods, no device. Additionally **STOPPED** on tracked defects **B2** and **B3** below, either of which forecloses PASS with a device present. |

Neither lane is PASS, PARTIAL, or INCONCLUSIVE-pending-analysis. Per the handoff's own
rule, missing evidence is BLOCKED, never PASS, and a Safari-only pass never substitutes
for WKWebView — here neither lane was runnable at all, so no substitution was attempted.

**The device is necessary but not sufficient.** The material result of this pass is not
the host receipt — it is that a static audit of tracked configuration found three
defects that would each have produced a STOP verdict on a fully provisioned iPhone. They
are cheap to fix now and expensive to discover on a borrowed macOS host.

## Host inventory — command receipts

Executor: Windows 10 Pro 10.0.19045, MSYS/MinGW64 shell, Node 24.15.0, npm 11.12.1.

| Command | Result |
|---|---|
| `uname -a` | `MINGW64_NT-10.0-19045 WIN-NJTORH3267V 3.6.6-1cdd4371.x86_64 ... x86_64 Msys` |
| `sw_vers` | `bash: sw_vers: command not found` — not macOS |
| `xcodebuild -version` | `bash: xcodebuild: command not found` |
| `xcrun --version` | `bash: xcrun: command not found` |
| `xcrun xctrace list devices` | `bash: xcrun: command not found` — no device enumeration possible |
| `idevice_id -l` | `bash: idevice_id: command not found` — no libimobiledevice fallback |
| `pod --version` | `bash: pod: command not found` — no CocoaPods |
| `swift --version` | `bash: swift: command not found` |

Required and absent: macOS, Xcode, CocoaPods (or the tracked Capacitor package-manager
path), a physical recent iPhone, and Safari Web Inspector. Device model, iOS version,
Mobile Safari version, and WKWebView version are therefore **unrecorded** — not
estimated, not carried over from another platform.

## Tracked-config defects that block the iPhone lanes independently of the host

### B1 — the service worker deletes the encrypted chunk cache on every activation (Lane A)

[`public/sw.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/public/sw.js)
declares `const CACHE = 'ors-cabinet-shell-v1'` and its `activate` handler deletes every
other cache:

```js
caches.keys().then((keys) =>
  Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
)
```

The Wave 0 store writes ciphertext to a different cache —
`CacheEncryptedChunkStore` defaults to `systema-offline-spike-encrypted-v1`
([`resources/js/offline/spike.ts:159`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/resources/js/offline/spike.ts)).
That name is in the delete set. The `install` handler calls `self.skipWaiting()` and
`activate` calls `self.clients.claim()`, so activation is immediate on any `sw.js` byte
change, not deferred to the next cold start.

Consequence for Lane A: every service-worker update **silently destroys all downloaded
encrypted lesson content**, on every platform, iPhone included. The user-visible failure
is a cabinet that reports content as downloaded and then cannot open it offline. This is
the risk row already ruled in the verification contract — *"Current service worker
deletes unrelated caches → Replace activation policy before storing content"* — now
confirmed as present in tracked code rather than hypothetical. The activation policy must
be replaced **before** any content is stored, exactly as ruled.

Not fixed here: H2619 fences this pass to two documentation files, and the fix belongs to
the SW owner alongside a cache-migration test.

### B2 — the remote/local origin split leaves the offline fallback with no access to the store (Lane B)

[`mobile/capacitor.config.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/mobile/capacitor.config.ts)
sets `server.url` to the remote cabinet host and `server.errorPath: 'error.html'`, with
`webDir: 'www'`. Online, the WKWebView runs on the remote `https://<cabinet-host>`
origin, which is where a WebCrypto device key in IndexedDB and any Cache Storage
ciphertext are written. Offline, the remote origin will not load, and Capacitor falls
back to the bundled
[`mobile/www/error.html`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/mobile/www/error.html),
served from the local bundle scheme (`capacitor://localhost` by default on iOS).

Those are different origins, so the fallback page cannot reach the device key or the
encrypted chunks under the same-origin policy. `error.html` is in fact a "Нет соединения"
screen with a **Повторить** button — a retry prompt, not an offline reader — which
[`mobile/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/mobile/README.md)
already describes as the intended Wave 1 behaviour.

Consequence for Lane B: cold offline launch as currently configured reaches a retry
screen, and the V0 claim "cold-start offline and read encrypted content" cannot pass on
any device. This is the first risk row in the verification contract — *"Capacitor
remote/local origin split prevents cold offline launch → V0 spike; stop affected
platform"* — reaching its documented stop condition. Resolving it is an architectural
decision (a local-origin offline reader shell reading a store written under the same
local origin, versus a remote-origin-only design that cannot serve offline content),
**not** a configuration tweak, and it is owed before an iPhone session is worth booking.

### B3 — no tracked `OfflineCrypto` bridge exists, so the native probe cannot return PASS (Lane B)

`probeNativeCrypto()` fails closed unless `window.Capacitor.Plugins.OfflineCrypto` is
present ([`resources/js/offline/spike.ts:266`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/resources/js/offline/spike.ts)).
A repository-wide search for `OfflineCrypto` outside `node_modules` returns only the
TypeScript type, the probe itself, and prose in the baseline doc, `mobile/README.md`,
`changelog.md`, and `.ai_state.md` — **no implementation** in any tracked Swift,
Objective-C, Kotlin, Java, or plugin-registration file. `mobile/package.json` pins
`@capacitor/filesystem` 8.1.2 and `@capacitor/file-transfer` 2.0.5, which the baseline
doc correctly scopes to asset I/O only.

Consequence for Lane B: the Keychain-backed non-exportable key claim is unreachable on a
device today, because there is nothing behind the bridge to probe. The fail-closed
contract is behaving exactly as designed — this is a missing implementation, not a
defect in the probe.

H2619 permits implementing "the smallest tracked Capacitor 8 spike necessary" if no
accepted bridge exists. Not done here, deliberately: a Swift Keychain plugin authored on
a Windows host cannot be compiled, run, or security-reviewed against a real Secure
Enclave, and shipping unverifiable native crypto would convert a clean BLOCKED into an
untested implementation claim. It is the first task of the provisioned session, and it is
blocked behind B2 in any case — a bridge is useless if the offline page cannot reach the
store.

## V0 claim-by-claim status

| Required claim | Lane A (Safari PWA) | Lane B (WKWebView) |
|---|---|---|
| Device/OS/browser/WebView version inventory | BLOCKED — no device | BLOCKED — no device |
| Online setup from remote origin | BLOCKED — no device | BLOCKED — no device |
| Cold offline launch after termination / force-quit | BLOCKED — no device; **B1** destroys the store on SW update | BLOCKED — no device; **B2** forecloses it by design |
| Native bridge reachable offline | n/a (no bridge in a PWA) | BLOCKED — **B3**, no tracked bridge |
| Non-exportable persisted key survives restart | BLOCKED — no device (fake-IndexedDB proof only) | BLOCKED — **B3** |
| No raw key material in JavaScript | n/a | BLOCKED — **B3** |
| Encrypted Cache Storage / filesystem chunks | BLOCKED — no device; **B1** | BLOCKED — **B2**/**B3** |
| PDF and audio resume after interruption | BLOCKED — no device | BLOCKED — no device |
| Cross-profile / cross-device ciphertext rejection | BLOCKED — no device | BLOCKED — no device |
| Plaintext inspection of cache/filesystem | BLOCKED — no device | BLOCKED — no device |
| Budgets: ≤2 s offline open, ≤20 % overhead | BLOCKED — no device | BLOCKED — no device |

No timings were measured on iPhone. The `≈0.0027 %` storage expansion figure in the
baseline doc is arithmetic over the 12-byte IV plus 16-byte GCM tag per 1 MiB chunk and
is platform-independent; it is **not** an iPhone measurement of the ≤20 % end-to-end
download/encryption overhead budget, which remains unmeasured.

## What was actually run

The shared, platform-independent contract was re-run at this commit to confirm the
branch head is green before recording the blocked verdicts:

```text
npm ci --no-audit --no-fund        # added 127 packages in 1m
npm run test:offline-spike
  vitest run resources/js/offline/spike.test.ts   (Vitest 3.2.7)
  Test Files  1 passed (1)
  Tests       6 passed (6)   76ms
```

Environment: Windows 10 Pro 10.0.19045, Node 24.15.0, npm 11.12.1, worktree at
`e50dd06767f02ef27a2462d650ee31c10e28b3d7`.

This uses fake IndexedDB in Node and is **not** iPhone persistence evidence, exactly as
the baseline doc states of the same suite. It appears here only as a receipt that no
regression was introduced under this branch and that the blocked verdicts are not
masking a broken contract.

## What a properly provisioned session must do

Prerequisites, in order — the first two are code changes, not device steps, and booking
macOS time before they land wastes the session:

1. Replace the `activate` cache-deletion policy in `public/sw.js` with a versioned
   allowlist that preserves the encrypted content cache, plus a cache-migration test
   (clears **B1**).
2. Rule and implement the Capacitor origin model for offline reading (clears **B2**).
3. Implement the smallest tracked `OfflineCrypto` Capacitor 8 bridge performing AES
   inside the iOS Keychain and never returning raw key material to JavaScript, with the
   plugin source tracked under `mobile/` and reproducible via `npm run add:ios` — never
   left only in gitignored `mobile/ios` (clears **B3**).

Then, on macOS with Xcode, CocoaPods, and a recent physical iPhone, record for both
lanes: exact device model, iOS, Mobile Safari and WKWebView versions, Xcode and Capacitor
and plugin versions; build/install commands; the online setup, termination, and cold
offline launch sequence; Safari Web Inspector and Xcode logs; non-exportability and
restart-persistence tests; filesystem and Cache Storage plaintext inspection; the PDF and
audio resume transcript; the cross-device ciphertext rejection negative test; and the
timing budgets.

## Decision

Wave 0 remains open. Both iPhone lanes are BLOCKED. Do not start Wave 1, do not deploy,
do not enable a flag, and do not weaken encryption or introduce a plaintext fallback to
work around **B1**–**B3**. No encryption was weakened, no plaintext path was added, and
no native project was edited in this pass.

---

_Dr. Mārcis Gasūns_
