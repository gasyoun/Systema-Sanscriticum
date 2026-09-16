_Created: 16-09-2026 · Last updated: 16-09-2026_

# H4879: harvest-ростер getPwrChat флуд мёртвых peer'ов свёрнут в одну summary-строку, allowlist в `logs:error-watch` (Sonnet 5 `claude-sonnet-5`, 16-09-2026)

Nightly `logs:error-watch` TG alerts on prod were driven by WARNING-level noise from the Telegram harvest roster: every roster run re-failed dozens of dead/absent peers (`Telegram harvest roster: getPwrChat failed {"error":"This peer is not present in the internal peer database"}`) — growth 46 → 115 → 134 → 528 → 535 rows/day over 10-14.09.2026.

- **`TelegramHarvestSyncService::fetchGroupRosters`/`fetchRoster`/`pwrRoster`:** dead/absent peers are now counted separately from other `getPwrChat` failures and logged as ONE `Log::info('Telegram harvest roster: getPwrChat summary', [...])` per run (peers_tried/peers_dead/peers_other_failure/rosters_written) instead of one WARNING per peer; genuinely new failures still log a WARNING as before.
- **`config/logs_watch.php`:** new `allowlist_patterns` config (env `LOGS_WATCH_ALLOWLIST_PATTERNS`, `|`-delimited, default includes the peer-not-present substring); `WatchErrorLogs::collectHits` skips any matching raw message BEFORE it enters the hourly spike count — defense-in-depth in case `levels` ever widens to include WARNING.
- **Tests:** `SnapshotGroupRostersTest::test_dead_peers_collapse_to_one_summary_line_not_per_peer_warning`, `LogsErrorWatchTest::test_allowlisted_chronic_message_never_bursts`. Full suites: SnapshotGroupRostersTest 7/7, TelegramZapisiRosterTest 7/7 (no regression on the single-peer command), LogsErrorWatchTest 10/10.
- Independent verification pass (separate no-spawn subagent) re-read the full diff and re-ran all three suites from a clean vendor junction — PASS.
- [H4879](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4879-OxAlpha_Systema-Sanscriticum_tg-harvest-roster-getpwrchat-flood-error-watch-noise_15.09.26.md), [PR #2602](https://github.com/gasyoun/Systema-Sanscriticum/pull/2602) (open — Tier-0 product repo, PR-only, merge left for a human).
_Гасунс_
