# Metadoc — SUPPORT_CANREPLY_TEMPLATES_REVISION_2026-08

_Created: 07-08-2026 · Last updated: 07-08-2026_

- **Purpose:** durable gap analysis of support canreply templates vs ~2 months of prod Telegram traffic; source of truth for which MessageTemplate titles were added in H2339 and why.
- **Audience:** curators (which template to pick), engineers (seeder / placeholders), next revision pass.
- **Provenance:** Grok 4.5 (`grok-4.5`), H2339; data from prod `telegram_support_messages` + `support_topic_assignments` (2026-06-07 … 2026-08-06).
- **Related:** [MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md) · [support-reply-library-ru-register-a-f.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/support-reply-library-ru-register-a-f.md) · [MessageTemplateSeeder.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/MessageTemplateSeeder.php)
- **Backlog:**
  1. After deploy: seed prod, smoke-send E2 with a real magic link (curator).
  2. Optional S9 bind E1/E2→E, D2/D3→D, F2/F3→F in admin.
  3. Re-census in ~3 months; add abroad_pay / refund templates if volume grows.
- **Limitations:** keyword counts are non-exclusive and bot-noisy; uncategorized topics are half of rollups.

_Dr. Mārcis Gasūns_
