# /slovar CDSL link-out wave 1 — измеренная разрешимость ключей и живая сверка (H3762)

_Created: 31-08-2026 · Last updated: 31-08-2026_

Доказательная база приёмки [H3762](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3762-Fable_Systema-Sanscriticum_slovar-cdsl-linkout-wave1_30.08.26.md): блок «В словарях CDSL» на страницах `/slovar/{slug}` строит серверные ссылки формы `indexcaller.php?key={slp1}&transLit=slp1&filter=roman` ([app/Support/CdslLinks.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CdslLinks.php)); SLP1-ключ — [app/Support/IastToSlp1.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/IastToSlp1.php), PHP-порт канонической таблицы `sanskrit-util` `_SLP1`.

## Разрешимость ключа (все боевые заголовки, 31-08-2026)

Измерено по полному экспорту колонки `iast` (11 726 строк со слагом, `whereNotNull('slug')`):

| Проход | Эвристики | Разрешимо | Доля |
|---|---|---|---|
| 1 (сырая конверсия) | NFC + lowercase + `_SLP1` | 10 110 | 86,22 % |
| 2 (принято) | + снятие обёртки `/слово/` · первый вариант списка («duṣyanta, duḥṣanta») · бесдефисная основа («-akṣa», «sam-», «deva-datta») | 11 656 | **99,40 %** |

Цель ≥95 % достигнута со второй попытки (лимит — 3). Остаток (70 строк) — грязь источника: аваграха-стяжения (`/so'ham/` — ключ не выпускается сознательно: `getword.php` на аваграхе отдаёт 500, проба 30-08), ведийские акценты (`/ākampitá/`), фразовые статьи, и **кириллические омоглифы внутри «IAST»** (`/ргаdā/`, `/dorака/`, `/drumа/` — заведён integrity-тикет).

## Живая сверка 10 заголовков (getword.php, 31-08-2026, последовательно — хост 429-ит серии)

| # | Заголовок (боевой `iast`) | SLP1-ключ | Словарь | Итог |
|---|---|---|---|---|
| 1 | `/duḥkha/` (висарга) | `duHKa` | MW | ✔ «duḥkha mfn. …» |
| 2 | `/saṃsāra/` (анусвара) | `saMsAra` | MW | ✔ «going or wandering through…» |
| 3 | `/yoga/` | `yoga` | PWG | ✔ «yoga (von 1. yuj)…» |
| 4 | `/dharma/` | `Darma` | AP90 | ✖ not found → см. вывод про AP90 |
| 5 | `/kṛṣṇa/` | `kfzRa` | MW | ✔ «kṛṣṇa mf(ā)n. black…» |
| 6 | `/agni/` | `agni` / `agniH` | AP90 | ✖ по основе; **✔ по номинативу `agniH`** |
| 7 | `/-akṣa/` (связ. форма) | `akza` | MW | ✔ «akṣa m. an axle…» |
| 8 | `/veda/` | `veda` | MW | ✔ «1. veda m. …» |
| 9 | `/guru/` | `guru` | PWG | ✔ «guru [2-0767]…» |
| 10 | `/aśva/` | `aSva` | MW | ✔ «aśva m. … a horse» |

Аваграха-случай приёмки: `/adho'dhas/` — блок не рендерится вовсе (ключ не выпускается), проверено тестом `test_headword_cleanup_handles_real_slovar_formatting`.

## Вывод: AP90 не в волне 1

Ключи AP90 — **печатные заголовки в номинативе** (`agniH`, `yogaH` — живые пробы №4/№6 и контрольная `yogaH` ✔), а не основы. Ссылка по основе садится на «not found» у большинства существительных; вывод номинатива требует рода/класса основы, которых в `/slovar` нет. По правилу приёмки («fix or drop, never ship known-dead links») AP90 исключён; MW и PWG — ключи по основе, 10/10 живых проб. Это же **исправляет находку 30-08** «AP90 не имеет yoga/karma/veda/dharma как заглавных статей» — имеет, под номинативными ключами (поправка внесена в SHARED_CODE row 22).

_Dr. Mārcis Gasūns_
