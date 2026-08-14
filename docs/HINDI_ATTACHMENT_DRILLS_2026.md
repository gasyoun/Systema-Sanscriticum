# Hindi attachment drills (H2444) + PDF sidecar residual (H2731)

_Created: 14-08-2026 · Last updated: 14-08-2026_

## What ships

A student who already owns a Hindi lesson can open **Упражнения** built from
files **already on that lesson** (`attachments` and teacher-posted
`homework_attachments`). Same item format as
[H2443](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H2443-Grok_Systema-Sanscriticum_hindi-transcript-drills_08.08.26.md)
(cloze / translate / vocab pick). No Telegram scrape, no LLM, no live OCR
on a student request.

| Piece | Role |
|---|---|
| [`HindiAttachmentTextExtractor`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiAttachmentTextExtractor.php) | txt/md read; docx via ZipArchive; PDF only if a sibling `.txt` exists |
| [`HindiPdfSidecarWriter`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiPdfSidecarWriter.php) | H2731: offline, flag-off PDF → sibling `.txt` (page + byte cap) |
| [`HindiAttachmentDrills`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiAttachmentDrills.php) | Flag, cache, items, handout list |
| Same `/c/{slug}/u/{id}/drills` | Merged with transcript items when both flags are on |

Flag: `features.hindi_attachment_drills` / `HINDI_ATTACHMENT_DRILLS`, default **OFF**.
The sidecar artisan does **not** flip that flag.

Access: `HindiProgrammePlaylist::canAccessLesson` — same as H2441 / H2443.
The feature does not write payments or grants.

## How files are read

| Kind | Action |
|---|---|
| `.txt` / `.md` | UTF-8 read |
| `.docx` | `word/document.xml` inside the zip |
| `.pdf` | use sibling `name.txt` if present; otherwise skip (`pdf_no_extract`) and show «Открыть» |
| audio / image / other | skip (`binary`) |

Fabricating handout text is a fail. A PDF with no sidecar is a documented skip
on the request path. Filling the sidecar is a separate artisan (below).

## Bounded PDF sidecar (H2731)

Prod 14-08-2026 has `/usr/bin/gs` 10.05.1 and PHP imagick/gd. It does **not**
have `pdftotext`, poppler, or `tesseract`. Q1b already recorded that installing
packages on prod for a probe is out of scope
([docs/Q1B_ATTACHMENT_FEEDBACK_AUDIT.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/Q1B_ATTACHMENT_FEEDBACK_AUDIT.md)).

So the extract path is **Ghostscript `txtwrite`**, first **8** pages, skip if
the file is over **15 MB**. Optional Tesseract backend stays dark until the
binary exists (scanned pages with no text layer then skip as `pdf_no_text_layer`).

**Unicode path gotcha.** `gs -dSAFER` cannot open the Cyrillic filename on
lesson 1723 (`invalidfileaccess` / Permission denied). The writer copies the
blob to an ASCII temp file, extracts, then writes the sibling `.txt`.

```
php artisan hindi:pdf-sidecar 1723 --json
php artisan hindi:pdf-sidecar 1723 --apply --json
```

Dry-run is the default. JSON reports counts and script flags, never the
extracted textbook. One lesson per invocation. Not a bulk OCR of history.

### Lesson 1723 measured (14-08-2026, no fabricated text)

Homework file `homework-prompts/Практическая_грамматика_хинди_Уровень_a1.pdf`
(authors visible in the text layer: Е. Офимкина / К. Серова).

| Measure | Value |
|---|---|
| Bytes | 6 083 037 (under the 15 MB cap) |
| Pages | 148 |
| Bound | first 8 pages |
| Extracted chars (p1–8, gs txtwrite) | 12 338 |
| Devanagari / Cyrillic / Latin | yes / yes / yes |
| Sidecar before this residual | none (`pdf_no_extract`) |
| `HINDI_ATTACHMENT_DRILLS` | **OFF** |

This is a digital textbook with a text layer, not a scan-only PDF. After
`--apply`, H2444 consumes the sibling `.txt` the same way as a curator-written
sidecar. Bulk OCR of the remaining 140 pages is still a non-goal.

## Prod census (14-08-2026)

Hindi shells `356,366,357,371,401,402,416,432,438,442` (+ 413): **130** lessons.

| Source | Count |
|---|---|
| `lessons.attachments` non-empty | **0** |
| `homework_attachments` non-empty | **3** (1 PDF textbook, 5 mp3, 1 png) |
| `course_materials` rows | **0** |
| PDF sibling `.txt` | **0** (until `hindi:pdf-sidecar 1723 --apply`) |

So live Hindi review still comes from transcripts (H2443). Attachment drills
light up when a curator uploads a txt/md/docx (or a PDF plus sidecar `.txt`).
Until then the page can still show «Открыть раздатку» for the one homework PDF
on lesson 1723.

## Probe / census

```
php artisan hindi:attachments-census --json
php artisan hindi:drills-probe {lesson_id} --json
php artisan hindi:pdf-sidecar {lesson_id} --json
```

All three work with the flag OFF. Probe redacts answers (`***`). Sidecar
JSON redacts extract text.

## Enable on prod

Default **OFF**. After deploy: `HINDI_ATTACHMENT_DRILLS=true` + `config:cache`.
Rollback: `false` + `config:cache`. Writing a sidecar does not require the flag.

_Dr. Mārcis Gasūns_
