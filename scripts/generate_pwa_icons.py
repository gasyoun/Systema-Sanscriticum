"""Generate the PWA manifest icons from the source logo.

Why this exists: `public/manifest.webmanifest` used to declare
`public/images/logo.png` as both `192x192` and `512x512`, but that file is
**197x129**. Chromium verifies the decoded bitmap against the declared `sizes`
string and discards any icon that disagrees, which left only the 48x48 favicon —
below the mandatory 192 minimum — so the browser offered no install entry at all
and said nothing about why. Measured 13-08-2026 in the repo and on production.

Run after changing the source logo or any constant below:

    python scripts/generate_pwa_icons.py            # writes public/images/icon-*.png
    python scripts/generate_pwa_icons.py --check     # verify only, exit 1 on drift

Requires Pillow (dev-only; nothing at runtime imports this).

## The crop, and how to change it

Every layout decision is a constant here rather than a baked-in pixel, because
the framing is a branding call that will be redone. Change a constant, re-run,
commit the PNGs — the manifest needs no edit as long as the file names hold.

- `BACKGROUND` — the logo ships with a transparent ground, and a launcher icon
  should not gamble on what sits behind it. Flattened onto the manifest's own
  `background_color` so the icon matches the app's ground instead of inventing a
  second one. Set to `None` to keep transparency.
- `ANY_FILL` — for `purpose: any`. The glyph occupies 84 % of the canvas width,
  leaving a margin so a launcher's rounded mask does not clip the wordmark.
- `MASKABLE_FILL` — for `purpose: maskable`, where Android may crop to a circle
  of 80 % diameter and only the inside of that circle is guaranteed visible. A
  197x129 rectangle inscribed in that circle can be at most ~67 % of the canvas
  wide (w^2 + (0.655w)^2 <= (0.8 * 512)^2); 60 % sits comfortably inside with
  room for the platform's own shape variation.

The source's transparent margin is trimmed before scaling, so the percentages
describe the visible glyph and not whatever padding the source file happens to
carry.
"""

import sys
from pathlib import Path

sys.stdout.reconfigure(encoding='utf-8')

try:
    from PIL import Image
except ImportError:  # pragma: no cover - dev-only tool
    print('Pillow is required: pip install Pillow', file=sys.stderr)
    raise SystemExit(2)

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'public' / 'images' / 'logo.png'
OUT_DIR = ROOT / 'public' / 'images'

BACKGROUND = (244, 241, 234)  # #F4F1EA — manifest background_color
ANY_FILL = 0.84
MASKABLE_FILL = 0.60

# (filename, canvas size, fill fraction)
# icon-180 is the iOS `apple-touch-icon`, which iOS never masks and expects at 180x180 —
# same defect class as the manifest icons: the layout linked the 197x129 source there too.
TARGETS = [
    ('icon-180.png', 180, ANY_FILL),
    ('icon-192.png', 192, ANY_FILL),
    ('icon-512.png', 512, ANY_FILL),
    ('icon-maskable-512.png', 512, MASKABLE_FILL),
]


def trimmed_source() -> Image.Image:
    """The logo with its transparent margin removed."""
    logo = Image.open(SOURCE).convert('RGBA')
    box = logo.getbbox()
    return logo.crop(box) if box else logo


def render(logo: Image.Image, size: int, fill: float) -> Image.Image:
    canvas = Image.new('RGBA', (size, size), (*BACKGROUND, 255) if BACKGROUND else (0, 0, 0, 0))

    target_width = size * fill
    scale = min(target_width / logo.width, target_width / logo.height)
    scaled = logo.resize(
        (max(1, round(logo.width * scale)), max(1, round(logo.height * scale))),
        Image.LANCZOS,
    )

    canvas.alpha_composite(scaled, ((size - scaled.width) // 2, (size - scaled.height) // 2))
    return canvas if BACKGROUND is None else canvas.convert('RGB')


def main() -> int:
    check_only = '--check' in sys.argv[1:]
    logo = trimmed_source()
    print(f'source {SOURCE.name}: {logo.width}x{logo.height} after trim')

    failed = False
    for name, size, fill in TARGETS:
        path = OUT_DIR / name
        image = render(logo, size, fill)

        if check_only:
            if not path.exists():
                print(f'MISSING {name}')
                failed = True
                continue
            existing = Image.open(path)
            if existing.size != (size, size):
                print(f'WRONG SIZE {name}: {existing.size[0]}x{existing.size[1]}, want {size}x{size}')
                failed = True
            else:
                print(f'ok {name}: {size}x{size}')
            continue

        image.save(path, format='PNG', optimize=True)
        print(f'wrote {name}: {size}x{size}, fill {fill:.0%}, {path.stat().st_size} bytes')

    return 1 if failed else 0


if __name__ == '__main__':
    raise SystemExit(main())
