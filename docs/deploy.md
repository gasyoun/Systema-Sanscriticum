# Деплой — один скрипт, один ритуал

_Created: 02-07-2026 · Last updated: 16-07-2026_

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
   Исключение — `public/docs/*.pdf` (оферта/политика/согласие, которые заменяют
   на сервере мимо git): их скрипт сам стэшит на время обновления кода и
   возвращает после (`git stash pop`); конфликт при возврате = стоп с
   инструкцией. Любая другая грязь — по-прежнему отказ деплоить.
2. `git pull --ff-only origin main` — только fast-forward, никаких мержей на проде.
3. `composer install --no-dev -o` + `npm ci && npm run build`
   (`public/build` в git не хранится — фронт собирается на сервере).
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
9. Смоук: `curl` главной страницы, ожидается 200; иначе скрипт падает.
10. Строка в `storage/logs/deploys.log`: дата, диапазон коммитов, версия PHP, кто.

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
