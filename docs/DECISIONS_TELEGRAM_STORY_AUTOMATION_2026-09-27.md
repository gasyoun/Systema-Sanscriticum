# Telegram Business Story automation decisions — 2026-09-27

Owner answers to the 2026-09-27 Story automation grill. These apply to the
automatic editorial-channel video lane fed by `@samskrte` and `@samskrtamru`.
They do not alter the separate student-homework Story consent workflow.

| Topic | Decision | Delivery note |
| --- | --- | --- |
| Timing | Publish immediately, with a daily cap and queued overflow. | Provisional default: 5 source videos per Moscow day; configurable with `TELEGRAM_BUSINESS_STORY_DAILY_VIDEO_CAP`. |
| Long videos | Do not stop publication at ten parts. Publishing a bounded excerpt is acceptable if truncation is recorded and alerted. | Never silently claim the entire source was published. |
| Cross-channel duplicates | Suppress exact duplicates; near-matches go to review. | Existing SHA-256 check covers exact bytes only. |
| Editorial-channel consent | Do not add a consent approval step to this automatic lane, including when people appear. | Separate student-homework workflow remains unchanged. |
| Reframing | Use a blurred 9:16 canvas by default; face-aware crops require review. | Text that must be read should be made legible ahead of publication, not rescued by cropping. |
| Captions/subtitles | Generate drafts away from production; publish as-is if unapproved after one week. | Requires a clear draft timestamp and an opt-out/hold path. |
| Links | Source post by default; campaign-specific tracked URL when configured. | Put an existing `https://samskrte.ru/ga/...` link in the source post caption to select that campaign; the tracked-links subsystem supplies UTM. |
| Metrics | Track final-part reach and link clicks. | An isolated session worker samples recent Stories every two hours; reused tracked links aggregate clicks across uses. |
| Failures | Alert on partial/failed publication; retry only parts whose non-publication is certain. | Ambiguous Telegram upload outcomes need review, not blind retry. |
| Operations view | Show per-part receipts in the existing announcement/Story operations surface, read-only. | No approval step for ordinary editorial videos. |

## Delivery order

1. Publisher safety: bound very long sources, record truncation, alert, and
   distinguish certain failures from ambiguous uploads.
2. Safe retry and per-part receipts in the existing operations surface.
3. Near-duplicate review and campaign-link selection UI, if caption links prove inadequate.
4. Off-production subtitle pilot, with seven-day approval deadline; its worker and timing relative to immediate publication remain undecided.

Do not enable a new webhook or move this lane to another bot. The existing
Telegram Business connection and source channels remain authoritative.
