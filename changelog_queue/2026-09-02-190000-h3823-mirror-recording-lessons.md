# Курс-запись получает собственные уроки: `catalog:mirror-recording-lessons` (Opus 5 `claude-opus-5`, 02-09-2026)

H3823, рулинг MG 01-09-2026 «собственные уроки у 327 со ссылками на те же записи». Курс 327
«Йога-сутры Патанджали (1 поток, 2025) в записи» продан 129 раз (`block_1` — 40, `block_2` — 33,
`block_3` — 28, `block_4` — 28; `full` не купил никто) и не имел ни одного урока, тогда как все
шестнадцать записей лежали на уроках живого курса 396. Доступ считается ПО КУРСУ, поэтому купившие
не получали ничего.

- **Команда** [`catalog:mirror-recording-lessons {source} {target}`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MirrorRecordingLessons.php)
  ([PR #2313](https://github.com/gasyoun/Systema-Sanscriticum/pull/2313)) переносит ровно то, что делает
  урок записью (заголовок, блок и половина, порядок, дата, длительность, признаки публикации, ссылки
  `youtube_url`/`rutube_url`/`video_url`, тема, вид записи). По умолчанию **сухой прогон**, запись только
  с `--apply`, идемпотентна по слоту `(block_number, block_half, sort_order)`.
- **Пишет ТОЛЬКО в `lessons`.** `courses`, `tariffs`, оплаты и видимость не трогает (инцидент
  31-08-2026 состоял ровно в правке тарифов); `group_id` не переносится, `homework_enabled` явно 0.
  Отказывается работать, если у цели нет блока, который есть у уроков источника — иначе перенесённый
  урок получил бы ключ `block_N`, недостижимый для купивших.
- **Почему этого хватает без правки money/access-контура:** `Lesson::unlockingKeys()` выводит `block_N`
  из `lessons.block_number`, поэтому дословный перенос номера блока открывает каждому ровно то, что он
  оплатил.
- Тесты [`MirrorRecordingLessonsTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Catalog/MirrorRecordingLessonsTest.php)
  — 7/7, 51 assertion; `--filter="Catalog|Tariff|Access|Payment|Lesson"` — 850 passed; Pint clean.
- **На прод не применялось.** Команда доставлена автодеплоем, но `--apply` по паре `396 → 327` ещё не
  выполнялся: у курса 327 по-прежнему ноль уроков. Остаток — прод-шаг в
  [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).
