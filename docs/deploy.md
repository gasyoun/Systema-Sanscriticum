# Деплой — один скрипт, один ритуал

_Created: 02-07-2026 · Last updated: 02-08-2026_

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
   Любая другая грязь — отказ деплоить. На проде **не правят** tracked
   `app/`/`config/` руками: только PR → `main` → auto-deploy / `deploy.sh`.
   **Worked stop (01-08-2026):** ручной edit
   [`config/marathon_landing_copy.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon_landing_copy.php)
   (testimonial framing) → dirty-gate → auto-deploy
   `storage/auto_deploy.disabled` с `[blocked-preflight]` → soft-TG
   «Кабинет: soft-сбой (guards)» при живом HTTP. Copy/testimonial — PR или
   env `MARATHON_TESTIMONIAL` / MarketingSetting, не `nano` на VPS.
   Full recovery: [server-resource-guards.md §8](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).
2. `git pull --ff-only origin main` — только fast-forward, никаких мержей на проде.
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
CI по SSH запускает тот же `sudo bash deploy.sh`, что и раньше запускал Иван
руками — сам скрипт не меняется, меняется только кто/что его вызывает.

**Инвариант безопасности сохраняется: у агентов по-прежнему нет прод-кредов.**
SSH-ключ живет только в секретах GitHub Environment `production`; job
запускается на GitHub-раннере, не на машине агента, и ждет approval человека
на КАЖДЫЙ прогон.

### Как работает гейт (MG-confirm)

1. PR смерджен в `main` → workflow ставится в очередь **"Waiting"** во вкладке
   Actions — job физически не стартует.
2. Ревьюер (MG) открывает run и жмет **Review deployments → Approve and
   deploy**.
3. Только после этого раннер поднимается, SSH'ится на прод и гоняет
   `sudo /var/www/html/deploy.sh` — тот же ритуал (git pull --ff-only, composer/npm,
   migrate, optimize, reload php-fpm, restart horizon, смоук), что описан выше
   в этом файле.
4. `workflow_dispatch` дает тот же путь вручную (Actions → Deploy production →
   Run workflow) для внепланового деплоя между пушами в `main`.

`concurrency: deploy-production` не дает второму прогону стартовать поверх
незавершенного — следующий push просто встает в очередь на approval.

### Первичная настройка (человек, один раз) — ещё НЕ сделано

Этот PR добавляет только workflow-файл. Ниже — что должен завести человек
(Иван и/или MG) в GitHub и на сервере, прежде чем прогон реально сработает;
до этого push в `main` просто копит "Waiting"-раны без вреда.

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
   - `DEPLOY_HOST` — IP/домен прода (`31.129.104.252`).
   - `DEPLOY_USER` — `deploy` (пользователь из шага 1 выше, НЕ `root`).
   - `DEPLOY_SSH_KEY` — приватный ключ из шага 2 (весь PEM-блок, включая
     `-----BEGIN`/`-----END`).

После этого следующий push в `main` (или ручной `workflow_dispatch`) впервые
дойдет до реального approval-гейта.

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

_Dr. Mārcis Gasūns_
