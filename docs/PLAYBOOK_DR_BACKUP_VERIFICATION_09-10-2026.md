# PLAYBOOK — регулярные DR-учения и бэкап-верификация (restic + spatie)

_Created: 09-10-2026 · Last updated: 17-11-2026_

**Аудитория:** ops/агенты. **Когда:** плановая проверка «бэкап восстанавливается» (ежедневный чек + ежемесячный cold-drill). Это НЕ аварийный переезд — переезд = [ops/migrate/RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/RUNBOOK.md) (kit debian13, прецедент Aeza 23-08: голый бокс → прод за вечер).
**Источники:** [RESTORE_DRILL_COLD_2026-08-24](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESTORE_DRILL_COLD_2026-08-24.md) (прецедент drill H3390 PASS), ops/backup/, аудит прод-стойкости 05-10, живые пробы .92 09-10-2026. **Сверка 17-11-2026 (H8085):** датированные ноябрьские доказательства — [H7058_LIVE_PROBE_09-11-2026](https://github.com/gasyoun/Uprava/blob/main/reports/H7058_LIVE_PROBE_09-11-2026.md) (read-only SSH, presence-only), [PREMORTEM_H7058_OFFSITE_2026-11-09](https://github.com/gasyoun/Uprava/blob/main/docs/PREMORTEM_H7058_OFFSITE_2026-11-09.md), GTD-строка `Y8H` в [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md); процедура сверки — [Uprava STANDING_POLICY_DOC_ARCHIVE_FRESHNESS_2026](https://github.com/gasyoun/Uprava/blob/main/docs/STANDING_POLICY_DOC_ARCHIVE_FRESHNESS_2026.md).

## Сверка свежести (H8085, 17-11-2026)

Октябрьские формулировки ниже — **датированная история пробы 09-10-2026, а не текущее состояние сервера**. Ничего здесь не выводится из старого документа: у каждого пункта своя дата доказательства и свой указатель. Процедура (владелец · триггер · дата доказательства · устаревшие утверждения · обработка остатков) живёт в [Uprava STANDING_POLICY_DOC_ARCHIVE_FRESHNESS_2026 §Evidence-based reconciliation](https://github.com/gasyoun/Uprava/blob/main/docs/STANDING_POLICY_DOC_ARCHIVE_FRESHNESS_2026.md); этот раздел — её исполнение на канарейке.

- **Владелец:** Systema-Sanscriticum — текст плейбука; Uprava — процедура сверки; незакрытые остатки — [H7058](https://github.com/gasyoun/Uprava/blob/main/handoffs/H7058-DeepSeek_Systema-Sanscriticum_backup-offsite-retention-encryption_18.10.26.md) / GTD `Y8H`.
- **Триггер ревью:** новое датированное доказательство к любому утверждению ниже; SLA свежести Tier-0/1 (14 дней); закрытие H7058.
- **Дата доказательств сверки:** 09-11-2026 (read-only SSH-пробы; значения `.env` в документы не копировались) + GTD `Y8H`, minted 09-11-2026.
- **Сверка не закрывает нерешённое:** S3-нога и свежий restore-drill остаются открытыми до доказательства.

| Утверждение плейбука (проба 09-10-2026) | Диспозиция 17-11-2026 | Доказательство (дата) | Остаток / владелец |
|---|---|---|---|
| Слой 1: `restic-forget.timer` loaded, **disabled** — retention-чистка не ходит, репо растёт | **RESOLVED HISTORY** — retention переехала на `.91` ещё 13-09; на `.92` таймер disabled **by design** | [H7058_LIVE_PROBE §Retention](https://github.com/gasyoun/Uprava/blob/main/reports/H7058_LIVE_PROBE_09-11-2026.md): `.91` active/enabled, 06:40 UTC ежедневно, тики 08-11 и 09-11 `Finished`, `lane=forget-host91 status=OK`; репо 39 снапшотов, oldest 2026-08-24, newest 2026-11-09T15:38:27Z | **не включать `.92` forget** — прямой запрет GTD `Y8H` |
| Слой 2 `*** GATE ***`: **нешифрованный архив на Яндексе**, `BACKUP_ARCHIVE_PASSWORD` в `.env` отсутствует (P1) | **RESOLVED 02-11-2026** | [H7058_LIVE_PROBE §Archive encryption](https://github.com/gasyoun/Uprava/blob/main/reports/H7058_LIVE_PROBE_09-11-2026.md): ключ присутствует (presence only), проводка через `config/backup.php`; `storage/app/Laravel/2026-11-02-10-04-54.zip` = `AES Encrypted` (предыдущий `2026-10-26-02-05-05.zip` — plain deflate) | ротация пароля без сохранения старого значения делает прежние зипы нечитаемыми — @DECIDE MG, [PREMORTEM §Points of no return](https://github.com/gasyoun/Uprava/blob/main/docs/PREMORTEM_H7058_OFFSITE_2026-11-09.md) |
| Слой 1: `lane s3=SKIP` — `/root/.restic-s3.env` ABSENT, второй оффсайт не заведён | **UNVERIFIED RESIDUAL — не изменилось** | [H7058_LIVE_PROBE §S3 leg](https://github.com/gasyoun/Uprava/blob/main/reports/H7058_LIVE_PROBE_09-11-2026.md) (09-11): лог `lane=s3 status=SKIP reason=no-/root/.restic-s3.env`, сводка `s3=SKIP` | H7058 **NOT PASS**; лестница: bucket+key — MG (H3413) → `/root/.restic-s3.env` → sha256-восстановление из оффсайта; GTD `Y8H` |
| Слой 3: свежая cold-drill не запускалась («дата назначается MG») | **UNVERIFIED RESIDUAL** — последний PASS датирован 24-08-2026 | [RESTORE_DRILL_COLD_2026-08-24](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESTORE_DRILL_COLD_2026-08-24.md) (H3390 PASS); ручное восстановление 23-08-2026 SHA-256 сверено, см. [Uprava BACKUPS.md](https://github.com/gasyoun/Uprava/blob/main/BACKUPS.md) | дата следующего drill — MG (GATE §Слой 3); **оффсайт-восстановление не доказано** |
| Слой 2 проба: оба диска spatie ❌ unhealthy, лимит `BACKUP_MAX_STORAGE_MB` (4.88 GB) | **НЕ ПЕРЕПРОВЕРЕНО в ноябре** — октябрьское чтение остаётся последним известным | ноябрьские источники сверки (H7058_LIVE_PROBE 09-11, [BACKUPS.md](https://github.com/gasyoun/Uprava/blob/main/BACKUPS.md) 15-11) не содержат `backup:list` / `backup:monitor` | не выдавать за текущее; перепроба при следующей ревью |
| Слой 2: `yandex_disk` + докатка `systema-yandex-resume.timer` (тик 09-10 18:10 UTC) | **NOT RE-PROBED** — последний известный тик остаётся 09-10 | — как выше; BACKUPS.md 15-11: «Yandex-нога не проверена» | Yandex-нога не верифицирована с 09-10 |

_Устаревшие утверждения в теле ниже помечены `SUPERSEDED`/`NOT RE-PROBED` со ссылкой на эту таблицу; они сохраняются как история пробы и не переписываются задним числом._

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

**Стоящие раны (проба 09-10):** lane `s3=SKIP` — `/root/.restic-s3.env` ABSENT (второй оффсайт не заведён) **[UNVERIFIED RESIDUAL — 17-11-2026 не изменилось, GTD `Y8H`; см. §Сверка]**; `restic-forget.timer` loaded, но **disabled** — retention-чистка репозитория не ходит, репо растёт **[SUPERSEDED 09-11-2026 — retention переехала на `.91`, там таймер active и тикает; на `.92` disabled by design, не включать; см. §Сверка]**.

## Слой 2 — spatie laravel-backup (data-only: db-dumps + storage/app, БЕЗ vendor — H3390)

- weekly, пн 02:00 → коллекция «Laravel» в `storage/app/Laravel/` (путь-призрак `laravel-backup/` снят 05-10, H3239). Свежесть-лимит weekly — 216 ч.
- Два диска: `local` + `yandex_disk`; докатка недокачанных Yandex-частей — `systema-yandex-resume.timer` hourly (свежий тик 09-10 18:10 UTC: «докатка завершена») **[NOT RE-PROBED — тик 09-10 остаётся последним известным; см. §Сверка]**.
- `*** GATE ***` **Нешифрованный архив на Яндексе:** `BACKUP_ARCHIVE_PASSWORD` в прод-`.env` отсутствует (проба 09-10: есть только `BACKUP_CLEANUP_MB` / `BACKUP_MAX_STORAGE_MB` / `FILESYSTEM_DISK`) — архив с дампом БД лежит на Яндекс-диске без пароля. Риск P1; закрытие (включить шифрование архива + перезалив) — отдельный юнит по визе MG, этим плейбуком не исполняется. **[SUPERSEDED 02-11-2026 — ключ `BACKUP_ARCHIVE_PASSWORD` присутствует, `2026-11-02-10-04-54.zip` = `AES Encrypted`; ротация пароля — @DECIDE MG; см. §Сверка]**

```bash
# рид-онли чек (на .92):
cd /var/www/html && php artisan backup:list
php artisan backup:monitor
journalctl -u systema-yandex-resume --since '-26 hours' --no-pager | tail -5
```

**Проба 09-10:** local — 4 бэкапа, 9.69 GB; yandex — 364 бэкапа, 7.08 GB; newest 4 дня (норма weekly); **оба диска ❌ unhealthy** — превышен лимит `BACKUP_MAX_STORAGE_MB` (4.88 GB). Это не порча данных, но монитор горит красным: чистка/лимит не согласованы — чинить отдельным юнитом. **[NOT RE-PROBED 17-11-2026 — ноябрьские источники не содержат `backup:list`/`backup:monitor`; цифры 09-10 не выдавать за текущие; см. §Сверка]**

## Слой 3 — cold restore drill (раз в месяц, запуск по слову MG)

Полный протокол — [RESTORE_DRILL_COLD_2026-08-24](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESTORE_DRILL_COLD_2026-08-24.md). Скелет:

1. Свежий spatie-zip (или restic-снапшот) на .91 scratch: `/var/scratch-drill/cold-<дата>/`.
2. `db-dumps/mysql-laravel.sql` в изолированную MariaDB (`--skip-networking`, свой datadir, порт 3307; импорт ~9 с на H3390).
3. Money control id-fenced: count + SUM payments cold == live (H3390: 9242 платежа / 26 824 971.78 — byte-parity).
4. Boot: код с .92 (`tar --exclude=bootstrap/cache` — там APP_KEY/секреты!) + archived storage → `artisan about` + `serve :8021` → `GET /` = 200.
5. Teardown: DROP + rm scratch, маркер `TEARDOWN_OK`.

`*** GATE ***` drill ест диск .91 и поднимает scratch-инстансы — запуск по слову MG. Статус первой пробы на 09-10: слои 1–2 проверены рид-онли (run_complete OK, backup:list жив); fresh restore-проба не запускалась — дата назначается MG. **[UNVERIFIED RESIDUAL 17-11-2026 — последний PASS остаётся H3390 24-08-2026, дата не назначалась; оффсайт-восстановление не доказано; см. §Сверка]**

## Сводка PASS/FAIL

| Слой | PASS | FAIL → эскалация |
|---|---|---|
| restic | `run_complete … systema=OK samudra=OK`, возраст ≤ 26 ч | P1 page от верификатора; смотреть sftp-leg в логе |
| spatie | newest ≤ 216 ч, оба диска reachable | unhealthy-storage → юнит на чистку/лимит; докатка молчит > 26 ч → журнал resume-сервиса |
| drill | money-parity + `GET /` 200 + teardown | расхождение денег = немедленно MG |

_Критерии выше — процедура (как читать PASS/FAIL), а не текущее состояние сервера; датированные диспозиции по каждому утверждению — в §Сверка свежести (17-11-2026). Ряд `run_complete` с `s3=SKIP` — последняя известная строка лога 09-10; ноябрьская сводка того же прогона — в [H7058_LIVE_PROBE](https://github.com/gasyoun/Uprava/blob/main/reports/H7058_LIVE_PROBE_09-11-2026.md) (09-11): `restic=OK/OK`, `lane=s3 status=SKIP`, `lane=forget-host91 status=OK`._

_Dr. Mārcis Gasūns_
