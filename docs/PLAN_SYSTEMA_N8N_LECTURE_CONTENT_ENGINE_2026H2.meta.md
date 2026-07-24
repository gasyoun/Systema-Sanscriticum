# Metadoc — PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2

_Created: 23-07-2026 · Last updated: 24-07-2026_

## Purpose

Companion record for the H2 plan that turns weekly lecture video/transcript/
timecode data (n8n + lecture AI) into five sequenced content products under one
`ContentCandidate` backbone.

## Audience

- Executing agents (Sonnet-tier handoffs W1–W5)
- MG for activation (flags, VK/TG/SMTP, editorial free-clip policy)

## Provenance

- Method: `/ask` (5 interview rounds, 23-07-2026)
- Author model: Grok 4.5 (`grok-4.5`)
- Prior art: Anton ops-gaps plan + H1452 clip pipeline; Content-AI CAI1/CAI3/CAI4–7
- Data ruling: lecture-only sources for early waves (no support-gap input)

## Ranked improvement backlog

1. After pilot metrics, optionally dual-signal with CAI3 support gaps
2. Live VK analytics → ranker features
3. Student-facing study_artifact surface in cabinet (W5 left staff-first)
4. Retire parallel Filament surfaces if Content digest fully subsumes LectureClip UI

## Limitations

- Staging n8n dry-run is human activation, not CI
- August email depends on SMTP revival (H1449 path)
- Local clones may lag origin and miss H1452 files until fetch

## Related docs

- Plan index + four layers (same stem under `docs/`)
- [`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md)
- [`docs/ROADMAP_CONTENT_AI_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_CONTENT_AI_2026_2027.md)
- [`docs/n8n/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/README.md)

## Revision history

| Date | Change | Model |
|---|---|---|
| 23-07-2026 | Created from full `/ask` interview | Grok 4.5 (`grok-4.5`) |
| 24-07-2026 | Wave 1 (H1547) executed: `ContentCandidate`, `SpanRanker`, `QuotePolicy`, `LessonObserver`/`LectureClipObserver` sync, thin Filament resource, DEPLOY_QUEUE №49 | Sonnet 5 (`claude-sonnet-5`) |

_Dr. Mārcis Gasūns_
