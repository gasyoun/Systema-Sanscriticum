# RWS councils — Majors resolution ledger (ACCEPT A9)

_Created: 26-07-2026 · Last updated: 26-07-2026_

Runs: `sanskrit` → `runs/pwg-arz-skt-final` (архив: [sanskrit-council-report.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/rws/sanskrit-council-report.md));
`indology` → см. ниже. Провайдер: DeepSeek `deepseek-v4-pro` (алиас `deepseek-chat` мёртв — папиркат `6a605fe558e2`).
`validate-run` падает на внутренней сверке ID (`pNNN-finding-NNN`) — баг движка после смены модели, папиркат `1c363c5c62b8`; сами findings целы и разобраны ниже.

## Sanskrit council — 13 findings (11 major, 2 minor)

| Span | Стиль / тип | Вердикт | Как закрыто |
|---|---|---|---|
| p035 | elizarenkova / missing_iast_on_first_mention (major) | FIXED | «Ригведы (Ṛgveda)» при первом упоминании; также Atharvaveda, kośa |
| p005 | lidova / missing_tradition_context (major) | FIXED | в гл. 1 добавлена отсылка к индийской словарной традиции (→ гл. 14) |
| p033 | lidova / weak_genre_framing (major) | FIXED | гл. 6: фраза о смене жанра — «сплошной комментарий ко всей традиции» |
| p035 | lidova / missing_authority_chain (major) | FIXED | гл. 6: конфликт авторитетов + оговорка Ольденбурга-1904 (FACTS CA-41) |
| p042 | lidova / commentary_function_gap (minor) | LOGGED | FOLLOWUPS (минор; глава и так трактует латынь как фильтр аудитории) |
| p072 | lidova / missing_tradition_context (major) | FIXED | гл. 14: развёрнута характеристика кош + Амаракоша (FACTS CA-42) |
| p006, p023, p034, p054, p055, p070 | tronsky / missing_source (major ×6) | RESOLVED-BY-DESIGN + FIXED | жанр Arzamas выносит аппарат в FACTS.md (решение D17 плана); финал эссе теперь даёт прямую ссылку на таблицу фактов; для статистики добавлены inline-атрибуции (гл. 8 «исследование 2026 года… нашего проекта», гл. 10 ссылка на csl-atlas, гл. 13 даты письма/предисловия) |
| p043 | tronsky / missing_source (major) | FIXED | гл. 8: атрибуция исследования (A36) добавлена в текст, полная ссылка в FACTS C8-* |
| p077 | tronsky / unsupported_reading (major) | FIXED | гл. 15: ссылка на Мединикошу названа в тексте (FACTS CA-43; pwg.txt L1 `MED. avy. 2`) |

Открытых Majors: **0**. Единственный minor — в FOLLOWUPS.

## Indology council — 11 findings (4 major, 7 minor), run `pwg-arz-ind-final2`

Архив: [indology-council-report.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/rws/indology-council-report.md).

| Span | Стиль / тип | Вердикт | Как закрыто |
|---|---|---|---|
| p078 | toporov-etym / unsupported_sanskrit_etymology (major) | FIXED | гл. 15: сопоставление a- ~ ἀ-/in-/un- переатрибутировано самому словарю («сам словарь… сопоставляет») — это пересказ статьи pwg.txt L3 (FACTS C15-2), а не авторская этимология |
| p078 | toporov-etym / missing_iast (minor) | FIXED | «анАнга» → anaṅga (IAST) |
| p035, p054 | elizarenkova / missing_iast (minor ×2) | FIXED | Саяна (Sāyaṇa), Панини (Pāṇini) при первом упоминании |
| p002, p027 ×3 | elizarenkova / missing_iast (minor ×4: Mahābhārata, Yajurveda, Taittirīya-saṃhitā, Śatapatha-brāhmaṇa) | LOGGED | сознательное решение регистра (Arzamas, D1/D2): сплошные IAST-скобки в научно-популярном тексте утяжеляют лид и «географию» гл. 5; IAST дан выборочно для терминов, несущих филологическую нагрузку. FOLLOWUPS W2-6 |
| p002 | lidova / weak_genre_framing (major) | RESOLVED-BY-GENRE | паспорт lidova-commentary требует академическое введение с исследовательским вопросом; жанр зафиксирован решением плана D1 (Arzamas 1100, нарративная завязка). Плановое решение старше стилевого паспорта (D20: default плана) |
| p033 | lidova / missing_tradition_context (major) | PARTIALLY FIXED + LOGGED | добавленное в sanskrit-пассе («сплошной комментарий ко всей традиции», коши+Амаракоша в гл. 14) — предел жанра; полная теория «словаря-комментария» → академический companion (FOLLOWUPS W2-6) |
| p035 | lidova / missing_authority_chain (major) | PARTIALLY FIXED + LOGGED | конфликт авторитетов и оговорка Ольденбурга уже добавлены (гл. 6); институциональная теория бхашьи → W2-6 |

Открытых Majors, требующих правки текста: **0** (2 lidova-major закрыты по границе жанра с письменной мотивировкой — D1/D20; toporov-major исправлен).

_Dr. Mārcis Gasūns_
