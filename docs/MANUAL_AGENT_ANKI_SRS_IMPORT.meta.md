# Metadoc — MANUAL_AGENT_ANKI_SRS_IMPORT.md

_Created: 30-07-2026 · Last updated: 30-07-2026_

| Field | Value |
|---|---|
| Purpose | Agent/operator runbook for AnkiWeb → Systema SRS import + student study path |
| Audience | Agents (Claude/Codex/Grok); ops on prod import |
| Provenance | H1970 pipeline + media UI follow-up; skill `/anki-srs-import` |
| Companion skill | `~/.claude/commands/anki-srs-import.md` (canonical phases) |

## Improvement backlog

1. Prod one-liner in DEPLOY_QUEUE when pilot should go live.
2. Optional Filament admin “import Anki seed” button (human-gated).
3. Bidirectional review still Wave 3 on SRS_ROADMAP — not this manual’s scope.

## Limitations

- Covers public AnkiWeb Basic Front/Back decks; exotic note types need converter flags.
- Audio autoplay may be blocked by some browsers until a user gesture.

## Revision history

| Date | Change |
|---|---|
| 30-07-2026 | Initial (media publish + review UI wired) |

_Dr. Mārcis Gasūns_
