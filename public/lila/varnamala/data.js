/* ============================================================
   Varnamālā pilot — interactive Devanāgarī akṣara content (H2436).

   Metamorphabet-inspired: touch glyph → 3 morph stages → word card.
   Runtime: public/lila/varnamala/engine.js
   Brief:   docs/VARNAMALA_PILOT_BRIEF_2026.md

   Scope fence: 10 akṣara, no mātrā, no conjuncts.
   Words: beginner-concrete Sanskrit nouns (pictureable).
   ============================================================ */
(function (global) {
  "use strict";

  global.VARNAMALA_PILOT = {
    version: 1,
    family: "varnamala",
    band: "pilot",
    complete_distinct_for_play: 5,
    aksaras: [
      {
        id: "a",
        devanagari: "अ",
        iast: "a",
        cyrillic: "а",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "А оживает", label_en: "A wakes" },
          { stage: 2, visual: "hint-mane", label_ru: "грива…", label_en: "a mane…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "अश्व",
          iast: "aśva",
          cyrillic: "аш́ва",
          translation_ru: "лошадь",
          translation_en: "horse"
        },
        rive: "a"
      },
      {
        id: "aa",
        devanagari: "आ",
        iast: "ā",
        cyrillic: "а̄",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "А̄ тянется", label_en: "Ā stretches" },
          { stage: 2, visual: "hint-sun", label_ru: "диск…", label_en: "a disk…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "आकाश",
          iast: "ākāśa",
          cyrillic: "а̄ка̄ша",
          translation_ru: "небо",
          translation_en: "sky"
        },
        rive: "aa"
      },
      {
        id: "i",
        devanagari: "इ",
        iast: "i",
        cyrillic: "и",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "И наклоняется", label_en: "I leans" },
          { stage: 2, visual: "hint-crescent", label_ru: "серп…", label_en: "a crescent…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "इन्दु",
          iast: "indu",
          cyrillic: "инду",
          translation_ru: "луна",
          translation_en: "moon"
        },
        rive: "i"
      },
      {
        id: "u",
        devanagari: "उ",
        iast: "u",
        cyrillic: "у",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "У опускается", label_en: "U drops" },
          { stage: 2, visual: "hint-hump", label_ru: "горб…", label_en: "a hump…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "उष्ट्र",
          iast: "uṣṭra",
          cyrillic: "ушт̣ра",
          translation_ru: "верблюд",
          translation_en: "camel"
        },
        rive: "u"
      },
      {
        id: "ka",
        devanagari: "क",
        iast: "ka",
        cyrillic: "ка",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "Ка просыпается", label_en: "Ka wakes" },
          { stage: 2, visual: "hint-lotus", label_ru: "лепесток…", label_en: "a petal…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "कमल",
          iast: "kamala",
          cyrillic: "камала",
          translation_ru: "лотос",
          translation_en: "lotus"
        },
        rive: "ka"
      },
      {
        id: "ga",
        devanagari: "ग",
        iast: "ga",
        cyrillic: "га",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "Га надувается", label_en: "Ga swells" },
          { stage: 2, visual: "hint-trunk", label_ru: "хобот…", label_en: "a trunk…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "गज",
          iast: "gaja",
          cyrillic: "гаджа",
          translation_ru: "слон",
          translation_en: "elephant"
        },
        rive: "ga"
      },
      {
        id: "ca",
        devanagari: "च",
        iast: "ca",
        cyrillic: "ча",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "Ча щёлкает", label_en: "Ca snaps" },
          { stage: 2, visual: "hint-rim", label_ru: "обод…", label_en: "a rim…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "चक्र",
          iast: "cakra",
          cyrillic: "чакра",
          translation_ru: "колесо",
          translation_en: "wheel"
        },
        rive: "ca"
      },
      {
        id: "ta",
        devanagari: "त",
        iast: "ta",
        cyrillic: "та",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "Та выпрямляется", label_en: "Ta stands" },
          { stage: 2, visual: "hint-branch", label_ru: "ветка…", label_en: "a branch…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "तरु",
          iast: "taru",
          cyrillic: "тару",
          translation_ru: "дерево",
          translation_en: "tree"
        },
        rive: "ta"
      },
      {
        id: "na",
        devanagari: "न",
        iast: "na",
        cyrillic: "на",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "На колышется", label_en: "Na sways" },
          { stage: 2, visual: "hint-wave", label_ru: "волна…", label_en: "a wave…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "नदी",
          iast: "nadī",
          cyrillic: "надӣ",
          translation_ru: "река",
          translation_en: "river"
        },
        rive: "na"
      },
      {
        id: "ma",
        devanagari: "म",
        iast: "ma",
        cyrillic: "ма",
        morphs: [
          { stage: 1, visual: "wake", label_ru: "Ма раскрывается", label_en: "Ma opens" },
          { stage: 2, visual: "hint-feather", label_ru: "перо…", label_en: "a feather…" },
          { stage: 3, visual: "word", label_ru: "слово", label_en: "word" }
        ],
        word: {
          devanagari: "मयूर",
          iast: "mayūra",
          cyrillic: "майӯра",
          translation_ru: "павлин",
          translation_en: "peacock"
        },
        rive: "ma"
      }
    ]
  };
})(typeof window !== "undefined" ? window : globalThis);
