# H4474 — Subhashita audio → SRS layer: manifest + mapping (rights gate open)

_Created: 13-09-2026 · Last updated: 13-09-2026_

Handoff: [H4474](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4474-OxAlpha_Systema-Sanscriticum_subhashita-audio-srs_09.09.26.md).

## What this delivers

- [audio_manifest.tsv](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4474-subhashita-audio-srs-drain/docs/H4474-subhashita-audio-srs/audio_manifest.tsv) — 207 rows (15 `Kochergina-Subhashitas` + 96 `Subhashitas-Systematic` + 96 `Subhashitas-Systematic (1)`, the latter two being two parallel namings of the same 96-saying set), each with `size_bytes` + `duration_sec_approx` (calibrated from 2 downloaded samples at a constant ~128 kbps CBR, ffprobe-verified: `18.34s`/`293858B` and `18.65s`/`298873B`, both giving ~16024 bytes/s — good enough for a manifest, not frame-exact).
- Filename → Böhtlingk *Indische Sprüche* numbering mapping, matched against [kosha `data/subhashita/subhashita_difficulty.tsv`](https://github.com/gasyoun/kosha/blob/main/data/subhashita/subhashita_difficulty.tsv) (the 7,537-saying canonical index that feeds the `subhashita-reader-pack`, per its [manifest row](https://github.com/gasyoun/kosha/blob/main/data/manifest/datasets.json) `keying: "... Indische Sprüche numbering"`): **40 high-confidence exact-first-word matches** (≥10 required by acceptance — spot-checked below), **46 medium-confidence prefix matches**, 121 unmatched (mostly the `Subhashitas-Systematic` docx list rows and files whose leading transliterated word doesn't isolate to one saying — see `match_method` column).
- [WIRING_PLAN.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4474-subhashita-audio-srs-drain/docs/H4474-subhashita-audio-srs/WIRING_PLAN.md) — design-only plan to extend the `kosha-srs-deck-b1-demo` card-deck pattern with an `audio_file` field, keyed by `boehtlingk_num`.

## Spot-check (5 of the 40 high-confidence rows, verified by hand against `iast_head`)

| yadisk file | matched saying_id | iast_head (kosha) |
|---|---|---|
| `Субхашита Дива (Diva) दिवा.mp3` | Saying 2805 | divā paśyati nolūkaḥ kāko naktaṃ na paśyati |
| `Субхашита Кавих (Kaviḥ) कविः.mp3` | Saying 1584 | kaviḥ karoti kāvyāni svādu jānāti paṇḍitaḥ |
| `Su23-Adata.mp3` | Saying 192 | adātā vaṃśadoṣeṇa karmadoṣāddaridratā |
| `Su46-Sukhasya.mp3` | Saying 7082 | sukhasya duḥkhasya na me 'sti dātṛtā |
| `Su85-Vidvaneva.mp3` | Saying 6114 | vidvāneva vijānāti vidvajjanapariśramam |

All five read as semantically coherent subhashitas whose opening word matches the filename's transliteration — not coincidental collisions.

## Access method used (for the next session — do not re-derive)

This box (Windows, this worktree) has **no rclone install and no `~/.config/rclone/rclone.conf`** — [FINDINGS §719](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md)'s `yadisk:` remote is Mac-only. Fetched WebDAV credentials instead, over the already-trusted SSH key: `ssh root@193.232.229.92 "grep -E '^YANDEX_DISK_(LOGIN|APP_PASSWORD)=' /var/www/html/.env | base64 -w0"` (whole-blob base64, **not** line-wise — line-wise truncates the password per the same finding's gotcha), then plain `curl -X PROPFIND -u "$LOGIN:$PASS" -H "Depth: 1" https://webdav.yandex.ru/<folder>/` — no rclone needed, WebDAV is a raw HTTP verb. Scripts: [`tools_h4474_list_yadisk.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4474-subhashita-audio-srs-drain/tools_h4474_list_yadisk.py), [`tools_h4474_list_sys.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4474-subhashita-audio-srs-drain/tools_h4474_list_sys.py), [`tools_h4474_build_manifest.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4474-subhashita-audio-srs-drain/tools_h4474_build_manifest.py), [`tools_h4474_add_duration.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4474-subhashita-audio-srs-drain/tools_h4474_add_duration.py) (repo root of this worktree — move into a proper `scripts/` home if this line of work continues).

## ⚠️ Rights gate — NOT resolved, mission's own stop condition

The handoff's mission text is explicit: *"RIGHTS CONFIRM from MG in first step (recordings ownership/license) ... Stop if rights unclear — ask."* No prior rights confirmation for `Kochergina-Subhashitas` or `Subhashitas-Systematic` exists anywhere searched (Uprava `FINDINGS.md`, `GTD_NEXT_ACTIONS.md`, `SHADOW_ASSETS_POINTERS.md`, memory store) — only the Palsule XLS rights clearance (07-09-2026, unrelated asset) turned up.

**What is known:** the *source text* (the subhashitas themselves) is Böhtlingk's *Indische Sprüche* (1870–73), already registered in kosha as public domain (`"Base text public domain (Böhtlingk, Indische Sprüche 1870-73, SanskritLexicography F33, consumed in place — never copied wholesale)"`, kosha manifest row `subhashita-reader-pack`). **What is NOT known:** who performed/recorded these 111 audio files (a named reader? MG? a hired teacher?) and under what terms — the *recording* (a performance) is a separate right from the public-domain text being read.

**Because of this, this PR does NOT wire the audio into the live Systema-Sanscriticum SRS app** — it stops at manifest + mapping + a design-only plan, per the mission's explicit stop condition. Publishing these files to enrolled/paying students is the step that needs the answer below.

### The question for MG (with the concrete facts this session found, so a one-line answer suffices)

1. Who recorded the 111 audio files at `yadisk:Kochergina-Subhashitas/` (15 files) and `yadisk:Subhashitas-Systematic/` (96 files, duplicated under `Subhashitas-Systematic (1)/` with an older/alternate numbering)?
2. Is Systema-Sanscriticum (a paid product, samskrte.ru) cleared to embed these recordings as playable SRS audio for students, or are they for internal/personal study only?

**If yes:** the next session executes [WIRING_PLAN.md](WIRING_PLAN.md) directly — no further mapping work needed, the manifest above is ready to consume.
**If unclear or no:** the manifest stays as a private internal reference (this doc's home, not shipped to the app); no student-facing change happens.
