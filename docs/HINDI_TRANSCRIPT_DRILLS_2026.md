# Hindi transcript drills (H2443)

_Created: 14-08-2026 · Last updated: 20-08-2026_

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

YouTube re-ASR (`metadata.source = deepgram-nova-3`) is a **second** flag:
`features.hindi_youtube_nova3_drills` / `HINDI_YOUTUBE_NOVA3_DRILLS`, default **OFF**.
Students do not see those cards until the Hindi teacher says they are usable.
Teachers still preview them on [Мой хинди](https://samskrte.ru/dvaram/programme/hindi)
(`data-testid="hindi-youtube-asr-review"`). Zoom/n8n transcripts with no `source`
are unchanged. Cache key `v6`.

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

Live transcripts n8n already pushed (ZOOM 1.4 → `POST /api/lessons/{id}/transcript`):

| Lesson | Course | Items | HTTP |
|---|---|---|---|
| 1863 Костина нач. 1 | 401 `hindi-1-sr800-2026` | 12 cloze | paid user 6738 → 200; guest → 302 |
| 1854 Костина нач. 2 | 402 | 11 cloze + 1 vocab_pick | probe only |
| 1853 Интенсив хинди | 438 | already on disk | n8n exec 830 |
| 1830 Интенсив хинди #1 | 438 | on disk | n8n, already filled |

**Backfill 14-08-2026 (no human):** n8n ZOOM 1.4 already POSTed every Hindi
Deepgram payload still in SQLite (1830 / 1853 / 1854 / 1863). Drive course
folders hold only ~1 KB TOC `.txt` (agenda timestamps), not `words[]`.
Deepgram on the n8n box returns `ASR_PAYMENT_REQUIRED` — Ivan:
[issue #1692](https://github.com/gasyoun/Systema-Sanscriticum/issues/1692).

Older YouTube shells first got `ru-orig` auto-captions for the lesson
player. `metadata.source = youtube-auto-ru-orig` still yields **zero**
drill items.

**Re-ASR 14-08-2026 (H2717, Deepgram still 402):** lesson 938 was
re-transcribed with faster-whisper `small` / `language=ru` on the app
host (8454 words, ~15 min). Whisper writes Hindi in Cyrillic
(`Намасте`), so the extractor now matches a short distinctive
Cyrillic classroom lexicon. Remaining 88 youtube-auto shells are
queued on `/tmp/run_hindi_reasr.py`. New Zoom classes still need
Deepgram credits ([issue #1692](https://github.com/gasyoun/Systema-Sanscriticum/issues/1692)).

**H2445 (14-08-2026):** each item now carries `lemma` (the Hindi headword;
vocab_pick inherits it from the source item). Cache key was `v5`.

**H2758 (20-08-2026):** 89 published YouTube shells now have
`metadata.source = deepgram-nova-3` (the remaining `youtube-auto-ru-orig`
drain). Drill quality from that ASR is a teacher gate, not a student
rollout: `HINDI_YOUTUBE_NOVA3_DRILLS` stays OFF; n8n lessons 1830/1853/1854/1863
were not overwritten. Cache key `v6`. Do not relaunch `/tmp/hindi_reasr`
(that scratch was 7.6 GiB and was deleted on 19-08-2026 as part of the
`.92` tmpfs cleanup).

_Dr. Mārcis Gasūns_
