_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4461: telegram-harvest:sync — чекпоинт-по-пиру + бюджет прохода + ротация окна (09-09-2026)

Watchdog дважды в день убивал harvest-sync по 120-с потолку с 27-08 (exit 75, phase-крошка `drain_pending` — протухший хлебный след support-синка на общих ключах сессии, H3411-класс). Причина: файл пиров вырос до 3188 id (19-08) при 233 курсорах, проход пейджил тысячи некурсорных пиров (history_limit 5000), а ingest шёл ОДИН раз после всего цикла — килл терял всё, курсоры не двигались, следующий заход перечитывал тех же пиров.

- **Чекпоинт-по-пиру** ([TelegramHarvestSyncService::fetchIncremental](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramHarvest/TelegramHarvestSyncService.php)): ingest + advance cursor после КАЖДОГО завершённого пира — килл/бюджет-стоп сохраняет всё собранное, следующий проход продолжает от курсоров.
- **Бюджет прохода**: `services.telegram_harvest.sync_budget_seconds` (env `TELEGRAM_HARVEST_SYNC_BUDGET_SECONDS`, 0 = без потолка — тесты/CI в старом поведении); на проде = 100 с внутри 120-с watchdog. Проверка ПОСЛЕ анти-бан задержки — новый пир не стартует на исчерпанном бюджете.
- **Ротация окна**: каждый проход стартует с `sync_state['harvest_next_index']` (записывается после каждого пира, wrap). Без неё окно ~15 пиров никогда не покидало уже прокурсоренную голову списка (прод-проба: счётчик курсоров замер на 233 через четыре зелёных запуска). Явные `--peer` порядок оператора сохраняют.
- **Тесты**: [TelegramHarvestSyncTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/TelegramHarvestSyncTest.php) 14/14 (бюджет-стоп без ошибки; чекпоинт первого пира до стопа; ротация продолжает со сохранённого индекса); Pint clean.
- **Прод**: деплой `0b8ffa08` + `.env` `TELEGRAM_HARVEST_SYNC_BUDGET_SECONDS=100`; ручные прогоны зелёные (`status:ok`), лог бюджета показывает `start_index:0 → next_run_starts_at:15`, ноль watchdog-убийств после деплоя; support:sync деградирует в session_busy на окно прохода и ретраит минутой позже (штатно).
- **Известное ограничение**: маркер ротации двигается и на явных `--peer` прогонах (на +1 за прогон, самокорректируется за оборот); чистка отложена — правка требует удалить строки младше 3 дней (stale-base guard), косметика не стоит human-escape.
_Dr. Mārcis Gasūns_
