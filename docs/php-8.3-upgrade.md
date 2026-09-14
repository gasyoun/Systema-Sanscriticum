_Created: 30-06-2026 · Last updated: 05-09-2026_

# Апгрейд прода PHP 8.1 → 8.3 (чек-лист)

Цель: увести прод с EOL-8.1 на поддерживаемую Laravel 10 версию 8.3, разблокировать
Telegram-аналитику (MadelineProto требует ≥8.2.14) и убрать «платформенную ложь» из
composer.json. Окно простоя — момент переключения FPM (секунды).

Почему 8.3, а не 8.5: Laravel 10 официально тестировался до 8.3. Код проекта проходит весь
тест-сьют и на 8.5 (локальная dev-машина), но 8.5 правильно брать в паре с апгрейдом
Laravel 11/12 — иначе поток депрекейшенов (implicit-nullable, `PDO::MYSQL_ATTR_SSL_CA` и т.п.).

ВАЖНО про порядок: правка composer.json (`platform.php`, `require.php`) и регенерация
composer.lock делаются ТОЛЬКО на шаге 4 — после установки 8.3. Если поднять `require: ^8.3`
и пересобрать lock, пока сервер на 8.1, то `composer install` может подтянуть пакеты,
требующие 8.2/8.3, и они упадут на 8.1 (платформенный чек сейчас отключен и не защитит).

---

## 0. Предполетная фиксация (записать ДО изменений — пригодится для отката)

```bash
php -v
php -m                                   # ← список расширений, который надо воспроизвести на 8.3
php-fpm8.1 -v 2>/dev/null; ls /etc/php/  # какие версии стоят
grep -rn fastcgi_pass /etc/nginx/ | grep -v '#'   # текущий fpm-сокет
grep -rn "command=" /etc/supervisor/conf.d/ | grep -i horizon  # команда Horizon
php artisan --version
```
Сделать бэкоп БД (`php artisan backup:run` или дамп) и зафиксировать текущий git-коммит.

## 1. Установить PHP 8.3 + расширения (ppa:ondrej/php, Ubuntu)

Набор подобран под фактический `php -m` прода (8.1). PECL-расширения (imagick, redis, igbinary)
ставятся отдельными пакетами — без них сломаются картинки и Redis-кэш (igbinary-сериализатор).

```bash
add-apt-repository ppa:ondrej/php -y && apt update
apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-common \
  php8.3-mbstring php8.3-xml php8.3-xsl php8.3-curl php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-mysql php8.3-gd php8.3-readline \
  php8.3-redis php8.3-imagick php8.3-igbinary
```
Бандл-расширения вашего прода (`ctype, exif, fileinfo, ftp, gettext, iconv, pcntl, posix, sockets,
sodium, shmop, sysvmsg/sem/shm, calendar, ffi, filter, hash, json, openssl, tokenizer, zlib, Phar`)
идут в `php8.3-common`/CLI — отдельные пакеты не нужны. `gmp` на проде НЕ установлен — не ставим.

Сверка (набор 8.3 не должен быть уже 8.1):
```bash
diff <(php -m) <(php8.3 -m)
```
Любое расширение, оставшееся «только в 8.1», — доставить (`apt install php8.3-<ext>`).

## 2. Настройки php.ini (перенести из 8.1)

Сверить и перенести в `/etc/php/8.3/fpm/php.ini` (и cli):
`memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `date.timezone`,
OPcache (`opcache.enable`, `opcache.validate_timestamps` — у вас, судя по инциденту, `0`,
поэтому деплой требует reload fpm), `max_input_vars` (для больших Filament-форм).

```bash
diff /etc/php/8.1/fpm/php.ini /etc/php/8.3/fpm/php.ini
```

## 3. Переключить CLI / FPM / nginx / Horizon

Факт прода: nginx — ДВА сайта с сокетом 8.1 (`sites-available/sanskrit` строка 21 и `default`
строка 21, путь `/var/run/php/php8.1-fpm.sock`); Horizon в supervisor использует «голый» `php`
(`command=php /var/www/html/artisan horizon`).

```bash
update-alternatives --set php /usr/bin/php8.3      # CLI → 8.3
php -v                                              # проверить

systemctl enable --now php8.3-fpm

