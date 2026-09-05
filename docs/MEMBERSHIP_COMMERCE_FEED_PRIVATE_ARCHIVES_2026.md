_Created: 17-08-2026 · Last updated: 05-09-2026_

# Membership commerce feed and private archives

_H2745 · 17-08-2026 · deploy-dark runbook and acceptance evidence_

## Outcome

One versioned feed now supplies every commercial field used by two intentionally
different storefronts. All H2745 switches remain `false` by default; merging and
deploying this code publishes nothing until the corresponding rollout decision.

| Surface | Route | Rendering | Source tag |
|---|---|---|---|
| Canonical feed | `/api/public/v1/autumn-membership` | JSON, `X-Robots-Tag: noindex` | both checkout URLs |
| samskrte.ru | `/osen-2026` | editorial chronological calendar | `samskrte` |
| samskrtam.ru | `/courses/autumn-2026` | compact catalogue cards | `samskrtam` |

Schema: `systema.membership-commerce-feed.v1`. Each offer has a stable `id`,
`kind`, `title`, `course_slug`, `tariff_id`, `tier`, `term_months`, `starts_on`,
`status`, `price_rub`, and a checkout URL for each storefront. Dates come from
forming/active groups in the configured September–October window; prices and
terms come from active Systema tariffs. No storefront owns a second price/date
copy.

Top remains **HOLD**: `MEMBERSHIP_TOP=false` removes Top from the feed and the
verifier fails if it appears. Its separate 01-10 go/no-go is not pre-empted.

## Private archive controls

The authenticated route is `/dvaram/private-archive/{archive}` for
`yoga_sutras`, `soboleva_ayurveda`, and `druzhinin_ayurveda`. Eligibility is
derived at request time from a real, paid, access-granting `payments` row on one
of the configured historical course slugs. No email, name, phone, exported
contact list, or purchaser roster enters config, HTML, the feed, or analytics.

An eligible page carries `noindex,nofollow`. Ineligible and anonymous requests
fail closed. Archive offer courses are excluded from the shop controller,
Livewire catalogue, product-ladder anchors, sitemap, direct public course route,
and canonical feed. Every allowed/denied view is append-only audited.

Each archive has its own operational circuit breaker:

```powershell
php artisan membership:archive-kill yoga_sutras --reason=contract-stop
php artisan membership:archive-kill yoga_sutras --restore
```

The same command accepts the other two archive keys. A contractual objection to
one archive does not disable the others. `MEMBERSHIP_PRIVATE_ARCHIVES=false`
remains the global emergency stop.

## Analytics contract

`membership_funnel_events` is append-only and records `offer_view`, `checkout`,
`payment`, `renewal`, `lapse`, `restoration`, and `feature_use`. Required
dimensions are `tier`, `term_months`, `source_site`, `course_id`, and `feature`
where the event has that dimension. Anonymous visits use a one-way session hash;
no raw IP, email, phone, name, session ID, or archive eligibility list is stored.

Example (illustrative IDs only):

```json
{
  "event_name": "renewal",
  "user_id": 42,
  "course_id": 444,
  "tariff_id": 5039,
  "payment_id": 9001,
  "tier": "club",
  "term_months": 3,
  "source_site": "samskrtam",
  "feature": null
}
```

The checkout source is retained through an anonymous-to-account transition in
the server-side session, so the later payment does not collapse to `system`.

## Rollout

1. Deploy and migrate with every switch below still off.
2. Run `php artisan membership:commerce-verify`; require `PASS` and confirm
   `Top checkout = HOLD (correct)`.
3. Confirm the autumn groups/tariffs and archive course-slug mappings in the
   production database. Do not substitute a hand-copied purchaser list.
4. Enable `MEMBERSHIP_FUNNEL_ANALYTICS=true`, clear config cache, and verify a
   source-tagged test view/checkout event.
5. Enable `MEMBERSHIP_PUBLIC_FEED=true`, clear config cache, then compare both
   host renderings and the feed.
6. Enable `MEMBERSHIP_PRIVATE_ARCHIVES=true` plus only the specifically approved
   per-archive switches. Probe anonymous, ineligible, and eligible accounts.

Per-archive flags are `PRIVATE_ARCHIVE_YOGA_SUTRAS`,
`PRIVATE_ARCHIVE_SOBOLEVA_AYURVEDA`, and
`PRIVATE_ARCHIVE_DRUZHININ_AYURVEDA`. Their offer and eligibility course slugs
are separately configurable; defaults are documented in the environment
inventory.

## Rollback

Fast rollback is configuration-only: set the three H2745 global switches and all
three per-archive switches to `false`, then run `php artisan config:clear` (or
the deployment's normal config-cache rebuild). For a named contractual stop,
prefer `membership:archive-kill` so other approved archives remain available.

The migration is additive. After all switches are off and only if removal of the
empty telemetry/control tables is explicitly required:

```powershell
php artisan migrate:rollback --path=database/migrations/2026_08_17_130000_create_membership_commerce_events.php
```

Do not roll back after analytics or access-audit rows have become evidence
without first archiving them under the applicable retention policy.

## Acceptance evidence

- `MembershipCommerceFeedTest`: 6 tests / 60 assertions, including schema,
  commercial parity, source preservation, private discovery probes, eligibility,
  audit, kill/restore, and lifecycle dimensions.
- Membership regression slice: 103 tests / 343 assertions.
- Full application suite: 3,681 passed / 20,303 assertions; three expected
  platform/opt-in skips.
- `membership:commerce-verify`: PASS on a migrated disposable SQLite fixture;
  four public offers, zero private slugs, Top held.
- Browser QA: 1440×1000 and 390×844; identical commercial payloads, distinct
  renderings, correct source tags and CTA targets, no horizontal overflow.
- Screenshots: `docs/evidence/h2745/storefront-*.png`.
- Public Samudra is untouched; no route, controller, config, or access policy for
  it changed.

_Dr. Mārcis Gasūns_
