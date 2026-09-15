# TG-харвест ростера перестал заваливать основной лог дохлыми peer'ами (OxAlpha `opencode/z-ai/glm-5.3-flash`, 15-09-2026)

H4879 — «getPwrChat failed … peer not present in the internal peer database» рос 46 → 535
строк/день за 10–14.09.2026 (мёртвые/покинутые группы в ростере) и 15.09 бёрстом 23 строки/2 мин
пересёк порог `logs:error-watch`, который прислал алерт с посторонней устаревшей находкой.

- **Основной фикс:** [`TelegramHarvestSyncService::pwrRoster()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramHarvest/TelegramHarvestSyncService.php)
  теперь классифицирует «peer not present» как known-benign — деталь идёт в отдельный
  daily-канал `telegram_harvest` ([`config/logging.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/logging.php)),
  не в основной лог; батч-метод `fetchGroupRosters()` (команда `telegram-harvest:roster-groups`)
  пишет ОДНУ сводную `Log::info` строку на проход (`peers_tried`/`peers_missing`/`rosters_written`)
  вместо десятков WARNING на каждый мёртвый peer.
- **Вторая линия защиты:** [`logs_watch.excluded_message_patterns`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/logs_watch.php)
  allowlist'ит ту же подстроку в `logs:error-watch`, чтобы известный хронический класс не считался
  всплеском, даже если когда-нибудь всплывёт на уровне ERROR.
- Инцидент задокументирован в [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) «15-09-2026».
- Тесты: `SnapshotGroupRostersTest::test_dead_peers_collapse_to_one_summary_line_not_per_peer_warning`,
  `LogsErrorWatchTest::test_excluded_message_pattern_does_not_count_toward_a_burst`.
