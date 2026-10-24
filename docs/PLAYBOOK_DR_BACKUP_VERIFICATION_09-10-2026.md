# PLAYBOOK — регулярные DR-учения и бэкап-верификация (restic + spatie)

_Created: 09-10-2026 · Last updated: 09-10-2026_

**Аудитория:** ops/агенты. **Когда:** плановая проверка «бэкап восстанавливается» (ежедневный чек + ежемесячный cold-drill). Это НЕ аварийный переезд — переезд = [ops/migrate/RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/RUNBOOK.md) (kit debian13, прецедент Aeza 23-08: голый бокс → прод за вечер).
**Источники:** [RESTORE_DRILL_COLD_2026-08-24](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESTORE_DRILL_COLD_2026-08-24.md) (прецедент drill H3390 PASS), ops/backup/, аудит прод-стойкости 05-10, живые пробы .92 09-10-2026.

## Слой 1 — restic (primary estate: код + .env + nginx + db-dump + storage)

- hourly: `restic-backup.timer` → wrapper `/usr/local/sbin/systema-restic-run.sh`; lanes `systema` + `samudra` → SFTP-репозиторий `restic-push@192.168.200.91:/systema`; лог `/var/log/restic-backup.log`.
- **Пароль/репо задаются В wrapper** (строки 5–6, `export RESTIC_PASSWORD_FILE` / `RESTIC_REPOSITORY`). В `/etc/default/*` и `/etc/restic.env` их НЕТ — это и есть «RESTIC_PASSWORD_FILE дрейф» аудита 05-10: ручные `restic snapshots` вне wrapper-контекста падают. Для ручных операций — переносить его `export`-строки в своё окружение.
- Надзиратель: `/home/hermes/bin/backup_verify.sh` (daily 03:07 UTC, report-only; свежесть ≤ 26 ч; пропущенный бэкап = P1 page). Утренний вердикт — `/home/hermes/brief/backup_latest.md`.

```bash
# ежедневный чек (рид-онли, .92):
tail -4 /var/log/restic-backup.log
# 09-10-2026: run_complete overall_rc=0 systema=OK samudra=OK sftp=OK s3=SKIP
cat /home/hermes/brief/backup_latest.md
```

**Стоящие раны (проба 09-10):** lane `s3=SKIP` — `/root/.restic-s3.env` ABSENT (второй оффсайт не заведён); `restic-forget.timer` loaded, но **disabled** — retention-чистка репозитория не ходит, репо растёт.

## Слой 2 — spatie laravel-backup (data-only: db-dumps + storage/app, БЕЗ vendor — H3390)

- weekly, пн 02:00 → коллекция «Laravel» в `storage/app/Laravel/` (путь-призрак `laravel-backup/` снят 05-10, H3239). Свежесть-лимит weekly — 216 ч.
- Два диска: `local` + `yandex_disk`; докатка недокачанных Yandex-частей — `systema-yandex-resume.timer` hourly (свежий тик 09-10 18:10 UTC: «докатка завершена»).
- `*** GATE ***` **Нешифрованный архив на Яндексе:** `BACKUP_ARCHIVE_PASSWORD` в прод-`.env` отсутствует (проба 09-10: есть только `BACKUP_CLEANUP_MB` / `BACKUP_MAX_STORAGE_MB` / `FILESYSTEM_DISK`) — архив с дампом БД лежит на Яндекс-диске без пароля. Риск P1; закрытие (включить шифрование архива + перезалив) — отдельный юнит по визе MG, этим плейбуком не исполняется.

```bash
# рид-онли чек (на .92):
cd /var/www/html && php artisan backup:list
php artisan backup:monitor
journalctl -u systema-yandex-resume --since '-26 hours' --no-pager | tail -5
```

**Проба 09-10:** local — 4 бэкапа, 9.69 GB; yandex — 364 бэкапа, 7.08 GB; newest 4 дня (норма weekly); **оба диска ❌ unhealthy** — превышен лимит `BACKUP_MAX_STORAGE_MB` (4.88 GB). Это не порча данных, но монитор горит красным: чистка/лимит не согласованы — чинить отдельным юнитом.

## Слой 3 — cold restore drill (раз в месяц, запуск по слову MG)

Полный протокол — [RESTORE_DRILL_COLD_2026-08-24](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESTORE_DRILL_COLD_2026-08-24.md). Скелет:

1. Свежий spatie-zip (или restic-снапшот) на .91 scratch: `/var/scratch-drill/cold-<дата>/`.
2. `db-dumps/mysql-laravel.sql` в изолированную MariaDB (`--skip-networking`, свой datadir, порт 3307; импорт ~9 с на H3390).
3. Money control id-fenced: count + SUM payments cold == live (H3390: 9242 платежа / 26 824 971.78 — byte-parity).
4. Boot: код с .92 (`tar --exclude=bootstrap/cache` — там APP_KEY/секреты!) + archived storage → `artisan about` + `serve :8021` → `GET /` = 200.
5. Teardown: DROP + rm scratch, маркер `TEARDOWN_OK`.

`*** GATE ***` drill ест диск .91 и поднимает scratch-инстансы — запуск по слову MG. Статус первой пробы на 09-10: слои 1–2 проверены рид-онли (run_complete OK, backup:list жив); fresh restore-проба не запускалась — дата назначается MG.

## Сводка PASS/FAIL

| Слой | PASS | FAIL → эскалация |
|---|---|---|
| restic | `run_complete … systema=OK samudra=OK`, возраст ≤ 26 ч | P1 page от верификатора; смотреть sftp-leg в логе |
| spatie | newest ≤ 216 ч, оба диска reachable | unhealthy-storage → юнит на чистку/лимит; докатка молчит > 26 ч → журнал resume-сервиса |
| drill | money-parity + `GET /` 200 + teardown | расхождение денег = немедленно MG |

_Dr. Mārcis Gasūns_
