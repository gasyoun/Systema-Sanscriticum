# Inbound email as a support channel — spec (H1200 residual)

_Created: 19-08-2026 · Last updated: 19-08-2026_

Companion to [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)
(ground truth for what exists) and
[ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md §4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)
(requirement 4, S5 item 3). Written to satisfy the
[H1200](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1200-Sonnet_Systema-Sanscriticum_jivo-parity-s5of5-email-channel-badging_17.07.26.md)
line "Inbound email как канал — later-phase и у Jivo; начать со спеки/скелета."
**No ingestion code ships with this spec** — see "Why no code yet" below.

## Why no code yet

Channel badging (VK/TG-bot) reused an *existing* write path — those bots already
posted into `chat_messages`, so H1200's job was labelling rows that already
existed. Inbound email has **no existing write path**: nothing in this repo
receives mail today. Standing up one requires a decision only a human can make
(which mailbox, which provider, DNS control) — see **Open decisions** below.
Writing ingestion code against a guessed provider risks building the wrong
integration twice; jivo.md itself calls email "later-phase" (lowest priority
of the six Jivo-parity requirements) for the same reason. This document is the
skeleton so that decision, once made, is a single afternoon of wiring rather
than a fresh architecture pass.

## Target shape (reuses existing machinery, adds nothing structural)

Inbound email slots into the **existing** `chat_messages` badging pattern from
[H1200's shipped half](https://github.com/gasyoun/Systema-Sanscriticum/pull/565)
— no new message store, no schema redesign:

- `chat_messages.source = 'email'` (the column is a free `string(20)`,
  [migration `2026_07_18_100000_add_source_to_chat_messages.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_18_100000_add_source_to_chat_messages.php)
  — no enum/check constraint to widen, so this needs no migration).
- [`UnifiedMessage::fromChatMessage()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedMessage.php)
  gains one more `match` arm (`'email' => self::CHANNEL_EMAIL`) exactly like
  the `vk`/`telegram_bot` arms added in H1200 — this is the one-line hook
  point, deliberately **not** added yet (an unreferenced channel constant
  with no producer is dead code the next session would have to re-verify is
  still unused).
- `role`/`user_id`/`answered_by` follow the same convention as web/VK/TG-bot
  rows — incoming mail is `role='user'`, an operator reply from Helpdesk is
  `role='curator'`.
- Helpdesk channel badge: same `channelLabel()` match in `UnifiedMessage`
  gets `self::CHANNEL_EMAIL => 'Email'`, matching the VK/TG-bot badge pattern
  pixel-for-pixel — no new UI component.

## What inbound ingestion actually needs (the part gated on a decision)

1. **A receiving address and a way to get mail out of it into HTTP.** Three
   realistic options, in order of setup cost:
   - **Provider inbound-parse webhook** (Postmark "Inbound", SendGrid Inbound
     Parse, Mailgun Routes) — provider receives mail at an MX-pointed
     subdomain and POSTs a parsed payload to a Laravel route. Lowest server
     ops cost; adds an external vendor + its webhook-signature verification
     (mirrors the existing pattern in
     [`VerifyTelegramBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramBotWebhook.php)/[`VerifyTelegramMagnetWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramMagnetWebhook.php)).
   - **IMAP polling** (Laravel scheduled command reads a real mailbox via
     IMAP) — no vendor, no MX change, but needs a scheduler tick, IMAP
     credentials in `.env` (`encrypted` cast, same as the magnet-bot secrets),
     and its own dedupe-by-`Message-ID` logic since polling can re-see mail.
   - **Forwarding rule → existing webhook provider** (e.g. mailbox forwards to
     a Postmark inbound address) — a middle ground when the mailbox itself
     can't be MX-repointed.
2. **Threading.** Reply-to token in the outbound subject/body (the same
   family of idea as VCS commit trailers) so a reply lands on the right
   `user_id`/conversation rather than creating an orphan thread — mirrors how
   `TelegramSupportMessage` threads via `telegram_support_chat_id`. Needs a
   `ChatMessage`-side conversation key; if unification work (`SupportConversation`
   per [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md#the-supportconversation-naming-landmine--resolved-step-1-01-07-2026))
   lands first, email threading should build on that key rather than a
   parallel one.
3. **Identity resolution.** Match the incoming `From:` address to a `User` by
   `email` (already unique/indexed) — the one channel where identity
   resolution is *simpler* than VK/TG (no `external_identity` mapping table
   needed, mirrors jivo.md's own note that identity chaos is the real risk
   in adding channels). Unmatched sender → route to a holding queue, not a
   silently dropped message.
4. **Outbound (operator reply → real email).** Out of scope for this spec —
   H1200's own reply-out canary (WS1.3, TG-support) is still an explicit
   human step for a channel that already has a send path; email reply-out
   is a second, separate canary once inbound exists at all.

## Open decisions (human, not agent-resolvable)

- **Which mailbox/address** receives support mail (new dedicated address vs.
  an existing one) — a business decision, not inferable from the repo.
- **Which provider**, if the inbound-parse route is chosen — has billing and
  DNS/MX implications on a domain the agent does not control.
- **Priority vs. the roadmap's other S5 residuals** — [ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md §4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)
  already ranks email lowest of the three S5 items; this spec does not
  change that ranking, only removes the "where would this even start"
  re-derivation cost for whoever picks it up next.

## Definition of done for the eventual build

- One of the three ingestion options chosen and wired behind a feature flag
  (default OFF, per this repo's flag convention in `config/features.php`).
- `UnifiedMessage::CHANNEL_EMAIL` added + the two `match` arms above, with
  tests mirroring the H1200 VK/TG-bot badge tests
  ([PR #565](https://github.com/gasyoun/Systema-Sanscriticum/pull/565)).
- Helpdesk shows an `Email` badge on ingested rows.
- Identity-unmatched mail visibly queued, not dropped.
- A residual note in [ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)
  flips requirement 4 from ⚠️ Частично to ✅.

_Dr. Mārcis Gasūns_
