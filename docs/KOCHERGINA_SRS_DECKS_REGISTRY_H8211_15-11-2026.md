# Реестр колод SRS — «Учебник санскрита» В. А. Кочергиной (H8211)

_Created: 15-11-2026 · Last updated: 15-11-2026_

_Ручная сверка mapping 41 уровня экспорта Memrise-курса 6502608 на 40 уроков учебника и открытые ученические ссылки. Курс в Memrise создан самим автором учебной программы (samskrtamru = M. Gasūns), экспорт от 22-07-2026, 41 непустой уровень, 2124 строки. Команда импорта: [`srs:import-kochergina-course`](../app/Console/Commands/ImportKocherginaCourseSrsDeck.php) (идемпотентная, `--dry-run` поддерживается)._

## Правило соответствия 41 → 40

- **Level 1 = вводный уровень**: «Условные сокращения» грамматики из предисловия учебника (`A.` → «винительный падеж», `adj.` → «прилагательное» …), НЕ словарь урока — установлен cross-check'ом H1431 (см. [архивный handoff](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1431-Sonnet_Systema_memrise-kochergina-lesson1-mapping_21.07.26.md)), который изначально принял level 1 за словарь Занятия I и опроверг это по оцифрованному учебнику.
- **Level N+1 = Занятие N** для N = 1..40. Якорь: level_02 ↔ словарь Занятия I, сверенный в H1431 с Упражнением II оцифрованного учебника. Учебник содержит ровно 40 занятий (Занятие I … Занятие XL); уровни экспорта идут в порядке уроков.
- Ни один уровень не обрезан и не выброшен: все 41 непустой уровень дают колоду; итог — 41 колода (1 вводная + 40 уроков), 2124 карточки.

## Реестр колод

Открытый URL — публичная страница без входа и без оплаты (`/koloda/{slug}`, guest-trial). Сохранение личного прогресса FSRS — только после штатного входа (`/dvaram/koloda/{slug}`); публичное чтение от этого не зависит. Аудио: в экспорте Memrise **нет аудио-колонок** — аудио ни у одной колоды нет (текстовые карточки IAST → русский).

