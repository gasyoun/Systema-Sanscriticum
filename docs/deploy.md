# Деплой — один скрипт, один ритуал

_Created: 02-07-2026 · Last updated: 06-07-2026_

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
