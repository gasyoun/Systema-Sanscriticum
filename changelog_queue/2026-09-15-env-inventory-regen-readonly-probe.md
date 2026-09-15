# `docs/ENVIRONMENT_VARIABLES.md` регенерирован под второй YANDEX_DISK-диск (красный гейт `Environment inventory` + tracked-dirty на проде)

Побочный след коммита `c667ebd2` (GTD 0bn, 15-09-2026 11:30 +03, «probe read leg gets 45s curl ceiling»): он добавил в `config/filesystems.php` второй диск `yandex_disk_readonly_probe` (строки 96–99), читающий те же четыре `YANDEX_DISK_*`-ключа, что и боевой диск, — и **не прогнал штатный реген в том же проходе**. Правило стоит в [CLAUDE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md) («New `env()` key in `config/*.php` ⇒ regen the inventory same pass»), гейт — [`.github/workflows/env-inventory.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/env-inventory.yml) (`--check`).

Последствия, измеренные 15-09-2026:

- **Гейт на `main` красный** с ~11:30 +03: `php scripts/generate_env_inventory.php --check` → rc=1 на закоммиченной доке (проверено на `origin/main` перед правкой).
- **Прод заблокирован**: инвентарь был регенерирован локально на сервере (mtime `16:14:18 UTC` — через 6 с после строки деплоя `16:14:12 781ec159..e47e5187`), файл остался tracked-dirty, и `php artisan guards:verify` пишет `auto-deploy: tracked dirty на проде (deploy.sh/auto-deploy остановятся): docs/ENVIRONMENT_VARIABLES.md`. Следующий cron-тик (`*/30`) ушёл бы в `blocked-preflight` и поставил мягкий предохранитель.

Правка: `php scripts/generate_env_inventory.php` в репозитории — четыре строки `YANDEX_DISK_APP_PASSWORD` / `YANDEX_DISK_BACKUP_PATH` / `YANDEX_DISK_LOGIN` / `YANDEX_DISK_WEBDAV_URL` получают второй источник `config/filesystems.php:96–99` рядом с `:81–84`; **901 ключ**, `--check` после правки зелёный (до — rc=1). Поведение не менялось ни в одной строке кода: правка чисто доковая, но она же снимает грязь на проде при следующем деплое (файл на сервере станет байт-в-байт равен закоммиченному).