| Урок | Занятие | Source level | Deck slug | Карточек | Аудио | Открытый URL |
|---|---|---|---|---|---|---|
| — (вводный) | — | 1 (`level_01.csv`) | `kochergina-intro` | 53 | нет | https://samskrte.ru/koloda/kochergina-intro |
| 1 | I | 2 (`level_02.csv`) | `kochergina-lesson-1` | 41 | нет | https://samskrte.ru/koloda/kochergina-lesson-1 |
| 2 | II | 3 (`level_03.csv`) | `kochergina-lesson-2` | 33 | нет | https://samskrte.ru/koloda/kochergina-lesson-2 |
| 3 | III | 4 (`level_04.csv`) | `kochergina-lesson-3` | 68 | нет | https://samskrte.ru/koloda/kochergina-lesson-3 |
| 4 | IV | 5 (`level_05.csv`) | `kochergina-lesson-4` | 59 | нет | https://samskrte.ru/koloda/kochergina-lesson-4 |
| 5 | V | 6 (`level_06.csv`) | `kochergina-lesson-5` | 35 | нет | https://samskrte.ru/koloda/kochergina-lesson-5 |
| 6 | VI | 7 (`level_07.csv`) | `kochergina-lesson-6` | 60 | нет | https://samskrte.ru/koloda/kochergina-lesson-6 |
| 7 | VII | 8 (`level_08.csv`) | `kochergina-lesson-7` | 34 | нет | https://samskrte.ru/koloda/kochergina-lesson-7 |
| 8 | VIII | 9 (`level_09.csv`) | `kochergina-lesson-8` | 41 | нет | https://samskrte.ru/koloda/kochergina-lesson-8 |
| 9 | IX | 10 (`level_10.csv`) | `kochergina-lesson-9` | 38 | нет | https://samskrte.ru/koloda/kochergina-lesson-9 |
| 10 | X | 11 (`level_11.csv`) | `kochergina-lesson-10` | 69 | нет | https://samskrte.ru/koloda/kochergina-lesson-10 |
| 11 | XI | 12 (`level_12.csv`) | `kochergina-lesson-11` | 45 | нет | https://samskrte.ru/koloda/kochergina-lesson-11 |
| 12 | XII | 13 (`level_13.csv`) | `kochergina-lesson-12` | 57 | нет | https://samskrte.ru/koloda/kochergina-lesson-12 |
| 13 | XIII | 14 (`level_14.csv`) | `kochergina-lesson-13` | 71 | нет | https://samskrte.ru/koloda/kochergina-lesson-13 |
| 14 | XIV | 15 (`level_15.csv`) | `kochergina-lesson-14` | 49 | нет | https://samskrte.ru/koloda/kochergina-lesson-14 |
| 15 | XV | 16 (`level_16.csv`) | `kochergina-lesson-15` | 60 | нет | https://samskrte.ru/koloda/kochergina-lesson-15 |
| 16 | XVI | 17 (`level_17.csv`) | `kochergina-lesson-16` | 63 | нет | https://samskrte.ru/koloda/kochergina-lesson-16 |
| 17 | XVII | 18 (`level_18.csv`) | `kochergina-lesson-17` | 30 | нет | https://samskrte.ru/koloda/kochergina-lesson-17 |
| 18 | XVIII | 19 (`level_19.csv`) | `kochergina-lesson-18` | 38 | нет | https://samskrte.ru/koloda/kochergina-lesson-18 |
| 19 | XIX | 20 (`level_20.csv`) | `kochergina-lesson-19` | 32 | нет | https://samskrte.ru/koloda/kochergina-lesson-19 |
| 20 | XX | 21 (`level_21.csv`) | `kochergina-lesson-20` | 38 | нет | https://samskrte.ru/koloda/kochergina-lesson-20 |
| 21 | XXI | 22 (`level_22.csv`) | `kochergina-lesson-21` | 47 | нет | https://samskrte.ru/koloda/kochergina-lesson-21 |
| 22 | XXII | 23 (`level_23.csv`) | `kochergina-lesson-22` | 42 | нет | https://samskrte.ru/koloda/kochergina-lesson-22 |
| 23 | XXIII | 24 (`level_24.csv`) | `kochergina-lesson-23` | 44 | нет | https://samskrte.ru/koloda/kochergina-lesson-23 |
| 24 | XXIV | 25 (`level_25.csv`) | `kochergina-lesson-24` | 47 | нет | https://samskrte.ru/koloda/kochergina-lesson-24 |
| 25 | XXV | 26 (`level_26.csv`) | `kochergina-lesson-25` | 56 | нет | https://samskrte.ru/koloda/kochergina-lesson-25 |
| 26 | XXVI | 27 (`level_27.csv`) | `kochergina-lesson-26` | 44 | нет | https://samskrte.ru/koloda/kochergina-lesson-26 |
| 27 | XXVII | 28 (`level_28.csv`) | `kochergina-lesson-27` | 49 | нет | https://samskrte.ru/koloda/kochergina-lesson-27 |
| 28 | XXVIII | 29 (`level_29.csv`) | `kochergina-lesson-28` | 43 | нет | https://samskrte.ru/koloda/kochergina-lesson-28 |
| 29 | XXIX | 30 (`level_30.csv`) | `kochergina-lesson-29` | 75 | нет | https://samskrte.ru/koloda/kochergina-lesson-29 |
| 30 | XXX | 31 (`level_31.csv`) | `kochergina-lesson-30` | 38 | нет | https://samskrte.ru/koloda/kochergina-lesson-30 |
| 31 | XXXI | 32 (`level_32.csv`) | `kochergina-lesson-31` | 68 | нет | https://samskrte.ru/koloda/kochergina-lesson-31 |
| 32 | XXXII | 33 (`level_33.csv`) | `kochergina-lesson-32` | 66 | нет | https://samskrte.ru/koloda/kochergina-lesson-32 |
| 33 | XXXIII | 34 (`level_34.csv`) | `kochergina-lesson-33` | 46 | нет | https://samskrte.ru/koloda/kochergina-lesson-33 |
| 34 | XXXIV | 35 (`level_35.csv`) | `kochergina-lesson-34` | 55 | нет | https://samskrte.ru/koloda/kochergina-lesson-34 |
| 35 | XXXV | 36 (`level_36.csv`) | `kochergina-lesson-35` | 64 | нет | https://samskrte.ru/koloda/kochergina-lesson-35 |
| 36 | XXXVI | 37 (`level_37.csv`) | `kochergina-lesson-36` | 74 | нет | https://samskrte.ru/koloda/kochergina-lesson-36 |
| 37 | XXXVII | 38 (`level_38.csv`) | `kochergina-lesson-37` | 77 | нет | https://samskrte.ru/koloda/kochergina-lesson-37 |
| 38 | XXXVIII | 39 (`level_39.csv`) | `kochergina-lesson-38` | 56 | нет | https://samskrte.ru/koloda/kochergina-lesson-38 |
| 39 | XXXIX | 40 (`level_40.csv`) | `kochergina-lesson-39` | 45 | нет | https://samskrte.ru/koloda/kochergina-lesson-39 |
| 40 | XL | 41 (`level_41.csv`) | `kochergina-lesson-40` | 77 | нет | https://samskrte.ru/koloda/kochergina-lesson-40 |

