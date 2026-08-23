> **SUPERSEDED 23-08 поздним вечером:** актуальный комплект под Debian 13 - [ops/migrate/RUNBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/RUNBOOK.md) с исполняемыми скриптами `ops/migrate/debian13/*.sh`. Этот документ оставлен как черновик-первоисточник (Ubuntu-формулировки).

# Аварийный переезд Systema на новый VPS — механический runbook

_Created: 23-08-2026 · Контекст: null-route публичного IP `.92` у ООО «Пудлинк», Артём в отпуске
до конца сентября. Данные верифицированы ([BACKUPS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/BACKUPS.md)):
restic на `.91` (`/srv/restic/systema`, SFTP по LAN `192.168.200.91`), снапшоты почасовые,
restore-проверка 19-08 прошла побайтово._

Состав снапшота (см. `ops/backup/systema-restic-run.sh`): **дамп MariaDB `laravel`**
(`mariadb-dump --single-transaction --routines --triggers --events`) + **`storage/app`** +
**`.env`** + **`/etc/nginx`**. Код приложения живёт в этом приватном репо GitHub.
Стек: PHP 8.3 (composer.json `^8.3`), MariaDB, nginx, Laravel-очереди, cron
(в т.ч. `*/30 systema-auto-deploy-run.sh`).

## Фаза 0 — что должно быть у MG до старта (~30 мин, можно уже сейчас)

| # | Действие | Где |
|---|---|---|
| 0.1 | **Понизить TTL A-записи samskrte.ru до 300** (сейчас ~21 600 c ≈ 6 ч) | reg.ru → домен → DNS |
| 0.2 | Выбрать нового хостера и оплатить VPS: **≥16 GB RAM, ≥80 GB NVMe, Ubuntu 24.04**, локация RU (money row) | любой |
| 0.3 | Restic-пароль репозитория из менеджера паролей (критерий 0.2 ещё не подтверждён!) | менеджер паролей |
| 0.4 | Deploy key GitHub (read-only) для приватного репо: публичный ключ новой VM → repo Settings → Deploy keys | github.com/gasyoun/Systema-Sanscriticum |
| 0.5 | Остаток Волны 1 (~20 мин): бакет Yandex Object Storage с Object Lock + PutObject-only ключ; `/root/.restic-s3.env` на НОВОМ боксе сразу после создания ([инструкция](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/backup/RUN_LOG_H3175_WAVE1_23-08-2026.md)) | console.cloud.yandex.ru |

## Фаза 1 — голый бокс → базовая система (~40 мин)

```bash
# 1.1 Пакеты (версии под composer.json ^8.3)
apt update && apt -y install nginx mariadb-server php8.3-fpm php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis \
  redis-server unzip git restic certbot python3-certbot-nginx mariadb-client
# Composer 2:
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 1.2 Пользователь и каталог
adduser --disabled-password --gecos "" deploy
install -d -o deploy -g deploy /var/www/html

# 1.3 SSH: ключ MG + LAN-доступ с .91 (нужен для фазы 2)
install -d -m700 /root/.ssh   # authorized_keys << ключ(и) MG
```

## Фаза 2 — данные из restic (~30 мин)

Репозиторий недоступен извне — копируем каталог репозитория с `.91`
(~19 GB): `scp -r root@193.232.229.91:/srv/restic/systema /srv/restic/systema`

```bash
export RESTIC_REPOSITORY=/srv/restic/systema
export RESTIC_PASSWORD='<пароль из менеджера 0.3>'
restic snapshots                                  # выбрать последний целостный
restic restore latest --target /tmp/restore
# В снапшоте: дамп laravel (*.sql[.gz]), storage/app/**, .env, etc/nginx/**
# Разложить: storage/app → /var/www/html/storage/app ; .env → /var/www/html/.env
#   (chmod 640, owner deploy) ; etc/nginx → сверкой в фазу 4.
```

## Фаза 3 — код и приложение (~30 мин)

```bash
sudo -u deploy git clone git@github.com:gasyoun/Systema-Sanscriticum.git /var/www/html/app
cd /var/www/html/app && sudo -u deploy composer install --no-dev --optimize-autoloader
# .env УЖЕ восстановлен из снапшота (APP_KEY внутри!) — править только DB_* под новую машину
# и при желании включить институтские флаги как на старом боксе.
php artisan migrate --force && php artisan config:cache && php artisan route:cache
php artisan storage:link
chown -R deploy:www-data storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache
```

## Фаза 4 — сервисы (~30 мин)

1. **MariaDB**: создать БД/юзера из `.env` (`DB_DATABASE=laravel`, `DB_USERNAME`,
   `DB_PASSWORD`), импорт дампа из фазы 2.
2. **nginx**: восстановленный `/etc/nginx` из снапшота; поправить пути до app-каталога;
   `nginx -t && systemctl reload nginx`.
3. **PHP-FPM**: пул на сокет, `systemctl enable --now php8.3-fpm`.
4. **Очереди**: systemd-unit `laravel-worker.service`
   (`php artisan queue:work --tries=3 --backoff=5`) — стандартный юнит Laravel-деплоя.
5. **Cron root** (перенести со старого бокса; был виден в triage 23-08):
   `*/30 systema-auto-deploy-run.sh`; таймеры restic (`restic-backup.timer`,
   `restic-forget.timer`) + скрипты `ops/backup/*.sh` → `/usr/local/sbin/`.
6. **TLS** (после фазы 6): `certbot --nginx -d samskrte.ru -d www.samskrte.ru`.

## Фаза 5 — проверка ДО переключения DNS (~20 мин)

`curl -H 'Host: samskrte.ru' http://<NEW_IP>/health` · логин тестового юзера в кабинет ·
`GET /mecenaty` (форма доната живая) · урок с видео (записи = storage/app) ·
`php artisan about` без красных строк.

## Фаза 6 — переключение (~5 мин + распространение DNS)

reg.ru → A-запись samskrte.ru → `<NEW_IP>` (TTL уже 300 с фазы 0.1). Через 5–10 минут —
certbot, затем полный smoke.

## Фаза 7 — после переезда (не забыть!)

- [ ] Webhooks внешних систем привязаны к ДОМЕНУ (Точка, PayPal) — перенастройка не нужна.
- [ ] Better Stack: обновить адрес мониторинга/агент.
- [ ] `.91` остаётся backup-target: обеспечить новому боксу путь к `192.168.200.91`
      или пробросить ключ `restic-push`.
- [ ] Offsite S3 leg (фаза 0.5): первая проверка `systema-restic-s3-verify.sh`.
- [ ] Старый .92: если Pudlink отпустит IP — cold standby неделю, потом гасить.

_Исполнитель восстановления: ox-alpha или любой агент с SSH-ключом MG на новом боксе._
_Dr. Mārcis Gasūns_
