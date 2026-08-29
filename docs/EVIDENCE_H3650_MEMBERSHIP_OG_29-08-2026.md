# Evidence — H3650 membership / autumn-calendar OG stills

_Created: 29-08-2026 · Last updated: 29-08-2026_

Executor: Grok 4.6 (`grok-4.6`). Handoff: [H3650](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3650-Grok_Systema-Sanscriticum_autumn-membership-og-imagine_28.08.26.md).

## Surfaces

| Surface | View | `og:image` path | Size |
|---|---|---|---|
| [https://samskrte.ru/klub](https://samskrte.ru/klub) | `shop/club.blade.php` | `/images/og-membership-club-h3650.webp` | 1200×630 |
| Basic still (committed; `/klub` has no Basic-only URL) | same landing, file only | `/images/og-membership-basic-h3650.webp` | 1200×630 |
| [https://samskrte.ru/osen-2026](https://samskrte.ru/osen-2026) (flag `MEMBERSHIP_PUBLIC_FEED`) | `membership/storefront-editorial.blade.php` | `/images/og-autumn-calendar-h3650.webp` | 1200×630 |
| [https://samskrtam.ru/courses/autumn-2026](https://samskrtam.ru/courses/autumn-2026) | `membership/storefront-catalogue.blade.php` | same calendar still | 1200×630 |
| Home | `main.blade.php` | `/images/og-main-preview.jpg` | unchanged |

1:1 variants sit next to each wide file (`*-h3650-1x1.webp`, 1200×1200).

## Constraints kept

- House palette (`#0A0D14` / `#E85C24` Club; `#38BDF8` Basic; editorial cream `#f7f1e4` / terracotta `#a3412f` calendar).
- Real `public/images/logo.png` glyph. No photoreal faces. No invented Devanagari. No video. No new sales copy on the pages.
- `og:image` not replaced site-wide.

## Proof

- Feature test: `php artisan test --filter=MembershipOgImageTest`
- Live (after deploy): `curl -I` of each WebP must return `image/webp`; `/klub` HTML contains `og-membership-club-h3650.webp`; `/` HTML still contains `og-main-preview.jpg`. `/osen-2026` is 404 while `MEMBERSHIP_PUBLIC_FEED` is OFF — the still URL is still public under `/images/`.

_Dr. Mārcis Gasūns_
