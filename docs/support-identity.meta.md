# support-identity.meta.md — metadoc about `support-identity`

_Created: 13-07-2026 · Last updated: 13-07-2026_

Companion record for [docs/support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md) — what surrounds that decision document, not a restatement of it.

## Subject

- **Document:** [docs/support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md)
- **Purpose:** Records the single canonical decision for where external-identity mappings live, so no competing store is ever added.
- **Audience:** Backend/Laravel developers and any future session touching support, OAuth login, bot channels, or user-identity storage in Systema-Sanscriticum.
- **Format / contract:** A committed architectural decision doc — prose + tables + shell snippets, resolving open decision #3 of the support-subsystem map. Load-bearing invariant: never create a fourth identity mapping.

## Provenance

- **Subject created:** 01-07-2026
- **Metadoc authored:** 13-07-2026, H890 (metadoc sweep II), Opus 4.8 `claude-opus-4-8`
- **Next hardening:** confirm the `identity:backfill-social-accounts` command has been run with `--apply` in production and record the conflict count, so the doc can move from "decided" to "executed".

## Ranked improvement backlog

| # | Improvement | Why | Status |
|---|---|---|---|
| 1 | Note the actual `--apply` backfill run + conflict count | Doc describes the tool but not whether reconciliation has happened | parked (needs production run evidence) |
| 2 | Cross-link from `User`, `SocialAccount`, and the migration back to this doc | Discoverability — a dev editing those files should be steered here | parked (code-comment pass) |
| 3 | Add a short "how to add a new provider" checklist | Rule for future work is stated but not step-by-step | parked (low priority) |
| 4 | Diagram of the three-source → one-target consolidation | Visual aid over the current table | parked (nice-to-have) |

## Known limitations / caveats

- The doc asserts the backfill is idempotent and non-clobbering but does not point at a test proving it; treat those properties as design intent until a test is cited.
- The denormalized cache columns (`telegram_id`, `vk_id`, `max_user_id`) are deliberately kept — a reader skimming for "normalize everything" could wrongly delete them; the doc warns against this but the risk persists.
- Provider-name reconciliation (VK-bot + VK-OAuth into one `vkontakte`) is a convention encoded in constants; nothing mechanically prevents a future channel from splitting it again.

## Intended use / known misuse

- **Intended:** consult before adding any external-identity storage or a new login/bot channel; add a new `PROVIDER_*` constant, not a new table or id column.
- **Misuse:** citing it to justify dropping the outbound cache columns, or creating a per-channel identity table because "this case is special" — the exact failure the decision forbids.

## Maintenance & sunset plan

- Revisit whenever a new external channel is onboarded, the `social_accounts` schema changes, or the backfill command is run for real.
- Owner: Systema-Sanscriticum backend maintainers. No fixed review cadence; event-driven.

## Deprecation status

`active`

## Related documents

- [docs/support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) — parent map; this doc resolves its open decision #3.
- [docs/support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md) — the subject.

## Revision history

| Date | Change | Author |
|---|---|---|
| 13-07-2026 | metadoc created (H890) | Opus 4.8 `claude-opus-4-8` |

_Dr. Mārcis Gasūns_
