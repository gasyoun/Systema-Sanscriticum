# Hindi drills from Kostina module dictionaries (H3206)

_Created: 20-08-2026 · Last updated: 20-08-2026_

## What ships

Student practice on `/dvaram/programme/hindi/vocab` built from Elena Kostina's
module PDFs («Хинди для начинающих», M1–M12), not from lesson transcripts.

Agent-made cloze/translate cards from Whisper/Deepgram and from PDF handouts
stay **off** for students (`HINDI_TRANSCRIPT_DRILLS` / `HINDI_ATTACHMENT_DRILLS`).
The teacher sees them collected on
`/admin/hindi-agent-drills` (Filament, group «Обучение»).

| Piece | Role |
|---|---|
| [`database/data/kostina_hindi_dicts/entries.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/data/kostina_hindi_dicts/entries.json) | 976 lemma↔gloss rows |
| [`HindiKostinaDictionary`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiKostinaDictionary.php) | JSON store |
| [`HindiDictionaryDrills`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HindiDictionaryDrills.php) | translate / reverse / vocab_pick |
| [`HindiAgentDrillsReview`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/HindiAgentDrillsReview.php) | teacher-only dump of agent cards |

Flag: `features.hindi_dictionary_drills` / `HINDI_DICTIONARY_DRILLS`, default **OFF**.
Hindi teachers still preview (same `teachesHindi` pattern as H2446).

Access: same playlist unlock as H2441. Does not grant payments.

## Extraction

PDFs in `D:\ClaudeTools\evidence\Словари\Словари`. Kokila-Bold ToUnicode
doubles matras (`दाादाा` → `दादा`). Repair: overlapping glyph boxes +
consecutive-consonant collapse + Tesseract `hin+rus` for holes (`कि�तना` → `कितना`).
Cyrillic from TTNorms is clean. 12 broken rows dropped (no safe Hindi).

Probe (flag may stay off):

```
php artisan hindi:dict-probe --json
```

## Enable on prod

`HINDI_DICTIONARY_DRILLS=true` + `php artisan config:cache`.
Keep transcript/attachment flags **false**.

_Dr. Mārcis Gasūns_