Число карточек = число непустых строк CSV уровня (правило пропуска: пустая `col_a`); parity каждой колоды с исходником проверяется тестом `ImportKocherginaCourseSrsDeckTest::test_deck_card_counts_match_source_csv_rows_for_every_level`.

## Дубли headword с расходящимися глоссами

В 6 уровнях встречается 14 групп повторяющихся headword с **разными** глоссами (не точные дубли). Импортер НЕ схлопывает их: каждая непустая строка остаётся отдельной карточкой (identity = deck + `fields->iast` + `fields->translation_ru`, паттерн H1990/Бюлер), так что паритет counts с CSV сохраняется и ни один вариант глоссы не теряется. Ниже полный список — для ревизии человеком; правка исходника Memrise не выполнялась (не выдумываем и не правим чужой контент).

| Урок (level) | Headword | Глоссы (все варианты) |
|---|---|---|
| 7 (8) | `tuṣṭā` | радостная, довольная · довольная |
| 7 (8) | `utthā` | вставать, подниматься, вскочить · вставать, восходить |
| 7 (8) | `brū` | говорить, рассказывать, называть · говорить |
| 8 (9) | `kutra` | куда? когда? где? · куда? |
| 10 (11) | `rāvaṇa` | (n) nom. pr. Равана (царь о. Ланка) · nom.pr. - Равана (царь о-ва Ланка) |
| 10 (11) | `sītā` | (f) nom. pr. Сита (супруга Рамы) · nom. pr. - Сита (супруга Рамы) |
| 10 (11) | `godāvaryās` | (f) G. sg. от godāvarī (назв. реки) · (f) G. sg. название реки Годаваря |
| 10 (11) | `saha` | (+I.) с, вместе с · с, вместе с (+I.) |
| 10 (11) | `śikhara` | (m, n) вершина (горы) · (m,n) вершина (горы) |
| 10 (11) | `upadiś` | (А.)наставлять кого либо, в чем либо · наставлять кого-либо (A.) в чем-либо (A.) |
| 23 (24) | `labh` | получать · получать, брать, добиваться чего либо |
| 23 (24) | `upagam` | приближаться · собираться, доходить, попадать |
| 29 (30) | `dhīmant` | умный, мудрый · умный · умный, мудрый, разумный (3 строки) |
| 32 (33) | `mahā-bhāgya` | (n) великая участь · (n) великая участь, счастливая судьба |

## Импорт и идемпотентность

```sh
php artisan srs:import-kochergina-course --dry-run   # отчёт без записи
php artisan srs:import-kochergina-course             # идемпотентный импорт
```

- Dictionary `Kochergina — «Учебник санскрита»` общий с колодой H1431; колода `kochergina-lesson-1` **не дублируется и не мутируется** (firstOrCreate по slug; на проде уже есть — 41 карточка от H1431, note type `kochergina_l1` остаётся у неё).
- Повторный прогон на чистой БД и поверх существующего импорта не создаёт дубликатов (тест `test_is_idempotent_on_rerun`, `test_reuses_existing_h1431_lesson1_deck_without_duplicating`).
- Универсальный `srs:import-memrise` для этого курса НЕ используется: манифест 6502608 содержит generic-колонки `col_a`/`col_b`, которые не мапятся на поля generic-импортера.

## Проверка и что не проверено

- **Локально (SQLite, feature-тесты):** полнота 40 уроков + вводного уровня, паритет counts каждого уровня с CSV, содержимое canary (первый/средний/последний урок: `ka`, `śrī`, `āgamana` + вводный `A.`), сохранение дублей, идемпотентность, сценарий ученика (гость открывает `/koloda`, `/koloda/kochergina-lesson-{1,20,40}`, начинает повторение в guest-trial, прогресс не пишется без входа).
- **Production (samskrte.ru):** до мержа PR и деплоя **НЕ проверено** — статусы URL в таблице выше требуют живого прогона после деплоя (`srs:import-kochergina-course` на проде + `cabinet:probe`). Существующее состояние прод-БД на 15-11-2026: ровно одна колода `kochergina-lesson-1` (41 карточка, H1431), колод 2–40 и вводной нет.
- Прогресс FSRS: сохранение требует штатного входа (`/dvaram/koloda/{slug}`); гостевой пробный режим отдаёт `SRS_GUEST_TRIAL_CARDS` (по умолчанию 10) карточек без регистрации, дальше — предложение войти. Публичное чтение колод входом не блокируется.

## Правки вне scope

Карточки — факультативная работа со словами к каждому уроку (не барьер прохождения); оплата, права на курсы, тарифы, чужие данные и мемrise-источник не менялись. Деструктивный reimport не выполнялся.

_Dr. Mārcis Gasūns_
