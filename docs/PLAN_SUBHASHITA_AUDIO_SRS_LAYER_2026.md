# Subhāṣita audio → SRS layer — manifest, Böhtlingk mapping, wiring plan (H4474)

_Created: 13-09-2026 · Last updated: 13-09-2026_

Executor: OxAlpha (opencode/z-ai/glm-5.3-flash) label, run by Opus 5 (claude-opus-5[1m]) · Handoff: [H4474](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4474-OxAlpha_Systema-Sanscriticum_subhashita-audio-srs_09.09.26.md) — субхашит-аудио → SRS, class `data`, effort medium.

The 126 subhāṣita mp3 on MG's Yandex.Disk ([YADISK_INVENTORY_07-09-2026 §3 row 6](https://github.com/gasyoun/Uprava/blob/main/reports/YADISK_INVENTORY_07-09-2026.md)) are the **audio delta** for the existing text layer: kosha's [subhashita-reader-pack](https://github.com/gasyoun/kosha/blob/main/data/subhashita/subhashita_beginner_pack.json) (106 graded sayings) and the 7537-saying Böhtlingk corpus [indische_sprueche.jsonl](https://github.com/gasyoun/SanskritLexicography/blob/master/IndischeSprueche/data/indische_sprueche.jsonl). This pass lands the join — an audio manifest with Böhtlingk numbers — not the audio itself.

## 1 · What is on the disk (live `rclone lsf`, 13-09-2026)

| Folder | Files | Naming | Role |
|---|--:|---|---|
| `yadisk:Subhashitas-Systematic` | 96 mp3 + 3 docx | `Su<N>-<Slug>.mp3` | **canonical** systematic series |
| `yadisk:Subhashitas-Systematic/Kochergina-Subhashitas` | 15 mp3 | `Su-<Slug>.mp3` | Kochergina set, Latin-named mirror |
| `yadisk:Kochergina-Subhashitas` | 15 mp3 | `Субхашита <Slug> (IAST) देवनागरी.mp3` | same 15 recordings, Devanagari-named |
| `yadisk:Subhashitas-Systematic (1)` | 96 mp3 | `NN Субхашита <Slug> (IAST) देवनागरी.mp3` | same 96 recordings, Devanagari-named |

**111 distinct recordings** (96 + 15); the two Devanagari-named folders are byte-identical mirrors and exist only to carry the pratīka in the filename — that is what makes the Böhtlingk join possible. 126 = 111 + the 15 Kochergina files counted twice in the 07-09 inventory. Total audio **35.1 min**, all 128 kbps mono mp3, 15–21 s per saying.

Three docx in `Subhashitas-Systematic` are the text key:

1. `Subhashita-Recordings-Text.docx` — 53 numbered verses in Devanagari, numbering identical to `Su<N>`.
2. `Subhashita_EnRuDe_Boethlingk-95.docx` — «सुभाषित-दैनन्दिनम् / Древнеиндийские афоризмы» (2014), 95 sayings with IAST, word split, EN/RU/DE translation; its table of contents gives a pratīka + page for each `Su<N>`.
3. `Subhashita_EnRuDe_Boethlingk-95_2018.docx` — 2018 revision of the same anthology (not parsed this pass).

## 2 · The manifest

[resources/data/subhashita_audio_manifest.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/subhashita_audio_manifest.tsv) — 111 rows, one per recording:

`audio_id · set · file · su_num · slug · iast · deva_pratika · duration_s · size_bytes · bitrate_kbps · anthology_num · anthology_page · is_num · match_method`

- `audio_id` — stable key for the SRS card link (`sub-su065-vidyadadati`, `sub-koch-vidya`).
- `file` — path **relative to the Yandex.Disk folder**, not a repo path: no mp3 is vendored here.
- `is_num` — Böhtlingk *Indische Sprüche* number, the join key to the text layer.
- `match_method` — how that number was established (see §3).

Built by [scripts/build_subhashita_audio_manifest.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/build_subhashita_audio_manifest.py), checked by [scripts/verify_subhashita_audio_manifest.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/verify_subhashita_audio_manifest.py).

## 3 · Mapping result — 59 of 111 recordings carry a Böhtlingk number

| Evidence tier | Rows | What it means |
|---|--:|---|
| `verse_prefix` | 23 | the recorded verse (from the recordings docx) opens exactly one saying |
| `toc_pratika` | 24 | the anthology's pratīka opens exactly one saying |
| `file_pratika` | 10 | the Devanagari in the filename opens exactly one saying |
| `*_ambiguous(N)` | 2 | several sayings open the same way — first kept, needs a human eye |
| `unmatched` | 52 | — |

Verifier output: **pass 54 · variant 5 · fail 0** (`exit 0`). "Variant" = the tape and Böhtlingk print the same verse in different recensions — छायामन्यस्य कुर्वन्ति *तिष्ठन्ति* / *स्वयं*, पृथिव्यां *त्रीणि* / *त्रीणी*, अनित्यानि शरीराणि *वैभवं* / *विभवो*, संसारविषवृक्षस्य द्वे *एव* / *फले*, पुस्तकस्था *तु* / *च* या. These are real matches and are flagged, never silently folded into `pass`.

**The 52 unmatched are not a defect.** The anthology's own preface names Mahābhārata, Pañcatantra, Hitopadeśa, Vikramacarita, the Upaniṣads, Sutta-nipāta, Bhartṛhari and Manu beside Böhtlingk — probed live: गते शोकं न कुर्वीत and विदेशेषु धनं विद्या have **zero** occurrences in the 7537-saying corpus. The Kochergina set (15) is unmatched by construction: its filenames carry a one-word pratīka (विद्या, दिवा, त्यज) too weak to identify one saying out of 7537, and its verses are not in the recordings docx.

## 4 · Rights — OPEN, and the reason nothing is blocked by it

The handoff asked for a rights confirmation from MG before work. This pass was run unattended, so **no confirmation was obtained and none was assumed**. What is known:

1. The recordings sit in MG's own Yandex.Disk teaching tree, in the TEACHING/money layer of the 07-09 inventory — the reading is that they are own-produced classroom audio, but the speaker is not documented anywhere in the estate.
2. The **text** layer is unambiguously clear: Böhtlingk's *Indische Sprüche* (2nd ed. 1870–73) is public domain, and the reader-pack already ships on that basis.
3. **No audio is committed by this pass** — the manifest holds filenames, durations and saying numbers only, so nothing here republishes a recording.

Per the org standing policy ([rights uncertainty is not a stop](https://github.com/gasyoun/Uprava/blob/main/docs/STANDING_POLICY_RIGHTS_UNCERTAINTY_IS_NOT_A_STOP_2026.md)) the metadata work proceeds; the one thing gated on a human is **serving the mp3 to students**, which is stage 3 below.

## 5 · Wiring plan — three stages, after the pattern of the b1 demo deck

Existing pattern to copy: [resources/data/kosha_srs_deck_b1_demo.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/data/kosha_srs_deck_b1_demo.json) (vendored static feed) + [app/Console/Commands/ImportKoshaSrsDeckB1Demo.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportKoshaSrsDeckB1Demo.php) (idempotent import into the SRS tables).

1. **Stage 1 — deck feed (no audio, ships today).** Generate `resources/data/subhashita_srs_deck.json` from the manifest joined to the reader-pack: card front = Devanagari verse, back = RU translation from the anthology docx, `is_num` as the stable card key, `audio_id` carried but unused. Import command `subhashita:import-deck` modelled line-for-line on `ImportKoshaSrsDeckB1Demo`. Nothing user-visible changes until the deck is published.
2. **Stage 2 — audio hosting (needs a storage decision).** 35.1 min / ~33 MB is small enough for the prod disk under `storage/app/public/audio/subhashita/` served through the existing public-disk symlink; the alternative is the S3-compatible bucket already configured for lecture clips. The mp3 are pushed with `rclone copy yadisk:Subhashitas-Systematic <target> --include "*.mp3"`, filenames normalised to `audio_id`.
3. **Stage 3 — play button in the SRS card (human-gated by §4).** The card renders an `<audio>` element for rows with an `audio_id`; 59 cards additionally show «Бётлингк IS <n>» as provenance. Ship only after MG confirms the recordings may be served to students.

## 6 · Reproduce

```sh
R=~/tools/rclone.exe; S=/tmp/h4474-staging; mkdir -p "$S/audio" "$S/docx"
$R lsf -R --files-only --format sp  yadisk:Subhashitas-Systematic      > "$S/listing_systematic.txt"
$R lsf -R --files-only --format sp "yadisk:Subhashitas-Systematic (1)" > "$S/listing_systematic1.txt"
$R lsf -R --files-only --format sp  yadisk:Kochergina-Subhashitas      > "$S/listing_kochergina.txt"
$R copy yadisk:Subhashitas-Systematic "$S/docx"  --include "*.docx"
$R copy yadisk:Subhashitas-Systematic "$S/audio" --include "*.mp3" --transfers 8
pandoc -t plain "$S/docx/Subhashita-Recordings-Text.docx"        -o "$S/recordings_text.txt"
pandoc -t plain "$S/docx/Subhashita_EnRuDe_Boethlingk-95.docx"   -o "$S/boethlingk95.txt"
cd "$S/audio" && find . -name '*.mp3' | while read f; do \
  printf '%s\t%s\t%s\n' "${f#./}" \
    "$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$f")" \
    "$(ffprobe -v error -show_entries format=bit_rate -of csv=p=0 "$f")"; done > "$S/durations.tsv"

python scripts/build_subhashita_audio_manifest.py --staging "$S" \
  --sprueche ../SanskritLexicography/IndischeSprueche/data/indische_sprueche.jsonl \
  --out resources/data/subhashita_audio_manifest.tsv
python scripts/verify_subhashita_audio_manifest.py \
  --manifest resources/data/subhashita_audio_manifest.tsv \
  --sprueche ../SanskritLexicography/IndischeSprueche/data/indische_sprueche.jsonl
```

The `yadisk:` remote is self-served from prod credentials — procedure in [Uprava FINDINGS §719](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md); it had to be recreated on the Windows box this pass (it existed only on the Mac), which is a one-time `rclone config create` from `/var/www/html/.env`.

## 7 · Risks and open questions

1. **Speaker/licence of the recordings is undocumented** — §4; blocks stage 3 only.
2. **Duplicate takes:** `Su27`/`Su27-Ayusha`, `Su32`/`Su32-Arthanam`, `Su41`/`Su41-Nabhisheko` are different byte sizes — alternative takes, both kept in the manifest; a human picks one per card at stage 1.
3. **Gaps in the series:** no `Su12`, `Su37`, `Su96+` on disk although the anthology runs to 95; the corresponding sayings simply have no recording.
4. **Two `*_ambiguous(2)` rows** carry the first of two candidate saying numbers — worth a human eye before they reach a card.
5. The 2018 anthology revision is unparsed; it may resolve some of the 52 unmatched rows if its TOC cites Böhtlingk numbers directly.

_Гасунс_
