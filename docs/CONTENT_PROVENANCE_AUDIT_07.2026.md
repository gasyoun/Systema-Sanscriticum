# Аудит источников учебного контента (MEGABOOK §2.9) — июль 2026

_Created: 11-07-2026 · Last updated: 11-07-2026_

Аудит по [H712](https://github.com/gasyoun/Uprava/blob/main/handoffs/H712-Fable_Systema-Sanscriticum_lesson-source-provenance-audit_11.07.26.md),
выполнен Fable 5 (`claude-fable-5`). Правило
[MEGABOOK §2.9](https://github.com/gasyoun/Uprava/blob/main/MEGABOOK.md): каждый
учебный элемент (урок, упражнение, карточка) ведёт к проверенному
словарному/грамматическому основанию; «нельзя копировать определение без
source link». Канонические источники: kosha cards, Whitney/DCS, MW (Cologne
CDSL), SanskritRussian glosses, Bühler exercises.

## 1. Инвентарь учебного контента (что где хранится)

| Поверхность | Хранилище | Механизм провенанса | Вердикт |
|---|---|---|---|
| Статические игры-упражнения ([`public/exercises/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/develop/public/exercises)) — 4 боевых дрилла: sort/genders, sort/noun-pronoun, match/verb-roots, cloze/verb-fill | данные зашиты в `index.html` каждого дрилла | до аудита — только LearningApps-оригинал в README (источник ВЁРСТКИ, не лексики); словарных ссылок не было | ❌ был пробел → ✅ **исправлено этим PR** (см. §3) |
| SRS-колоды ([`SrsSanskritDeckSeeder`](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/database/seeders/SrsSanskritDeckSeeder.php): `sanskrit-core`, `sanskrit-cyrillic-only`) | БД, сеются из `dictionary_words` | у карточки есть `source_word_id` → `dictionary_words`; **но у самой `dictionary_words` нет колонки внешнего научного источника** (только `dictionary_id` → локальная `dictionaries.name`, частично `wikidata_qid`) | ⚠️ структурный пробел (см. §4.1) |
| Memrise-импорт ([`database/seeders/data/memrise_6679375/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/develop/database/seeders/data/memrise_6679375)) | каталог пуст by design (P0 не запускался) | контракт `manifest.json` ТРЕБУЕТ `source_url` — провенанс заложен в дизайн импортера | ✅ ок на уровне дизайна, контента нет |
| Уроки курсов (`lessons`), лекции (`LectureDraft`), статьи (`articles`) | только прод-БД; в репозитории контента нет | не проверяемо из репозитория (агентам недоступны SSH/БД прода) | ⚠️ вне досягаемости аудита (см. §4.2) |
| Квизы марафона ([`config/marathon.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/config/marathon.php) `day0/day1/day2_quiz`) | конфиг | навигационные вопросы «цель → маршрут», лексических/грамматических утверждений нет | ✅ не требует источника |

## 2. Выборка и метод

Выборка: **все 43 уникальных лексических элемента** четырёх боевых дриллов
(29 существительных/местоимений + 12 корней + 2 повторно используемых) — это
100 % учебной лексики, лежащей в репозитории. Проверка механическая
(скрипт сессии, 11-07-2026):

- существительные/местоимения — членство в списке заголовочных слов MW
  [`MW-unique-key1-194084.txt`](https://github.com/gasyoun/SanskritLexicography/blob/master/HeadwordLists/now-2026/MW-unique-key1-194084.txt)
  (SLP1, срез Cologne CDSL 2026);
- глагольные корни — живые страницы ридера Уитни
  [samskrtam.ru/whitney-roots](https://samskrtam.ru/whitney-roots/) (11 URL,
  все HTTP 200 на 11-07-2026) + перекрёстно MW key1.

**Результат: 43/43 элементов подтверждены** (0 ложных слов). Два элемента
потребовали нетривиальной привязки: mlā- (лемма MW — *mlai*, `mlE`; форма
mlāyati) и puṣp- (не корень у Уитни; деноминатив *puṣpyati* — MW `puzpya` от
`puzpa`). Полные таблицы соответствий закоммичены рядом с данными:
[sort/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/public/exercises/sort/README.md) ·
[match/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/public/exercises/match/README.md) ·
[cloze/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/public/exercises/cloze/README.md).

## 3. Исправления в этом PR (класс «источник существует — ссылки не было»)

1. Футер каждого из 4 дриллов получил строку «Источники» со ссылками на
   Cologne CDSL (MW) и ридер Уитни.
2. README каждого семейства (sort / match / cloze) получил раздел
   «Data provenance (MEGABOOK §2.9)» с поэлементной таблицей соответствий.
3. Попутно: [sort/README.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/develop/public/exercises/sort/README.md)
   не упоминал дрилл `noun-pronoun` (устаревшая таблица Contents) — строка
   добавлена.

Только данные/документация; движки упражнений, схема БД и money-path не тронуты.

## 4. Пробелы, требующие отдельного решения (в GTD)

1. **`dictionary_words` не несёт внешнего научного источника.** Цепочка
   SRS-карточки → `source_word_id` → `dictionary_words` обрывается на
   локальном словаре LMS: `dictionary_id` указывает на `dictionaries`
   (name/description), связи с MW/kosha-ключом нет (частичный якорь —
   `wikidata_qid` из SEO-волны H204). Это ровно тот «новый словарь внутри
   LMS», о котором предупреждает §2.9. Нужна колонка типа `mw_key`/`kosha_ref`
   (миграция + бэкфилл) — схемное изменение, вне рамок H712.
2. **Аудит уроков/лекций/статей требует доступа к прод-БД.** Контент живёт
   только в проде; агентские сессии не имеют SSH/БД-доступа (см.
   [SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md)).
   Вариант без доступа: экспорт дампа `lessons`/`articles` в файл силами
   подрядчика, затем офлайн-аудит той же методикой.

## 5. Побочные находки

- Ссылки на корни в [`WhitneyRoots/src/app_data.json`](https://github.com/gasyoun/WhitneyRoots/blob/main/src/app_data.json)
  устарели относительно живого деплоя: в данных `root_ml_a.html` /
  `root_k_r.html`, на сайте — `root_mlaa.html` / `root_1_k_r.html`
  (долгие гласные удвоены, омонимы получают числовой префикс). Первые дают
  404. Зафиксировано для репозитория WhitneyRoots, здесь не чинится.

_Dr. Mārcis Gasūns_
