# Деплой — один скрипт, один ритуал

_Created: 02-07-2026 · Last updated: 31-08-2026_

Единственный санкционированный способ выкладки —
[`deploy.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh)
в корне репозитория. Ручные выкладки («git pull и посмотрим») запрещены: именно
они породили класс багов
[#193](https://github.com/gasyoun/Systema-Sanscriticum/issues/193) — прод отдает
страницу в старой разметке, потому что скомпилированные Blade-вьюхи и OPcache
пережили обновление кода.

## Почему без reload php-fpm деплой НЕ работает

На проде OPcache сконфигурирован с `opcache.validate_timestamps=0`
(см. [`docs/php-8.3-upgrade.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/php-8.3-upgrade.md),
шаг 2): PHP-FPM **никогда** не перечитывает изменившиеся файлы сам. Быстро для
продакшена, но означает: после любого обновления кода или пересборки вьюх
обязателен `systemctl reload php{ver}-fpm`. Скрипт делает это автоматически и
падает с ошибкой, если reload не удался.

## Что делает скрипт (по шагам)

1. **Предполет:** каталог приложения, ветка `main`, чистое рабочее дерево.
   Исключения (H2066):
   - `public/docs/*.pdf` (оферта/политика/согласие) — стэш на время pull, `pop` после.
   - Tracked dirty, **уже совпадающий с `origin/main`** (ручной partial hotfix
     будущего commit) — `git checkout HEAD -- <file>`, затем обычный pull.
   - Режим `--rollback` — dirty-gate **пропускается** (`reset --hard` сам
     снимет tracked dirty; иначе автооткат падает на том же preflight).
   Любая другая грязь — отказ деплоить.

   **Что нужно / чего не нужно человеку на проде**

   | Нужно | Не нужно |
   |---|---|
   | Код и тексты — только PR → `main` → auto-deploy / `deploy.sh` | Править tracked `app/` / `config/` руками на VPS (`nano`, `sed`) |
   | Цитата отзыва — env `MARATHON_TESTIMONIAL` / MarketingSetting | Менять testimonial framing в `config/*.php` на сервере |
   | PDF оферты/политики — `public/docs/*.pdf` | Другой tracked dirty «на минутку» |

   **Случай-стоп (01-08-2026):** ручной edit
   [`config/marathon_landing_copy.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon_landing_copy.php)
   → dirty-gate → fuse `storage/auto_deploy.disabled` с `[blocked-preflight]` →
   soft-TG «Кабинет: soft-сбой (guards)» при живом HTTP.
   Полный разбор: [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) ·
   [server-resource-guards.md §8.1](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).
2. `git pull --ff-only origin main` — только fast-forward, никаких мержей на проде.

   **Чем прод аутентифицируется в GitHub (H3799, 31-08-2026, рулинг «б»).**
   `origin` = `git@github.com:gasyoun/Systema-Sanscriticum.git`, ключ
   `/root/.ssh/id_ed25519_github_systema` (ed25519, без пароля, **read-only**
   deploy key репозитория), прибит к хосту блоком `Host github.com` с
   `IdentitiesOnly yes` в `/root/.ssh/config` — у root лежат и другие ключи
   (restic, hermese), и без этой опции SSH успевает получить «Too many
   authentication failures» раньше, чем дойдёт до нужного. Ключи хоста
   github.com взяты из `https://api.github.com/meta` (проверенный TLS-канал),
   а не голым `ssh-keyscan`, чтобы первое подключение не было слепым TOFU.

   Так сделано после инцидента: раньше в URL `origin` был **вшит PAT**, он
   протух и начал отдавать `HTTP 401`, что уронило деплой ровно здесь, на
   `git pull` — прод при этом остался жив и здоров, просто перестал получать
   новый код, и заметно это стало только когда деплой понадобился. Диагностика,
   которая различает «токен умер» и «GitHub лежит»: репозиторий публичный,
   поэтому `git ls-remote https://github.com/gasyoun/Systema-Sanscriticum.git`
   с той же машины работает **без** всякой авторизации — если анонимный запрос
   проходит, а `origin` отдаёт 401, дело в креденшале, а не в GitHub.

   Deploy key **read-only** намеренно: прод только тянет, толкать ему нечего.
   Ротация ключа не нужна (deploy key не истекает), но если он скомпрометирован
   — удалить его в Settings → Deploy keys и повторить процедуру выше.
3. `composer install --no-dev -o` + **`npm ci && npm run build` только если
   изменились asset-пути** (package*/vite/postcss/tailwind/`resources/{js,css}`)
   относительно предыдущего HEAD, или нет `public/build/manifest.json`, или
   `FORCE_NPM=1` (H2104). Docs/PHP-only PR больше не гоняют vite ~25 мин.
   `public/build` в git не хранится — фронт собирается на сервере при нужде.
4. (с флагом `--down`) `php artisan down` на время миграций.
5. `php artisan optimize:clear` + `filament:optimize-clear` (кеш
   Filament-компонентов `optimize:clear` не трогает — без явного сброса новый
   виджет/страница падает `ComponentNotFoundException` на update-запросе)
   → `php artisan migrate --force`.
6. Прогрев: `php artisan optimize` (config/route/view) + `filament:optimize`.
   Then, if deploy is running as root, `chown -R www-data:www-data storage/framework/views`.
   `optimize` writes compiled Blade as root; php-fpm is www-data. Without the
   chown, the next Blade recompile does `touch()` and 500s Filament `/admin`
   (`Utime failed: Operation not permitted`, 17-08-2026). Homepage stays 200.
   The same chown must run **before** `fail` when `cabinet:probe --fail-on-critical`
   exits 1: `fail` is `exit 1`, so a post-probe chown after `|| fail` never
   runs. 19-08-2026 21:01Z left 8 `root:root` compiled views that way; 20-08
   SOS Filament `/admin` 500 until a manual chown (H3194).
7. **`systemctl reload php{ver}-fpm`** — сброс OPcache (версия PHP определяется
   автоматически, переживет апгрейд 8.1 → 8.3).
8. `supervisorctl restart horizon` — `horizon:terminate` на этом проде воркеры
   не циклит (PID-ы не меняются, старый код продолжает крутиться); фолбэк на
   terminate только там, где supervisor отсутствует.
8b. `supervisorctl restart zapisi-poll` — **только если он сейчас `RUNNING`**.
   Это аварийный поллер @zapisi_ORSbot (штатно апдейты приходят вебхуком), и он
   стоит с `autostart=false`. Условие важно: `supervisorctl restart` на
   *остановленной* программе её **запускает**, а запуск поллера снимает вебхук и
   уводит бота с рабочей дорожки. Не `fail`: без демона сайт работает.
9. Смоук: `curl` главной страницы, ожидается 200; иначе скрипт падает.
10. Строка в `storage/logs/deploys.log`: дата, диапазон коммитов, версия PHP, кто.

## Nginx: статика `/lila/` (index.html) + redirect с `/exercises/`

Каталог `public/lila/**` — HTML-тренажёры без Laravel (URL `/lila/`; до 27-07-2026
было `public/exercises/` → `/exercises/`). В vhost
`/etc/nginx/sites-enabled/samskrte.ru`:

1. **`index`** must include `index.html` so directory URLs work:

```nginx
index index.php index.html;
```

2. **Permanent redirect** old bookmarks:

```nginx
location ^~ /exercises {
    rewrite ^/exercises(.*)$ /lila$1 permanent;
}
```

После правки: `nginx -t && systemctl reload nginx`.

## Nginx: `Content-Type` для `manifest.webmanifest`

Прод отдавал `/manifest.webmanifest` как `application/octet-stream`: расширения
`.webmanifest` нет в `/etc/nginx/mime.types`, поэтому срабатывал http-уровневый
`default_type application/octet-stream` из `nginx.conf`. Исправлено 13-08-2026
([scripts/nginx_webmanifest_mime.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/nginx_webmanifest_mime.sh),
идемпотентный, делает бэкап, откатывается сам при `nginx -t` FAIL):

```nginx
location = /manifest.webmanifest {
    default_type application/manifest+json;
}
```

**Почему именно так, а не двумя более очевидными способами.** Правка
`/etc/nginx/mime.types` — это conffile пакета `nginx-common`: она спорит с dpkg на
каждом обновлении и может быть молча откачена. Блок `types { … }` внутри `server`
**заменяет** унаследованную таблицу типов, а не дополняет её (в nginx нет merge-семантики
для `types`, и двух блоков `types` в одном контексте быть не может) — то есть ради одного
типа можно сломать все остальные. Точное совпадение `location =` имеет наивысший приоритет
маршрутизации и несёт только `default_type`, который применяется исключительно когда
расширение не дало типа, поэтому разрешение MIME у остальных файлов не затрагивается;
`root` наследуется от server-блока, файл отдаётся как обычно.

**Это гигиена, а не починка установки PWA.** Chromium к типу манифеста нетребователен:
до этой правки его движок установки (`Page.getInstallabilityErrors`) разбирал манифест с
`manifestErrors: []`, а причиной неустановимости были иконки (см. `## [1.89.17]` в
[CHANGELOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CHANGELOG.md)).
Проверка после правки: `curl -sI https://samskrte.ru/manifest.webmanifest` →
`application/manifest+json`, при этом `image/png`, `image/x-icon`, `text/html` и
`application/javascript` у остальной статики не изменились.

Скрипт не входит в `deploy.sh` — это разовая настройка хоста, которую надо повторить
только при пересборке сервера или переезде vhost.

## Первичная настройка (один раз)

```bash
# на сервере, под root/sudo
cd /var/www/html          # фактический каталог приложения — проверить root в
                          # /etc/nginx/sites-available/sanskrit (см. php-8.3-upgrade.md, шаг 3)
git pull origin main      # получить сам скрипт
chmod +x deploy.sh
```

Переменные окружения (опционально, дефолты в скрипте):

| Переменная | Дефолт | Смысл |
|---|---|---|
| `APP_DIR` | `/var/www/html` | каталог приложения |
| `BRANCH` | `main` | деплоим только main |
| `SMOKE_URL` | `https://samskrte.ru/` | URL смоук-проверки |

### Планировщик (`schedule:run`) — раннер для ВСЕХ scheduled-команд

Ни один `$schedule->command(...)` из
[`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php)
(дебиторка, Telegram-support синк + его healthcheck, напоминания, марафон,
warm-tail и т.д.) не сработает без единственной crontab-строки на сервере:

```cron
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

Это **не отдельный демон/systemd-юнит** — обычный минутный cron-вызов, который
Laravel сам маршрутизирует по `everyMinute()`/`dailyAt()`/`everyFifteenMinutes()`
и т.д. внутри `Kernel.php`. Для Telegram-support раннера это и есть
зафиксированное решение MG (D2,
[telegram-userbot-inventory.md §4](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md#4-единый-раннер-и-риск-двух-демонов)):
cron драйвит `schedule:run` → срабатывает `telegram-support:sync`
(`everyMinute`, под `madeline-session` lock'ом) — отдельный самостоятельный
MadelineProto-скрипт-демон на той же сессии **не держим** (риск `AUTH_RESTART`
от двух демонов на одном аккаунте). Подтверждение, что на проде именно так,
запрошено у Ивана в [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
§Telegram, T1.

## Обычный деплой

```bash
sudo bash deploy.sh
```

С maintenance-окном (когда есть тяжелые миграции):

```bash
sudo bash deploy.sh --down
```

## Проверка после первого прогона (закрытие #193)

```bash
curl -s https://samskrte.ru/online/kursy/grammatika-po-kocerginoi-gr62 | grep -oE 'Коротко о курсе|id="program"|snap-x'
```

Непустой вывод = новая разметка отдается, issue
[#193](https://github.com/gasyoun/Systema-Sanscriticum/issues/193) можно закрывать.

## CI/CD — GitHub Actions → SSH → `deploy.sh` (H1046)

Прежде агент не мог задеплоить вообще — прод-креды были только у Ивана (H478:
это была развязка доступа, а не хостинга — SSH/root на проде есть с самого
начала). [`.github/workflows/deploy.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/deploy.yml)
закрывает разрыв **Опцией A** из
[`SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md`](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md):
Ручной GitHub Actions run по SSH запускает тот же `sudo bash deploy.sh`, что и
раньше запускал Иван руками. Каноническая автоматическая доставка остаётся у
серверного cron; SSH workflow — защищённый ручной путь, а не второй автодеплой.

**Инвариант безопасности сохраняется: у агентов по-прежнему нет прод-кредов.**
SSH-ключ живет только в секретах GitHub Environment `production`; job
запускается на GitHub-раннере, не на машине агента, и ждет approval человека
на КАЖДЫЙ прогон.

### Как работает ручной гейт (MG-confirm)

1. Оператор открывает **Actions → Deploy production → Run workflow**. Push и
   merge в `main` этот SSH workflow не запускают.
2. Run встаёт в **"Waiting"**; ревьюер (MG) открывает его и жмет **Review deployments → Approve and
   deploy**.
3. Только после этого раннер поднимается, SSH'ится на прод и гоняет
   `sudo /var/www/html/deploy.sh` — тот же ритуал (git pull --ff-only, composer/npm,
   migrate, optimize, reload php-fpm, restart horizon, смоук), что описан выше
   в этом файле.
4. Обычный автоматический прод-деплой по-прежнему выполняет серверный cron; этот
   путь нужен для осознанного внепланового прогона.

`concurrency: deploy-production` не дает второму прогону стартовать поверх
незавершенного — второй ручной run ждёт завершения первого.

### Первичная настройка (человек, один раз) — ещё НЕ сделано

Этот PR добавляет только workflow-файл. Ниже — что должен завести человек
(Иван и/или MG) в GitHub и на сервере, прежде чем прогон реально сработает;
до этого ручной run безопасно пропустит SSH-шаг из-за отсутствующих секретов.

**На сервере (Иван, ~15 мин, root):**

1. Создать **непривилегированного** пользователя для деплоя (не `root`
   напрямую): `adduser deploy && mkdir -p /home/deploy/.ssh`.
2. Сгенерировать пару ключей ed25519 **для этого назначения** (не переиспользовать
   личный ключ), публичный — в `/home/deploy/.ssh/authorized_keys`
   (`chmod 600`), приватный отдать в GitHub Environment secret (см. ниже),
   больше нигде не хранить.
3. Узкое правило `sudoers` — **только** этот скрипт, не общий root:
   ```
   deploy ALL=(root) NOPASSWD: /var/www/html/deploy.sh
   ```
   (`visudo -f /etc/sudoers.d/deploy-script`). Это тот же принцип, что H478
   заложил в Option A — деплой-путь узкий, а не "дать агенту root".

**В GitHub (MG, ~10 мин, Settings → Environments):**

1. Создать Environment **`production`**.
2. Добавить **Required reviewers** = MG (сам гейт approval).
3. Добавить секреты Environment (не repo-secrets — они видны всем workflow'ам,
   Environment-секреты видны только job'ам с `environment: production`):
   - `DEPLOY_HOST` — IP/домен прода (`193.232.229.92`).
   - `DEPLOY_USER` — `deploy` (пользователь из шага 1 выше, НЕ `root`).
   - `DEPLOY_SSH_KEY` — приватный ключ из шага 2 (весь PEM-блок, включая
     `-----BEGIN`/`-----END`).

После этого следующий ручной `workflow_dispatch` впервые дойдет до реального
approval-гейта. Push в `main` продолжит обслуживать канонический серверный cron.

## Откат

Скрипт не делает откатов сам (намеренно — откат это решение человека):

```bash
cd /var/www/html
tail storage/logs/deploys.log      # найти прошлый коммит в журнале деплоев
git reset --hard <прошлый-коммит>
sudo bash deploy.sh                # прогонит тот же ритуал на старом коде
```

Миграции назад не откатываются автоматически — при необходимости
`php artisan migrate:rollback --step=N` руками, глядя на конкретные миграции.

## Восстановление из резервной копии (H3181, 19-08-2026)

Резервная копия, которую никто не разворачивал, — это гипотеза. Ниже путь,
проверенный 19-08-2026 на архиве `2026-08-10-02-01-48.zip`.

**Данные людей.** Дамп содержит `users`, `payments`, переписку — персональные
данные учеников. Он не попадает ни в issue, ни в PR, ни на любую публичную
поверхность, а рабочая копия уничтожается сразу после сверки
(`~/.claude/rules/staff-instructions-live-in-the-cabinet.md`).

### Шаг 1. Достать дамп, не разворачивая весь архив (на сервере)

Архив весит 1.4 ГиБ, из них SQL — 91 МиБ. Распаковывать целиком незачем:

```bash
ssh root@193.232.229.92
mkdir -p /root/restore-drill && cd /root/restore-drill
unzip -o -j /var/www/html/storage/app/Laravel/<АРХИВ>.zip \
      db-dumps/mysql-laravel.sql -d /root/restore-drill
gzip -9 -f mysql-laravel.sql          # 91 МиБ → ~10 МиБ
```

### Шаг 2. Целостность — до всякого разворачивания

Три проверки, каждая отвечает на свой вопрос, и все три read-only:

```bash
# а) архив не побился: CRC всех записей
unzip -t /var/www/html/storage/app/Laravel/<АРХИВ>.zip | tail -3
#    ждём: «No errors detected in compressed data»

# б) дамп дописан до конца, а не оборван на середине.
#    mariadb-dump пишет восстановление SET-переменных ТОЛЬКО в самом конце,
#    поэтому наличие этого хвоста и есть доказательство завершённости.
zcat mysql-laravel.sql.gz | tail -3
#    ждём: /*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

# в) сколько таблиц внутри
zcat mysql-laravel.sql.gz | grep -c "^CREATE TABLE"
```

### Шаг 3. Строки в дампе против строк на живой базе

Дамп пишется с ОДНИМ кортежем на строку (не `),(` в одну длинную строку), так
что строки считаются построчно:

```bash
for t in users payments lessons; do
  n=$(zcat mysql-laravel.sql.gz | sed -n "/^INSERT INTO \`$t\` VALUES/,/;\$/p" | grep -c "^(")
  echo "$t: $n"
done

# живые значения, для сравнения
cd /var/www/html && sudo -u www-data env HOME=/tmp php artisan tinker --execute="
foreach ([\"users\",\"payments\",\"lessons\"] as \$t) { echo \$t.': '.DB::table(\$t)->count().PHP_EOL; }"
```

Замер 19-08-2026 (архив от 09-08, живая база — 19-08):

| Таблица | В дампе | Живая | Разница за 10 суток |
|---|---|---|---|
| `users` | 1015 | 1021 | +6 |
| `payments` | 9175 | 9234 | +59 |
| `lessons` | 1698 | 1716 | +18 |

Разница обязана быть **небольшой и положительной**. Ноль строк, полное
совпадение с живой базой или отрицательная разница — повод остановиться и
разобраться, а не разворачивать.

### Шаг 4. Разворачивание в черновую базу — ТОЛЬКО на машине разработчика

**Никогда на проде.** Прод-сервер MySQL не является площадкой для проверки
бэкапов ни в каком виде, включая «отдельную базу рядом».

```bash
# на машине разработчика, куда дамп скачан
scp root@193.232.229.92:/root/restore-drill/mysql-laravel.sql.gz .
mysql -u root -e "DROP DATABASE IF EXISTS restore_drill; CREATE DATABASE restore_drill;"
zcat mysql-laravel.sql.gz | mysql -u root restore_drill
mysql -u root restore_drill -e "
  SELECT 'users' t, COUNT(*) n FROM users
  UNION ALL SELECT 'payments', COUNT(*) FROM payments
  UNION ALL SELECT 'lessons',  COUNT(*) FROM lessons;"
```

Числа обязаны совпасть с колонкой «В дампе» из шага 3.

### Шаг 5. Убрать за собой — обязательно

```bash
mysql -u root -e "DROP DATABASE restore_drill;"     # на машине разработчика
rm -f mysql-laravel.sql.gz                          # локальная копия дампа
ssh root@193.232.229.92 'rm -rf /root/restore-drill' # рабочий каталог на сервере
```

_Dr. Mārcis Gasūns_
