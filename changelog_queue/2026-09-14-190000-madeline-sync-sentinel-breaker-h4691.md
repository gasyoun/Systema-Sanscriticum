_Created: 14-09-2026 · Last updated: 14-09-2026_

# H4691 (E002-C2, вторая волна): MadelineSync — watchdog-kill петля под общим предохранителем (Opus 5 `claude-opus-5`, 14-09-2026)

- **Что изменилось.** Каждый post-timeout cooldown в `telegram-support:sync` — это убийство Madeline-демона сторожем; теперь [MadelineSyncPhase::armCooldown](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSyncPhase.php) засчитывает его в общий предохранитель Uprava ([tools/sentinel_breaker.py](https://github.com/gasyoun/Uprava/blob/main/tools/sentinel_breaker.py), класс `self_kill`: 3 в час · 8 в сутки · авто-разморозка через 2 ч). При превышении бюджета `cooldownActive()` возвращает true — живой MTProto-заход пропускается, пока предохранитель не оттает сам; заморозка и разморозка кричат в критический чат `cabinet:probe` новой командой [`guards:breaker-alarm`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SentinelBreakerAlarm.php).
- **Обёртка:** [MadelineSyncBreaker](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSyncBreaker.php) — здоровая сессия (нет `freeze.json`, пустой `actions.log`) не порождает ни одного подпроцесса; отсутствие библиотеки на хосте = fail-open (как до H4691: только короткий cooldown) + `Log::error`. Guardian пер-сессийный (`madeline_sync` + суффикс H3380).
- **Конфиг:** `services.sentinel_breaker` (`SENTINEL_BREAKER_ENABLED`/`_BIN`/`_PYTHON`/`_STATE_DIR`), инвентарь env перегенерирован.
- **Тесты:** [MadelineSyncBreakerTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/MadelineSyncBreakerTest.php) — 7 кейсов (запись kill, тишина здоровой сессии, заморозка пропускает sync, fail-open, доставка крика, opt-in прогон против настоящей библиотеки: 4-й старт после 3 kill в час отказан, `freeze.json` с `guardian_class=self_kill`).

_Гасунс_
