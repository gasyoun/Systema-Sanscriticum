# Anons publishing v2 — operator manual

_Created: 17-09-2026 · Last updated: 10-10-2026_

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
| `php artisan anons:plan-occasions [--kind=…] [--json]` | Шаг 0 минта кампании: отбор поводов анонса по виду занятия — `обзорное \| разовое \| обычное`, без флага все виды (H6329) |

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

## Senler manual lane (H5935)

**Decision (04-10-2026): Senler ships as a documented manual lane, not an adapter.** Access probes found no Senler API credential anywhere in the estate: the prod `.env` has only site-callback `VK_*` keys (bot token, confirm code, group id — not Senler), and the Mac has no `~/.claude/secrets/vk_poll.env` (that file is the H5079 VK *wall* tool anyway). A Senler API token can only be minted by hand in MG's Senler UI, and until MG's explicit decision every Senler API call and every send is off-limits. So the manifest keeps `senler` as a valid platform name, the tracked link already exists (channel key `vk` in `config/tracked_links.php` → `utm_source=vk_senler`, `utm_medium=broadcast`), and `AdapterRegistry` stays fail-closed — its refusal now points here instead of "future work".

Name mapping for the future adapter author: manifest platform = `senler`; attribution source = `vk_senler` (the `/ga/…-vk-…` links).

### Broadcast checklist (UI Senler)

