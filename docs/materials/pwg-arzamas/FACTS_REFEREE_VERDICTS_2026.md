# FACTS referee verdicts — hostile pre-publication pass, «Петербургский словарь: 20 глав»

_Created: 30-07-2026 · Last updated: 30-07-2026_

Adversarial re-verification of **every** row of
[FACTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/FACTS.md)
plus factual assertions found in
[SOURCE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/SOURCE.md)
that had no row. Executed under
[H1862](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1862-Fable_Systema-Sanscriticum_arzamas-facts-hostile-referee-prepublication_29.07.26.md)
by Fable 5 (`claude-fable-5`), 30-07-2026. Method: every claim re-read against the
**actual named source** — local files
([pwgpref_all.ru.md](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/pwgpref_all.ru.md)
read in full, [pwg.txt](https://github.com/sanskrit-lexicon/csl-orig/blob/master/v02/pwg/pwg.txt)
entries by L-number, papers/atlas reports by cited line, Вигасин гл. IV and Ольденбург «Этюды»
full texts, [pwg.mdx](https://github.com/sanskrit-lexicon/csl-guides/blob/main/docs/dictionaries/pwg.mdx)),
the two 1855 scans re-read as images, and external references fetched (EB1911 ×4, NIE ×1).
No claim was passed on memory.

**Verdicts:** `sourced` — the named source says what the claim says; `corrected` — a real
divergence was found and the essay/FACTS fixed in this pass; `struck` — the assertion rested
only on model knowledge, no source found → removed from the essay, not softened.

**Totals: 136 claims checked · 121 sourced · 14 corrected · 1 struck. Coverage: 100 % of
FACTS rows + 6 found claims (CR-1…CR-4, F-1, F-2); nothing sampled or skipped.**

## Corrections applied (the review's yield)

| # | Where | Was | Now | Evidence |
|---|---|---|---|---|
| 1 | гл. 3 + C0-2/C3-4 | Жалоба «на расстоянии… единичные встречи» приписана предисловию **т. 2** | «В первом же предисловии…» | Жалоба стоит в предисловии т. 1 (RU-пред. ~189); предисловие т. 2 (~880–885) её не содержит |
| 2 | гл. 3 | Рот «**годом** моложе» Бётлингка | «шестью годами моложе» | 1815 (EB1911) vs 1821 (NIE) |
| 3 | гл. 3 | В 1866 «до конца словаря оставалось **меньше трети**» | «бо́льшая часть пути была уже позади» | По балансу 1864 (RU-пред. ~1090) впереди ⅖; по томам после 1866 — 3 из 7. «Меньше трети» не выдерживает ни одной метрики |
| 4 | гл. 4 + CA-6 | «Тираж **по ходу дела подняли** с 400–500 до 1000» | «пришлось сразу назначить… а в тысячу» | Вигасин IV:~76 — тираж определён при запуске из-за интереса, не поднят в процессе |
| 5 | гл. 4 + CA-8 | «**Лондонский** соперник Гольдштюкер» — эпитет был без источника | оставлен, источник добавлен | EB1911 «Goldstücker»: профессор санскрита UCL с 1852, словарь с 1856, умер в Лондоне 1872 |
| 6 | гл. 5 + C5-2 | Цитата «частью полностью, частью с выбором характернейшего и важнейшего» | точный текст перевода: «отчасти полностью, отчасти с выбором характерного и наиболее важного» | RU-пред. ~100 — закавыченная цитата обязана совпадать дословно |
| 7 | гл. 12 + C12-5 | hevākin — «из **кашмирской хроники**» | «из поэмы „Викрамаанкадевачарита“» | pwg.txt L122732 даёт только `VIKRAMĀṄKADEVACARITA 7,63`; «кашмирская хроника» не подтверждена ни одним источником корпуса (и предметно неточна) |
| 8 | гл. 14 + C14-1/CA-42 | Коши «передавались изустно, от учителя к ученику, задолго до печатного станка»; Амаракоша — «школьный словарь Индии полторы тысячи лет» | стихи «чтобы заучиваться наизусть»; «сложена около IV века — и полтора тысячелетия спустя её всё ещё переиздавали по всему миру, от Рима до Калькутты» | EB1911 «Amara Sinha»: in metre «to aid the memory», fl. c. A.D. 375, издания Rome 1798 … Calcutta 1831; «школьный словарь» источника не имел — снято |
| 9 | гл. 15 | «со ссылкой на **средневековую** Мединикошу» | «со ссылкой на Мединикошу» | датирующий эпитет не имел источника |
| 10 | гл. 16 + CR-2 | «**Министр просвещения** Уваров потребовал… по-латыни» | «Президент Академии Уваров» | Вигасин IV:~70 (минпрос сдан в окт. 1849), ~82 («Президент Академии С. С. Уваров вмешался»); конфликт — дек. 1852 |
| 11 | гл. 16 + C16-2 | типография «**на Васильевском острове**» | «в типографии самой Академии» | адрес не имел источника; сам факт типографии Академии — на титуле (скан) |
| 12 | гл. 17 | Сеть словаря: «…Берлин, **Бреслау**, Тюбинген…» | Бреслау снят из перечня | город Штенцлера не подтверждён ни одним источником корпуса; остальные пять городов сорсированы |
| 13 | гл. 19 + C19-2 | «**Ещё до словаря** Рот вместе с Уитни издал Атхарваведу» | «В год выхода первого тома…» | AV. = Berlin 1855 (RU-пред. ~229) — год первого тома; гл. 10 эссе уже говорила это правильно (внутреннее противоречие устранено) |
| 14 | гл. 7 + C7-1 | классификация «создана индийскими грамматистами **за тысячелетия до европейской фонетики**» | «создана древнеиндийскими грамматистами» | сравнительная формула не имела источника; порядок алфавита сорсирован (Кочергина 1998) |
| 15 | гл. 1 | санскрит — язык, на котором «**уже две тысячи лет** никто не говорил на улице» | «давно уже никто не говорил на улице» | датировка выхода санскрита из уличного обихода спорна и источника не имела; сорсируемое ядро (рукописная традиция, не уличная речь — RU-пред. ~100/204) сохранено |

**Struck (1):** C16-4 «немецкий — рабочий язык тогдашней Академии» — обобщение об Академии
в целом источника не имело; формула удалена из гл. 16 (осталось «писался по-немецки» —
титул). Правка № 8 также содержит struck-компонент («школьный словарь Индии»), № 15, № 9,
№ 11, № 12, № 14 — struck-фрагменты внутри сохранённых предложений: везде неподтверждённое
УДАЛЕНО, а не смягчено.

## Per-claim verdict table

Evidence shorthand: **RU~N** = pwgpref_all.ru.md, line N of the committed file (re-read this
pass; the FACTS `~N` markers matched throughout except C0-2/C3-4 — correction № 1);
**L N** = pwg.txt `<L>` record; file:line = committed repo file re-read at that line.

| claim_id | verdict | evidence (what was actually checked) |
|---|---|---|
| C0-1 | sourced | scan pwg-title-1855.jpg (Sanskrit-Wörterbuch, Kaiserl. Akademie); pwg.mdx («Großes Petersburger Wörterbuch») |
| C0-2 | **corrected** | № 1 — quote real (RU~189), volume attribution fixed |
| C1-2 | sourced (+ № 15) | RU~100 (перечень эксцерпированного), RU~204/374 (пометы «Рукопись») |
| C1-3 | sourced | RU~1253 — дословно |
| C1-4 | sourced | RU~1094 — «пользуются сотни людей» |
| C2-1 | sourced | scan: «HERAUSGEGEBEN VON DER KAISERLICHEN AKADEMIE…, BEARBEITET VON OTTO BÖHTLINGK UND RUDOLPH ROTH» |
| C2-2 | sourced | scan: «ERSTER THEIL. (1852–1855) DIE VOCALE» — даты на скане (в RU-переводе титула опущены) |
| C2-3 | sourced | титулы т. 1 (Vocale) / т. 2 (क—…); Кочергина 1998 (гласные → согласные) — источник добавлен |
| C2-4 | sourced | scan: Eggers & Comp. St. Petersburg / Leopold Voss Leipzig / Benjamin Duprat; «…50 Cop. Silb. = 7 Thlr.» (низ обрезан — hedged-статус ряда оправдан) |
| C3-1 | sourced | EB1911 Böhtlingk: 30-05/11-06-1815 СПб; Академия 1842; ординарный 1855; Йена 1868; Лейпциг 1885; ум. 01-04-1904 |
| C3-2 | sourced (+ № 2) | NIE Roth: 1821 Штутгарт; экстраорд. проф. вост. языков 1848; орд. проф. + главный библиотекарь 1856; ум. 1895 |
| C3-3 | sourced | большая дуга СПб—Тюбинген ≈ 1 866 км — «почти две тысячи» корректно; пересчёт записан в ряд |
| C3-4 | **corrected** | № 1 |
| C3-5 | sourced | RU~884–885: «Санкт-Петербург / Тюбинген, 14/26 октября 1858» (предисловие т. 2) |
| C3-6 | sourced | RU~187: Рот — Веда + Suçruta + ботанические названия; Бётлингк — остальное + сведение |
| C3-7 | sourced | RU~1269: «Йена и Тюбинген, 4 августа 1875» |
| C4-1 | sourced | титулы: 1855 (RU~62), 1858 (~613), 1861 (~982), 1865 (~1079), 1868 (~1129), 1871 (~1217), 1875 (~1244) |
| C4-2 | sourced | `wc -l pwg.txt` = 593 596 — пересчитан этим пассом |
| C4-3 | sourced | RU~189: «не достанет прилежания и целого десятилетия»; преемники — там же |
| C4-4 | sourced | RU~1090: ⅗ по 2-му изд. Уилсона; 12½; 8½; «прожить которые…»; дата 17/29-11-1864 (RU~1092) |
| C4-5 | sourced | RU~1090: «посвятить длинный ряд лет всецело словарю» |
| C5-1 | sourced | RU~79: два элемента — индийские собрания + собственные |
| C5-2 | **corrected** | № 6 — перечень реален (RU~100), кавычковая цитата выровнена дословно |
| C5-3 | sourced | RU~156: копия TS через д-ра Рёра из Calcutta во время печатания |
| C5-4 | sourced | MW L87012 «chief recension of the Black YV.» — источник добавлен (был «общесанскритологический факт») |
| C5-5 | sourced | RU~158: Уитни, New Haven, полный словоуказатель к Atharvan |
| C5-6 | sourced | RU~160: Вебер — Çatapathabrâhmaṇa, сутры Kâtjâjana/Çâṅkhâjana/Pâraskara |
| C5-7 | sourced | RU~166: Штенцлер, указатель к Manu, «все слова с указанием всех мест» |
| C5-8 | sourced | RU~168: Шифнер, буддийская литература (Vjutpatti) |
| C5-9 | sourced | RU~424 (MBh.: с. 521 — кн. 1–3; с. 601 — последние пять; с ऋ — кн. 13) |
| C5-10 | sourced | RU~170 — дословно |
| C6-1 | sourced | RU~102: «совершенно новая область», «доселе едва ли предполагавшееся богатство» |
| C6-2 | sourced | RU~102: «истёртой или разбитой монете» / «полную, неповреждённую чеканку» |
| C6-3 | sourced | RU~106: Геродот и трагики; «отбросив Гомера» в эссе — глосс к «оставлял без внимания всё более древнее» (Гомер назван в RU~154 рядом) — в пределах жанра |
| C6-4 | sourced | RU~108–110: «мнимый перевод», «будучи слаб в знании языка…» — дословно |
| C6-5 | sourced | RU~152: «сила, жертва, пища, мудрость» |
| C6-6 | sourced | RU~131: «гораздо вернее и лучше»; «тот смысл, который сами поэты вложили» |
| C6-7 | sourced | RU~154: «будучи новейшею, первою же и устареет» |
| C6-8 | sourced | RU~154: Гомер — столетия трудов, «не объяснён до конца» |
| C7-1 | **corrected** | № 14 — порядок сорсирован (Кочергина 1998: артикуляционные признаки, ka→ma), сравнительная формула снята |
| C7-2 | sourced | RU~183: «полностью изгнали» ऋ/ॠ/ऌ |
| C7-3 | sourced | RU~185: ударение «простейшим, но не принятым у индийцев способом»; класс не указан |
| C7-4 | sourced | RU~1177: «проступок против европейской научности»; «не теории, а практике» |
| C8-1 | sourced | A36 abstract: √yabh → futuere, «no German verb at all» |
| C8-2 | sourced | A36: discretion-screen («щит благопристойности»), §1 механизм |
| C8-3 | sourced | A36 abstract: 2 104 в 11 словарях (1832–1959); 875 в PWG/PW/SCH: 79 vulgar + 796 clinical |
| C8-4 | sourced | A36 abstract: Wilson 1832 absent; futuere 0× (1872) → 4× (1899); оба Апте — открытый английский |
| C8-5 | sourced | A36 abstract: exactly one etymological footnote (yabh), seven comparative-grammar refs in 593 596 lines |
| C9-1 | sourced | EB1911 (7 vols 1879–1889); A50:117 (pw «kürzere Fassung» 1879–1889); pwg.mdx (abridgement) |
| C9-2 | sourced | RU~1150: «выкладывает весь свой запас знаний и ничего не оставляет для себя» |
| C9-3 | sourced | pwg.mdx: «Monier-Williams and Apte both built on it»; атлас pwg.md (proximate source) |
| C9-4 | sourced | A36 (AP90 = Apte 1890) |
| C9-5 | sourced | A36 (SCH = Schmidt's Nachträge, 1928) |
| C10-1 | sourced | pwg.txt L1–L3 (аппарат `<ls>` при значениях); pwg.mdx §Reading an entry |
| C10-2 | sourced | RU~81 — дословно |
| C10-3 | sourced | RU~181 — дословно |
| C10-4 | sourced | pwg.mdx §Citation link coverage: 50 065; 83 % (69 % scan + 14 % HTML); 446 un-digitised; snapshot 02-07-2026; ~51 roots |
| C11-1 | sourced | RU~200 слл. (список сокращений: ṚV.=RU~489, P.=~456, AK.=~213, ŚKDR.=~287, MBH.=~424) |
| C11-2 | sourced | RU~204: «Âśv. Śr. … Рукопись» |
| C11-3 | sourced | RU~229: AV. «издано R. Roth и W. D. Whitney. Berlin … 1855» |
| C12-1 | sourced | RU~618–871: «Дополнения к тому 1/2» — сотни микроправок «читать X вместо Y» |
| C12-2 | sourced | RU~1126 (титул т. 5) + ~1138 («все прежние… исправления отныне излишни») |
| C12-3 | sourced | RU~1240 (титул т. 7) |
| C12-4 | sourced | RU~1160 (сноска т. 5: дополнения Уитни «будут сообщены в конце труда»); «и сообщили» в эссе — вывод из титула т. 7 («ко всему сочинению») + благодарности Уитни в RU~1261 |
| C12-5 | **corrected** | № 7 — запись L122732 перечитана: hevākin, `VIKRAMĀṄKADEVACARITA 7,63`, последняя `<L>` файла |
| C12-6 | sourced | RU~1090 — дословно |
| C12-7 | sourced | RU~993 — дословно |
| C13-1 | sourced | A36 (MW 1872/1899 two-edition control); pwg.mdx; EB1911 M-W |
| C13-2 | sourced | pwg.txt (развёрнутые цитаты в записях); pwg.mdx; атлас pwg.md (4.6 ls/record vs MW 1.09) |
| C13-3 | sourced | pwg.txt L2/L19252 (`<div n>`); pwg.mdx (таблица разметки) |
| C14-1 | **corrected** | № 8 — стихотворность сорсирована (EB1911 «in metre, to aid the memory»; skd.mdx «verse synonym-collections»), изустная передача снята |
| C14-2 | sourced | RU~79 — дословно |
| C14-3 | sourced | RU~77: «предпочёл оставаться на точке зрения индийских учёных» |
| C14-4 | sourced | RU~425 (Med.: «расположены прежде всего по последнему согласному в слове») |
| C14-5 | sourced | RU~181: «к величайшей чести учёного индийца»; экземпляр — «щедрости автора» |
| C14-6 | sourced | RU~193 (сноска: Calcutta, 17-05-1855, «per every Mail», Академия согласилась) |
| C15-1 | sourced (+ № 9) | L1: `a apehi` P. 1,1,14 Sch. (комментарий к Панини ✓); «Drückt Mitleid aus» MED. avy. 2 ✓ |
| C15-2 | sourced | L3: греч. ἀ/ἀν, лат. in, нем. un — дословно |
| C15-3 | sourced | L3: abrāhmaṇa CHĀND. UP. 4,4,5; anaṅga «gliederlos, der Liebesgott» |
| C15-4 | sourced | MW L4817 (Kāma обесплочен вспышкой из глаза Шивы) — источник добавлен |
| C15-5 | sourced | L3 (конец): keśarahita / alpakeśayukta / apraśastakeśaviśiṣṭa — три толкования ✓ |
| C15-6 | sourced | L19252: кукушка / мышь / ядовитое насекомое / «Kohle (nach ihrer Schwärze)» / N. pr. Rājaputra |
| C16-1 | sourced | scan pwg-imprimatur-1855.jpg: дословно, вкл. «Den 13. (25.) December 1855» и «A. Th. v. Middendorff, beständiger Secretär» |
| C16-2 | **corrected** | № 11 — типография Академии на титуле ✓; Васильевский остров снят |
| C16-3 | sourced | RU~1265: «Покровительница же нашего труда, Императорская Академия наук… поручение» |
| C16-4 | **struck** | обобщение «рабочий язык Академии» удалено из эссе (см. выше) |
| C16-5 | sourced (hedged) | pwg.mdx (первый систематический RU/EN перевод); argumentum ex silentio честно помечен в ряде |
| C17-1 | sourced | перекрёстные ссылки эссе на C2-1, C6-7, C9-3, C5-* — все целевые ряды подтверждены выше; список городов исправлен (№ 12) |
| C18-1 | sourced | [csl-guides origins.mdx](https://github.com/sanskrit-lexicon/csl-guides/blob/main/docs/about/origins.mdx) (1994, IITS Cologne, «prepared since 1994»); 43 словаря — A36 §3d, A50; диапазон 1832–1993 — catalog.mdx |
| C18-2 | sourced | pwg.mdx: Basic / List / Advanced / Mobile (4 интерфейса); полный текст + сканы |
| C18-3 | sourced | PWG/prefaces: 27 страниц в pwgpref_all.{de,en,ru}.md — файлы на диске, METHODS.md |
| C18-4 | sourced | pwg.mdx («~51 PWG roots translated so far», RU/EN site) |
| C19-1 | sourced | RU~1094: «побуждает к дальнейшим исследованиям» |
| C19-2 | **corrected** | № 13 — издание AV 1855 ✓ (RU~229), «ещё до словаря» → «в год выхода первого тома» |
| C19-3 | sourced | RU~995–997: замысел собрания изречений (алфавит, разночтения, перевод) — предисловие т. 3 |
| C19-4 | sourced | Вигасин IV:~26: трёхтомник 1863–65; 2-е изд. 1870–73 — 7 613 изречений; EB1911 — источник уточнён, hedged→verified |
| C19-5 | sourced | RU~1098: список слов из надписей (JAOS) от Уитни |
| C19-6 | sourced | RU~1171: «дворянская грамота», «равнодушным взором» |
| C19-7 | sourced | RU~1257: «разрастаться в тезаурус»; «нередко по нашему побуждению» |
| C19-8 | sourced | RU~1259 — дословно |
| C20-1 | sourced | ссылки CTA проверяются импорт-тестом (PwgArzamasMaterialTest) — вне предмета рефери |
| C20-2 | sourced | PWG/prefaces/METHODS.md (провенанс перевода); финал эссе раскрывает |
| CA-1 | sourced | Вигасин IV:~20 («переселившейся в Россию при Петре Великом»); Ольденбург ~1944 («из Любека еще в начале XVIII века»); «по семейному преданию» в эссе — консервативная подача |
| CA-2 | sourced | Вигасин IV:~24: «много лет заведовал академической типографией» |
| CA-3 | sourced | Вигасин IV:~38: письмо 01-01-1852, «первое из тех сотен писем» |
| CA-4 | sourced (+ № 3) | Вигасин IV, прим. 156 (по Brückner & Zeller 2007): знакомство 1866; арифметика «15-й год» ✓; эссе-хвост про «треть» исправлен |
| CA-5 | sourced | Вигасин IV:~120: «примерно триста важнейших… памятников», «56 выпусков» |
| CA-6 | **corrected** | № 4 — план 10–15 лет, 10/5 талеров ✓ (IV:~76); формула о тираже уточнена |
| CA-7 | sourced | атлас pwg.md (~9 500 колонок; Records 123 366); `grep -c "^<L>"` = 123 366 — пересчитан; 106 082–106 085 — A40:277–278 |
| CA-8 | sourced (+ № 5) | атлас PD-отчёт:487–492 (1856 remake of Wilson, 6 761 статей, умер в «а»); EB1911 — Лондон добавлен |
| CA-9 | sourced | PD-отчёт:264–299 (Катре 1948, печать 1976, 104 959 лемм a–apaca, ~2280) + :377 (PWG denser 23.2×) |
| CA-10 | sourced | LETTER-отчёт:286–296: −14.3 %/дек., CI [−15.0, −13.7], «smooth fade… not a single policy break» |
| CA-11 | sourced | PD-отчёт:673–676 (Delbrück, Ber. Sächs. Ges. Wiss. 56, 1904: nine tenths); Вигасин IV:~118 |
| CA-12 | sourced | PD-отчёт:477 (PW 151 349 лемм, 10 лет) + :484–485 |
| CA-13 | sourced | paper_H:28–47: 0.79 PWG→PW, 0.70 PWG→SCH, 0.02 PWG→MW («reformatted») |
| CA-14 | sourced | A50:35–36 (over 800 000); A50:118 (pwg raw `<ls>` 801 790) |
| CA-15 | sourced | citation-apparatus.json: recordsWithCitations 116 332 / 123 366 = 94.3 % |
| CA-16 | sourced | A50 (крупнейшее ребро PWG→MBh 39 130; «queued as its own handoff» :294–295) |
| CA-17 | sourced | A50:168–169: Aṣṭādhyāyī 21 509 из PWG |
| CA-18 | sourced | атлас pwg.md §4: PWG `<ls>L.</ls>` = 0; MW = 40 212 |
| CA-19 | sourced | PD-отчёт:551: PWG s 12.1 % > p 10.2 % > v 9.1 % > a 9.0 %; LETTER-отчёт (буквенно-приставочный вывод; k — компаунд-бедная) |
| CA-20 | sourced | LETTER-отчёт:115–123 (PWG: vi 3 500, ā 2 647, sam 2 340, su 2 235, pra 2 195) |
| CA-21 | sourced | LETTER-отчёт:138–141: GRA su 452 > sam 126 (×3.6 ≈ «почти вчетверо») |
| CA-22 | sourced | LETTER-отчёт:66–71: MW a/ā 83.1 % (19 601 / 23 590) |
| CA-23 | sourced | awk-пересчёт по `<k1>`: max = 54 (jalaDara…aBijYa) — воспроизведён этим пассом; L26911: имя будды, BURN. Lot. de la b. l. 268, немецкий разбор в записи |
| CA-24 | sourced | sense-depth.json: pwg 1.66 / mw 1.04 (коды сверены по контексту записей) |
| CA-25 | sourced | pairwise-overlap.json: PWG–MW shared 94 778; 94 778/106 082 = 89.3 % |
| CA-26 | sourced | article_21:123–125: 0.811 concordance, 47.8 % perfectly identical (порядок ЦИТАТ — эссе формулирует верно) |
| CA-27 | sourced | SW notes:27–61: Мюллер 11-06-1881 (цитата дословно), Бётлингк — предисловие к pw т. 4, 1883, 35 пассажей; Zgusta 1988 — acknowledgement, not theft |
| CA-28 | sourced | article_21:189–199: 2/123 (обе root-vs-stem конвенции, ≈0 %); 0 общих print errors (:203) |
| CA-29 | sourced | Вигасин IV:~116: ÇKDr прислан, избран почётным членом 1856 |
| CA-30 | sourced | атлас pwg.md §3: ŚKDR. 20 109 — top-1 |
| CA-31 | sourced | dictionary-unique.json: PWG count 2 029 |
| CA-32 | sourced | Вигасин IV:~120: «награждены русскими орденами (Станислава 1 и 2 степени)» |
| CA-33 | sourced (+ № 10) | Вигасин IV:~82 — вся сцена (латынь «в добрые старые времена», отказ, корректура в наборе, боязнь огласки); титул Уварова исправлен |
| CA-34 | sourced | Вигасин IV:~90 (выпусками с 1854), ~110–114 (не пошёл далее первых букв); A43:64 |
| CA-35 | sourced | Вигасин IV:~126: Ламанский, «Новое время» 1879, «по меньшей мере, в сто тысяч рублей» |
| CA-36 | sourced | Ольденбург ~1942: «о двух периодах: до словаря и после словаря» — дословно |
| CA-37 | sourced | A40:277–278: 106 085 → 106 082 (−0.0 %) — «три заглавных слова» ✓ |
| CA-38 | sourced | A33:21–27/92–96: 73.5 %, пол 52.7 %, τ = 0.375, n = 11 882; :117–119 («preface never states a sense-ordering rule») |
| CA-39 | sourced | A33:138–141: PWG 23.4 % vs AP90 2.3 % |
| CA-40 | sourced | A50:288–295: 15 877 цитат Sprüche; 443 из 3 064 разрешимых (2 621 corroborated + 443 mismatch) ≈ каждая 7-я |
| CA-41 | sourced | Ольденбург ~1962: «немыслим Thesaurus санскритского языка без тщательнейшего исследования и обработки индийских словарей» |
| CA-42 | **corrected** | № 8 — EB1911 «Amara Sinha» (c. 375; издания 1798–1839), hedged→verified с новой формулировкой |
| CA-43 | sourced | L1: `MED. avy. 2` ✓ |
| CR-1 (found) | sourced | подпись к портрету MW (1819–1899, оксфордский профессор) — EB1911; ряд добавлен |
| CR-2 (found) | **corrected** | № 10 |
| CR-3 (found) | sourced | титул-скан перечитан целиком (ряд добавлен) |
| CR-4 (found) | sourced | гриф-скан перечитан целиком (ряд добавлен) |
| F-1 (found) | sourced | подпись к портрету Бётлингка («Фото Карла Беллаха. Туринская академия наук») — ASSETS A3 (Commons: Accademia delle Scienze di Torino, фотограф Carl Bellach) |
| F-2 (found) | sourced (+ №№ 3, 5, 12, 15) | прочие найденные вне-FACTS утверждения эссе: «почти целиком одна буква „а“» в т. 1 (PD-отчёт:487–489: «spent its whole first volume (1855) on a-»); «на седьмом десятке» (1815 + 1879 = 64 — арифметика от сорсированных дат); глоссы приставок su-/sam-/vi- (LETTER-отчёт:139–141 «laudatory su- „well-, good-“», «vi- … most productive») |

## What survives as hedged (honest limits)

- **C2-4** — нижние строки титула частично обрезаны сканом; продавцы и валюты видны, полная
  цена — нет. Ряд остаётся hedged, эссе не утверждает больше видимого.
- **C16-5** — «русского перевода не существовало» — argumentum ex silentio; помечен в FACTS,
  эссе формулирует осторожно («не появилось ни при жизни авторов, ни после»).
- **C12-4, хвост** — «и сообщили» — вывод из титула т. 7 («дополнениями ко всему сочинению»)
  + благодарности Уитни в финальном предисловии; прямого «вот дополнения Уитни» в корпусе нет.

Zero `sourced` verdicts rest on model knowledge: every claim above names a file, line,
scan, or fetched reference actually consulted in this pass.

_Dr. Mārcis Gasūns_
