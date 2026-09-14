# revenue-recognition.meta.md — метадок о `revenue-recognition`

_Created: 13-07-2026 · Last updated: 02-09-2026_

Метадок-спутник для [`revenue-recognition.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/revenue-recognition.md) — держит контекст ВОКРУГ документа (назначение, происхождение, бэклог улучшений, ограничения), не пересказывая его содержание.

## Subject (Предмет)

- **Ссылка на документ:** [`docs/revenue-recognition.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/revenue-recognition.md)
- **Назначение:** ground-truth-описание подсистемы признания выручки по методу начисления (accrual) в LMS «Аура» — как платеж раскладывается по месяцам блоков курса, что такое отложенная выручка и чем чревато включение реверса непризнанного остатка при возврате.
- **Аудитория:** финдир и разработчики LMS, читающие финансовые страницы (ОПиУ, «отложенная выручка») и сопровождающие сервисы `RevenueRecognitionService` / `RevenueScheduleService`.
- **Формат/контракт:** повествовательный технический док с таблицей «что где лежит», перечнем инвариантов и списком последствий флага `revenue.reverse_unrecognized_on_refund`. Ссылки на код — полные blob-URL. Держится в паре с решениями MG (H258, H352).

## Provenance (Происхождение)

- **Subject создан:** 08-07-2026 (git).
- **Метадок написан:** 13-07-2026, H890 (metadoc sweep II), Opus 4.8 `claude-opus-4-8`.
- **Следующее упрочнение:** решение MG по платежу-предоплате из H3951 (что значит `start_block=1, end_block=36` на курсе со штампованным прогоном) и, по итогу, судьба флага `revenue.recognition_stamped_block_run_guard` — см. бэклог #6.

## Ranked improvement backlog (Ранжированный бэклог улучшений)

| # | Улучшение | Зачем | Статус |
|---|---|---|---|
| 1 | Свести общий алгоритм раскладки `RevenueRecognitionService` и `TeacherSalaryService::recognizedShares` к единому источнику | Сейчас два отдельных сервиса с одинаковой логикой — риск дрейфа при правках | **done** — [`BlockMonthRecognition`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockMonthRecognition.php) единственный источник; H3951 добавил туда же `attribute()` с именем механизма |
| 2 | Скриншот/диаграмма вкладок ОПиУ (касса vs начисление) с примером сходимости | Инвариант «Σ признанной == Σ кассовой» словами; визуал ускорил бы сверку | parked (нет генератора рисунков из фида) |
| 3 | Runbook переключения флага `revenue.reverse_unrecognized_on_refund` на проде | Переключение = ретроактивное событие уровня финдира; сейчас разбросано по §64 | parked (требует решения финдира о процедуре) |
| 4 | Автоматическая проверка дисциплины линковки возвратов (`refund_of_payment_id`) | Незалинкованный возврат молча завышает выручку; флаг это не ловит | parked (нужен новый чек/тест) |
| 5 | Numeric-пример отложенной выручки (>0 и <0) с конкретными суммами | Знак deferred revenue объяснён абстрактно; пример снял бы двусмысленность | parked (иллюстративная задача, не гейт) |
| 6 | Решение MG по платежу-предоплате H3951 и судьбе флага `revenue.recognition_stamped_block_run_guard` | Месяц признания предоплаты на штампованном прогоне — вопрос финдира, а не кода: и месяц штампа, и месяц оплаты одинаково неверны, помесячная раскладка одним столбцом не выражается | **open** — ждёт человека; до решения флаг остаётся OFF, строка видна аудиту как `stamped` |
| 7 | Читающий чек на штампованные прогоны в CI/крон (а не только по требованию) | `recognition:attribution-audit` запускают руками; новая порция импорта с одинаковой датой всплывёт только при следующем разборе | parked (нужен владелец расписания) |

## Known limitations / caveats (Известные ограничения)

- Документ описывает состояние ПОСЛЕ включения флага реверса на проде (12-07-2026); отчёты «до/после» несопоставимы без поправки на реверс.
- Сторож штампованного прогона (H3951) описан как **выключенный**: пока флаг OFF, цифры в документе и на экранах — прежние, а поле `stamped` только помечает затронутые строки. Включение флага меняет месяц признания и потому ретроактивно — событие уровня финдира, как и реверс.
- Месяц оплаты, в который сторож переносит штампованный прогон, — **названный запасной вариант, а не истина**: он лишь не даёт деньгам уйти в закрытый период. Читать его как «правильный месяц» — ошибка.
- Честность цифр зависит от ручной дисциплины: реверс срабатывает только при заданном `refund_of_payment_id`; сам док это гарантировать не может.
- Субъект — описание, не источник истины: истина в коде и в решениях MG (H258/H352); при расхождении верно то, что в сервисах.

## Intended use / known misuse (Назначение и типичные ошибки использования)

- **Назначение:** ориентир для того, кто читает или сопровождает финансовые страницы accrual и должен понимать раскладку, инварианты и последствия флага.
- **Не для:** пошаговой инструкции по переключению флага на проде (её нет — см. бэклог #3), сверки с банком (это кассовая вкладка и ДДС), и трактовки как API-контракта — сигнатуры методов смотреть в самих сервисах.

## Maintenance & sunset plan (Сопровождение и вывод из эксплуатации)

- **Триггеры правки субъекта:** изменение алгоритма раскладки, смена дефолта любого из флагов признания, новый механизм атрибуции (константа в `BlockMonthRecognition`), новые производные от ОПиУ (EBITDA, фонды прибыли, KPI).
- При каждой правке субъекта — бампить «Last updated» в нём и тикать соответствующую строку бэклога здесь.
- **Sunset:** документ уходит в архив только если подсистема accrual будет заменена или удалена; до тех пор — активный ground-truth.

## Deprecation status (Статус)

`active`

## Related documents (Связанные документы)

- [`docs/revenue-recognition.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/revenue-recognition.md) — субъект
- [H258 (архив)](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H258-Opus_Systema-Sanscriticum_revenue_recognition_accrual_06.07.26.md) — исходный хендофф accrual
- [`config/revenue.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/revenue.php) — флаги `revenue.reverse_unrecognized_on_refund` и `revenue.recognition_stamped_block_run_guard`
- [`app/Services/BlockMonthRecognition.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockMonthRecognition.php) — общий алгоритм атрибуции обоих потребителей
- [`app/Console/Commands/AuditRecognitionAttribution.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/AuditRecognitionAttribution.php) — читающая перепись атрибуции и дельта ЗП
- [`app/Console/Commands/BackfillCourseBlockDates.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/BackfillCourseBlockDates.php) — пишущая половина того же правила (отказ записывать вырожденную дату)
- [`app/Services/RevenueRecognitionService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueRecognitionService.php) — алгоритм раскладки
- [`app/Services/RevenueScheduleService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/RevenueScheduleService.php) — персистентность и своды

## Revision history (История ревизий)

| Дата | Изменение | Модель |
|---|---|---|
| 13-07-2026 | метадок создан (H890) | Opus 4.8 claude-opus-4-8 |
| 02-09-2026 | H3951: бэклог #1 закрыт (алгоритм унифицирован), добавлены #6–#7, оговорки о стороже штампованного прогона | Opus 5 claude-opus-5 |

_Dr. Mārcis Gasūns_