1. Take copy + the rendered creative from the campaign's `anons:preview` artifacts — the CTA plaque is already in the pixels; do not rebuild it in Senler.
2. Tracked link: `https://samskrte.ru/ga/m26-vk-s` (creative variants `-v/-c/-t/-h` live in `config/tracked_links.php`). The link goes AFTER the copy — never the first line (anons publication rule).
3. senler.ru → samskrte community → «Рассылки» → create: paste copy, link, attach the image.
4. Test send to MG's own subscription first: link opens, redirect lands on the clean page, copy + image render on the phone.
5. Send to the full subscriber segment (~3000) — real sending is MG's explicit decision, every time.
6. Post-send smoke (one probe click per link; it lands in `anons_link_clicks` like any reader click): `curl -sI https://samskrte.ru/ga/m26-vk-s` must return `HTTP/2 302` with `location: https://samskrte.ru/online/kursy/grammatika-gasuns-2026` — clean URL, UTM stays in the session. Live-probed PASS 04-10-2026.
7. Journal row per the [campaign-record-template](https://github.com/gasyoun/claude-config/blob/main/commands/anons.md) in the `/anons` skill: campaign/creative, `vk_senler`, the `/ga/` link, segment size, delivered count, 24h/72h clicks (via `php artisan anons:ops metrics` / `anons_link_clicks` — slug + UTM + time only). **152-ФЗ: aggregates only, no PII in the journal.**

### Why there is no adapter — the API cannot send (H5944, 04-10-2026)

The H5935 upgrade path ("MG mints a token → mint the adapter") is dead on the PLATFORM, not on access: **the Senler API has no broadcast-send method at all.** Verified 04-10-2026 (github-first prior-art, H5032) against the [official methods index](https://help.senler.ru/senler/dev/api/methods) — `Deliveries` exposes only read-only `deliveries/get`, `deliveries/stat`, `deliveries/statCount` — and both user SDKs ([SenlerPy](https://github.com/tezmen/SenlerPy), [senler-sdk](https://github.com/Alexey-zaliznuak/senler-sdk)) agree: no send/create for рассылки, from any token, ever (until Senler ships one). Sending is UI-only — the checklist above is not a stopgap, it is THE send path.

What a token WOULD buy (candidate `SenlerStatsService`, awaiting MG's product call): `deliveries/stat` + `deliveries/statCount` + `utms/statCount` pull per-broadcast delivered/opened/clicked stats into the placements journal and reconcile them against `anons_link_clicks`. If Senler ever ships a send method, the adapter follows the H5079 design (`publication_key` idempotency, a `VK_PUBLISH_AUTHORIZED`-style gate with empty list = refuse, persistent ledger, test-mode contour; first real send = MG's explicit go). Until then `anons:validate`/`anons:publish` accept the schema but refuse at the adapter check with a pointer to this section — that refusal is the designed behavior, not a bug.

## Placements journal — anons:ops journal (H6095)

Машинный журнал размещений: хвост публикации (permalink, время, 24h/72h клики) пишется командой в таблицу `anons_placements` вместо ручного PR по md-документу. Ключи, UTM-кортеж и destination выводятся из [config/tracked_links.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/tracked_links.php) — ничего не набирается руками; PII-правило то же, что у `anons_link_clicks` (только агрегаты).

Вид кампании (`обзорное | разовое | обычное`, словарь канона [SCHEDULE_KINDS_CANON_ANONS_SITE_09-10-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SCHEDULE_KINDS_CANON_ANONS_SITE_09-10-2026.md)) входит в цикл минта с шага 0 — отбор поводов (H6329, PR [#3132](https://github.com/gasyoun/Systema-Sanscriticum/pull/3132)):

```bash
# шаг 0 минта кампании — отбор поводов по виду (без --kind — все виды; --json — машинный вывод)
php artisan anons:plan-occasions --kind=разовое
# после минта кампании — строки размещений из конфига + вид в журнал (одна команда на кампанию)
php artisan anons:ops journal-add --campaign=up26 --kind=разовое        # или --link=up26-ors-c
# после выхода поста — хвост: permalink + время (МСК), клики за 24/72h из anons_link_clicks
php artisan anons:ops journal-fill --link=up26-ors-c --permalink=https://t.me/samskrte/633 --published-at="2026-10-04 22:43:00"
# сводка (вид каждой строки виден как [вид]; фильтр — по --campaign)
php artisan anons:ops journal-list --campaign=up26
```

Правила `--kind` (H6329):

- Словарь fail-closed: `обзорное | разовое | обычное`; неизвестный вид — отказ с подсказкой словаря, а не молчаливое «пусто/всё».
- Повторный `journal-add` без `--kind` вид не стирает (merge по `/ga/` ключу); смена вида — только явным `--kind=`. Строки, заведённые до H6329, живут без вида.
- `--kind` — опция `journal-add`; фильтра `journal-list` по виду нет (только `--campaign`), вид строки виден в выводе как `[вид]`.
- Пробные занятия полем `kind` не дублируются — в фиде они уже видны по `bookable`/`book_token` (канон); напоминания по обычным занятиям остаются в чатах групп (`classes:remind-upcoming`), анонс-кампания их не заменяет.
- Живой пример отбора (прод 10-10-2026): `anons:plan-occasions --kind=разовое` → 3 повода «Открытые занятия и вебинары» (#1594–#1596); журнал на ту же дату — 37 строк, из них up26 [разовое] ×2 (бэкфилл).

Идемпотентность — по `/ga/` ключу (повторный `journal-add` обновляет строку). Story-ключи (`st-`) команда отказывается принимать — они живут в `story_campaigns`-формате. Живой пример первого заполнения: up26, пост 633 (PR [#3008](https://github.com/gasyoun/Systema-Sanscriticum/pull/3008) — до команды хвост был ручным PR; после H6095 — одна команда). Читаемый md-журнал кампании (campaign-record-template) остаётся человеком-артефактом, команда его не патчит.

## Known limits

- `senler` destinations are a documented manual lane (§ above) — the registry refuses them with a pointer there; the Senler API has no send method at all (H5944), so no adapter is possible until upstream ships one; `vk` (wall) destinations remain fail-closed future work.
- Archive indexer requires a live session (probe-gated) and runs one bounded `getStoriesArchive` page per invocation.
- Font: freetype TTF resolved from `services.anons.cta_font` / `ANONS_CTA_FONT` / DejaVu(Linux)/Arial(macOS) paths; without any TTF the plaque falls back to GD's ASCII-only bitmap font — acceptable for ASCII CTA, Cyrillic needs the TTF (DejaVu present on the prod box).

_Dr. Mārcis Gasūns_
