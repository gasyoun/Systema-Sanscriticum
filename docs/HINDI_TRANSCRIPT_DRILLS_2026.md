# Hindi transcript drills (H2443)

_Created: 14-08-2026 · Last updated: 14-08-2026_

## What ships

A student who already owns a Hindi lesson can open **Упражнения** built from that
lesson's `transcript_file`. Items are derived on the fly (cached by file mtime),
no LLM, no Telegram dump, no Sanskrit grammar invented.

| Piece | Role |
|---|---|
| [`HindiTranscriptDrillExtractor`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiTranscriptDrillExtractor.php) | Cloze / translate / vocab pick from transcript sentences |
| [`HindiTranscriptDrills`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiTranscriptDrills.php) | Flag, Hindi-shell check, cache, item lookup |
| [`HindiTranscriptDrillsController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Student/HindiTranscriptDrillsController.php) | `/c/{slug}/u/{id}/drills` + JSON check |
| Playlist + lesson CTA | Entry from [H2441](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2441-Grok_Systema-Sanscriticum_hindi-programme-playlist-one-list_08.08.26.md) row and the lesson page |

Flag: `features.hindi_transcript_drills` / `HINDI_TRANSCRIPT_DRILLS`, default **OFF**.

Access: `HindiProgrammePlaylist::canAccessLesson` — same group / grant / paid-key
rules as the playlist. The feature does not write payments or grants.

## How items are chosen

1. **Translate** — `«kitāb» — это книга` or `नमस्ते значит здравствуйте`.
2. **Cloze** — blank the longest Devanagari token, else a Latin Hindi-looking token.
   Russian-only sentences are skipped.
3. **Vocab pick** — when three or more Hindi tokens exist, one multiple-choice
   item is added with a stable shuffle.

Fixture used by tests:
[tests/fixtures/hindi_transcript/hindi_lesson_sample.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/hindi_transcript/hindi_lesson_sample.json)
(Deepgram-shaped; four spoken sentences). Measured 14-08-2026, Grok 4.6 (`grok-4.6`):

| # | Type | Prompt (abridged) | Answer |
|---|---|---|---|
| 1 | translate | Как по-русски: kitāb | книга |
| 2 | translate | Как по-русски: नमस्ते | здравствуйте |
| 3 | cloze | Повторите слово _____ ещё раз. | पानी |
| 4 | cloze | Скажите aap _____ hai. | kaise |
| 5 | vocab_pick | same as item 1, 3 choices | книга |

## Probe

```
php artisan hindi:drills-probe {lesson_id} --json
```

Works with the flag OFF. Answers are redacted (`***`).

## Enable on prod

✅ **ON 14-08-2026.** `HINDI_TRANSCRIPT_DRILLS=true` + `config:cache` on
`319f33e6`. Rollback: `false` + `config:cache`.

Live transcripts (only two Hindi lessons have `transcript_file` today):

| Lesson | Course | Items | HTTP |
|---|---|---|---|
| 1863 Костина нач. 1 | 401 `hindi-1-sr800-2026` | 12 cloze | paid user 6738 → 200; guest → 302 |
| 1854 Костина нач. 2 | 402 | 11 cloze + 1 vocab_pick | probe only |

User 6494 (playlist 36) has no `transcript_file` on owned lessons, so her
playlist rows do not show «Упражнения» until those transcripts land.

_Dr. Mārcis Gasūns_
