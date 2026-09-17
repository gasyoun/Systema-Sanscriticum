# Anons publishing v2 — operator manual

_Created: 17-09-2026 · Last updated: 17-09-2026_

H5049 delivery: declarative announcement/Story publishing with visible CTA plaques burned into pixels, idempotent reruns, per-destination retries, explicit-missingness analytics, archive catalog and a safe test contour. Companion of the canonical [`/anons` skill](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md).

## Mental model

A **manifest** (one JSON file on prod; YAML also accepted in dev/tests, where symfony/yaml is installed — dev-only per the H4880 revert contract) declares ONE campaign application: frames (ordered series), destinations (account/platform pairs), copy, assets, CTA. The **service** (`App\Services\Anons\AnonsPublishingService`) turns it into publications through platform **adapters**. CLI and HTTP API are thin shells over the same service.

Key invariants:

- **The plaque is in the pixels.** `CtaPlaqueCompositor` draws the CTA plaque into the image BEFORE upload; `PlaqueInspector` re-opens the exported bytes and fails if the layer is absent. A `mediaAreaUrl` alone was the original H5049 defect (API-side link, nothing visible).
- **One key, no duplicates.** `publication_key = sha256(campaign|creative|destinations|slot)`. Rerunning the same manifest reports/resumes; mutating content under the same key is refused — bump creative or slot.
- **Coordinates are measured, not remembered.** The click area sent to `stories.sendStory` is derived from the rendered plaque bounds (`PlaqueBounds::asMediaAreaCoordinates()`), so the Telegram link area lies exactly over the visible plaque. The layout constant `x=50` is CENTER-X (large centered overlay, left edge = 11%), `y=55` is the top edge.
- **Zero is not "unknown".** Metrics carry `value|unavailable|not_supported|pending|failed`; an absent API field never becomes a zero (R7).
- **Sessions are probed, not assumed.** Publication refuses to start unless `getSelf` succeeds in the subprocess lane; FLOOD_WAIT parses into a bounded retry; AUTH_* fails closed for human reauthorization (R13).

## Manifest format

```json
{
  "version": 1,
  "campaign": "m26",             // короткий слаг
  "creative": "sep-start",       // что именно публикуем
  "slot": "2026-09-18T08:00",    // bucket идемпотентности (дата+время запуска)
  "test_mode": false,
  // "test_mode": true          → обязательный private-контур:
  // "test_destination": {"platform": "telegram_story", "account": "marcis_test"}
  "frames": [                    // упорядоченная серия (R12)
    {
      "asset": "/abs/path/story.jpg",   // только абсолютные пути
      "caption": "Набор осенней группы с нуля.",  // URL никогда не первым
      "alt_text": "Анонс осенней группы",          // обязателен
      "cta_text": "страница записи",               // текст вжариваемой плашки
      "cta_url": "https://samskrte.ru/ga/m26-rs-st-sep-20260918-01"  // чистая /ga/, без UTM
    }
  ],
  "destinations": [
    {"platform": "telegram_story", "account": "rusamskrtam"}
  ],
  "deletion_policy": "retain"    // | rollback_target — разрешает bounded rollback
}
```

Validation is fail-closed: relative asset paths, UTM inside `cta_url`, a caption starting with a URL, duplicate destinations and missing alt/CTA are all rejections BEFORE any publication.

## Commands

| Command | What it does |
|---|---|
| `php artisan anons:validate manifest.json` | Schema+adapter check, prints key/hash |
| `php artisan anons:preview manifest.json` | Renders all frames to `storage/app/anons/preview/<hash>/`, prints plaque rects + media areas + UTM; publishes nothing |
| `php artisan anons:publish manifest.json` | Idempotent publication; rerun reports/resumes; `--promote` moves an accepted test publication to prod (manifest must already be test_mode=false) |
| `php artisan anons:publish --report-key=<key>` | Status JSON by publication key |
| `php artisan anons:ops metrics --manifest=…` | Live readout: story metrics + /ga/ clicks by key, explicit missingness states |
| `php artisan anons:ops history --key=…` | All persisted observations |
| `php artisan anons:ops archive-index --account=rusamskrtam` | Index the live story archive into the catalog (hash/phash/text/tags) |
| `php artisan anons:ops archive-search --query="осенний"` | Find evergreen assets without rescanning Telegram |

HTTP API (auth:sanctum personal token): `POST /api/anons` (draft/validate), `POST /api/anons/preview`, `POST /api/anons/publish`, `GET /api/anons/{key}`, `POST /api/anons/{key}/metrics`, `POST /api/anons/{key}/rollback`.

## Safe staging procedure (no private test account configured yet)

Until a private test account is registered (`test_destination`), the sanctioned staging drill is **publish + immediate rollback** on `deletion_policy: rollback_target`:

1. `anons:validate` → green.
2. `anons:preview` → open the artifact, confirm the plaque is visible and centered.
3. `anons:publish` → story goes live for seconds, clearly identified by caption.
4. `anons rollback` via API (or rerun flow) deletes the recorded remote id through the SAME subsystem; nothing manual is touched (bounded rollback only deletes ids recorded in `anons_destination_runs`).

Production promotion reuses the accepted manifest hash — no asset regeneration (R14).

## Where things live

- Service core: `app/Services/Anons/` (manifest, key, compositor, inspector, service, metrics, archive, session probe, adapters/).
- Commands: `app/Console/Commands/Anons*.php`.
- Worker scripts (isolated MTProto processes): `scripts/stories_lane_worker.php` (extended: `get_self`, media_area), `scripts/anons_archive_worker.php` (new).
- Click counter: `TrackedLinkController` → `anons_link_clicks` (no PII: slug + UTM + time).
- Tables: `anons_publications`, `anons_destination_runs`, `anons_metrics`, `anons_archive_items`, `anons_link_clicks`.
- Tests: `tests/Feature/Anons/` (15 tests, incl. the no-plaque regression pin).

## Known limits

- VK/Senler destinations are fail-closed until their adapters are written (platform list validates, registry refuses).
- Archive indexer requires a live session (probe-gated) and runs one bounded `getStoriesArchive` page per invocation.
- Font: freetype TTF resolved from `services.anons.cta_font` / `ANONS_CTA_FONT` / DejaVu(Linux)/Arial(macOS) paths; without any TTF the plaque falls back to GD's ASCII-only bitmap font — acceptable for ASCII CTA, Cyrillic needs the TTF (DejaVu present on the prod box).

_Гасунс_
