_Created: 08-09-2026 · Last updated: 08-09-2026_

# H4403: storage:check — суточная JSONL-серия замеров (OxAlpha z-ai/glm-5.3-flash, 08-09-2026)

Дневной вердикт `storage:check` (04:20) раньше нигде не сохранялся — [H3655](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H3655-OxAlpha_Systema-Sanscriticum_storage-watch-thresholds-calibrate_28.08.26.md) закрылся калибровкой порогов по mtime-распределению именно потому, что «schedule.log не хранит stdout». Открытый [H4291](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4291-OxAlpha_Uprava_storage-watch-calibration-capacity-baseline_07.09.26.md) ждёт до 28-09 завершения месячного окна наблюдения, из которого калибровать — окно прошло бы впустую: команда только алертит при превышении и не оставляет данных. Проверено живьём на проде 08-09-2026: в [Kernel.php](https://github.com/Systema-Sanscriticum/app/Console/Kernel.php) у `storage:check` нет `sendOutputTo/appendOutputTo`, в `storage/logs/` серии нет.

- **Серия ([CheckStorageUsage::appendSeriesLine](https://github.com/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckStorageUsage.php)):** после таблицы и строки про свободное место команда дописывает одну JSONL-строку в `storage/logs/storage_check_series.jsonl`: `at` (ISO8601), `total_bytes`, `total_limit_mb`, `free_disk_bytes` (nullable — off/unknown), `free_disk_level`, `ok` и по-каталожно `path/bytes/limit_mb/ratio/level/truncated`. Байты — сырые int (не человекочитаемая `megabytes()`), чтобы калибровка 28-09 считала без парсинга единиц.
- **`--dry` не пишет:** ручной прогон «посмотреть сейчас» не должен загрязнять суточную серию.
- **Сбой записи не блокирует сторожа:** append в try/catch — `report($e)` + warn, алерт админам уходит в любом случае (серию можно потерять, сторож нет).
- Пороги, расписание, df-сэмплер НЕ тронуты — калибровка и df-базлайн остаются за гейтом H4291 (до 2026-09-28).
- Тесты: `StorageUsageWatchdogTest` +2 (здоровый/алертный прогон пишет ровно одну разборываемую строку с ключами и значениями; `--dry` не создаёт файл), 11/11 зелёные, Pint clean.

_Dr. Mārcis Gasūns_
