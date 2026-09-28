# Telegram Business Story automation decisions — 2026-09-27

Owner answers to the 2026-09-27 Story automation grill. These apply to the
automatic editorial-channel video lane fed by `@samskrte` and `@samskrtamru`.
They do not alter the separate student-homework Story consent workflow.

| Topic | Decision | Delivery note |
| --- | --- | --- |
| Timing | Keep a Story covering every day; otherwise allow up to seven days for subtitles. | If no Story covers the next hour, release the oldest original immediately. Approved drafts publish when approved. After seven days, release the original even without approval; this deadline outranks the provisional cap of 5 source videos per Moscow day. |
| Long videos | Do not stop publication at ten parts. Publishing a bounded excerpt is acceptable if truncation is recorded and alerted. | Never silently claim the entire source was published. |
| Cross-channel duplicates | Suppress exact duplicates; near-matches go to review. | SHA-256 suppresses exact bytes. Three-frame perceptual hashes hold likely re-encodes for explicit approve/suppress in Auto Stories; ordinary videos need no review. |
| Editorial-channel consent | Do not add a consent approval step to this automatic lane, including when people appear. | Separate student-homework workflow remains unchanged. |
| Reframing | Use a blurred 9:16 canvas by default; face-aware crops require review. | Text that must be read should be made legible ahead of publication, not rescued by cropping. |
| Captions/subtitles | Generate drafts away from production; publish as-is if unapproved after one week. | Try Ivan's Tailscale Whisper API first when configured; MacBook Air's local Whisper is the fallback. The existing final Story transcode on production burns approved SRT into each part. |
| Links | Source post by default; campaign-specific tracked URL when configured. | Put an existing `https://samskrte.ru/ga/...` link in the source post caption to select that campaign; the tracked-links subsystem supplies UTM. |
| Metrics | Track final-part reach and link clicks. | An isolated session worker samples recent Stories every two hours; reused tracked links aggregate clicks across uses. |
| Failures | Alert on partial/failed publication; retry only parts whose non-publication is certain. | Ambiguous Telegram upload outcomes need review, not blind retry. |
| Operations view | Show per-part receipts in the existing announcement/Story operations surface, read-only. | No approval step for ordinary editorial videos. |

## Delivery order

1. Publisher safety: bound very long sources, record truncation, alert, and
   distinguish certain failures from ambiguous uploads.
2. Safe retry and per-part receipts in the existing operations surface.
3. Near-duplicate review in Auto Stories; campaign-link selection UI only if caption links prove inadequate.
4. Off-production subtitle pilot, with seven-day approval deadline and empty-coverage release. The Air worker can run now; Ivan becomes primary once its API is reachable over Tailscale and its key is supplied on the Air (never on production).

“Story for every day filled” is implemented as a Story part posted within its
configured active period minus one hour, or an upload/release currently in
flight. The scheduler checks every 15 minutes. This avoids a gap when the
previous Story expires; it does not add a human gate to the gap-filling video.

Near-match review compares the public source posts. It never silently suppresses a
visual match: only exact SHA-256 duplicates are automatic. A reviewer may
approve a likely match for the normal capped queue or suppress it. Review holds
release the provisional daily slot. Flat or unreadable video frames do not
produce a near-match verdict; the original source still publishes normally.

Do not enable a new webhook or move this lane to another bot. The existing
Telegram Business connection and source channels remain authoritative.
