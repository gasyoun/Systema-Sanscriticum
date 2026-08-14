# Hindi attachment drills (H2444)

_Created: 14-08-2026 · Last updated: 14-08-2026_

## What ships

A student who already owns a Hindi lesson can open **Упражнения** built from
files **already on that lesson** (`attachments` and teacher-posted
`homework_attachments`). Same item format as
[H2443](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2443-Grok_Systema-Sanscriticum_hindi-transcript-drills_08.08.26.md)
(cloze / translate / vocab pick). No Telegram scrape, no LLM, no OCR.

| Piece | Role |
|---|---|
| [`HindiAttachmentTextExtractor`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiAttachmentTextExtractor.php) | txt/md read; docx via ZipArchive; PDF only if a sibling `.txt` exists |
| [`HindiAttachmentDrills`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiAttachmentDrills.php) | Flag, cache, items, handout list |
| Same `/c/{slug}/u/{id}/drills` | Merged with transcript items when both flags are on |

Flag: `features.hindi_attachment_drills` / `HINDI_ATTACHMENT_DRILLS`, default **OFF**.

Access: `HindiProgrammePlaylist::canAccessLesson` — same as H2441 / H2443.
The feature does not write payments or grants.

## How files are read

| Kind | Action |
|---|---|
| `.txt` / `.md` | UTF-8 read |
| `.docx` | `word/document.xml` inside the zip |
| `.pdf` | use sibling `name.txt` if present; otherwise skip (`pdf_no_extract`) and show «Открыть» |
| audio / image / other | skip (`binary`) |

Fabricating handout text is a fail. Binary-only PDF is a documented skip.

## Prod census (14-08-2026)

Hindi shells `356,366,357,371,401,402,416,432,438,442` (+ 413): **130** lessons.

| Source | Count |
|---|---|
| `lessons.attachments` non-empty | **0** |
| `homework_attachments` non-empty | **3** (1 PDF textbook, 5 mp3, 1 png) |
| `course_materials` rows | **0** |
| PDF sibling `.txt` | **0** |

So live Hindi review still comes from transcripts (H2443). Attachment drills
light up when a curator uploads a txt/md/docx (or a PDF plus sidecar `.txt`).
Until then the page can still show «Открыть раздатку» for the one homework PDF
on lesson 1723 (`Практическая_грамматика_хинди_Уровень_a1.pdf`).

OCR of historical PDFs is a residual, not this ship.

## Probe / census

```
php artisan hindi:attachments-census --json
php artisan hindi:drills-probe {lesson_id} --json
```

Both work with the flag OFF. Probe redacts answers (`***`).

## Enable on prod

Default **OFF**. After deploy: `HINDI_ATTACHMENT_DRILLS=true` + `config:cache`.
Rollback: `false` + `config:cache`.

_Dr. Mārcis Gasūns_
