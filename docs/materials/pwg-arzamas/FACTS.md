# FACTS — «Петербургский словарь: 20 глав» (claim → source)

_Created: 26-07-2026 · Last updated: 30-07-2026_

Обязательная таблица по [ARCHITECTURE §2.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_PWG_ARZAMAS_MATERIAL.md):
каждая фактическая фраза эссе — строка. `source_type`: `primary_preface` (предисловия PWG),
`primary_entry` (текст словаря), `primary_title` (титул/гриф по скану), `paper` (A36),
`org_stat` (посчитанное в проекте), `secondary` (внешняя научная/справочная литература).
`status`: `verified` | `hedged`.

Ключевые источники (полные ссылки, в таблице — короткие метки):

- **RU-пред.** — [pwgpref_all.ru.md](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/pwgpref_all.ru.md) (русский перевод предисловий, 2026; сверка — [pwgpref_all.de.md](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/pwgpref_all.de.md)); `~N` = примерная строка файла.
- **A36** — [A36_latin_obscena_note.md](https://github.com/gasyoun/SanskritLexicography/blob/master/papers/A36_latin_obscena_note.md) (готовая статья 5/5, числа из аннотации и §3).
- **pwg.mdx** — [csl-guides/docs/dictionaries/pwg.mdx](https://github.com/sanskrit-lexicon/csl-guides/blob/main/docs/dictionaries/pwg.mdx).
- **pwg.txt** — [csl-orig/v02/pwg](https://github.com/sanskrit-lexicon/csl-orig/tree/main/v02/pwg) (машиночитаемый текст словаря; `L…` = номер записи).
- **Титул-1855** — скан тома I, [archive.org bub_gb_Vn1WAAAAcAAJ](https://archive.org/details/bub_gb_Vn1WAAAAcAAJ), стр. n4 (титул) и n5 (гриф).
- **EB1911** — [Britannica 1911, «Böhtlingk»](https://en.wikisource.org/wiki/1911_Encyclop%C3%A6dia_Britannica/B%C3%B6htlingk,_Otto_von).
- **NIE** — [New International Encyclopædia, «Roth»](https://en.wikisource.org/wiki/The_New_International_Encyclop%C3%A6dia/Roth,_Rudolf_von).
- **CDSL** — [портал Кёльнских цифровых словарей](https://www.sanskrit-lexicon.uni-koeln.de/).

| claim_id | chapter | claim_text | source_type | source_ref | status |
|---|---|---|---|---|---|
| C0-1 | lead | Семь томов изданы Императорской Академией наук в 1855–1875 гг.; название Sanskrit-Wörterbuch; мировое имя «Большой Петербургский словарь» | primary_title + secondary | Титул-1855; pwg.mdx (код PWG, «Großes Petersburger Wörterbuch») | verified |
| C0-2 | lead | Авторы ни разу не работали за одним столом (жили на «слишком большом» расстоянии для встреч) | primary_preface | RU-пред. ~189 (т. 1; исправлено рефери 30-07-2026 — жалоба стоит в предисловии т. 1, не т. 2) | verified |
| C1-2 | 1 | Санскрит ко времени словаря — язык рукописной традиции, не уличной речи; материал добывался из рукописей и изданий | primary_preface | RU-пред. ~100 (перечень эксцерпированного), ~204/374 (пометы «Рукопись») | verified |
| C1-3 | 1 | «После труда, растянувшегося почти на двадцать пять лет, мы довели словарь до завершения» | primary_preface | RU-пред. ~1253 (т. 7) | verified |
| C1-4 | 1 | В 1864 г. словарём «пользуются сотни людей» | primary_preface | RU-пред. ~1094 (т. 4) | verified |
| C2-1 | 2 | Формулы титула: «herausgegeben von der Kaiserlichen Akademie…», «bearbeitet von Otto Böhtlingk und Rudolph Roth» | primary_title | Титул-1855 n4 | verified |
| C2-2 | 2 | Том I: «Erster Theil. (1852–1855). Die Vocale» — выпуски печатались с 1852 г. | primary_title | Титул-1855 n4 | verified |
| C2-3 | 2 | В санскритском алфавите все гласные предшествуют согласным | primary_title + secondary | Титул-1855 (т. 1 = Die Vocale; т. 2 начинается с क); [Кочергина, «Учебник санскрита» (1998)](https://github.com/gasyoun/SanskritGrammar/blob/main/KocherginaUchebnik_1998/Kochergina_unicode.mdx) — «Алфавит начинается с гласных…, затем следуют согласные» | verified |
| C2-4 | 2 | Распространители: Эггерс (СПб), Фосс (Лейпциг), Дюпра (Париж); цена в серебряных рублях и талерах | primary_title | Титул-1855 n4 (нижние строки; частично обрезаны сканом) | hedged |
| C3-1 | 3 | Бётлингк: род. в Петербурге 30 мая / 11 июня 1815; при Академии с 1842; ординарный академик 1855; Йена 1868; Лейпциг 1885; ум. 1904 | secondary | EB1911 | verified |
| C3-2 | 3 | Рот: род. 1821, Штутгарт; с 1848 экстраординарный, с 1856 ординарный профессор и главный библиотекарь в Тюбингене; ум. 1895 | secondary | NIE | verified |
| C3-3 | 3 | Расстояние Петербург—Тюбинген ≈ 2000 км («почти две тысячи») | org_stat | пересчёт рефери 30-07-2026: большая дуга СПб (59.93N 30.36E) — Тюбинген (48.52N 9.06E) ≈ 1 866 км | verified |
| C3-4 | 3 | Жалоба на невозможность встреч — предисловие т. 1 | primary_preface | RU-пред. ~189 (исправлено рефери 30-07-2026; эссе поправлено: «в первом же предисловии») | verified |
| C3-5 | 3 | Двойная подпись «Санкт-Петербург / Тюбинген, 14/26 октября 1858» — два календаря | primary_preface | RU-пред. ~1193–1194 | verified |
| C3-6 | 3 | Разделение труда: Рот — Веда, ведийская обрядовая литература, Сушрута, ботанические названия; Бётлингк — остальная литература и сведение материала | primary_preface | RU-пред. ~187 (т. 1) | verified |
| C3-7 | 3 | Финальная подпись — «Йена и Тюбинген, 4 августа 1875» | primary_preface | RU-пред. ~1269 (т. 7) | verified |
| C4-1 | 4 | Годы выхода томов: 1855, 1858, 1861, 1865, 1868, 1871, 1875 | primary_title | титулы томов в RU-пред. ~44, ~608, ~976, ~1073, ~1124, ~1211, ~1240 | verified |
| C4-2 | 4 | Оцифрованный текст словаря — 593 596 строк | paper | A36 (аннотация: «all 593,596 lines of PWG») | verified |
| C4-3 | 4 | Стартовый прогноз: «не достанет прилежания и целого десятилетия»; готовность передать преемникам | primary_preface | RU-пред. ~189 (т. 1) | verified |
| C4-4 | 4 | Баланс 1864 г.: ⅗ работы за ~12½ лет; на остаток ~8½ лет; «прожить которые… можем надеяться» | primary_preface | RU-пред. ~1090 (т. 4, предисловие датировано 17/29.11.1864) | verified |
| C4-5 | 4 | Обет «посвятить длинный ряд лет всецело словарю» | primary_preface | RU-пред. ~1090 (т. 4) | verified |
| C5-1 | 5 | Две опоры: индийские словесные собрания + собственные выписки | primary_preface | RU-пред. ~79 (т. 1) | verified |
| C5-2 | 5 | Особый перечень эксцерпированного приложен к предисловию («частью полностью, частью с выбором…») | primary_preface | RU-пред. ~100 | verified |
| C5-3 | 5 | Копия Тайттирия-самхиты доставлена через д-ра Рёра из Калькутты во время печатания | primary_preface | RU-пред. ~156 | verified |
| C5-4 | 5 | Тайттирия-самхита — редакция Яджурведы | primary_entry | MW L87012 (taittirīya-saṃhitā: «chief recension of the Black YV.»); ср. RU-пред. ~370 (Kāṭh. = «Kâṭhaka-редакция Jaǵ. V.») | verified |
| C5-5 | 5 | Уитни (Нью-Хейвен) — полный словоуказатель к Атхарваведе | primary_preface | RU-пред. ~158 | verified |
| C5-6 | 5 | Вебер (Берлин) — Шатапатха-брахмана и своды ритуальных сутр | primary_preface | RU-пред. ~160 | verified |
| C5-7 | 5 | Штенцлер — указатель к Ману, «все слова с указанием всех мест» | primary_preface | RU-пред. ~166 | verified |
| C5-8 | 5 | Шифнер (петербургский академик) — буддийский материал | primary_preface | RU-пред. ~168 | verified |
| C5-9 | 5 | «Махабхарата» подключалась к росписи по ходу печати (с 521-й стр. — книги 1–3, с 601-й — ещё 5, затем кн. 13) | primary_preface | RU-пред. ~424 (список источников т. 1) | verified |
| C5-10 | 5 | «Слабее всего представлена у нас философская литература…» — открытый призыв о помощи | primary_preface | RU-пред. ~170 | verified |
| C6-1 | 6 | До PWG европейские словари санскрита опирались на классическую литературу; Веда — целина; «совершенно новая область», «едва ли предполагавшееся богатство» | primary_preface | RU-пред. ~102 | verified |
| C6-2 | 6 | Метафора «истёртой или разбитой монеты» и «полной, неповреждённой чеканки» в Веде | primary_preface | RU-пред. ~102 | verified |
| C6-3 | 6 | Параллель: греческий лексикон, начинающийся с Геродота | primary_preface | RU-пред. ~106 | verified |
| C6-4 | 6 | Разгром Ланглуа: «мнимый перевод», «слаб в знании языка… потерял многие годы» | primary_preface | RU-пред. ~108–110 | verified |
| C6-5 | 6 | Комментаторы толкуют слова Веды набором «сила, жертва, пища, мудрость» | primary_preface | RU-пред. ~152 | verified |
| C6-6 | 6 | Против Саяны: европейский толкователь понимает Веду «гораздо вернее и лучше»; цель — «смысл, который сами поэты вложили» | primary_preface | RU-пред. ~131 | verified |
| C6-7 | 6 | «Эта часть словаря, будучи новейшею, первою же и устареет» | primary_preface | RU-пред. ~154 | verified |
| C6-8 | 6 | Гомеровская параллель: столетия трудов, словарный состав «не объяснён до конца» | primary_preface | RU-пред. ~154 | verified |
| C7-1 | 7 | Санскритский алфавит — фонетическая таблица (гласные, затем согласные по месту образования); принят словарём и стандартен для санскритской лексикографии | secondary | [Кочергина (1998)](https://github.com/gasyoun/SanskritGrammar/blob/main/KocherginaUchebnik_1998/Kochergina_unicode.mdx): «Порядок расположения графических знаков основан на акустических и артикуляционных признаках», таблица ka→ma; ср. pwg.mdx (SLP1-ключи, том «Die Vocale»). Формула «за тысячелетия до европейской фонетики» снята из эссе рефери 30-07-2026 | verified |
| C7-2 | 7 | Из глагольных корней «полностью изгнали» ṛ/ṝ/ḷ | primary_preface | RU-пред. ~183 | verified |
| C7-3 | 7 | Ударение — «простейшим, но не принятым у индийцев способом»; класс глагола не указывается | primary_preface | RU-пред. ~185 | verified |
| C7-4 | 7 | Ответ критикам (т. 5): сохранять старую запись корней — «проступок против европейской научности»; индийские грамматисты «стремились удовлетворить не теории, а практике» | primary_preface | RU-пред. ~1177 | verified |
| C8-1 | 8 | PWG глоссирует корень yabh латинским futuere без немецкого перевода | paper | A36 (аннотация) | verified |
| C8-2 | 8 | Термин «щит благопристойности» (discretion-screen) и механизм приёма | paper | A36 (заглавие, §1) | verified |
| C8-3 | 8 | 2104 латинских толкования в 11 словарях (1832–1959); 875 в петербургском ядре: 79 грубых + 796 клинических | paper | A36 (аннотация) | verified |
| C8-4 | 8 | Хронология приёма: у Уилсона (1832) отсутствует; MW: futuere 0 раз в 1872, 4 раза в 1899; оба Апте — без экрана, открытым английским | paper | A36 (аннотация) | verified |
| C8-5 | 8 | В PWG всего 7 отсылок к сравнительной грамматике; единственная этимологическая сноска «неприличного» слова — при yabh | paper | A36 (аннотация) | verified |
| C9-1 | 9 | PW («малая редакция») — Бётлингк, 7 томов, 1879–1889, без цитат, охват шире | secondary + paper | EB1911 («new ed. 7 vols., 1879–1889»); A36 (PW = «Kürzere Fassung»); pwg.mdx (PW = abridgement) | verified |
| C9-2 | 9 | Манифест жанров: полный словарь «выкладывает весь свой запас знаний…» (т. 5) | primary_preface | RU-пред. ~1150 | verified |
| C9-3 | 9 | MW и Апте строились на петербургском материале | secondary | pwg.mdx («Monier-Williams and Apte both built on it») | verified |
| C9-4 | 9 | Апте — 1890 (первое издание) | paper | A36 (AP90, Apte 1890) | verified |
| C9-5 | 9 | Шмидт, «Nachträge» — 1928 | paper | A36 (SCH, Schmidt 1928) | verified |
| C10-1 | 10 | Почти при каждом значении — ссылка на источник (устройство статьи) | primary_entry + secondary | pwg.txt (любая запись, напр. L1–L3); pwg.mdx («Reading an entry») | verified |
| C10-2 | 10 | «Взгляд в лежащие перед нами книги должен быть сильнее свидетельства глоссаторов» | primary_preface | RU-пред. ~81 | verified |
| C10-3 | 10 | «Ни одно слово и ни одно значение не внесли… без предварительной проверки» | primary_preface | RU-пред. ~181 | verified |
| C10-4 | 10 | В переведённой части — 50 065 вхождений ссылок; 83% разрешаются автоматически (69% скан + 14% текст); 446 цитируемых сочинений не оцифрованы (срез 02-07-2026, ~51 корень) | org_stat | pwg.mdx («Citation link coverage», snapshot 02-07-2026) | verified |
| C11-1 | 11 | Пометы ṚV., P., AK., ŚKDR., MBH. — имена текстов/авторов; ключ — перечень источников при предисловии | primary_preface + primary_entry | RU-пред. ~200 слл.; pwg.txt (записи с `<ls>`) | verified |
| C11-2 | 11 | У многих текстов в перечне помета «Рукопись» (напр., шраута-сутры Ашвалаяны) | primary_preface | RU-пред. ~204 | verified |
| C11-3 | 11 | Издание Атхарваведы Рота и Уитни, Берлин, 1855 — в перечне источников | primary_preface | RU-пред. ~229 | verified |
| C12-1 | 12 | Том 2 открывается многостраничными «Дополнениями и исправлениями» к тт. 1–2 | primary_preface | RU-пред. ~618–871 | verified |
| C12-2 | 12 | Том 5 — «вместе с дополнениями и исправлениями», прежние поправочные листы «отныне излишни» | primary_preface | RU-пред. ~1124, ~1138 | verified |
| C12-3 | 12 | Том 7 — «вместе с исправлениями и дополнениями ко всему сочинению» | primary_preface | RU-пред. ~1240 | verified |
| C12-4 | 12 | Опоздавшие дополнения Уитни «будут сообщены в конце труда» | primary_preface | RU-пред. ~1160 (сноска т. 5) | verified |
| C12-5 | 12 | Последняя запись семитомника — hevākin со ссылкой на поэму «Викрамаанкадевачарита» | primary_entry | pwg.txt L122732 (последняя запись файла; `<ls>VIKRAMĀṄKADEVACARITA 7,63</ls>`). Характеристика «кашмирская хроника» снята рефери 30-07-2026 — в записи её нет, атрибуция Бильхане в корпусе не подтверждена | verified |
| C12-6 | 12 | «Незавершённый словарь менее пригоден, чем любой другой не доведённый до конца труд» | primary_preface | RU-пред. ~1090 (т. 4) | verified |
| C12-7 | 12 | «Всякий заранее произведённый расчёт объёма… оказывается неверным» | primary_preface | RU-пред. ~993 (т. 3) | verified |
| C13-1 | 13 | MW: 1872, радикальная переработка 1899; стандартный словарь англоязычного мира | paper + secondary | A36 (MW 1872/1899 two-edition control); pwg.mdx (MW «for English glosses») | verified |
| C13-2 | 13 | MW называет источники шифром без развёрнутых цитат; PWG даёт цитаты с контекстом | primary_entry + secondary | pwg.txt (структура записей); pwg.mdx («depth of attestation and citation … unmatched») | verified |
| C13-3 | 13 | Оттенки значений в PWG — нумерованные рубрики `<div n>` | primary_entry | pwg.txt (напр., L2, L19252); pwg.mdx (таблица разметки) | verified |
| C14-1 | 14 | Коши — стихотворные словари, заучивались наизусть | secondary | [EB1911, «Amara Sinha»](https://en.wikisource.org/wiki/1911_Encyclop%C3%A6dia_Britannica/Amara_Sinha) («arranged, like other works of its class, in metre, to aid the memory»); [csl-guides skd.mdx](https://github.com/sanskrit-lexicon/csl-guides/blob/main/docs/dictionaries/skd.mdx) («older verse synonym-collections») | verified |
| C14-2 | 14 | «Повторять бесчисленные ошибки и искажения, которые предлагает нам индийская учёность» | primary_preface | RU-пред. ~79 | verified |
| C14-3 | 14 | Упрёк Уилсону: «предпочёл оставаться на точке зрения индийских учёных» | primary_preface | RU-пред. ~77 | verified |
| C14-4 | 14 | Мединикоша упорядочена «прежде всего по последнему согласному в слове» | primary_preface | RU-пред. ~425 (перечень источников т. 1) | verified |
| C14-5 | 14 | Похвала Радхаканте: труд «во многих отношениях служит к величайшей чести учёного индийца»; экземпляр подарен Академии | primary_preface | RU-пред. ~181 | verified |
| C14-6 | 14 | Письмо Радхаканты из Калькутты 17.05.1855: просьба высылать листы «с каждой почтой» для 2-го изд. Шабдакальпадрумы; Академия согласилась | primary_preface | RU-пред. ~193 (сноска т. 1) | verified |
| C15-1 | 15 | Первая запись словаря — междометие a («a apehi», выражает сострадание, со ссылкой на MED.) | primary_entry | pwg.txt L1 (`<pc>1-0001`) | verified |
| C15-2 | 15 | Третья запись — отрицательное a-/an-, сопоставленное с греч. ἀ/ἀν, лат. in-, нем. un- | primary_entry | pwg.txt L3 | verified |
| C15-3 | 15 | Примеры в статье: abrāhmaṇa «не-брахман» (упанишады), anaṅga «бестелесный» — эпитет бога любви | primary_entry | pwg.txt L3 (CHĀND. UP. 4,4,5; «gliederlos, der Liebesgott») | verified |
| C15-4 | 15 | Сожжение Камы взглядом Шивы — сюжет, стоящий за эпитетом anaṅga | primary_entry | MW L4817 (anaṅga: «N. of Kāma… made bodiless by a flash from the eye of Śiva») | verified |
| C15-5 | 15 | ŚKDR. толкует akeśa трояко: «безволосый», «с жидкими волосами», «не блещущий красотой волос» | primary_entry | pwg.txt L3 (концовка статьи) | verified |
| C15-6 | 15 | kokila: кукушка (осн. знач.), мышь, ядовитое насекомое, «уголь (по его черноте)», имя раджпута | primary_entry | pwg.txt L19252 (`<pc>2-0441`) | verified |
| C16-1 | 16 | Гриф: «Напечатано по распоряжению Императорской Академии наук», 13(25).12.1855, подпись Миддендорфа, непременного секретаря | primary_title | Титул-1855 n5 | verified |
| C16-2 | 16 | Академическая типография — в Петербурге | primary_title | Титул-1855 n4 («Buchdruckerei der Kaiserlichen Akademie der Wissenschaften», St. Petersburg). Уточнение «на Васильевском острове» снято из эссе рефери 30-07-2026 (не имело источника) | verified |
| C16-3 | 16 | Благодарность «покровительнице… Императорской Академии наук», исполнение её «поручения» | primary_preface | RU-пред. ~1265 (т. 7) | verified |
| C16-4 | 16 | ~~Немецкий — рабочий язык тогдашней Академии~~ — ВЫЧЕРКНУТО рефери 30-07-2026: обобщение не имело источника; формула удалена из эссе (осталось сорсируемое «писался по-немецки», Титул-1855) | — | — | struck |
| C16-5 | 16 | Русского перевода словаря не существовало до текущего проекта | org_stat | pwg.mdx (RU/EN сайт — первый систематический перевод); отсутствие изданий — argumentum ex silentio | hedged |
| C17-1 | 17 | «Мифы» опровергаются источниками предыдущих глав (перекрёстные ссылки на C2-1, C6-7, C9-3, C5-*) | — | внутренние ссылки эссе | verified |
| C18-1 | 18 | CDSL начат в 1994 г. Институтом индологии и тамилистики Кёльнского университета; 43 словаря 1832–1993 гг. | secondary | CDSL (страница проекта) | verified |
| C18-2 | 18 | PWG в CDSL: полный размеченный текст + связь со сканами; четыре интерфейса (Basic/List/Advanced/Mobile) | secondary | pwg.mdx (таблица «Open», «Data») | verified |
| C18-3 | 18 | Предисловия всех томов распознаны и переведены на RU/EN | org_stat | [PWG/prefaces](https://github.com/sanskrit-lexicon/PWG/tree/main/prefaces) (файлы `.md`/`.en.md`/`.ru.md` для 27 предисловий, METHODS.md) | verified |
| C18-4 | 18 | С 2026 г. идёт систематический перевод статей словаря на русский и английский с открытой публикацией | org_stat | [сайт переводов](https://gasyoun.github.io/SanskritLexicography/); pwg.mdx («~51 PWG roots translated so far») | verified |
| C19-1 | 19 | «Побуждает к дальнейшим исследованиям» (1864) | primary_preface | RU-пред. ~1094 | verified |
| C19-2 | 19 | Рот и Уитни издали Атхарваведу (Берлин, 1855); издание — источник словаря | primary_preface | RU-пред. ~229 | verified |
| C19-3 | 19 | Замысел «Indische Sprüche» описан в предисловии т. 3 (собрание изречений с разночтениями и переводом) | primary_preface | RU-пред. ~997 | verified |
| C19-4 | 19 | «Indische Sprüche» Бётлингка — трёхтомник, ~7600 афоризмов | secondary | Вигасин, IV:~26 («трехтомное собрание… в первом издании 5419 изречений, во втором издании (1870–73 гг.) — 7613»); EB1911 (2-е изд., 3 части, 1870–73) | verified |
| C19-5 | 19 | Уитни прислал список слов из санскритских надписей (JAOS) — эпиграфика вошла в словарь | primary_preface | RU-пред. ~1098 (т. 4) | verified |
| C19-6 | 19 | Ведийская аттестация — «дворянская грамота», на которую нельзя «смотреть равнодушным взором» | primary_preface | RU-пред. ~1171 (т. 5) | verified |
| C19-7 | 19 | «Мог всё более разрастаться в тезаурус»; литература издавалась «нередко по нашему побуждению» | primary_preface | RU-пред. ~1257 (т. 7) | verified |
| C19-8 | 19 | «Всё приходит после нас. Нам оставили нежеланную честь первенства вплоть до самого конца» | primary_preface | RU-пред. ~1259 (т. 7) | verified |
| C20-1 | 20 | Ссылки CTA: материалы школы, CDSL-портал, анатомия словарной статьи, PWG Basic | — | сами ссылки (работоспособность проверяется при импорте) | verified |
| C20-2 | 20 | Провенанс цитат: русский перевод предисловий 2026 г., с немецкого, при участии языковых моделей, выверка по изданию | org_stat | [METHODS.md](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/METHODS.md) | verified |

## Дополнение 26-07-2026 — данные атласа, Вигасина и статей (по указанию MG)

Дополнительные источники: **атлас** — [csl-atlas](https://github.com/gasyoun/csl-atlas) (файлы указаны построчно);
**Вигасин** — [«IV. Дело о санскритском словаре»](https://github.com/gasyoun/SanskritLexicography/tree/master/literature/md/Alexey_Vigasin) (полнотекст, права закрыты 22-07-2026);
**Ольденбург** — «Этюды о людях науки» (тот же каталог; некролог Бётлингка, ЖМНП 1904);
**A33** — [A33_sense_ordering_note.md](https://github.com/gasyoun/SanskritLexicography/blob/master/papers/A33_sense_ordering_note.md);
**A40** — [A40_headword_inventory_note.md](https://github.com/gasyoun/SanskritLexicography/blob/master/papers/A40_headword_inventory_note.md);
**A50** — [A50_ls_citation_frequency_graph.md](https://github.com/sanskrit-lexicon/csl-atlas/blob/main/docs/articles/A50_ls_citation_frequency_graph.md);
**SW** — [Stache-Weiske_Bö-MW.notes.md](https://github.com/gasyoun/SanskritLexicography/blob/master/papers/Stache-Weiske_B%C3%B6-MW.notes.md).

| claim_id | chapter | claim_text | source_type | source_ref | status |
|---|---|---|---|---|---|
| CA-1 | 3 | Семья Бётлингка — немецкая, переселилась в Россию «при Петре Великом» (предки из Любека) | secondary | Вигасин, IV:~20–24; Ольденбург (Этюды ~1944: предки из Любека) | hedged |
| CA-2 | 3 | Бётлингк много лет заведовал академической типографией | secondary | Вигасин, IV:~22 | verified |
| CA-3 | 3 | Переписка началась письмом Бётлингка Роту от 01-01-1852 — «первое из тех сотен писем» | secondary | Вигасин, IV:~38 | verified |
| CA-4 | 3 | Лично соавторы познакомились только в 1866 г., на 15-м году работы | secondary | Вигасин, IV, прим. 156 (по изданию переписки Brückner & Zeller, Wiesbaden 2007) | verified |
| CA-5 | 4 | 56 выпусков; основой послужили ~300 важнейших памятников | secondary | Вигасин, IV:~120 | verified |
| CA-6 | 4 | План 10–15 лет; гонорары: Роту 10 талеров/лист, Веберу 5; тираж вместо предполагавшихся 400–500 назначен в 1000 (из-за интереса, при запуске — не «поднят по ходу дела»; эссе уточнено рефери 30-07-2026) | secondary | Вигасин, IV:~76 | verified |
| CA-7 | 4 | ~9 500 колонок; 123 366 записей; 106 082–106 085 уникальных заглавных слов | org_stat | атлас `src/dicts/pwg.md:19–24`, `data/dictionary_inventory.csv`; пересчёт записей 26-07-2026: `Select-String '^<L>' pwg.txt` = 123 366 | verified |
| CA-8 | 4 | Гольдштюкер (лондонский профессор, UCL) умер, не выйдя из буквы «а» (6 761 статья); словарь с 1856 г. | org_stat + secondary | атлас `reports/PD_DCS_CORPUS_COVERAGE_2026.md:492–496`; [EB1911, «Goldstücker»](https://en.wikisource.org/wiki/1911_Encyclop%C3%A6dia_Britannica/Goldst%C3%BCcker,_Theodor) (профессор санскрита UCL с 1852, словарь с 1856, умер в Лондоне 1872) | verified |
| CA-9 | 4 | Пунский словарь (PD): задуман 1948 (Катре), печать с 1976; изданное (a–~apaca) ≈ 105 тыс. лемм ≈ весь PWG; в 23,2 раза подробнее; горизонт завершения ≈ 2280 г. | org_stat | атлас `reports/PD_DCS_CORPUS_COVERAGE_2026.md:264–299, 365–384` | verified |
| CA-10 | 4 | Статьи PWG «худеют» на −14,3 %/десятилетие (95 % ДИ −15,0…−13,7), плавно, без разрыва | org_stat | атлас `reports/LETTER_ANATOMY_AND_ENTRY_SIZE_2026.md:286–299` (H1423) | verified |
| CA-11 | 4 | Дельбрюк (некролог 1904): ~9/10 текста написал Бётлингк | secondary | атлас `reports/PD_DCS_CORPUS_COVERAGE_2026.md:673–679`; Вигасин, IV:~118 («девять десятых работы принадлежало неутомимому О. Бетлингу») | verified |
| CA-12 | 9 | PW («малый»): 151 349 заглавных слов за 10 лет против 106 тыс. большого за 20 | org_stat | атлас `reports/PD_DCS_CORPUS_COVERAGE_2026.md:477–484`; файл [PWK-unique-key1-151349.txt](https://github.com/gasyoun/SanskritLexicography/blob/master/HeadwordLists/now-2026/PWK-unique-key1-151349.txt) | verified |
| CA-13 | 9 | «Почерк»-наследование: PWG→PW 0,79; PWG→SCH 0,70; PWG→MW 0,02 (конвенции переформатированы) | org_stat | атлас `docs/articles/paper_H_convention_vs_content_lineage.md:28–47`, `docs/L0_RESULTS.md:25–38` | verified |
| CA-14 | 10 | Свыше 800 тыс. ссылок на источники (801 790 сырых `<ls>`-тегов) — крупнейший аппарат CDSL | paper | A50:35–36, 118 | verified |
| CA-15 | 10 | ≥1 ссылку несут 94,3 % записей (116 332 из 123 366) | org_stat | атлас `src/data/dicts/citation-apparatus.json` (2026-06-14) | verified |
| CA-16 | 10 | Крупнейшее ребро цитатного графа: PWG→Махабхарата, 39 130 цитат | paper | A50:293–295 | verified |
| CA-17 | 10 | Аштадхьяи (Панини) цитируется 21 509 раз | paper | A50:168–169 | verified |
| CA-18 | 10 | У PWG ноль глухих помет `L.`; у MW их 40 212 | org_stat | атлас `src/dicts/pwg.md:104–114` | verified |
| CA-19 | 11 | Буква s — крупнейшая (12,1 %); большие буквы возглавляют приставочные семьи (s=sam-/su-, v=vi-, p=pra-/pari-/prati-); k не возглавляет ни одной | org_stat | атлас `reports/LETTER_ANATOMY_AND_ENTRY_SIZE_2026.md:144–150, 551` (в PD-отчёте) | verified |
| CA-20 | 11 | Приставочные семьи PWG: vi- 3 500, ā- 2 647, sam- 2 340, su- 2 235, pra- 2 195 заглавных слов | org_stat | атлас `reports/LETTER_ANATOMY_AND_ENTRY_SIZE_2026.md:115–136`; `data/pd/upasarga_counts.tsv` | verified |
| CA-21 | 11 | У Грассмана (Ригведа) su- 452 > sam- 126 — обратно классическим словарям | org_stat | атлас `reports/LETTER_ANATOMY_AND_ENTRY_SIZE_2026.md:138–143` | verified |
| CA-22 | 11 | В MW на a/ā сложные слова = 83,1 % статей (19 601 из 23 590) | org_stat | атлас `reports/LETTER_ANATOMY_AND_ENTRY_SIZE_2026.md:64–79` | verified |
| CA-23 | 11 | Самое длинное заглавное слово PWG — jalaDaragarjita…aBijYa, 54 знака SLP1; имя будды, цитата из Бюрнуфа (Lotus de la bonne loi 268); немецкий разбор состава в самой статье | primary_entry + org_stat | pwg.txt `L26911` (`<pc>3-0058`); длина — пересчёт 26-07-2026 (скрипт по `<k1>`) | verified |
| CA-24 | 13 | Сенс-глубина: PWG 1,66 значения/статью, MW 1,04 | org_stat | атлас `src/data/dicts/sense-depth.json` (2026-06-14) | verified |
| CA-25 | 13 | MW содержит 89 % заглавных слов PWG (пересечение ~94,8 тыс.) | org_stat | атлас `src/data/dicts/pairwise-overlap.json`; `docs/articles/paper_three_axes_descent.md:122–126` | verified |
| CA-26 | 13 | Порядок цитат MW повторяет петербургский с конкордансом 0,811 (~8 из 10) | org_stat | атлас `docs/articles/article_21_apparatus_not_errors.md:117–179` | verified |
| CA-27 | 13 | Скандал: Мюллер (11-06-1881) — «die Reihenfolge der Bedeutungen einfach abgeschrieben»; Бётлингк в предисловии к pw (1883) — 35 пассажей; вердикт Згусты: непризнание, не кража | secondary | SW:~27–61 | verified |
| CA-28 | 13 | Из 123 курируемых опечаток PWG MW повторяет 2 (обе сомнительные, ≈0); общих типографских ошибок нет | org_stat | атлас `docs/articles/article_21_apparatus_not_errors.md:189–206` | verified |
| CA-29 | 14 | Радхаканта избран почётным членом Академии (1856) | secondary | Вигасин, IV:~116 | verified |
| CA-30 | 14 | Самый цитируемый источник PWG — Шабдакальпадрума: 20 109 ссылок | org_stat | атлас `src/dicts/pwg.md:83–100` | verified |
| CA-31 | 15 | 2 029 лемм PWG нет ни в одном сопоставимом словаре | org_stat | атлас `src/data/dicts/dictionary-unique.json` (2026-06-14) | verified |
| CA-32 | 16 | Сотрудникам жаловали ордена Св. Станислава | secondary | Вигасин, IV:~120 | verified |
| CA-33 | 16 | Уваров потребовал латынь «как в добрые старые времена»; Бётлингк отказался (латынью не владеют свободно) и победил — листы в наборе, огласки боялись | secondary | Вигасин, IV:~82 | verified |
| CA-34 | 16 | Коссович: санскрито-русский словарь с 1854 г., поддержка славянофилов, дальше первых букв не ушёл | secondary + paper | Вигасин, IV:~84–114; [A43](https://github.com/gasyoun/SanskritLexicography/blob/master/papers/A43_ru_dict_family.md):64 | verified |
| CA-35 | 16 | Фельетон (1879, «Новое время», Ламанский): словарь стоил Академии «по меньшей мере сто тысяч рублей» — упомянуто как тизер второй заметки | secondary | Вигасин, IV:~126–128 | verified |
| CA-36 | 16 | Ольденбург (некролог, 1904): «два периода: до словаря и после словаря» | secondary | Ольденбург, Этюды ~1942 | verified |
| CA-37 | 18 | Выгрузки 2014 и 2026 гг. расходятся на 3 заглавных слова из 106 тыс. (106 085 → 106 082, −0,0 %) | paper | A40:277–278, 492 | verified |
| CA-38 | 19 | В 73,5 % многозначных статей первым стоит древнейшее значение (случайный пол 52,7 %, τ=0,375, n=11 882); правило нигде не сформулировано — метод, а не декларация | paper | A33:23–27, 92–98, 117–119 | verified |
| CA-39 | 19 | Ведийские цитаты — 23,4 % аппарата PWG против 2,3 % у Апте | paper | A33:138–143 | verified |
| CA-40 | 19 | «Индийские изречения» цитируются 15 877 раз; сверка: ~каждая 7-я проверяемая цитата расходится (443 из 3 064 разрешимых) | paper + org_stat | A50:288–295; атлас `data/forensic/SPRUECHE_CITATION_VERIFICATION_CENSUS.md` (H611) | verified |
| CA-41 | 6 | Некролог 1904 г. (Ольденбург): полный тезаурус санскрита немыслим без «тщательнейшего исследования и обработки индийских словарей» | secondary | Ольденбург, Этюды ~1962 | verified |
| CA-42 | 14 | Амаракоша — самая знаменитая коша, сложена ок. IV в.; полтора тысячелетия спустя её всё ещё переиздавали от Рима до Калькутты | secondary | [EB1911, «Amara Sinha»](https://en.wikisource.org/wiki/1911_Encyclop%C3%A6dia_Britannica/Amara_Sinha) (fl. c. A.D. 375; издания Rome 1798, Serampore 1808, Calcutta 1831, Paris 1839). Формула «школьный словарь Индии» снята из эссе рефери 30-07-2026 (не имела источника) | verified |
| CA-43 | 15 | Первая статья «a» (междометие) ссылается на Мединикошу (`MED. avy. 2`) | primary_entry | pwg.txt L1 | verified |
| CR-1 | 13 | Подпись под портретом: Монье-Вильямс (1819–1899), оксфордский профессор санскрита | secondary | [EB1911, «Monier-Williams»](https://en.wikisource.org/wiki/1911_Encyclop%C3%A6dia_Britannica/Monier-Williams,_Sir_Monier) (род. 12-11-1819, ум. 11-04-1899; Boden chair с 1860). Добавлено рефери 30-07-2026 — подпись к A8 не имела строки в таблице | verified |
| CR-2 | 16 | Уваров потребовал латынь в качестве ПРЕЗИДЕНТА Академии (минпрос он оставил в 1849; конфликт — декабрь 1852) | secondary | Вигасин, IV:~70 (смена министра 1849), ~82 («Президент Академии С. С. Уваров вмешался в дело»). Эссе исправлено рефери 30-07-2026: «Министр просвещения» → «Президент Академии» | verified |
| CR-3 | 2 | Титул: «Erster Theil. (1852–1855). Die Vocale»; продавцы Eggers (СПб) / Voss (Лейпциг) / Duprat (Париж); цена серебром и в талерах; типография Академии | primary_title | скан `pwg-title-1855.jpg` перечитан рефери 30-07-2026 — все элементы видны (низ листа частично обрезан) | verified |
| CR-4 | 16 | Гриф: «Gedruckt auf Verfügung der Kaiserlichen Akademie der Wissenschaften. Den 13. (25.) December 1855. A. Th. v. Middendorff, beständiger Secretär» | primary_title | скан `pwg-imprimatur-1855.jpg` перечитан рефери 30-07-2026 — дословно | verified |

## Правила, применённые при вычитке

1. Все цитаты предисловий — из AI-перевода 2026 г. ([METHODS.md](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/METHODS.md)); провенанс раскрыт читателю в финале эссе (C20-2). Перед публикацией выборочно сверены с [немецким оригиналом](https://github.com/sanskrit-lexicon/PWG/blob/main/prefaces/pwgpref_all.de.md): метафора монеты («einer abgenutzten oder zerschlagenen Münze gleich») и пассаж о Ланглуа подтверждены сканом стр. IV предисловия т. 1 (файл `pwg-vorwort-p4.jpg` в паке — обе формулы видны на скане).
2. Диапазон букв тома 2 в эссе не упоминается: в OCR титула тома 2 вероятна ошибка (`क—ङ` против «до ट» в предисловии).
3. Утверждение «не могли встречаться» дано в форме пересказа жалобы предисловия, не как биографический факт обо всех 25 годах.
4. Числа A36 и статистика ссылок взяты только из закоммиченных текстов (A36, pwg.mdx), без пересчёта.

_Dr. Mārcis Gasūns_
