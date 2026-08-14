# Curated Telegram-chat practice layer (H2446)

_Created: 14-08-2026 · Last updated: 14-08-2026_

## Verdict

**GO** for a teacher-curated JSON store behind a default-OFF flag.
**NO-GO** for raw Telegram history, live Madeline / `telegram-harvest:sync`
on the student path, and auto-LLM over a group chat.

Pilot: 10 teacher-authored items in
[database/data/hindi_tg_curated/items.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/data/hindi_tg_curated/items.json).
None of them were scraped from Telegram.

Teacher brief: [issue #1709](https://github.com/gasyoun/Systema-Sanscriticum/issues/1709).
14-08-2026 Гасунс спросил Костину **лично в Telegram** (в личке он всегда
Гасунс). Бот кабинета `@samskrtamru_bot` в тот же день слал ссылку на issue —
это не он.

## Why not harvest

| Source already in the house | What it is | Why it is not this layer |
|---|---|---|
| `telegram-harvest:sync` / [telegram-sanskrit-corpus](https://github.com/gasyoun/telegram-sanskrit-corpus) | Track-B Sanskrit corpus | Different language, store-only, `noforwards` publication gate. Not Hindi class chat. |
| `groups.telegram_chat_id` + `classes:post-group-link` | Autopost of the Zoom link | Operational, not a practice corpus. |
| Support / Madeline session | Curator DMs and roster | Student correspondence. 152-FZ personal data. |
| H2443 / H2444 lesson transcripts and files | Licensed course material | Already a practice source. Do not replace them with chat dumps. |

A Hindi study group contains student names, usernames, phones, homework
photos, and private asides. Putting that stream on `/dvaram` would be a
raw dump. The handoff forbids that.

Rights uncertainty is not a stop for **teacher-authored** items (org
standing policy, 30-07-2026). It **is** a stop for publishing other
people's chat messages without a curator pass.

## Who may curate

Only a teacher (Kostina), a curator, or MG. A student never writes this
store. The curator copies a **paraphrased** Q/A they would put on a
worksheet — not a message blob, not a screenshot OCR of the thread.

Allowed in a row: `id`, `type`, `prompt`, `answer`, `lemma`, `choices`,
`programme_unit`, optional `lesson_id` / `course_slug`, `curator`,
`source=teacher_curated`, `source_note`.

Rejected by
[`HindiTgCuratedPractice::rejectReason`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiTgCuratedPractice.php):
`telegram_*`, `from_id`, `username`, `first_name`, `phone`, `raw_text`,
`message_id`, `chat_id`, `user_id`, `@username` mentions, `t.me/` /
`telegram.me/` URLs, `+` phone strings. Cap: 50 items.

## What ships

| Piece | Role |
|---|---|
| JSON store | The only content source. No live TG API. |
| [`HindiTgCuratedPractice`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiTgCuratedPractice.php) | Load, privacy gate, access, probe |
| [`HindiTgCuratedPracticeController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Student/HindiTgCuratedPracticeController.php) | `/dvaram/programme/hindi/chat-practice` |
| Playlist CTA | «Практика из чата» on [Мой хинди](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/programme/hindi.blade.php) when this flag is on |

Flag: `features.hindi_tg_curated_practice` / `HINDI_TG_CURATED_PRACTICE`,
default **OFF**.

Access: `HindiProgrammePlaylist::itemsFor` is non-empty — same group /
grant / paid-key rules as
[H2441](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2441-Grok_Systema-Sanscriticum_hindi-programme-playlist-one-list_08.08.26.md).
The feature does not write payments or grants. A row with `lesson_id`
is shown only when that lesson is unlocked.

`programme_unit` (`kostina-beginner-1` / `kostina-beginner-2`) is the
human link to the playlist shells. Live `lesson_id` stays null until a
teacher pins a real lesson.

## Pilot items (14-08-2026)

Teacher-authored classroom lemmas that already appear in the H2443
fixture register. Not student speech.

| id | type | prompt | answer | unit |
|---|---|---|---|---|
| htg-001 | translate | Как по-русски: kitāb | книга | kostina-beginner-1 |
| htg-002 | translate | Как по-русски: नमस्ते | здравствуйте | kostina-beginner-1 |
| htg-003 | translate | Как по-русски: पानी | вода | kostina-beginner-1 |
| htg-004 | cloze | aap _____ ho? | kaise | kostina-beginner-1 |
| htg-005 | translate | Как по-русски: मैं | я | kostina-beginner-1 |
| htg-006 | translate | Как по-русски: tum | ты | kostina-beginner-1 |
| htg-007 | translate | Как по-русски: नाम | имя | kostina-beginner-2 |
| htg-008 | cloze | merā _____ Rām hai | nām | kostina-beginner-2 |
| htg-009 | translate | Как по-русски: अच्छा | хорошо | kostina-beginner-2 |
| htg-010 | vocab_pick | Что значит घर? | дом | kostina-beginner-2 |

## Probe

```
php artisan hindi:tg-practice-probe --json
```

Works with the flag OFF. Answers are redacted (`***`).

## Enable on prod

Leave **OFF** until a human flips it after auto-deploy:

```
HINDI_TG_CURATED_PRACTICE=true
php artisan config:cache
```

Rollback: `false` + `config:cache`. No migration. No Telegram credential.

_Dr. Mārcis Gasūns_
