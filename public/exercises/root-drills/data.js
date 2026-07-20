/* ============================================================
   Root-frequency data for the root drill (H1356).

   Source: database/seeders/data/roots_frequency_ru.tsv (H1280) --
   kosha roots_frequency.json (DCS token frequency, H950) joined
   against WhitneyRoots ru_root_glosses.tsv (RU glosses, H347) by
   database/seeders/data/build_roots_frequency_ru.py. Same fixture
   already ships as the D4 SRS deck (H1280, SrsRootFrequencyDeckSeeder).

   Bands are CUMULATIVE frequency-rank slices (top25 subset-of top50
   subset-of top100), rank 1 = most frequent root by DCS token count.
   coverage_pct = cumulative % of all root-tagged tokens covered up
   to and including this rank.

   Regenerate: python public/exercises/root-drills/generate.py
   (after refreshing database/seeders/data/roots_frequency_ru.tsv)
   ============================================================ */
(function (global) {
  "use strict";
  global.ROOT_BANDS = {
  "top25": [
    {
      "rank": 1,
      "devanagari": "कृ",
      "iast": "1 kṛ / 2 kṛ / 3 kṛ",
      "gloss_ru": "сделал",
      "top_form": "kṛtvā",
      "coverage_pct": 7.4241
    },
    {
      "rank": 2,
      "devanagari": "भू",
      "iast": "bhū",
      "gloss_ru": "будет",
      "top_form": "bhavati",
      "coverage_pct": 14.7743
    },
    {
      "rank": 3,
      "devanagari": "अस्",
      "iast": "1 as / 2 as",
      "gloss_ru": "есть",
      "top_form": "syāt",
      "coverage_pct": 21.2802
    },
    {
      "rank": 4,
      "devanagari": "वच्",
      "iast": "vac",
      "gloss_ru": "сказал",
      "top_form": "uvāca",
      "coverage_pct": 27.4576
    },
    {
      "rank": 5,
      "devanagari": "गम्",
      "iast": "gach / gam",
      "gloss_ru": "пришел",
      "top_form": "gatvā",
      "coverage_pct": 30.7951
    },
    {
      "rank": 6,
      "devanagari": "दृश्",
      "iast": "dṛś",
      "gloss_ru": "увидев",
      "top_form": "dṛṣṭvā",
      "coverage_pct": 33.5002
    },
    {
      "rank": 7,
      "devanagari": "दा",
      "iast": "1 dā / 2 dā / 3 dā / 4 dā",
      "gloss_ru": "взяв",
      "top_form": "dattvā",
      "coverage_pct": 35.6853
    },
    {
      "rank": 8,
      "devanagari": "विद्",
      "iast": "1 vid / 2 vid",
      "gloss_ru": "знает",
      "top_form": "veda",
      "coverage_pct": 37.744
    },
    {
      "rank": 9,
      "devanagari": "श्रु",
      "iast": "śru",
      "gloss_ru": "услышав",
      "top_form": "śrutvā",
      "coverage_pct": 39.6556
    },
    {
      "rank": 10,
      "devanagari": "ब्रू",
      "iast": "brū",
      "gloss_ru": "сказал",
      "top_form": "abravīt",
      "coverage_pct": 41.4354
    },
    {
      "rank": 11,
      "devanagari": "अह्",
      "iast": "ah",
      "gloss_ru": "сказал",
      "top_form": "āha",
      "coverage_pct": 43.1545
    },
    {
      "rank": 12,
      "devanagari": "स्था",
      "iast": "sthā",
      "gloss_ru": "пребывает",
      "top_form": "sthitaḥ",
      "coverage_pct": 44.841
    },
    {
      "rank": 13,
      "devanagari": "जन्",
      "iast": "jan",
      "gloss_ru": "родился",
      "top_form": "jāyate",
      "coverage_pct": 46.5236
    },
    {
      "rank": 14,
      "devanagari": "हन्",
      "iast": "han",
      "gloss_ru": "убит",
      "top_form": "hanti",
      "coverage_pct": 48.1147
    },
    {
      "rank": 15,
      "devanagari": "यज्",
      "iast": "yaj",
      "gloss_ru": "жертвователя",
      "top_form": "yajamānaḥ",
      "coverage_pct": 49.3439
    },
    {
      "rank": 16,
      "devanagari": "हु",
      "iast": "hu",
      "gloss_ru": "жертвуют",
      "top_form": "juhoti",
      "coverage_pct": 50.5118
    },
    {
      "rank": 17,
      "devanagari": "युज्",
      "iast": "yuj",
      "gloss_ru": "союзником",
      "top_form": "yuktam",
      "coverage_pct": 51.5914
    },
    {
      "rank": 18,
      "devanagari": "या",
      "iast": "yā",
      "gloss_ru": "идут",
      "top_form": "yāti",
      "coverage_pct": 52.6193
    },
    {
      "rank": 19,
      "devanagari": "पा",
      "iast": "1 pā / 2 pā / 3 pā",
      "gloss_ru": "Защищайте",
      "top_form": "pibet",
      "coverage_pct": 53.6007
    },
    {
      "rank": 20,
      "devanagari": "ज्ञा",
      "iast": "jñā",
      "gloss_ru": "зная",
      "top_form": "jñātvā",
      "coverage_pct": 54.518
    },
    {
      "rank": 21,
      "devanagari": "इष्",
      "iast": "1 iṣ / 2 iṣ / ich",
      "gloss_ru": "хочу",
      "top_form": "icchāmi",
      "coverage_pct": 55.3991
    },
    {
      "rank": 22,
      "devanagari": "पश्",
      "iast": "1 paś / 2 paś",
      "gloss_ru": "вижу",
      "top_form": "paśya",
      "coverage_pct": 56.2601
    },
    {
      "rank": 23,
      "devanagari": "मन्",
      "iast": "man",
      "gloss_ru": "считаю",
      "top_form": "manye",
      "coverage_pct": 57.1148
    },
    {
      "rank": 24,
      "devanagari": "धा",
      "iast": "1 dhā / 2 dhā",
      "gloss_ru": "вера",
      "top_form": "dadhāti",
      "coverage_pct": 57.969
    },
    {
      "rank": 25,
      "devanagari": "स्मृ",
      "iast": "smṛ",
      "gloss_ru": "считается",
      "top_form": "smṛtaḥ",
      "coverage_pct": 58.7227
    }
  ],
  "top50": [
    {
      "rank": 1,
      "devanagari": "कृ",
      "iast": "1 kṛ / 2 kṛ / 3 kṛ",
      "gloss_ru": "сделал",
      "top_form": "kṛtvā",
      "coverage_pct": 7.4241
    },
    {
      "rank": 2,
      "devanagari": "भू",
      "iast": "bhū",
      "gloss_ru": "будет",
      "top_form": "bhavati",
      "coverage_pct": 14.7743
    },
    {
      "rank": 3,
      "devanagari": "अस्",
      "iast": "1 as / 2 as",
      "gloss_ru": "есть",
      "top_form": "syāt",
      "coverage_pct": 21.2802
    },
    {
      "rank": 4,
      "devanagari": "वच्",
      "iast": "vac",
      "gloss_ru": "сказал",
      "top_form": "uvāca",
      "coverage_pct": 27.4576
    },
    {
      "rank": 5,
      "devanagari": "गम्",
      "iast": "gach / gam",
      "gloss_ru": "пришел",
      "top_form": "gatvā",
      "coverage_pct": 30.7951
    },
    {
      "rank": 6,
      "devanagari": "दृश्",
      "iast": "dṛś",
      "gloss_ru": "увидев",
      "top_form": "dṛṣṭvā",
      "coverage_pct": 33.5002
    },
    {
      "rank": 7,
      "devanagari": "दा",
      "iast": "1 dā / 2 dā / 3 dā / 4 dā",
      "gloss_ru": "взяв",
      "top_form": "dattvā",
      "coverage_pct": 35.6853
    },
    {
      "rank": 8,
      "devanagari": "विद्",
      "iast": "1 vid / 2 vid",
      "gloss_ru": "знает",
      "top_form": "veda",
      "coverage_pct": 37.744
    },
    {
      "rank": 9,
      "devanagari": "श्रु",
      "iast": "śru",
      "gloss_ru": "услышав",
      "top_form": "śrutvā",
      "coverage_pct": 39.6556
    },
    {
      "rank": 10,
      "devanagari": "ब्रू",
      "iast": "brū",
      "gloss_ru": "сказал",
      "top_form": "abravīt",
      "coverage_pct": 41.4354
    },
    {
      "rank": 11,
      "devanagari": "अह्",
      "iast": "ah",
      "gloss_ru": "сказал",
      "top_form": "āha",
      "coverage_pct": 43.1545
    },
    {
      "rank": 12,
      "devanagari": "स्था",
      "iast": "sthā",
      "gloss_ru": "пребывает",
      "top_form": "sthitaḥ",
      "coverage_pct": 44.841
    },
    {
      "rank": 13,
      "devanagari": "जन्",
      "iast": "jan",
      "gloss_ru": "родился",
      "top_form": "jāyate",
      "coverage_pct": 46.5236
    },
    {
      "rank": 14,
      "devanagari": "हन्",
      "iast": "han",
      "gloss_ru": "убит",
      "top_form": "hanti",
      "coverage_pct": 48.1147
    },
    {
      "rank": 15,
      "devanagari": "यज्",
      "iast": "yaj",
      "gloss_ru": "жертвователя",
      "top_form": "yajamānaḥ",
      "coverage_pct": 49.3439
    },
    {
      "rank": 16,
      "devanagari": "हु",
      "iast": "hu",
      "gloss_ru": "жертвуют",
      "top_form": "juhoti",
      "coverage_pct": 50.5118
    },
    {
      "rank": 17,
      "devanagari": "युज्",
      "iast": "yuj",
      "gloss_ru": "союзником",
      "top_form": "yuktam",
      "coverage_pct": 51.5914
    },
    {
      "rank": 18,
      "devanagari": "या",
      "iast": "yā",
      "gloss_ru": "идут",
      "top_form": "yāti",
      "coverage_pct": 52.6193
    },
    {
      "rank": 19,
      "devanagari": "पा",
      "iast": "1 pā / 2 pā / 3 pā",
      "gloss_ru": "Защищайте",
      "top_form": "pibet",
      "coverage_pct": 53.6007
    },
    {
      "rank": 20,
      "devanagari": "ज्ञा",
      "iast": "jñā",
      "gloss_ru": "зная",
      "top_form": "jñātvā",
      "coverage_pct": 54.518
    },
    {
      "rank": 21,
      "devanagari": "इष्",
      "iast": "1 iṣ / 2 iṣ / ich",
      "gloss_ru": "хочу",
      "top_form": "icchāmi",
      "coverage_pct": 55.3991
    },
    {
      "rank": 22,
      "devanagari": "पश्",
      "iast": "1 paś / 2 paś",
      "gloss_ru": "вижу",
      "top_form": "paśya",
      "coverage_pct": 56.2601
    },
    {
      "rank": 23,
      "devanagari": "मन्",
      "iast": "man",
      "gloss_ru": "считаю",
      "top_form": "manye",
      "coverage_pct": 57.1148
    },
    {
      "rank": 24,
      "devanagari": "धा",
      "iast": "1 dhā / 2 dhā",
      "gloss_ru": "вера",
      "top_form": "dadhāti",
      "coverage_pct": 57.969
    },
    {
      "rank": 25,
      "devanagari": "स्मृ",
      "iast": "smṛ",
      "gloss_ru": "считается",
      "top_form": "smṛtaḥ",
      "coverage_pct": 58.7227
    },
    {
      "rank": 26,
      "devanagari": "जि",
      "iast": "1 ji / 2 ji",
      "gloss_ru": "Санджая",
      "top_form": "jita",
      "coverage_pct": 59.4655
    },
    {
      "rank": 27,
      "devanagari": "चर्",
      "iast": "car",
      "gloss_ru": "движется",
      "top_form": "carati",
      "coverage_pct": 60.1983
    },
    {
      "rank": 28,
      "devanagari": "वद्",
      "iast": "vad",
      "gloss_ru": "говорят",
      "top_form": "vadanti",
      "coverage_pct": 60.9105
    },
    {
      "rank": 29,
      "devanagari": "मुच्",
      "iast": "muc",
      "gloss_ru": "освобождается",
      "top_form": "mucyate",
      "coverage_pct": 61.6111
    },
    {
      "rank": 30,
      "devanagari": "इ",
      "iast": "1 i / 2 i",
      "gloss_ru": "идет",
      "top_form": "eti",
      "coverage_pct": 62.2794
    },
    {
      "rank": 31,
      "devanagari": "लभ्",
      "iast": "labh",
      "gloss_ru": "обретает",
      "top_form": "labhate",
      "coverage_pct": 62.8881
    },
    {
      "rank": 32,
      "devanagari": "पत्",
      "iast": "1 pat / 2 pat",
      "gloss_ru": "упал",
      "top_form": "papāta",
      "coverage_pct": 63.4564
    },
    {
      "rank": 34,
      "devanagari": "हृ",
      "iast": "1 hṛ / 2 hṛ / har",
      "gloss_ru": "похитил",
      "top_form": "harati",
      "coverage_pct": 64.4832
    },
    {
      "rank": 35,
      "devanagari": "सृज्",
      "iast": "sṛj",
      "gloss_ru": "выпустил",
      "top_form": "asṛjata",
      "coverage_pct": 64.9835
    },
    {
      "rank": 36,
      "devanagari": "हा",
      "iast": "1 hā / 2 hā",
      "gloss_ru": "оставив",
      "top_form": "hīna",
      "coverage_pct": 65.4819
    },
    {
      "rank": 37,
      "devanagari": "भुज्",
      "iast": "1 bhuj / 2 bhuj",
      "gloss_ru": "наслаждайся",
      "top_form": "bhuktvā",
      "coverage_pct": 65.9777
    },
    {
      "rank": 38,
      "devanagari": "बन्ध्",
      "iast": "bandh",
      "gloss_ru": "связывается",
      "top_form": "baddhvā",
      "coverage_pct": 66.4607
    },
    {
      "rank": 39,
      "devanagari": "मृ",
      "iast": "1 mṛ / 2 mṛ",
      "gloss_ru": "умирает",
      "top_form": "mṛta",
      "coverage_pct": 66.9365
    },
    {
      "rank": 40,
      "devanagari": "वस्",
      "iast": "1 vas / 2 vas / 3 vas",
      "gloss_ru": "жил",
      "top_form": "vaset",
      "coverage_pct": 67.3984
    },
    {
      "rank": 41,
      "devanagari": "वृत्",
      "iast": "vṛt",
      "gloss_ru": "возвращаются",
      "top_form": "vartate",
      "coverage_pct": 67.8495
    },
    {
      "rank": 42,
      "devanagari": "क्षिप्",
      "iast": "kṣip",
      "gloss_ru": "метнул",
      "top_form": "kṣipet",
      "coverage_pct": 68.2973
    },
    {
      "rank": 43,
      "devanagari": "त्यज्",
      "iast": "tyaj",
      "gloss_ru": "оставив",
      "top_form": "tyaktvā",
      "coverage_pct": 68.7371
    },
    {
      "rank": 44,
      "devanagari": "शुध्",
      "iast": "śudh",
      "gloss_ru": "очищается",
      "top_form": "śuddha",
      "coverage_pct": 69.1756
    },
    {
      "rank": 45,
      "devanagari": "अर्ह्",
      "iast": "arh",
      "gloss_ru": "должен",
      "top_form": "arhasi",
      "coverage_pct": 69.6087
    },
    {
      "rank": 46,
      "devanagari": "नी",
      "iast": "nī",
      "gloss_ru": "приведи",
      "top_form": "nayet",
      "coverage_pct": 70.0363
    },
    {
      "rank": 47,
      "devanagari": "आप्",
      "iast": "āp",
      "gloss_ru": "достигает",
      "top_form": "āpnoti",
      "coverage_pct": 70.4574
    },
    {
      "rank": 48,
      "devanagari": "दह्",
      "iast": "dah",
      "gloss_ru": "сжигает",
      "top_form": "dagdha",
      "coverage_pct": 70.8714
    },
    {
      "rank": 49,
      "devanagari": "जीव्",
      "iast": "jīv",
      "gloss_ru": "живет",
      "top_form": "jīvati",
      "coverage_pct": 71.2757
    },
    {
      "rank": 50,
      "devanagari": "स्तु",
      "iast": "1 stu / 2 stu",
      "gloss_ru": "восхвалять",
      "top_form": "stuvanti",
      "coverage_pct": 71.6768
    },
    {
      "rank": 51,
      "devanagari": "पू",
      "iast": "pū",
      "gloss_ru": "Павамана",
      "top_form": "pavasva",
      "coverage_pct": 72.0702
    }
  ],
  "top100": [
    {
      "rank": 1,
      "devanagari": "कृ",
      "iast": "1 kṛ / 2 kṛ / 3 kṛ",
      "gloss_ru": "сделал",
      "top_form": "kṛtvā",
      "coverage_pct": 7.4241
    },
    {
      "rank": 2,
      "devanagari": "भू",
      "iast": "bhū",
      "gloss_ru": "будет",
      "top_form": "bhavati",
      "coverage_pct": 14.7743
    },
    {
      "rank": 3,
      "devanagari": "अस्",
      "iast": "1 as / 2 as",
      "gloss_ru": "есть",
      "top_form": "syāt",
      "coverage_pct": 21.2802
    },
    {
      "rank": 4,
      "devanagari": "वच्",
      "iast": "vac",
      "gloss_ru": "сказал",
      "top_form": "uvāca",
      "coverage_pct": 27.4576
    },
    {
      "rank": 5,
      "devanagari": "गम्",
      "iast": "gach / gam",
      "gloss_ru": "пришел",
      "top_form": "gatvā",
      "coverage_pct": 30.7951
    },
    {
      "rank": 6,
      "devanagari": "दृश्",
      "iast": "dṛś",
      "gloss_ru": "увидев",
      "top_form": "dṛṣṭvā",
      "coverage_pct": 33.5002
    },
    {
      "rank": 7,
      "devanagari": "दा",
      "iast": "1 dā / 2 dā / 3 dā / 4 dā",
      "gloss_ru": "взяв",
      "top_form": "dattvā",
      "coverage_pct": 35.6853
    },
    {
      "rank": 8,
      "devanagari": "विद्",
      "iast": "1 vid / 2 vid",
      "gloss_ru": "знает",
      "top_form": "veda",
      "coverage_pct": 37.744
    },
    {
      "rank": 9,
      "devanagari": "श्रु",
      "iast": "śru",
      "gloss_ru": "услышав",
      "top_form": "śrutvā",
      "coverage_pct": 39.6556
    },
    {
      "rank": 10,
      "devanagari": "ब्रू",
      "iast": "brū",
      "gloss_ru": "сказал",
      "top_form": "abravīt",
      "coverage_pct": 41.4354
    },
    {
      "rank": 11,
      "devanagari": "अह्",
      "iast": "ah",
      "gloss_ru": "сказал",
      "top_form": "āha",
      "coverage_pct": 43.1545
    },
    {
      "rank": 12,
      "devanagari": "स्था",
      "iast": "sthā",
      "gloss_ru": "пребывает",
      "top_form": "sthitaḥ",
      "coverage_pct": 44.841
    },
    {
      "rank": 13,
      "devanagari": "जन्",
      "iast": "jan",
      "gloss_ru": "родился",
      "top_form": "jāyate",
      "coverage_pct": 46.5236
    },
    {
      "rank": 14,
      "devanagari": "हन्",
      "iast": "han",
      "gloss_ru": "убит",
      "top_form": "hanti",
      "coverage_pct": 48.1147
    },
    {
      "rank": 15,
      "devanagari": "यज्",
      "iast": "yaj",
      "gloss_ru": "жертвователя",
      "top_form": "yajamānaḥ",
      "coverage_pct": 49.3439
    },
    {
      "rank": 16,
      "devanagari": "हु",
      "iast": "hu",
      "gloss_ru": "жертвуют",
      "top_form": "juhoti",
      "coverage_pct": 50.5118
    },
    {
      "rank": 17,
      "devanagari": "युज्",
      "iast": "yuj",
      "gloss_ru": "союзником",
      "top_form": "yuktam",
      "coverage_pct": 51.5914
    },
    {
      "rank": 18,
      "devanagari": "या",
      "iast": "yā",
      "gloss_ru": "идут",
      "top_form": "yāti",
      "coverage_pct": 52.6193
    },
    {
      "rank": 19,
      "devanagari": "पा",
      "iast": "1 pā / 2 pā / 3 pā",
      "gloss_ru": "Защищайте",
      "top_form": "pibet",
      "coverage_pct": 53.6007
    },
    {
      "rank": 20,
      "devanagari": "ज्ञा",
      "iast": "jñā",
      "gloss_ru": "зная",
      "top_form": "jñātvā",
      "coverage_pct": 54.518
    },
    {
      "rank": 21,
      "devanagari": "इष्",
      "iast": "1 iṣ / 2 iṣ / ich",
      "gloss_ru": "хочу",
      "top_form": "icchāmi",
      "coverage_pct": 55.3991
    },
    {
      "rank": 22,
      "devanagari": "पश्",
      "iast": "1 paś / 2 paś",
      "gloss_ru": "вижу",
      "top_form": "paśya",
      "coverage_pct": 56.2601
    },
    {
      "rank": 23,
      "devanagari": "मन्",
      "iast": "man",
      "gloss_ru": "считаю",
      "top_form": "manye",
      "coverage_pct": 57.1148
    },
    {
      "rank": 24,
      "devanagari": "धा",
      "iast": "1 dhā / 2 dhā",
      "gloss_ru": "вера",
      "top_form": "dadhāti",
      "coverage_pct": 57.969
    },
    {
      "rank": 25,
      "devanagari": "स्मृ",
      "iast": "smṛ",
      "gloss_ru": "считается",
      "top_form": "smṛtaḥ",
      "coverage_pct": 58.7227
    },
    {
      "rank": 26,
      "devanagari": "जि",
      "iast": "1 ji / 2 ji",
      "gloss_ru": "Санджая",
      "top_form": "jita",
      "coverage_pct": 59.4655
    },
    {
      "rank": 27,
      "devanagari": "चर्",
      "iast": "car",
      "gloss_ru": "движется",
      "top_form": "carati",
      "coverage_pct": 60.1983
    },
    {
      "rank": 28,
      "devanagari": "वद्",
      "iast": "vad",
      "gloss_ru": "говорят",
      "top_form": "vadanti",
      "coverage_pct": 60.9105
    },
    {
      "rank": 29,
      "devanagari": "मुच्",
      "iast": "muc",
      "gloss_ru": "освобождается",
      "top_form": "mucyate",
      "coverage_pct": 61.6111
    },
    {
      "rank": 30,
      "devanagari": "इ",
      "iast": "1 i / 2 i",
      "gloss_ru": "идет",
      "top_form": "eti",
      "coverage_pct": 62.2794
    },
    {
      "rank": 31,
      "devanagari": "लभ्",
      "iast": "labh",
      "gloss_ru": "обретает",
      "top_form": "labhate",
      "coverage_pct": 62.8881
    },
    {
      "rank": 32,
      "devanagari": "पत्",
      "iast": "1 pat / 2 pat",
      "gloss_ru": "упал",
      "top_form": "papāta",
      "coverage_pct": 63.4564
    },
    {
      "rank": 34,
      "devanagari": "हृ",
      "iast": "1 hṛ / 2 hṛ / har",
      "gloss_ru": "похитил",
      "top_form": "harati",
      "coverage_pct": 64.4832
    },
    {
      "rank": 35,
      "devanagari": "सृज्",
      "iast": "sṛj",
      "gloss_ru": "выпустил",
      "top_form": "asṛjata",
      "coverage_pct": 64.9835
    },
    {
      "rank": 36,
      "devanagari": "हा",
      "iast": "1 hā / 2 hā",
      "gloss_ru": "оставив",
      "top_form": "hīna",
      "coverage_pct": 65.4819
    },
    {
      "rank": 37,
      "devanagari": "भुज्",
      "iast": "1 bhuj / 2 bhuj",
      "gloss_ru": "наслаждайся",
      "top_form": "bhuktvā",
      "coverage_pct": 65.9777
    },
    {
      "rank": 38,
      "devanagari": "बन्ध्",
      "iast": "bandh",
      "gloss_ru": "связывается",
      "top_form": "baddhvā",
      "coverage_pct": 66.4607
    },
    {
      "rank": 39,
      "devanagari": "मृ",
      "iast": "1 mṛ / 2 mṛ",
      "gloss_ru": "умирает",
      "top_form": "mṛta",
      "coverage_pct": 66.9365
    },
    {
      "rank": 40,
      "devanagari": "वस्",
      "iast": "1 vas / 2 vas / 3 vas",
      "gloss_ru": "жил",
      "top_form": "vaset",
      "coverage_pct": 67.3984
    },
    {
      "rank": 41,
      "devanagari": "वृत्",
      "iast": "vṛt",
      "gloss_ru": "возвращаются",
      "top_form": "vartate",
      "coverage_pct": 67.8495
    },
    {
      "rank": 42,
      "devanagari": "क्षिप्",
      "iast": "kṣip",
      "gloss_ru": "метнул",
      "top_form": "kṣipet",
      "coverage_pct": 68.2973
    },
    {
      "rank": 43,
      "devanagari": "त्यज्",
      "iast": "tyaj",
      "gloss_ru": "оставив",
      "top_form": "tyaktvā",
      "coverage_pct": 68.7371
    },
    {
      "rank": 44,
      "devanagari": "शुध्",
      "iast": "śudh",
      "gloss_ru": "очищается",
      "top_form": "śuddha",
      "coverage_pct": 69.1756
    },
    {
      "rank": 45,
      "devanagari": "अर्ह्",
      "iast": "arh",
      "gloss_ru": "должен",
      "top_form": "arhasi",
      "coverage_pct": 69.6087
    },
    {
      "rank": 46,
      "devanagari": "नी",
      "iast": "nī",
      "gloss_ru": "приведи",
      "top_form": "nayet",
      "coverage_pct": 70.0363
    },
    {
      "rank": 47,
      "devanagari": "आप्",
      "iast": "āp",
      "gloss_ru": "достигает",
      "top_form": "āpnoti",
      "coverage_pct": 70.4574
    },
    {
      "rank": 48,
      "devanagari": "दह्",
      "iast": "dah",
      "gloss_ru": "сжигает",
      "top_form": "dagdha",
      "coverage_pct": 70.8714
    },
    {
      "rank": 49,
      "devanagari": "जीव्",
      "iast": "jīv",
      "gloss_ru": "живет",
      "top_form": "jīvati",
      "coverage_pct": 71.2757
    },
    {
      "rank": 50,
      "devanagari": "स्तु",
      "iast": "1 stu / 2 stu",
      "gloss_ru": "восхвалять",
      "top_form": "stuvanti",
      "coverage_pct": 71.6768
    },
    {
      "rank": 51,
      "devanagari": "पू",
      "iast": "pū",
      "gloss_ru": "Павамана",
      "top_form": "pavasva",
      "coverage_pct": 72.0702
    },
    {
      "rank": 52,
      "devanagari": "छिद्",
      "iast": "chid",
      "gloss_ru": "рассек",
      "top_form": "cicheda",
      "coverage_pct": 72.4594
    },
    {
      "rank": 53,
      "devanagari": "वृ",
      "iast": "1 vṛ / 2 vṛ",
      "gloss_ru": "окруженный",
      "top_form": "vṛtaḥ",
      "coverage_pct": 72.8385
    },
    {
      "rank": 54,
      "devanagari": "तप्",
      "iast": "tap",
      "gloss_ru": "греет",
      "top_form": "tapta",
      "coverage_pct": 73.2142
    },
    {
      "rank": 55,
      "devanagari": "पच्",
      "iast": "pac",
      "gloss_ru": "перевариваю",
      "top_form": "pacet",
      "coverage_pct": 73.5887
    },
    {
      "rank": 56,
      "devanagari": "भिद्",
      "iast": "bhid",
      "gloss_ru": "разбито",
      "top_form": "bhinna",
      "coverage_pct": 73.9612
    },
    {
      "rank": 57,
      "devanagari": "सिध्",
      "iast": "1 sidh / 2 sidh",
      "gloss_ru": "устанавливается",
      "top_form": "siddham",
      "coverage_pct": 74.3302
    },
    {
      "rank": 58,
      "devanagari": "आस्",
      "iast": "ās",
      "gloss_ru": "почитают",
      "top_form": "āste",
      "coverage_pct": 74.686
    },
    {
      "rank": 59,
      "devanagari": "शक्",
      "iast": "śak",
      "gloss_ru": "способен",
      "top_form": "śakyate",
      "coverage_pct": 75.0412
    },
    {
      "rank": 60,
      "devanagari": "अश्",
      "iast": "1 aś / 2 aś",
      "gloss_ru": "достигли",
      "top_form": "aśnute",
      "coverage_pct": 75.3956
    },
    {
      "rank": 61,
      "devanagari": "रक्ष्",
      "iast": "rakṣ",
      "gloss_ru": "охраняет",
      "top_form": "rakṣa",
      "coverage_pct": 75.7467
    },
    {
      "rank": 62,
      "devanagari": "गा",
      "iast": "1 gā / 2 gā",
      "gloss_ru": "пришел",
      "top_form": "gāyati",
      "coverage_pct": 76.0944
    },
    {
      "rank": 63,
      "devanagari": "वह्",
      "iast": "vah / vāh",
      "gloss_ru": "привези",
      "top_form": "vahanti",
      "coverage_pct": 76.4301
    },
    {
      "rank": 64,
      "devanagari": "भृ",
      "iast": "bhṛ",
      "gloss_ru": "принеси",
      "top_form": "bibharti",
      "coverage_pct": 76.7617
    },
    {
      "rank": 65,
      "devanagari": "शंस्",
      "iast": "śaṃs",
      "gloss_ru": "скажи",
      "top_form": "śaṃsati",
      "coverage_pct": 77.0865
    },
    {
      "rank": 66,
      "devanagari": "प्री",
      "iast": "prī",
      "gloss_ru": "доволен",
      "top_form": "prītaḥ",
      "coverage_pct": 77.4086
    },
    {
      "rank": 67,
      "devanagari": "जप्",
      "iast": "jap",
      "gloss_ru": "знатоков гимнов",
      "top_form": "japet",
      "coverage_pct": 77.7183
    },
    {
      "rank": 68,
      "devanagari": "क्रुध्",
      "iast": "krudh",
      "gloss_ru": "разгневанный",
      "top_form": "kruddho",
      "coverage_pct": 78.0202
    },
    {
      "rank": 69,
      "devanagari": "स्ना",
      "iast": "snā",
      "gloss_ru": "омывшись",
      "top_form": "snātvā",
      "coverage_pct": 78.32
    },
    {
      "rank": 70,
      "devanagari": "व्यध्",
      "iast": "vyadh",
      "gloss_ru": "пронзил",
      "top_form": "vivyādha",
      "coverage_pct": 78.6177
    },
    {
      "rank": 71,
      "devanagari": "वृध्",
      "iast": "vṛdh",
      "gloss_ru": "возрастает",
      "top_form": "vardhate",
      "coverage_pct": 78.9123
    },
    {
      "rank": 72,
      "devanagari": "रम्",
      "iast": "ram",
      "gloss_ru": "радуются",
      "top_form": "rataḥ",
      "coverage_pct": 79.1949
    },
    {
      "rank": 73,
      "devanagari": "भाष्",
      "iast": "bhāṣ",
      "gloss_ru": "сказал",
      "top_form": "abhāṣata",
      "coverage_pct": 79.4737
    },
    {
      "rank": 74,
      "devanagari": "पृ",
      "iast": "1 pṛ / 2 pṛ / 3 pṛ",
      "gloss_ru": "полный",
      "top_form": "pūrṇa",
      "coverage_pct": 79.7488
    },
    {
      "rank": 75,
      "devanagari": "नश्",
      "iast": "1 naś / 2 naś",
      "gloss_ru": "погибнут",
      "top_form": "naṣṭa",
      "coverage_pct": 80.0069
    },
    {
      "rank": 76,
      "devanagari": "जृ",
      "iast": "1 jṛ / 2 jṛ",
      "gloss_ru": "стареет",
      "top_form": "jīrṇe",
      "coverage_pct": 80.2642
    },
    {
      "rank": 77,
      "devanagari": "भी",
      "iast": "bhī",
      "gloss_ru": "в страхе",
      "top_form": "bhītā",
      "coverage_pct": 80.516
    },
    {
      "rank": 78,
      "devanagari": "द्विष्",
      "iast": "dviṣ",
      "gloss_ru": "ненавидит",
      "top_form": "dveṣṭi",
      "coverage_pct": 80.7604
    },
    {
      "rank": 79,
      "devanagari": "सेव्",
      "iast": "sev",
      "gloss_ru": "служит",
      "top_form": "seveta",
      "coverage_pct": 81.0021
    },
    {
      "rank": 80,
      "devanagari": "युध्",
      "iast": "yudh",
      "gloss_ru": "в битве",
      "top_form": "yudhyasva",
      "coverage_pct": 81.2326
    },
    {
      "rank": 81,
      "devanagari": "वध्",
      "iast": "vadh",
      "gloss_ru": "убил",
      "top_form": "vadhyaḥ",
      "coverage_pct": 81.4575
    },
    {
      "rank": 82,
      "devanagari": "धृ",
      "iast": "dhṛ",
      "gloss_ru": "наделенный",
      "top_form": "dhṛta",
      "coverage_pct": 81.6812
    },
    {
      "rank": 83,
      "devanagari": "जुष्",
      "iast": "juṣ",
      "gloss_ru": "наслаждайся",
      "top_form": "juṣṭaṃ",
      "coverage_pct": 81.9043
    },
    {
      "rank": 84,
      "devanagari": "व्रज्",
      "iast": "vraj",
      "gloss_ru": "загон",
      "top_form": "vrajet",
      "coverage_pct": 82.123
    },
    {
      "rank": 85,
      "devanagari": "क्षि",
      "iast": "1 kṣi / 2 kṣi",
      "gloss_ru": "живет",
      "top_form": "kṣīṇa",
      "coverage_pct": 82.3379
    },
    {
      "rank": 86,
      "devanagari": "भज्",
      "iast": "bhaj",
      "gloss_ru": "почитают",
      "top_form": "bhajet",
      "coverage_pct": 82.5497
    },
    {
      "rank": 87,
      "devanagari": "ध्या",
      "iast": "dhyā",
      "gloss_ru": "сумерки",
      "top_form": "dhyātvā",
      "coverage_pct": 82.7615
    },
    {
      "rank": 88,
      "devanagari": "धम्",
      "iast": "dham",
      "gloss_ru": "затрубили",
      "top_form": "dhamet",
      "coverage_pct": 82.9729
    },
    {
      "rank": 89,
      "devanagari": "दीप्",
      "iast": "dīp",
      "gloss_ru": "пылающий",
      "top_form": "dīpta",
      "coverage_pct": 83.1844
    },
    {
      "rank": 90,
      "devanagari": "शम्",
      "iast": "1 śam / 2 śam / 3 śam",
      "gloss_ru": "счастье",
      "top_form": "śānta",
      "coverage_pct": 83.3953
    },
    {
      "rank": 91,
      "devanagari": "हृष्",
      "iast": "hṛṣ",
      "gloss_ru": "радостно",
      "top_form": "hṛṣṭa",
      "coverage_pct": 83.604
    },
    {
      "rank": 92,
      "devanagari": "बुध्",
      "iast": "budh",
      "gloss_ru": "узнай",
      "top_form": "buddhvā",
      "coverage_pct": 83.8074
    },
    {
      "rank": 93,
      "devanagari": "शी",
      "iast": "1 śī / 2 śī",
      "gloss_ru": "лежит",
      "top_form": "śete",
      "coverage_pct": 84.0105
    },
    {
      "rank": 94,
      "devanagari": "मुह्",
      "iast": "muh",
      "gloss_ru": "глупец",
      "top_form": "mūḍha",
      "coverage_pct": 84.2112
    },
    {
      "rank": 95,
      "devanagari": "स्वप्",
      "iast": "svap",
      "gloss_ru": "спит",
      "top_form": "supta",
      "coverage_pct": 84.4114
    },
    {
      "rank": 96,
      "devanagari": "यम्",
      "iast": "yach / yam",
      "gloss_ru": "подняв",
      "top_form": "yaccha",
      "coverage_pct": 84.6103
    },
    {
      "rank": 97,
      "devanagari": "मद्",
      "iast": "mad",
      "gloss_ru": "я",
      "top_form": "matta",
      "coverage_pct": 84.8077
    },
    {
      "rank": 98,
      "devanagari": "द्रु",
      "iast": "dru",
      "gloss_ru": "ринулся",
      "top_form": "drutaṃ",
      "coverage_pct": 85.003
    },
    {
      "rank": 99,
      "devanagari": "पिष्",
      "iast": "piṣ",
      "gloss_ru": "сжимая",
      "top_form": "piṣṭvā",
      "coverage_pct": 85.1962
    },
    {
      "rank": 100,
      "devanagari": "स्पृश्",
      "iast": "spṛś",
      "gloss_ru": "коснулся",
      "top_form": "spṛṣṭvā",
      "coverage_pct": 85.3889
    },
    {
      "rank": 101,
      "devanagari": "विश्",
      "iast": "viś",
      "gloss_ru": "вошел",
      "top_form": "viveśa",
      "coverage_pct": 85.5805
    }
  ]
};
  Object.freeze(global.ROOT_BANDS);
})(this);
