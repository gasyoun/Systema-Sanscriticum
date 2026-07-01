# Support identity — the one canonical external-identity story

<p align="right"><sub>Created: 01-07-2026 · Last updated: 01-07-2026</sub></p>

> Companion to [docs/support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md). Resolves open decision #3 (identity reconciliation). Read this before adding any external-identity storage — the point is that a **fourth** mapping must never be created.

## The decision

**[`social_accounts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_25_130000_create_social_accounts_table.php) `(provider, provider_id, user_id)` is the canonical external-identity store.** Its shape is already a generic "this external account belongs to this user" row, with a `unique(provider, provider_id)` guarantee that one external identity maps to exactly one user. Everything else either feeds into it or stays as a documented denormalized cache.

Model: [`SocialAccount`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SocialAccount.php). Canonical provider names are constants on it:

| Constant | Value | Namespace |
|---|---|---|
| `PROVIDER_TELEGRAM` | `telegram` | Telegram user id |
| `PROVIDER_VK` | `vkontakte` | VK user id — **shared** by VK-bot (`users.vk_id`) and VK-OAuth login, so they reconcile into one provider, not two |
| `PROVIDER_MAX` | `max` | MAX user id |

OAuth login already writes `google` / `vkontakte` / `yandex` rows via [`SocialAuthService::findOrCreateUser()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/SocialAuthService.php).

## What stays as-is (deliberately not "normalized away")

The denormalized id columns on [`User`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/User.php) — `telegram_id`, `vk_id`, `max_user_id` — **remain** as outbound-send caches. `User::sendTelegramMessage()` uses `users.telegram_id` directly as the `chat_id`; forcing a join through `social_accounts` on every bot send buys nothing. They are a cache of the canonical row, not a competing source. Do not drop them.

## The three mappings that get consolidated

| Source | Where | Fate |
|---|---|---|
| `users.telegram_id` / `vk_id` / `max_user_id` | outbound bot sends | materialized into `social_accounts`; column kept as cache |
| `social_accounts (provider, provider_id)` | OAuth login | already canonical — the target |
| `TelegramSupportContact.telegram_user_id` → `linked_user_id` | analytics import | materialized into `social_accounts` (provider `telegram`) |

## The backfill

[`identity:backfill-social-accounts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ConsolidateSocialIdentities.php) materializes the two non-canonical sources into `social_accounts`. It is:

- **Dry-run by default** — prints what it would create; `--apply` writes.
- **Idempotent** — keyed on `(provider, provider_id)`; a row already pointing at the **same** user is left untouched (counted "уже сведено").
- **Non-clobbering** — a row pointing at a **different** user is reported as a conflict and skipped, never overwritten. Manual and OAuth links win. Resolve conflicts by hand.

```sh
php artisan identity:backfill-social-accounts          # dry run
php artisan identity:backfill-social-accounts --apply   # write
```

## Rule for future work

Any new channel's identity goes into `social_accounts` under a new `PROVIDER_*` constant. **Never** add a fourth external-identity table or a per-channel id column that competes with `social_accounts` — that is the exact identity-chaos failure this decision exists to prevent. A denormalized outbound cache column (like `users.telegram_id`) is acceptable only as an explicit, documented cache of a `social_accounts` row.

<p align="right"><sub>Dr. Mārcis Gasūns</sub></p>
