# Offline cabinet Wave 0 — Android/WebView device verification (H2617)

_Created: 12-08-2026 · Last updated: 13-08-2026_

Handoff: [H2617](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2617-Opus_Systema-Sanscriticum_offline-cabinet-w0-android-webview-verification_12.08.26.md) ·
plan: [PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/PLAN_SYSTEMA_OFFLINE_CABINET_2026H2.md) ·
verification contract: [VERIFICATION_SYSTEMA_OFFLINE_CABINET.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/VERIFICATION_SYSTEMA_OFFLINE_CABINET.md) ·
parent spike: [OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md) ·
sibling platform row: [OFFLINE_CABINET_WAVE0_WINDOWS_EVIDENCE_2026-08-12.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_WINDOWS_EVIDENCE_2026-08-12.md).

Executor: Opus 5 (`claude-opus-5`).

## Verdict

**BLOCKED — host missing.** Not PASS, and not a platform STOP either: nothing about the
Android target was disproven, because nothing about it could be *executed*. The executor
host has **no part** of the required Android toolchain — no JDK, no Android SDK, no
platform-tools/ADB, no Android Studio — and **no Android device is attached**. The
emulator fallback is independently non-viable on this hardware (hardware virtualization
disabled in firmware, no SLAT, insufficient free disk).

### Superseded in part by H2634 — read this before the receipts

