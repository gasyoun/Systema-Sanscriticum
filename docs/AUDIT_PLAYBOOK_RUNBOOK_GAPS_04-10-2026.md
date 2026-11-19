# Аудит плейбуков и ранбуков: пробелы и устаревшее

_Created: 04-10-2026 · Last updated: 04-10-2026_ · [метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_PLAYBOOK_RUNBOOK_GAPS_04-10-2026.meta.md)

Инвентаризация операторского фонда на 04-10-2026: 4 плейбука + 11 ранбуков ([docs/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs), [ops/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/ops)). Вердикты — по датам `git log` и сверке содержимого со смежными доками; «не найден» = grep по `docs/` (`playbook`, `runbook`, `ssh -R`, `MadelineProto`, тема) не дал отдельного документа. Follow-up заминчен в реестре Uprava.

## Не хватает (новые)

| # | Документ | Пробел | Приоритет |
|---|---|---|---|
| 1 | `RUNBOOK_USERBOT_SESSION_RECOVERY` — восстановление MadelineProto-сессии (`@rusamskrtam`, harvest) | userbot несёт платёжную дисциплину и поддержку (приоритет продукта №1). Отказы разбирались ad hoc: flood/`getPwrChat` peer-db — строка 15-09 в [SERVER_SOFT_ALERT_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md), sync-watchdog — [H4461](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4461-OxAlpha_Systema-Sanscriticum_harvest-sync-watchdog-timeout-0909_09.09.26.md); единого «сессия умерла → шаги» нет. [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) фиксирует только lock-дисциплину (риск `AUTH_RESTART` второго демона) | высокий |
| 2 | `RUNBOOK_BOTS_WEBHOOK_RELAY` — релей вебхуков 103.112.71.201 (ssh -R + setWebhook) | через него идут все 4 бота (лидовый, кабинетный, записи, ops); отказ релея = боты молчат молча. [Карта ботов](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md) — research, [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md) — fail-policy; рестарт релея и перекат сертификата `setWebhook` ранбуком не покрыты | высокий |
| 3 | `RUNBOOK_FEATURE_FLAG_FLIP` — канонический рецепт включения флага | один и тот же рецепт (`.env` → `config:cache` → reload php-fpm → рестарт Horizon-воркеров → смоук) повторно собирается в каждом проходе: [Business-enable](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_TELEGRAM_BUSINESS_ENABLE_2026-09-17.md), interest-form H5066, webchat H5450, hints-digest H5452. Гоча «Horizon-воркеры до `config:cache` молча игнорят флаг» и CSRF 419 на curl-смоуках нигде не канонизированы | средний |

## Обновить (существующие)

| Файл | Обновлялся | Дельта |
|---|---|---|
| [PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026.md) | 02-08 | синхронизировать с [debtors-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md) (05-09): самообслуживание «Мои долги»/перенос даты; решить, входит ли черновой детектор отсрочек (`promise_suggestion_detection_enabled`, default OFF) в кураторский процесс |
| [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md) | 16-08 | дописать Telegram Business endpoint (`verify.tg.business`, секрёт fail-closed, 17-09 — позже матрицы); сверить, есть ли строки MAX и Tochka-рекуррентки (оба в High-Risk списке [AGENTS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AGENTS.md)) |
| [RUNBOOK_TELEGRAM_CABINET_LOGIN_2026-08-28](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_TELEGRAM_CABINET_LOGIN_2026-08-28.md) | 02-09 | написан при флагах OFF; с тех ожив кабинетный бот (привязка `/telegram/connect`). Сверить статус `telegram_cabinet_login*` с продом; если не включено — штамп «не включено» в шапку |
| [TEACHER_PAYROLL_OPERATOR_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_PAYROLL_OPERATOR_PLAYBOOK.md) | 27-09 | минтить отсутствующий `.meta.md`; после мёржа ветки `fix/payroll-gasuns-teacher` (since-override, ждёт открытия PR от MG) — дописать правило в readiness-таблицы |

## Гигиена (не срочно)

- `RUNBOOK_H3184_W4A_NFTABLES_DEFAULT_DENY_WINDOW_27-08-2026` и `RUNBOOK_SYSTEMA_SAMSKRTE_TIER0_W1_MARATHON_28_08` — инцидент-оконные; проставить исход окна (закрыто/архив) в шапку, чтобы не читались как действующие.
- `ops/backup/systema-newbox-restore-runbook.md` уже помечен SUPERSEDED ([ops/migrate/RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/RUNBOOK.md) — актуальный DR); его ссылка на `BACKUPS.md` ведёт в 404 (файл в репо не существует — факт-инвентарь бэкапов живёт в строках 22-08 [SERVER_SOFT_ALERT_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)). Хвосты каскада 22-08 (off-site proof split-upload, `BACKUP_ARCHIVE_PASSWORD`, restic-lane без гварда) — перепроверить и зафиксировать в актуальном DR-доке.

## Проверено — пробелом НЕ является

Email/Senler: [плейбук запуска §4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md) + [ANONS_PUBLISHING_V2 § Senler manual lane](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ANONS_PUBLISHING_V2.md) (оба 04-10). Дожим-каскад: указатель на [персоны куратора](https://github.com/gasyoun/Uprava/blob/main/docs/CURATOR_PERSONAS_SAMSKRTE_25-08-2026.md) в плейбуке §7. Инциденты: [SERVER_SOFT_ALERT_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) (живой журнал, 15-09) + [SERVER_INCIDENT_MANUAL](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_INCIDENT_MANUAL.md). DR: ops/migrate. Деплой: [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) (24-09, включая «деплой без GitHub»).

_Гасунс_
