# Telegram Business Story subtitle worker

The production server keeps the automatic channel-to-Story lane. It stages an
already-public source video only when another Story covers the day. The worker
transcribes away from production and returns an SRT draft; an admin reviews it
in **Content → Auto Stories**. Approval burns the subtitles into each Story
part during the normal publish transcode. Rejection publishes the original.
With no action, the original publishes after seven days. If coverage runs out
before then, the oldest original publishes immediately.
If Whisper detects no speech, the original publishes without a pointless
seven-day review hold.

The server's `telegram-business:story-subtitles release` job runs every 15
minutes. The MacBook Air's launchd worker runs every 15 minutes while the Mac
is awake. If the Air is off, the server's release/deadline rule remains live;
no Story is held indefinitely for an unavailable ASR worker.

## Air worker

The worker uses the Air's existing `whisper-cli`, `ffmpeg`, and
`~/.whisper/models/ggml-small.bin` in CPU mode. The large-v3-turbo model
failed a live GPU-memory smoke test on this Air, so it is not the unattended
fallback. The worker reads the pending queue over
the existing SSH route to `root@193.232.229.92`, downloads only staged public
channel media, and sends SRT back over SSH standard input. No Telegram bot
token or production database credential is copied to the Air.

After the merged code is present in the main checkout, run once:

```sh
python3 scripts/story_subtitle_worker.py
```

The scheduled launcher is `ops/launchd/com.samskrte.story-subtitles.plist`.
It references the stable main checkout, not a temporary worktree. Check it
with `launchctl print gui/$(id -u)/com.samskrte.story-subtitles`; logs are in
`~/Library/Logs/story-subtitles.{out,err}.log`. A sleeping or powered-off Air
does not block the server's automatic gap/deadline release.

## Ivan as preferred ASR

The Air worker prefers Ivan's Whisper API if **both**
`STORY_IVAN_WHISPER_URL` and `WHISPER_API_KEY` are available on the Air, either
as environment variables or in an owner-only
`~/.config/samskrte/story-subtitles.env` prepared from
`ops/story-subtitles.env.example`. Its
API is expected to accept `POST /transcribe` with a short-lived signed media
URL and expose `GET /jobs/{id}` plus paginated segments, as the existing
`scripts/transcribe_lessons.py` client does. The endpoint must be bound to
Tailscale, not the public internet. If it is unreachable or fails, the same
worker falls back to the Air's local model without delaying the Story queue.
Keep the API key in the Air's secret store or `~/.whisper_api_key`; never commit
it or put it in production's `.env`.

The signed media URL lasts 30 minutes and carries no Telegram token. Its route
serves only a pending public-editorial source from private storage. After a
Story is published, the staged video is deleted. The SRT receipt remains in
the admin ledger for audit.

## Recovery

- `php artisan telegram-business:story-subtitles queue` lists only drafts still
  awaiting transcription, with short-lived signed media URLs. Do not paste
  those URLs into issues or public chat.
- `php artisan telegram-business:story-subtitles release` safely runs the
  gap/deadline check. It posts at most one gap-filler per pass, while overdue
  items are released on their deadline.
- A failed Air run does not change a draft's state; it retries next interval.
  If ASR remains unavailable, the original publishes automatically.
- `TELEGRAM_BUSINESS_STORY_SUBTITLES_ENABLED=false` disables *new holds*
  without disabling release of already-held videos.
