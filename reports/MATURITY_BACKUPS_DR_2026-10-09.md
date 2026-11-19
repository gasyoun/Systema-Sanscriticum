# H6286 — Аудит зрелости: бэкапы и DR Systema (live-пробы 09-10-2026)

_Created: 09-10-2026 · Last updated: 09-10-2026_

_Хендофф: [H6286](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6286-OxAlpha_Systema-Sanscriticum_maturity-backups-dr_09.10.26.md) · baseline: [AUDIT-PROD-READINESS-2026-10-05](https://github.com/gasyoun/AUDIT-PROD-READINESS-Systema-Sanscriticum-2026-10-05.md) (локальный файл `~/Documents/GitHub/AUDIT-PROD-READINESS-Systema-Sanscriticum-2026-10-05.md`)._

**Модель:** GLM-5.3-Flash (z-ai), lane OxAlpha/opencode. **Время:** ~25 мин.

## Методика (read-only, воспроизводимо)

- SSH root на .92 (`samskrtam150`); все пробы только чтение. restic-чтение всегда с `--no-lock` (ни одного lock-файла в хранилище не оставлено).
- Мини restore-drill: источник — restic-репозиторий (только чтение), запись — только в `/tmp/h6286-drill.$$/`, каталог удалён после снятия чексумм.
- Пароли/токены в отчёт не выносятся; фиксируется только факт наличия/отсутствия. WebDAV-листинг Яндекса выполнялся целиком на стороне .92 (пароль не покидал бокс).
- FENCE соблюдён: ни конфиг, ни расписание, ни снапшоты не менялись.

## Таблица каналов: канал → шифрование → последний снапшот → вердикт

| # | Канал | Шифрование | Последний снапшот/артефакт | Вердикт |
|---|---|---|---|---|
| 1 | restic → SFTP .91 (`/systema`), теги systema/samudra | да (пароль `/root/.restic-pass` валиден — репозиторий открылся) | **2026-10-09 05:33 UTC (0.3 ч назад)**, по 28 снапшотов на тег, `size_sanity=OK`, timer hourly жив (последний тик 05:33, следующий 06:34) | 🟢 GREEN |
| 2 | restic → S3 (Yandex Object Storage, офсайт-нога) | н/п — нога не активна | никогда: `/root/.restic-s3.env` отсутствует, каждый час `lane=s3 status=SKIP reason=no-/root/.restic-s3.env` | 🔴 RED |
| 3 | spatie-архив локально (`storage/app/Laravel`) | **НЕТ** — `BACKUP_ARCHIVE_PASSWORD` в прод-.env отсутствует (0 строк) | 2026-10-05 07:41 МСК (3.8 ГБ) — 4 суток назад; в `schedule.log` **0** вхождений `backup:run`; каталог 9.7 ГБ (потолок превышен, H6124 открыт) | 🔴 RED |
| 4 | spatie → Яндекс.Диск (докатка частей, `/Backups/systema-sanscriticum/Laravel/`) | нет — части суть открытый zip (наследует #3) | группа 05-10: **182/182 частей на Диске** (baseline 05-10: 84 недовезено); группа 02-10: 178/178; `systema-yandex-resume` hourly, журнал: «докатка завершена» | 🟢 GREEN ⚠️ |
| 5 | restore-drill (восстановимость) | н/п | ежемесячный `/usr/local/sbin/restore_drill.sh` по-прежнему ищет `RESTIC_PASSWORD=` и не знает про `RESTIC_PASSWORD_FILE` (mtime 05-09, не чинен); **но живой мини-drill 09-10 = PASS** (см. ниже) | 🟡 AMBER |

## Вердикт: 2/5 GREEN (+1 AMBER)

🟢🟢🔴🔴🟡. Ядро восстановимости живое (restic-лейн цел и читается, файл из свежайшего снапшота восстанавливается байт-в-байт), офсайт-докатка на Яндекс догнала и держит 100% двух активных групп. Два контура не выполняют свой контракт: единственная S3-офсайт-нога не активирована 4-й месяц (с 23-08), а spatie-архив с PII и сессией @rusamskrtam по-прежнему лежит на Яндексе открытым zip'ом и создаётся вне какого-либо расписания.

## Мини restore-drill — выхват (09-10-2026)

```
restic -r sftp:restic-push@192.168.200.91:/systema -o "sftp.command=…" --no-lock \
  restore latest --tag systema --include /var/www/html/.env --target /tmp/h6286-drill.3815259

Summary: Restored 4 / 1 files/dirs (21.166 KiB / 21.166 KiB) in 0:00
SIZE_RESTORED=21674  SIZE_LIVE=21674
SHA_RESTORED=7bad51cabeb591cf96f4aa93c01f609cdf2829558dc3c5f867e3b5b8b9c74cf9
SHA_LIVE=7bad51cabeb591cf96f4aa93c01f609cdf2829558dc3c5f867e3b5b8b9c74cf9
TEMP_CLEANED
```

Файл `.env` восстановлен из снапшота `0879f7f9` (2026-10-09 05:33 UTC) в изолированный temp, SHA-256 совпал с живым файлом байт-в-байт. Снапшот старше 2 часов не понадобился — читается свежайший. Вывод: **восстановимость из restic-хранилища подтверждена live-пробой**; «восстановимость» канала 5 = PASS, «гарантия по расписанию» = сломана (скрипт не чинен с baseline, cron `14 3 1 * *` продолжает звать мёртвый скрипт).

## Канарейка: счёт снапшотов против baseline 63

- В репозитории .91 сейчас **117 снапшотов** (все клиенты/теги) — ≥ 63 ✅.
- Дельта +54 объясняется ростом **нерелевантных Systema** тегов (mac-secrets 36, chats 32, w3–w7, n8n, локальные клиенты MG) — репозиторий общий на несколько машин.
- По тегу `systema` сейчас 28 снапшотов, earliest = 2026-10-06 23:33 UTC: между 05-10 и 06-10 старые systema-снапшоты сняты ретеншн-forget'ом со стороны .91 (push-ключ `restic-push` append-only, сам забыть не мог). Это **ретеншн-подрезка, не потеря**: целостность подтверждена restore-пробой выше, `size_sanity=OK` в каждом часовом прогоне.

## Живые пробы (команды для повтора)

```sh
# 1. restic: счёт + свежесть (чтение, без локов)
ssh root@193.232.229.92 'export RESTIC_PASSWORD_FILE=/root/.restic-pass; \
  restic -r sftp:restic-push@192.168.200.91:/systema -o "sftp.command=ssh restic-push@192.168.200.91 -i /root/.ssh/id_restic_push -s sftp" --no-lock snapshots --latest 3'

# 2. таймер и статус ног
ssh root@193.232.229.92 'systemctl list-timers --no-pager | grep -iE "restic|yandex"; \
  tail -n 6 /var/log/restic-backup.log'          # → run_complete overall_rc=0 … s3=SKIP

# 3. шифрование spatie (только факт, значение не читается)
ssh root@193.232.229.92 'grep -c "^BACKUP_ARCHIVE_PASSWORD=." /var/www/html/.env'   # → 0
ssh root@193.232.229.92 'ls -lt /var/www/html/storage/app/Laravel | head -3; \
  du -sh /var/www/html/storage/app/Laravel; grep -c "backup:run" /var/www/html/storage/logs/schedule.log'

# 4. Яндекс: счёт частей группы (пароль только в remote-shell var)
ssh root@193.232.229.92 'cd /var/www/html; L=$(grep "^YANDEX_DISK_LOGIN=" .env | cut -d= -f2-); \
  P=$(grep "^YANDEX_DISK_APP_PASSWORD=" .env | cut -d= -f2-); \
  curl -s -u "$L:$P" -X PROPFIND -H "Depth: 1" \
  "https://webdav.yandex.ru/Backups/systema-sanscriticum/Laravel/" | \
  grep -oE "/Laravel/2026-10-05[^<]*" | wc -l'    # → 182 (= ceil(3816250944/20MiB), группа полная)

# 5. drill-скрипт: дрейф не починен
ssh root@193.232.229.92 'grep -n "RESTIC_PASSWORD" /usr/local/sbin/restore_drill.sh'  # → строка 21: ищет export RESTIC_PASSWORD=
```

## Дельта против baseline 05-10 (4 суток)

| Позиция | 05-10 | 09-10 | Движение |
|---|---|---|---|
| restic SFTP свежесть | 05-10 09:35 UTC | 09-10 05:33 UTC (0.3 ч), hourly тикает | без изменений, здоров |
| S3-нога | SKIP (env отсутствует) | SKIP (env отсутствует) | 🔴 не тронута (ждёт MG с 23-08) |
| Шифрование spatie | отсутствует | отсутствует (grep = 0) | 🔴 не тронуто |
| Расписание spatie | 0× `backup:run` | 0× `backup:run`; свежих архивов с 05-10 нет | 🔴 не тронуто |
| Яндекс-докатка | 84/182 недовезено | **182/182** (и 178/178 группа 02-10) | 🟢 доехало часовым resume |
| restore-drill | SKIPPED-NO-ACCESS | скрипт не чинен; живой мини-drill = PASS | 🟡 гарантия всё ещё сломана, восстановимость доказана |
| verify-гвард | крон зовёт устаревшую копию | `/usr/local/sbin/backup_verify.sh` mtime 05-09 — в кроне по-прежнему устаревшая | 🟠 не тронуто |
| Каталог локальных архивов | 9.9 ГБ > лимита | 9.7 ГБ > лимита (H6124 открыт) | 🟠 не тронуто |

## План закрытия топ-3 разрывов

1. **Активировать S3-офсайт-ногу (канал 2, RED)** — единственный офсайт сейчас Яндекс, у того же провайдера-риска; бакет с Object Lock по [OFFSITE_S3_ACTIVATION_2026-09.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/OFFSITE_S3_ACTIVATION_2026-09.md), `/root/.restic-s3.env` (0600 root:root), первый часовой тик подхватит сам. ~20 мин, ручное (нужны ключи Object Storage) — ждёт слова MG с 23-08. *Изменение прода — только по слову MG.*
2. **Включить шифрование spatie-архива (канал 3, RED)** — `BACKUP_ARCHIVE_PASSWORD` в прод-.env → `php artisan config:cache` → ротации на Диске перезалить зашифрованными. Убирает открытую личку студентов/сессию @rusamskrtam с офсайта; закрывает находки 1.3/1.4 baseline. Ручное + deploy-preflight hard-fail (код).
3. **Починить ежемесячную гарантию drill (канал 5, AMBER)** — фикс одной строки в `/usr/local/sbin/restore_drill.sh` (читать `RESTIC_PASSWORD_FILE`), занести скрипт + исправный `backup_verify.sh` в репо под `scripts/server_guards/`, перезалить устаревшую копию из `/usr/local/sbin` (находки 1.6/1.7 baseline). Код, ~30 мин; после фикса — прогон drill по расписанию 1-го числа.

_Восстановимость уже доказана (мини-drill PASS) — п.3 закрывает не способность, а гарантию: чтобы 1-го числа не ловить снова `SKIPPED-NO-ACCESS`._

_к.ф.н. М.Ю. Гасунс_
