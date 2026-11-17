# Memrise course 6502608 export

_Created: 22-07-2026 · Last updated: 17-11-2026_

Raw export of Memrise community course
[6502608 «Учебник санскрита» В.А. Кочергиной](https://community-courses.memrise.com/community/course/6502608/uchebnik-sanskrita-va-kocherginoi/)
(own course, author samskrtamru = Mārcis Gasūns), pulled 22-07-2026 via
`scripts/memrise_export.py` (H1431) — 41 non-empty levels, 2 columns
(`col_a`/`col_b`), see `manifest.json` for the per-level row counts.

Feeds the [H1431](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1431-Sonnet_Systema_memrise-kochergina-lesson1-mapping_21.07.26.md)
mapping of Kochergina textbook lesson 1 onto the SRS deck — level 1 here is
the lesson-1 candidate word list; column semantics (which side is
Sanskrit/IAST vs. Russian translation) still need human confirmation before
import, `col_a`/`col_b` are generic scrape-time labels, not verified fields.

**17-11-2026 (DeepSeek V4.1 Flash 1017) — массовый импорт выполнен без этого подтверждения.**
Решение MG в чате: «залить все 40 оставшихся уровней как есть».
`srs:import-memrise database/seeders/data/memrise_6502608` создал 41 колоду
`memrise-6502608-level-1…41` и 2 124 карточки в проде (до этого в проде была
ровно одна колода — `kochergina-lesson-1`, 41 карточка из `level_02.csv`).
Семантика `col_a`/`col_b` **по-прежнему не подтверждена**, то есть направления
в этих 2 124 карточках не верифицированы.

`level_02.csv` теперь живёт дважды: `kochergina-lesson-1` (518 повторений) и
`memrise-6502608-level-2` (0 повторений). Снятие дубля — открытое решение, не
сделанное этим проходом. Карта «41 уровень → 40 уроков учебника» остаётся за
[H8211](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8211-MiMo_Systema-Sanscriticum_kochergina-memrise-forty-decks_13.11.26.md),
который прямо требует не дублировать колоды и не выполнять destructive reimport.

**Уточнение к абзацу выше, 17-11-2026 (решения MG, интерактивно).** Абзац оставлен как
историческая запись того, что было верно на момент импорта; ниже — что решилось дальше.

- **Дубль снят.** Колода `memrise-6502608-level-2` удалена на проде (41 карточка,
  0 повторений, бэкап `/tmp/backup_level2_17-11.sql`); сохранена `kochergina-lesson-1`
  с 518 повторениями. Урок 1 в реестре живёт в `kochergina-lesson-1` — осознанная
  дырка в системной сетке slug-ов. Прод: 90 колод / 26 619 карточек.
- **Семантика колонок проверена — переворота нет.** `col_a` = санскрит/IAST,
  `col_b` = русский, на всех 41 уровне (автопроверка по кириллице: в `col_a` 0–2
  строки на уровень, в `col_b` — все). `level_32` — числительные, `col_b` = цифры
  (содержательное исключение, не дефект направления).
- **Маппинг 41 → 40 решён: 40 уроков + 1 уровень сокращений.** Сокращения — это
  `level_01.csv` (грамматические сокращения: `A.`=винительный падеж, `Abl.`=отложительный,
  `adj.`=прилагательное), он не урок; `level_02…level_41` = уроки 1–40 по порядку.

_Dr. Mārcis Gasūns_
