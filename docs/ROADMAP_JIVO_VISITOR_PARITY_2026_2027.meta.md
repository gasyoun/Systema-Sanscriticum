# Metadoc — ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md

_Created: 17-07-2026 · Last updated: 17-07-2026_

**Purpose.** Companion record for [`ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)
— the roadmap for closing the one Jivo capability our self-hosted web-chat lacks:
**visitor intelligence** (geo/city, on-site presence, operator-initiated proactive
outreach). Split into 5 streams (S1–S5) mapped to MG's 6 parity requirements.

**Audience.** The agent (or MG) executing any of H1196–H1200, and anyone deciding
whether to retire Jivo on samskrtam.ru.

**Provenance.** Authored Opus 4.8 (`claude-opus-4-8`), 17-07-2026, from MG's directive
"build the two missing Jivo pillars + set tasks for all 6 parity requirements." Grounded
in a live check of both sites (Jivo live on samskrtam.ru; our widget live on samskrte.ru
with Reverb-push confirmed connected in prod) and a full read of the support subsystem
([support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md),
[jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md),
`PublicChatController`, `Helpdesk`, `SupportConversationManager`, migrations, `config/features`).

**Key finding that shaped it.** The two design docs (jivo.md, support-subsystem-map.md)
**understate what already exists** — Helpdesk already has status tabs, assignment, canned
replies, AI-assist, a FAQ suggester, and guest threads; Reverb is now live in prod (the
docs still say "до деплоя Reverb"). So the roadmap deliberately covers only the genuinely
new axis (visitor intelligence), not a from-scratch inbox.

**Ranked improvement backlog.**
1. After S2 lands, fold the presence/geo data into a deflection/analytics view (ties to
   `ROADMAP_SUPPORT_AUTOMATION` S10 web-chat analytics).
2. Resolve the geo-provider @DECIDE (MaxMind vs Cloudflare vs ipapi) — blocks city display
   going live; currently `null` driver ships inert.
3. Resolve the 152-ФЗ / consent sign-off for anonymous visitor presence tracking (S2) —
   the most privacy-sensitive piece.
4. Refresh the stale "current state" columns in jivo.md / support-subsystem-map.md (Reverb
   live, status tabs exist) — flagged here, not yet fixed.

**Limitations.** S1 is built + tested but not deployed/enabled in prod (human deploy gate;
`support_visitor_geo` OFF, `SUPPORT_GEO_DRIVER=null`). S2–S5 are specs + handoffs, not code.
The parity verdict assumes MG's 6 requirements are the full set (from the clarifying interview).

**Revision history.**
- 17-07-2026 — created alongside the roadmap; S1 (H1196) executed same session, S2–S5 minted.

_Dr. Mārcis Gasūns_
