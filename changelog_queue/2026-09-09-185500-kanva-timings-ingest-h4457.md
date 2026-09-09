_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4457: Канва — ингестия таймкодов (09-09-2026)

Решение MG 09-09: хранилище таймкодов = Systema; madeline = Telegram на сервере; «найди и не пиши, что их нет».

- **Uprava:** [`tools/kanva_tg_drive_ingest.py`](https://github.com/gasyoun/Uprava/blob/main/tools/kanva_tg_drive_ingest.py) — ssh→sqlite (n8n execution_data на коробке)→извлечение таймкод-блоков → канонический JSON; Madeline-сессии не нужны: история в execution_data, читается ssh'ем.
- **Systema:** миграция `kanva_timings` + модель [`KanvaTiming`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/KanvaTiming.php) (валидация форматов/гэпов) + команда [`kanva:ingest-timings`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/KanvaIngestTimingsCommand.php) (привязка к грамматике: авто только при единственной живой, иначе `--course`; `--dry-run`) + секция «Канва: таймкоды» на [AttendanceDashboard](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/AttendanceDashboard.php) (покрытие грамматик + without-счётчик).
- **Прод-смоук:** миграция жива; dry-run корректно отказал единственной сессии (не грамматика-канва → неоднозначность, обязан `--course`); дашборд: rows=[], without=14 — «механизм жив, ждёт реальных нарезок грамматик».
_Dr. Mārcis Gasūns_