[H2634](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2634-Opus_Systema-Sanscriticum_offline-cabinet-w0-unblock-sw-cache-origin-bridge_13.08.26.md)
(Opus 5) merged **during this session** ([PR #1636](https://github.com/gasyoun/Systema-Sanscriticum/pull/1636),
13-08-2026 10:43Z), after this pass had already branched at `0979f025` — so everything below
was measured against a tip that did not yet carry its rulings. Two of them narrow what this
document means, and both correct its original framing rather than support it:

1. **The Android target is two lanes, and only one is still owed.** H2634's B2 ruled the
   Capacitor remote/local origin split unresolvable and **stopped the wrapper for offline
   content** on every platform — Capacitor hosts one origin per WebView, so the Android
   WebView lane is stopped for exactly the reason the iPhone WKWebView lane is. What
   survives is the **installed Chrome PWA** lane, same-origin end to end, which B2/B3 do
   not touch.
2. **The toolchain inventory below belongs to the STOPPED lane.** JDK 21, Android SDK
   API 36 and ADB are what building the Capacitor APK requires. An installed Chrome PWA
   needs none of them. The surviving lane-A blocker is therefore strictly narrower than
   this document originally claimed: **an Android device with Chrome, and nothing else** —
   no JDK, no SDK, no ADB, and the emulator/virtualization question does not arise.

`BLOCKED — host missing` stands for both lanes as measured, and every receipt below is
accurate as taken. What changed is the *scope* they were taken for. The finding on the
absent `OfflineCrypto` bridge is superseded outright — see its own section.

All five V0 proofs are therefore **UNATTEMPTED**, recorded as such rather than
substituted. Per the handoff's own instruction, Node/`fake-indexeddb` results are not a
substitute for device evidence, so this pass deliberately did **not** re-run
`npm run test:offline-spike` and present it as an Android row — those 6 passing cases
already live in the parent spike report and prove nothing about an Android WebView,
Capacitor bridge, or the Android Keystore.

No code was written, no deployment/flag/pilot/store/payment/credential change was made,
no native output was edited, no encryption contract was weakened, and Wave 1 was not
started.

## Host inventory (discovery receipts — every command and its actual output)

| Field | Result | Command |
|---|---|---|
| OS | Windows 10 Pro, build 19045 (`WindowsVersion 2009`) | `Get-ComputerInfo -Property WindowsProductName,WindowsVersion,OsBuildNumber` |
| **JDK 21** | **ABSENT** — `java` not on PATH; no `java.exe` anywhere under `C:\Program Files`, `C:\Program Files (x86)`, `%LOCALAPPDATA%` (recursive, depth 4) | `java -version` → `command not found`; `Get-Command java` → not found; `Get-ChildItem -Path "C:\Program Files","C:\Program Files (x86)","$env:LOCALAPPDATA" -Filter java.exe -Recurse -Depth 4` → **0 results** |
| `JAVA_HOME` | **empty** | `echo $env:JAVA_HOME` → `[]` |
| **Android SDK / API 36** | **ABSENT** — no SDK root at any standard location | `ls "$env:LOCALAPPDATA\Android\Sdk"`, `"$env:USERPROFILE\AppData\Local\Android\Sdk"`, `"C:\Program Files\Android"` → all *No such file or directory*; `Test-Path "$env:LOCALAPPDATA\Android"` → `False` |
| `ANDROID_HOME` / `ANDROID_SDK_ROOT` | **both empty** | `echo $env:ANDROID_HOME` → `[]`; `echo $env:ANDROID_SDK_ROOT` → `[]` |
| **platform-tools / ADB** | **ABSENT** — not on PATH, and no `adb.exe` anywhere under the four scanned roots | `adb version` → `command not found`; `Get-ChildItem -Path "$env:LOCALAPPDATA","$env:USERPROFILE","C:\Program Files","C:\Program Files (x86)" -Filter adb.exe -Recurse -Depth 4` → **0 results** |
| `sdkmanager` / `avdmanager` / `emulator` | **ABSENT** (not on PATH) | `which sdkmanager avdmanager emulator` → all *no … in PATH* |
| Android Studio | **ABSENT** | `Test-Path "C:\Program Files\Android\Android Studio"` → `False` |
| Gradle (standalone) | **ABSENT** (the repo would use the wrapper, which still needs a JDK) | `gradle --version` → `command not found` |
| **Physical Android device** | **NONE ATTACHED** — enumerated USB devices are a webcam, a card reader, hubs/controllers and a virtual-drive bus; no handset, no ADB interface | `Get-PnpDevice -Class USB -Status OK` → `Корневой USB-концентратор (USB 3.0)`, `Расширяемый хост-контроллер Intel(R) USB 3.1 — 1.10`, `Составное USB устройство`, `DAEMON Tools Lite Virtual USB Bus`, `Logitech BRIO`, `Realtek USB 2.0 Card Reader` |
| Node / npm (present, but not a substitute) | 24.15.0 / 11.12.1 | `node -v`, `npm -v` |

### Emulator fallback — independently non-viable on this host

The handoff permits an emulator in place of a physical device. That path is closed here on
its own merits, checked rather than assumed:

| Check | Result | Command |
|---|---|---|
| Hardware virtualization enabled in firmware | **False** | `Get-CimInstance Win32_Processor` → `VirtualizationFirmwareEnabled : False` |
| SLAT (required by both HAXM and WHPX) | **False** | same → `SecondLevelAddressTranslationExtensions : False` |
| CPU | Intel Core i5-8300H @ 2.30 GHz | same → `Name` |
| Hypervisor Platform (WHPX) feature state | **Undetermined** — the query itself requires elevation, which this session does not have and did not take | `Get-WindowsOptionalFeature -Online -FeatureName HypervisorPlatform` → `Запрошенная операция требует повышения.` |
| Free disk on `C:` | **11.7 GB** — below a realistic JDK 21 + cmdline-tools + platform-tools + API 36 platform + a system image footprint | `[math]::Round((Get-PSDrive C).Free/1GB,1)` |

Even granting the WHPX state as unknown, `VirtualizationFirmwareEnabled = False` means
acceleration is off at the BIOS/UEFI level — a change only a human at the machine can
make. An unaccelerated ARM/x86 system image would not produce credible **≤2 s cold-open**
or **≤20 % overhead** timings in any case, so measuring on one would manufacture numbers
rather than evidence.

## V0 rows — status

Every row is **UNATTEMPTED**, with the specific missing prerequisite named. None is
recorded as INCONCLUSIVE-after-testing, because no test ran.

**Read the "Blocked by" column as historical.** It was written against the Capacitor lane
that H2634 B2 has since stopped, so its JDK/SDK/ADB/bridge reasons describe work no longer
owed. On the surviving installed-Chrome-PWA lane the five requirements re-express as
same-origin PWA proofs (service-worker cold start, WebCrypto non-exportable key in
IndexedDB, Cache Storage chunk resume, plaintext inspection, timings) and every one of them
is blocked by the same single thing: **no Android device**.

| # | V0 requirement | Status | Blocked by |
|---|---|---|---|
| 1 | Remote `server.url` origin launches online, installs its service worker, cold-starts offline after force-stop/relaunch | UNATTEMPTED | No APK buildable (no JDK/SDK); no device/emulator to install or force-stop on |
| 2 | Remote-origin JS reaches tracked Capacitor filesystem + crypto bridges offline | UNATTEMPTED | Same; **and** no `OfflineCrypto` bridge exists to reach (see below) |
| 3 | AES uses a non-exportable Android Keystore-backed key; raw key material never crosses into JS; survives restart | UNATTEMPTED | Android Keystore is a device-only API — unreachable without a device; no bridge exists |
| 4 | PDF/audio persist as independent encrypted 1 MiB chunks, resume after interruption/termination, copied ciphertext fails under a second device key | UNATTEMPTED | Needs two device key contexts on real hardware |
| 5 | No fixture plaintext on disk/cache; overhead ≤20 %; cold offline open ≤2 s | UNATTEMPTED | Disk inspection needs ADB; timings need accelerated hardware |

## Repository-side finding (real, and it survives the host problem)

Inspected on `origin/codex/offline-cabinet-roadmap` at `0979f025`, so this stands
independently of the host and is the useful output of this pass:

- **The `OfflineCrypto` bridge does not exist as code.** `git grep -l OfflineCrypto` on the
  branch returns only prose — [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/.ai_state.md),
  [`changelog.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/changelog.md),
  [`docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/docs/OFFLINE_CABINET_WAVE0_SPIKE_2026-08-12.md),
  [`mobile/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/mobile/README.md),
  and one reference inside [`resources/js/offline/spike.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/resources/js/offline/spike.ts).
  There is no plugin source, no `@capacitor/…` package providing it, and no native module.
  The handoff anticipated this and authorized implementing the smallest tracked Capacitor
  8-compatible spike bridge — but a Keystore bridge is unwritable-then-unverifiable here:
  it cannot be compiled (no JDK) or exercised (no device), and shipping an unbuilt, untested
  native bridge into a tracked branch is worse than shipping nothing.

  > **SUPERSEDED by H2634 B3 (13-08-2026) — this is not a gap.** The measurement above is
  > correct: the bridge genuinely does not exist as code. The *inference* — that it is
  > missing work owed to Wave 0 — is wrong, and was already wrong when written. H2634's B3
  > ruled the bridge **not owed**: it exists to protect a content key inside the Capacitor
  > wrapper, and with B2 stopping that wrapper for offline content it has **no consumer**,
  > so no Swift/Kotlin plugin is authored. `probeNativeCrypto()` returning all-false on a
  > native platform is now the **correct steady state**, enforced by
  > `assertOfflineStorageAllowed()` in `resources/js/offline/spike.ts`. Anyone reading this
  > section as a to-do should read the ruling instead:
  > [`docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_OFFLINE_CABINET.md)
  > § Load-bearing platform gate. Reopening it is a human decision costed as its own wave.
- **The tracked `mobile/` scaffold is otherwise complete and regenerable.**
  [`mobile/package.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/mobile/package.json)
  pins `@capacitor/android` `^8.4.1`, `@capacitor/core` `^8.4.1`, `@capacitor/cli` `^8.4.1`,
  `@capacitor/filesystem` `^8.1.2`, `@capacitor/file-transfer` `^2.0.5`, and carries
  `add:android` / `sync` / `apk:debug` scripts.
  [`mobile/capacitor.config.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/codex/offline-cabinet-roadmap/mobile/capacitor.config.ts)
  drives `server.url` from `CAP_ENV` + `CAP_URL_STAGING`/`CAP_URL_PROD` and throws at build
  time when unset — so the remote-origin target of proof #1 is config-driven, not
  hardcoded, and `mobile/android` remains regenerable from tracked config via
  `npm run add:android`. **No tracked-config gap blocks the follow-up run** — the blocker
  is entirely the host. (The "plus the missing bridge" this line originally carried is
  withdrawn: per H2634 B3 there is no bridge owed. And per B2 this whole `mobile/` wrapper
  is stopped for offline content — it remains the **online** wrapper, unchanged.)
- Versions that would have gone in the "exact versions" inventory are therefore
  **unmeasurable** here: Android/WebView/Chrome versions require a device, and installed
  Capacitor/plugin versions require an `npm install` whose resolved tree would still not be
  a device measurement. The pinned *ranges* above are what the branch actually declares.

## What a follow-up needs (concrete unblocks)

**Post-H2634, the ask is one item, not three.** The original three-item list below assumed
the Capacitor lane was still owed; B2 stopped it, so most of what it asked for buys nothing.

1. **One recent Android device with Chrome and USB debugging on** — that is the whole
   lane-A blocker. No JDK, no Android SDK/API 36, no ADB toolchain install, no BIOS/UEFI
   virtualization change, no ~20 GB of disk. An installed Chrome PWA is same-origin end to
   end, so it needs a browser and a device, nothing more.

Withdrawn as no longer useful, and recorded so nobody re-derives them:

- ~~Enable virtualization in BIOS/UEFI / provision an emulator~~ — the emulator existed to
  substitute for a device on the **Capacitor build** lane, now stopped. An unaccelerated
  emulator would also have distorted the ≤2 s / ≤20 % gates.
- ~~Free ~20 GB for JDK + cmdline-tools + platform-tools + API 36 + system image~~ — that
  footprint is the Capacitor APK build, stopped.
- ~~Decide who writes `OfflineCrypto` first~~ — **settled**: H2634 B3 ruled it not owed.

`BLOCKED — host missing` remains the right verdict rather than a repair-attempt count: the
handoff's 5-attempt repair budget applies to a *failing* proof, and there was no proof to
repair. A device is not something a shell session can supply for itself.

## Decision

Do not mark the Android row PASS. **Split it in two, as the manifest now does:** the
Capacitor Android WebView lane is **STOPPED** by H2634 B2 — no device session is owed for
it, and its verdict is a ruling, not a host problem. The installed Chrome PWA lane stays
**BLOCKED — host missing**, still owed, and now unobstructed (B1 is fixed; B2/B3 do not
apply to a same-origin PWA) — the same disposition H2634 gave the iPhone Safari lane.

Per the plan, H2597 stays open until every still-owed row returns evidenced PASS or a
platform-scoped STOP; after H2634 that set is three installed-PWA rows (Android Chrome,
iPhone Safari, Windows 11 Edge), not the original six lanes. Wave 1 remains blocked. The
shared aggregate spike report was not edited (out of scope for this row, per the handoff).

---

_Dr. Mārcis Gasūns_
