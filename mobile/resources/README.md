_Created: 12-07-2026 · Last updated: 05-09-2026_

# App icon & splash (placeholders)

Wave 1 ships with **Capacitor's default generated icons** so a debug build installs
and runs. Real branding (icon, splash, store art) is a Wave 0 / Wave 3 item owned by
MG — see the [mobile-app roadmap](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md).

## How to drop in real art (when branding is ready)

1. Add two source images to this folder (gitignored — they are large binaries):
   - `icon.png` — **1024×1024**, no transparency, no rounded corners.
   - `splash.png` — **2732×2732**, key art centered (safe zone ≈ middle 1200×1200).
   - Optional: `icon-foreground.png` + `icon-background.png` for Android adaptive icons.
2. Generate all platform densities:
   ```sh
   npm run icons          # → npx capacitor-assets generate
   ```
   This writes into the (gitignored) `android/` and `ios/` projects. Re-run after any
   `npx cap add android|ios`.

## Splash / status-bar colours

The loading + offline pages and the native splash use the same palette
(`#0b1020` ground, `#c9a24b` accent) — set in
[`capacitor.config.ts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/capacitor.config.ts) (`SplashScreen`, `StatusBar`) and
[`www/css/app.css`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/mobile/www/css/app.css). Change them together.

_Dr. Mārcis Gasūns_
