# RWS councils — Majors resolution ledger (H1696)

_Created: 26-07-2026 · Last updated: 26-07-2026_

Runs: `sanskrit` → `runs/kos-arz-skt` (архив: [sanskrit-council-report.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/rws/sanskrit-council-report.md));
`general` → `runs/kos-arz-gen` (архив: [general-council-report.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/rws/general-council-report.md)).
Провайдер: DeepSeek `deepseek-v4-pro` (алиас `deepseek-chat` мёртв — папиркат `6a605fe558e2`);
`validate-run` падает на сверке ID — папиркат `1c363c5c62b8`, findings разобраны по `findings`/report.
Прецедентная база: [MAJORS_RESOLUTION первой заметки](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/rws/MAJORS_RESOLUTION.md)
(решения D1/D17/D20 плана + W2-6) — применена без повторного вывода.

## Sanskrit council — 24 findings (21 major, 3 minor)

| Span | Стиль / тип | Вердикт | Как закрыто |
|---|---|---|---|
| p006 | elizarenkova / missing_iast (major) | FIXED | «Ригведу» (Ṛgveda) при первом упоминании — тот же фикс, что p035 первой заметки |
| p017 | elizarenkova / missing_iast «Глиняная повозка» (major) | FIXED | добавлено оригинальное название (Mṛcchakaṭikā) — реальный титул драмы, несёт филологическую нагрузку |
| p017 | elizarenkova / missing_iast Махабхарата, Сунда/Упасунда (major ×2) | LOGGED | выборочная IAST-политика первой заметки (W2-6): сплошные IAST-скобки утяжеляют научно-популярный регистр; Махабхарата уже отклонена в первой заметке — консистентность |
| p045 | elizarenkova / missing_iast Вишну, Яма, шубх (major ×3) + toporov / missing_iast (minor) | LOGGED | Вишну/Яма — общеизвестные имена в рецепционном контексте; «шубх»/«жр» — решение M8 DECISIONS_LOG: это цитаты дилетантской работы 1855 г., IAST придал бы им ложную лингвистическую весомость (FACTS, правило 4) |
| p002, p003, p029, p033 | lidova / genre reframing (major ×4) | RESOLVED-BY-GENRE | паспорт lidova требует академическое введение с исследовательским вопросом и теорию «словаря-комментария»; жанр закреплён планом (Arzamas 1100, D1/D20) — то же решение, что p002 первой заметки; материал для академического companion-текста учтён в W2-6 первой заметки |
| p002, p005, p006, p009, p019, p024, p033, p039 | tronsky / missing_source (major ×8) | RESOLVED-BY-DESIGN | жанровое решение D17: научный аппарат вынесен в [FACTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/FACTS.md) (строки K-* с точными сносками Вигасина); Вигасин атрибутирован прямо в тексте (гл. 1 «изучивший архивное дело», гл. 6, 8, 11, 12 — по имени; финал — провенанс-абзац со ссылкой на FACTS); архивных шифров эссе сознательно не несёт — они в примечаниях Вигасина, см. FACTS правило 1 |
| p045 | tronsky / uncritical_amateur_etymology (major) | FIXED | добавлен явный дисклеймер сразу после примеров: «С точки зрения науки все эти сближения несостоятельны…» (закрывает и minor zalizniak p045) |
| p061 | tronsky / missing_data_source (major) | FIXED | гл. 13: прямая ссылка на A43 добавлена в текст («все числа этой и следующей главы — из открытой статьи…») |
| p045 | zalizniak / no_immediate_refutation (minor) | FIXED | тем же дисклеймером |
| p009 | zalizniak / missing_stress_marks (minor) | LOGGED | знаки ударения на именах — вне регистра Arzamas/веб-типографики samskrte.ru |

Открытых Majors, требующих правки текста: **0** (4 lidova-major и 8 tronsky-major закрыты
по границе жанра с письменной мотивировкой — прецедент первой заметки, D1/D17/D20;
IAST-мажоры: 2 исправлены, 5 отклонены по зафиксированной выборочной политике W2-6/M8).

## General council — 26 findings (19 major, 7 minor), run `kos-arz-gen`

Архив: [general-council-report.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/rws/general-council-report.md).

| Span | Стиль / тип | Вердикт | Как закрыто |
|---|---|---|---|
| p059 | zalizniak-method / overgeneralization (major) | FIXED | **единственная содержательная находка советов**: «единственным русским мостиком… так и остался» противоречила гл. 14 (Кнауэр-1908, Кочергина-1987); переформулировано «до следующего санскрито-русского словаря, учебного словарика Кнауэра 1908 года, единственной русской попыткой оставался…» (хронология по A43) |
| p003, p007, p011, p019, p045 | gasparov / metaphor, emotional lexicon (major ×5) | RESOLVED-BY-GENRE | паспорт gasparov требует сухую научную прозу; нарративный Arzamas-регистр закреплён планом (D1/D20) — метафоры лида и гл. 9 — сознательный жанровый выбор, тот же вердикт, что для lidova в первой заметке |
| p003 | averintsev / oversimplified dichotomy (major) | RESOLVED-BY-GENRE | «немецкая наука vs русская мечта» — тизер лида; сложность конфликта (II Отделение — тоже Академия; Уваров — покровитель санскритологии; Вигасин о небеспочвенности жалоб) развёрнута в гл. 5–6, 11 |
| p009, p045, p069 | melchuk / no formal analysis (major ×3) | RESOLVED-BY-GENRE | паспорт «Смысл — Текст» неприменим к научно-популярному очерку (ср. panini-traditional в sanskrit-совете: 0 находок с явной пометой inapplicable); формальный разбор этимологий Хомякова = задача A43-семейства, не Arzamas-заметки |
| p005, p006, p009, p039, p045 | tronsky / missing_source (major ×5) | RESOLVED-BY-DESIGN | тот же класс, что в sanskrit-совете: аппарат в FACTS.md (D17), Вигасин по имени в тексте + «А. А. Вигасина» в провенанс-абзаце финала; выходные данные работ XIX века — в примечаниях Вигасина (bibliography.md) |
| p009, p035, p045, p046 | zalizniak-novgorod / no philological verification (major ×4) | RESOLVED-BY-GENRE + частично FIXED | требуемый разбор графики/звуковых законов — вне регистра; несостоятельность сближений теперь заявлена явно (дисклеймер гл. 9 из sanskrit-пасса); полная критика этимологий — материал научного companion (W2-6 первой заметки) |
| 7 minor (разные) | — | LOGGED | без правок; существенных среди них нет (повторы тех же классов) |

Открытых Majors, требующих правки текста: **0**. Итог обоих советов: 2 содержательные
правки (p059-сверхобобщение, p061-ссылка на A43), 3 IAST-вставки, 1 дисклеймер;
остальное — зафиксированные жанровые границы по прецеденту первой заметки.

## Пост-советный mining-pass (M11, 26-07-2026)

После закрытия советов текст расширен 15 → 16 глав (DECISIONS M11): биография по статье
Ольденбурга о Коссовиче, новая гл. 10 «Внутри словаря» на материале kow.jsonl, блок о
переизданиях, финальные формулы из escrow-версии. Правки — фактологические дополнения,
каждое с новой FACTS-строкой (K3-9…K16-5); стилистический регистр не менялся, вердикты
советов выше остаются в силе. Повторный совет не созывался: лимит goal H1696 — 2 полных
rewrite-пасса — израсходован, дополнения прошли ту же FACTS-дисциплину, что и основной
текст.

_Dr. Mārcis Gasūns_
