_Created: 13-09-2026 · Last updated: 13-09-2026_

# H4648: route-500 watcher `logs:error-watch` + громкий unarmed-канва warn + coverage_partial (ox-alpha, opencode `z-ai/glm-5.3-flash`, 13-09-2026)

Детекционная половина инцидента 10–13.09 закрыта: фатал класса «CI green, прод 500» больше не может молчать днями. [PR #2526](https://github.com/gasyoun/Systema-Sanscriticum/pull/2526) (CI 18/18; merge у MG — product repo).

- **`logs:error-watch`** (`config/logs_watch.php`): скан сегодняшнего daily-лога + вчерашнего до 06:00 UTC (overlap через ротацию), группировка `production.ERROR` по классу исключения + месту из стектрейса, ≥3 одинаковых/час → TG soft в канал пробы (fallback soft→critical→`ADMIN_TELEGRAM_ID`), анти-spam H2335 (sticky до зелёного, reminder 24 ч). Ничего не пишет в прод-данные. Пустой TG-канал = громкий warn-блок, не тихий пропуск (класс слепого пятна H3797).
- **Планировщик:** третья сторож-строка cron рядом с `cabinet:probe` (`systema-watchdog-run.sh "logs:error-watch" logs-watch 120`, `*/15`) — `app-user.crontab` + `WATCHDOG_LOGS_WATCH_*` в `server_guards.conf`, сверка обязательна в `guards:verify`; в `Kernel.php` только примечание (урок H1917). Активация на проде: `scripts/server_guards_apply.sh` после мержа.
- **Loud silent-skip:** пустой `CABINET_PROBE_KANVA_COURSE_ID` → `cabinet:probe` кричит warn-блоком с рукой останова и ставит `coverage_partial` (новая колонка `cabinet_probe_runs`, soft — канал не выгорает); то же при пропущенной student-ветке.
- **Arm-рунбук для MG:** [docs/RUNBOOK_ARM_KANVA_FIXTURE_2026-09-13.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/h4648-drain/docs/RUNBOOK_ARM_KANVA_FIXTURE_2026-09-13.md) (~10 мин: tinker-сидинг курса «Кочергина…»+группы → `.env` → `config:cache` → контрольный прогон → проверка флага). GTD 0b2 обновлён.
- **Тесты:** `LogsErrorWatchTest` 9/9 (burst→TG+state; порог/классы/env-фильтр; пустой канал; sticky; вчера-окно с UTC-cutoff; --dry; нет файлов→warn), `CabinetProbeKanvaFixtureTest` 7/7 (unarmed→warn+флаг; armed→чисто), ServerGuards 59/59.
- **Остаток (после merge):** на .92 `deploy.sh` + `server_guards_apply.sh` + `migrate --force` → `logs:error-watch --dry` чистый прогон + алерт на подложенном логе (GTD @DO 20-09); arm-рунбук исполняет MG.
- [H4648](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4648-OxAlpha_Systema-Sanscriticum_route-500-watch-probe-arm-loud-skip_13.09.26.md) · [постмортем](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_STUDENT_DASHBOARD_FQCN_500_10-13-09-2026.md).
_Dr. Mārcis Gasūns_