# nginx: заменить сокет в ОБОИХ сайтах (sanskrit обязательно; default — обновить или отключить).
#   fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
sed -i 's#php8.1-fpm.sock#php8.3-fpm.sock#' /etc/nginx/sites-available/sanskrit /etc/nginx/sites-available/default
nginx -t && systemctl reload nginx

# Horizon: команда уже «php ...», поэтому после update-alternatives рестарт сам поднимет 8.3.
supervisorctl restart all
```
> ВАЖНО — путь приложения: supervisor указывает на `/var/www/html/artisan`, а в трейсах ошибок
> фигурировал `/root/sanskrit-lms/...`. Перед апгрейдом подтвердить, какой каталог реально
> обслуживает nginx (`root` в `sites-available/sanskrit`) и где крутится Horizon — все команды
> деплоя/кэша (шаги 4, 6) выполнять именно в этом каталоге. Если это симлинк — ок, но проверить.

## 4. composer.json: убрать платформенную ложь (ТОЛЬКО на 8.3)

В `config` сейчас:
```json
"config": {
    "platform-check": false,
    "platform": { "php": "8.2.12", "ext-pcntl": "8.1.2", "ext-posix": "8.1.2" }
}
```
Поменять на честные значения целевой версии (или удалить блок `platform` целиком, тогда
composer возьмет реальный PHP):
```json
"config": {
    "platform-check": false,
    "platform": { "php": "8.3.0" }
}
```
И поднять требование рантайма:
```json
"require": { "php": "^8.3", ... }
```
Затем на сервере (уже под 8.3):
```bash
composer update --no-dev -o      # пересоберет lock честно под 8.3
```
> Эту правку коммитим и деплоим строго в это окно, не раньше (см. «ВАЖНО про порядок»).

## 5. (опционально) Включить Telegram-аналитику

Теперь PHP подходит под MadelineProto:
```bash
composer require danog/madelineproto:^8.7
```
Затем env: `TELEGRAM_SUPPORT_ENABLED=true`, `TELEGRAM_SUPPORT_API_ID/HASH`, и разово
авторизовать MTProto-сессию (интерактивный вход — код из Telegram). Аккаунт — отдельный
служебный номер, не личный куратора (юзербот читает все диалоги аккаунта).

## 6. Прогреть кэши и перезапустить

```bash
composer dump-autoload -o
php artisan optimize:clear
php artisan optimize
php artisan filament:optimize-clear
systemctl reload php8.3-fpm     # OPcache
php artisan horizon:terminate
```

## 7. Смоук-проверка (то, что тесты на sqlite не ловят)

- Сайт открывается (`curl -I https://samskrte.ru/` → 200), вход в `/admin`.
- Filament-страницы рендерятся; сохранение урока/расписания/сертификата (без 500).
- Платежный вебхук Точки и Telegram-вебхук кабинетного бота принимают POST.
- Redis-кэш: проверить раздел с числовыми кэшами (известный баг — RedisStore отдает число
  строкой при типизированном возврате; на 8.3 поведение то же, просто убедиться, что не регресс).
- Горизонт жив (`/horizon`), очереди обрабатываются.
- Права на `storage/` и `bootstrap/cache` (иначе «View not found»).
- Логи: `tail -f storage/logs/laravel.log` — нет фаталов; депрекейшены на 8.3 минимальны.

## 8. План отката (если что-то фатально)

Старый PHP 8.1 остается установленным, поэтому откат — это вернуть 3 указателя:
```bash
update-alternatives --set php /usr/bin/php8.1
# nginx: вернуть fastcgi_pass на php8.1-fpm.sock; nginx -t && systemctl reload nginx
# supervisor: вернуть php8.1 в command=; supervisorctl restart all
git checkout <предыдущий-коммит> -- composer.json composer.lock   # если успели поменять
composer install -o
systemctl reload php8.1-fpm
```

## Риски (кратко)
- Депрекейшены (на 8.3 — немного; на 8.4/8.5 — много) могут засорять лог; при шуме поднять
  log level до `error` / убрать `E_DEPRECATED`.
- Несовпадение расширений — закрывается сверкой `php -m` (шаг 1).
- Преждевременная правка composer.json на 8.1 — закрывается порядком (шаг 4).
- Окно простоя на переключении FPM — закрывается планом отката (8.1 не удаляем).

_Dr. Mārcis Gasūns_
