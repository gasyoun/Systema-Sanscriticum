# Playbook: аварийный переезд Systema на новый VPS (Debian 13)

_Created: 23-08-2026 · Подготовлено заранее по распоряжению MG; сам переезд НЕ начат._
_Сценарий-триггер: Пудлинк не снимает null-route публичного IP `.92` за несколько дней_
_(VM жива в LAN, публичный IP чёрен, Артём в отпуске до конца сентября)._

**Всё механически готово**: этот каталог — полный комплект. Скрипты идут в порядке
исполнения, каждый можно запускать отдельно и перезапускать (идемпотентны настолько,
насколько это безопасно); человеческие гейты помечены `*** GATE ***` и не обходятся кодом.

## Кейс 23-08-2026 — реальный прогон (прецедент, один вечер)

Null-route .92 у Пудлинка → по слову MG поднят аварийный хост **Aeza SWEs-1** (1 vCPU / 2 GB,
Debian 13, 178.236.251.98, панель my.aeza.ru service 624477). Сделано по этому kit'у:

1. Стек: nginx + PHP 8.3 (sury) + MariaDB 11.8 + redis; очередь systemd-unit; cron schedule:run.
2. Данные: restic-снапшот 17:01 UTC → 1023 пользователя, оплаты целы; .env из бэкапа
   + INSTITUTE_DONATIONS_LIVE=true, PARTNER_PROGRAM_ENABLED=true; миграции актуальны.
3. Smoke HTTP 200: /, /mecenaty (форма + пресеты), /institut, /online, /partners.
4. Не понадобилось/не успели: DNS-flip и certbot (Pudlink снял блочок раньше),
   MadelineProto TG-daemon, kosha/samudra/pe4kinsmart vhosts, Reverb/websockets, /apps-прокси.
5. Итог: хост погашен по слову MG («Aeza использую как VPN»; сервис не продлевать, срок
   истекает 30.08; панель → service 624477). **Вывод: путь «голый бокс → прод» на Aeza
   занимает один вечер; провайдер пригоден как аварийный.** Личный VPN MG на Aeze —
   отдельная услуга, к процедуре отношения не имеет.
## Карта комплекта

| Файл | Что делает | Где запускать |
|---|---|---|
| `debian13/00-preflight.sh` | проверяет источник данных (repo на .91) и готовность нового бокса | обе машины |
| `debian13/01-bootstrap.sh` | Debian 13 → базовая система: пакеты, sury PHP 8.3, users, dirs | NEW box |
| `debian13/02-fetch-restore.sh` | тянет restic-repo с .91 и разворачивает последний снапшот в `/srv/migrate/restore` | NEW box |
| `debian13/03-mariadb-import.sh` | БД/юзер из `.env`, импорт дампа из restore | NEW box |
| `debian13/04-app-deploy.sh` | клон репо, composer, `.env`, storage/app, migrate, кэши, права | NEW box |
| `debian13/05-services.sh` | nginx vhost, php-fpm пул, worker-unit, cron + sbin из `scripts/server_guards/sbin/` | NEW box |
| `debian13/06-verify-cutover.sh` | pre-DNS верификация, затем `--cutover`: certbot + финальный smoke | NEW box |
| `templates/nginx-samskrte.conf` | vhost samskrte.ru (fallback на restore-конфиг при расхождении) | → /etc/nginx |
| `templates/laravel-worker.service` | systemd unit очередей | → /etc/systemd/system |
| `templates/www-migrate.fpm.conf` | php-fpm пул на сокет | → /etc/php/8.3/fpm/pool.d |
| `templates/cron-root` | cron root (auto-deploy */30, watchdog, schedule) | → /etc/cron.d |

## Порядок исполнения (сводка)

```text
MG:  Фаза 0 (деньги/DNS-TTL/restic-пароль/deploy-key/Yandex-бакет) — см. таблицу ниже
new: 01-bootstrap.sh
new: 02-fetch-restore.sh          # ~19 GB с .91
new: 03-mariadb-import.sh
new: 04-app-deploy.sh
new: 05-services.sh
new: 06-verify-cutover.sh         # без --cutover = только проверки
MG:  *** GATE *** слово «переключай» → reg.ru A-запись на NEW_IP
new: 06-verify-cutover.sh --cutover   # certbot + финальный smoke
```

## Фаза 0 — входные данные MG (до старта)

| # | Действие | Где |
|---|---|---|
| 0.1 | TTL A-записи samskrte.ru → **300** (сейчас ~21 600 c ≈ 6 ч) | reg.ru |
| 0.2 | Новый VPS: **Debian 13, ≥16 GB RAM, ≥80 GB NVMe** (money row) | хостер |
| 0.3 | Restic-пароль (`/root/.restic-pass` со старого бокса) | менеджер паролей |
| 0.4 | Deploy key GitHub (read-only) от публичного ключа новой VM | repo Settings |
| 0.5 | Yandex Object Storage: бакет с Object Lock + PutObject-only ключ (~20 мин, остаток [Волны 1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H3175_WAVE1_23-08-2026.md)) | console.cloud.yandex.ru |

## Важные факты, зашитые в скрипты

1. **Restic**: repo = `sftp:restic-push@192.168.200.91:/systema`; физически на .91 это
   `/srv/restic/systema` (копируется rsync'ом, скрипт делает сам). Пароль — файлом.
2. **Состав systema-lane**: `/var/backups/systema/db` (дампы MariaDB `laravel`) +
   `/var/www/html/storage/app` (− `storage/app/Laravel/*`, − `*.log`, − `*/cache/*`) +
   `/var/www/html/.env` + `/etc/nginx`. **APP_KEY уже внутри `.env` — ключ не генерируем.**
3. **Samudra-lane**: `/opt/samudra/db` (SQLite) + `/opt/samudra/corpus` — тот же репозиторий,
   восстанавливается тем же `02-fetch-restore.sh` (путь `/opt/samudra`).
4. **PHP 8.3 именно**: Debian 13 несёт PHP 8.4 — `01-bootstrap.sh` подключает sury.org и
   ставит строго 8.3.* (паритет с prod composer.json `^8.3`).
5. **Cron/sbin**: все серверные скрипты версионированы в репо
   ([scripts/server_guards/sbin/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards/sbin)) —
   копируются из репо, старый бокс для этого не нужен.
6. **Webhooks** Точки/PayPal привязаны к домену — при переключении DNS перенастройка не нужна.

## Откат

До фазы «GATE» откат не нужен (старый бокс продолжает быть единственным продом).
После GATE: вернуть A-запись на `193.232.229.92` (распространение ≤5 мин при TTL 300);
новый бокс гасим. Данные, записанные в новом боксе за окно теста, при откате теряются —
поэтому GATE даём только после зелёной фазы 5.

_Dr. Mārcis Gasūns_
