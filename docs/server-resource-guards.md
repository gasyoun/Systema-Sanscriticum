# Ресурсные предохранители прода: почему сервер зависал и что теперь этого не даёт

_Created: 29-07-2026 · Last updated: 29-07-2026_

Разбор зависаний samskrte.ru 23–24.07 и 28–29.07.2026 и перечень предохранителей,
поставленных на прод 29-07-2026. Диагностика — Opus 5 (`claude-opus-5[1m]`),
handoff [H1904](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1904-Opus_Systema-Sanscriticum_server-oom-scheduler-pileup-guards_29.07.26.md).

Сервер — LXC-контейнер `samskrtam150` на Proxmox, 8 vCPU, Debian 13. Было 8 ГиБ
RAM, с 29-07-2026 — 16 ГиБ. **Свопа нет и внутри контейнера он не заводится**
(см. §6).

## 1. Что именно произошло

Это была не «выключенная машина», а **зависание**: контейнер остался жив, но
перестал отвечать, и его пришлось перезагружать руками. Восстановленный
таймлайн (МСК; часы контейнера идут в UTC, Laravel пишет в Europe/Moscow):

| Время (МСК) | Событие | Откуда известно |
|---|---|---|
| 23-07 15:38 | Первый `oom-kill` в `cron.service` | journald |
| 24-07 13:30 | Журнал загрузки обрывается — машина умерла | `journalctl --list-boots` |
| 24–26.07 | Простой двое суток | [#730](https://github.com/gasyoun/Systema-Sanscriticum/issues/730) |
| 27-07 | `EMFILE`: десять параллельных `telegram-support:sync` | комментарий в [`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php) |
| 28-07 20:55 | Последняя успешная внешняя проба сайта | GitHub Actions |
| 28-07 22:37 | Первая упавшая проба — сайт уже не отвечает | GitHub Actions |
| 28-07 22:44 | Автоматически заведён [#823](https://github.com/gasyoun/Systema-Sanscriticum/issues/823) | GitHub Actions |
| 29-07 00:19:33 | **OOM-killer убивает процесс в `cron.service`** | journald |
| 29-07 00:19:35 | После рестарта cron систем­д видит **~20 осиротевших `php`** | journald |
| 29-07 03:08 | journald делает последнюю запись — и та с задержкой 2 ч 47 мин | journald |
| 29-07 ~09:41 | Horizon ещё дёргается, но валится на Redis | `laravel-2026-07-29.log` |
| 29-07 17:47 / 17:49 | Две ручные перезагрузки (вторая — под увеличение RAM) | `last`, supervisor |

Ключевые строки журнала, ради которых всё это восстанавливалось:

```
Jul 23 12:38:58 systemd[1]: cron.service: A process of this unit has been killed by the OOM killer.
Jul 28 21:19:33 systemd[1]: cron.service: A process of this unit has been killed by the OOM killer.
Jul 28 21:19:33 systemd[1]: cron.service: Failed with result 'oom-kill'.
Jul 28 21:19:35 systemd[1]: cron.service: Found left-over process 114146 (php) in control group while starting unit. Ignoring.
   ... то же для 114147…114167 — около двадцати процессов ...
```

Два вывода отсюда, и оба важны:

1. Память съедало **поддерево `cron.service`**, то есть планировщик, а не сайт,
   не Horizon и не база. MariaDB держала 165 МБ, Redis — 15 МБ.
2. Ядро убило **сам `cron`**, а не толстый `php`. Systemd перезапустил юнит
   поверх двух десятков осиротевших процессов, которых после этого уже никто
   никогда не подберёт. Освобождения памяти не произошло — стало хуже.

Дальше контейнер вошёл в **livelock по памяти**: свопа нет, ядро бесконечно
крутит reclaim, ничего не убивая до конца. Уже запущенные процессы вяло шевелятся
(Horizon писал в лог ещё утром 29-го), а новые аллокации не проходят — nginx и
php-fpm перестали отдавать страницы, journald не смог записать свою же строку
почти три часа. Снаружи это «сервер завис»; изнутри — «OS не осталось памяти,
чтобы работать». На момент восстановления 15-минутный `load average` был **370**
при 8 ядрах.

## 2. Корневая причина

```
cron (раз в минуту)  →  php artisan schedule:run  →  php artisan <команда>
      ↑ без замка              ↑ ждёт команду в ФОНЕ? нет — в forеground
```

`$schedule->command()` запускает каждую команду **дочерним процессом и ждёт его**.
Значит `schedule:run` живёт ровно столько, сколько идёт самая долгая команда за
эту минуту. А cron заводит новый `schedule:run` каждые 60 секунд **безусловно**.

Замеры на этом проде:

| Команда | Частота | Реально занимает |
|---|---|---|
| `telegram-support:sync` | `everyMinute` | **36–41 с** |
| `telegram-harvest:roster-groups` | `hourly` | до 600 с (таймаут) |
| `mail:scan-bounces` | `hourly` | замечено 3 мин 21 с |

`telegram-support:sync` съедает две трети каждой минуты в штатном режиме — запас
до переполнения меньше, чем длительность одного захода. Как только команда
переваливает за 60 секунд, минуты начинают **накладываться**, и каждая добавляет
пару PHP-процессов примерно по 100 МБ (Laravel + Filament в памяти). Дальше
ограничителей не было ни одного:

- `memory_limit = -1` в CLI — потолка на процесс нет;
- `pids.max = max` — потолка на число процессов нет;
- свопа нет — упругости нет;
- `earlyoom`/`systemd-oomd` не стояли — некому убить раньше ядра.

`->withoutOverlapping()` здесь не помогает и не должен: он защищает команду от
самой себя, но **никак не мешает копиться самому `schedule:run`**. Именно этот
разрыв и был дырой.

## 3. Почему никто не узнал

Отдельный вопрос и отдельный ответ: **каналов оповещения было три, и молчали все три.**

| Канал | Сработал? | Почему |
|---|---|---|
| Внешний монитор GitHub Actions | **Да**, частично | Завёл issue [#823](https://github.com/gasyoun/Systema-Sanscriticum/issues/823) в 22:44. Но Telegram-шаг пропущен: в репозитории **нет ни одного Actions-секрета**, то есть `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены. Ровно то, что обещано в шапке [`uptime-samskrte.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/uptime-samskrte.yml): без секретов issue заводится, сообщение не уходит. |
| Пульс на healthchecks.io (`heartbeat:ping`) | **Нет** | `HEARTBEAT_PING_URL` в `.env` отсутствует, `config('heartbeat.url')` резолвится в пустую строку → команда сознательно fail-open и не шлёт ничего. Это единственный сторож, который **переживает смерть сервера**, и он был выключен. |
| Внутренние Telegram-алерты приложения | **Нет и не могли** | Они выполняются на умирающей машине. Плюс 28-07 в логе 86 отказов `sendMessage` с `error_code 404`. |

Отсюда практический вывод: **включение `HEARTBEAT_PING_URL` — самая дешёвая и
самая важная из оставшихся мер.** Мониторы, живущие на самой машине, о её смерти
доложить не могут по построению; частота GitHub-крона измерена и составляет
~8 % от заявленной (медианный разрыв 122 мин).

Проверено 29-07-2026: токен бота в `.env` сейчас **рабочий** (`getMe` → `ok`), и
чат `5487293147` («Куратор курсов») **достижим**. То есть 404-е 28-го числа
относятся к прежнему значению токена, заменённому вручную 29-07.

## 4. Что поставлено на прод 29-07-2026

Всё ниже — уровень ОС, кода приложения не касается, деплой не требуется.

| Предохранитель | Где | Что делает |
|---|---|---|
| `systema-schedule-run.sh` | `/usr/local/sbin/` | Обёртка планировщика: `flock -n` (одновременно **ровно один** прогон), `timeout 900s` (зависший не держит замок вечно), жнец осиротевших `artisan`-процессов старше лимита |
| crontab `www-data` | — | Зовёт обёртку вместо голого `php artisan schedule:run` |
| `MemoryHigh=2G` / `MemoryMax=3G` | `cron.service.d/memory-cap.conf` | Потолок на всё поддерево планировщика (поставлено админом 29-07 15:37) |
| `TasksMax=200`, `OOMPolicy=kill`, `Restart=always` | `cron.service.d/99-systema-limits.conf` | Потолок по **числу** процессов; при OOM сносится **вся группа** — больше никаких сирот, cron всегда встаёт чистым |
| `MemoryHigh=3G` / `MemoryMax=4G` | `supervisor.service.d/memory-cap.conf` | То же для Horizon/Reverb (админ) |
| `memory_limit = 768M` | `php/8.3/cli/conf.d/99-memory-cap.ini` | Вместо `-1`; рабочий набор этих процессов ~100 МБ (админ) |
| `earlyoom` | `/etc/default/earlyoom` | SIGTERM при <10 % свободной памяти, SIGKILL при <5 %; `--prefer ^php[0-9.]*$`, `--avoid` для mariadbd/nginx/sshd/redis/supervisord. **Убивает раньше, чем ядро уйдёт в livelock** |
| `pm.max_children 5 → 12` | `php/8.3/fpm/pool.d/www.conf` | Пять воркеров на 16 ГиБ — отдельный отказ по доступности; плюс `pm.max_requests = 500` |

Проверка, что замок реально работает (выполнено на проде):

```
$ flock -n /var/www/html/storage/framework/schedule-run.lock -c 'sleep 6' &
$ sudo -u www-data /usr/local/sbin/systema-schedule-run.sh
2026-07-29T16:25:55Z SKIP: previous schedule:run still holds the lock
exit 0
```

**Побочный эффект, который надо знать:** пока идёт долгая команда, минутные
задания этой минуты **пропускаются**, а не копятся. Для
`telegram-harvest:roster-groups` (до 10 мин раз в час) это до десяти
пропущенных минут в час. Это сознательный размен: пропуск против зависания.
Полностью его снимает `->runInBackground()` на долгих командах — см. §6.

## 5. Что теперь пишется в логи

До 29-07 разбирать инцидент было почти нечем: `rsyslog` **не был установлен**,
`/var/log/syslog` лежал нулевого размера с марта, а `journald` хоть и был
персистентным, не хранил историю памяти по процессам.

| Что | Куда | Частота |
|---|---|---|
| `rsyslog` | `/var/log/syslog`, `auth.log` | поставлен, включён |
| `journald` | `/var/log/journal`, до 3 ГБ, 180 дней, rate-limit снят | постоянно |
| `sysstat` (`sar`) | `/var/log/sysstat/`, история 31 день | **раз в минуту** |
| `memwatch.sh` | `/var/log/memwatch.log` | раз в минуту: свободная память, load, число процессов, число `php` |
| `memwatch` детально | `/var/log/memwatch-pressure.log` | при <25 % свободной памяти — топ-25 по RSS и суммы по командам |
| `earlyoom` | journald | состояние памяти раз в 60 с |
| logrotate | `schedule.log`, `madelineproto.log`, `horizon.log`, `reverb.log` | раньше не ротировались вообще |

Пример строки `memwatch.log`:

```
2026-07-29T16:24:01Z avail=14906MB/16384MB (90%) load=0.28 0.44 2.74 procs=63 php=22 schedule_run=0
```

Именно этой строки и не хватало 28-го числа, чтобы за секунду увидеть, кто ест
память.

## 6. Что осталось человеку

Это единственное, что агент сделать не может — нужны доступы и решения.

1. **Своп на хосте Proxmox.** Внутри LXC своп не заводится; это делается на
   хосте: `pct set <vmid> -swap 4096`. Без свопа у ядра нет упругости, и
   livelock остаётся физически возможным — `earlyoom` его лишь предупреждает.
2. **`HEARTBEAT_PING_URL` в `.env`.** Завести проверку на
   [healthchecks.io](https://healthchecks.io) (period 5 мин, grace 10 мин) и
   вписать URL. Самая дешёвая мера с наибольшим эффектом: тревогу поднимает
   молчание, поэтому она переживает смерть машины.
3. **Actions-секреты `TELEGRAM_BOT_TOKEN` и `TELEGRAM_CHAT_ID`** в
   Settings → Secrets → Actions. Без них внешний монитор пишет issue в пустоту.
   Проверить путь тревоги: Actions → Uptime → Run workflow → `force_alert = true`.
4. **`->runInBackground()` на долгих командах** (`telegram-support:sync`,
   `telegram-harvest:roster-groups`, `mail:scan-bounces`, `backup:run`,
   `avatars:sync`) в [`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php).
   Это снимает размен из §4. **Сознательно не сделано этой сессией:** правка
   трогает ту самую логику замков MTProto-сессии, вокруг которой в `Kernel.php`
   выстроено рассуждение о TTL после аварии 27-07, а два экземпляра на одной
   сессии MadelineProto — это `AUTH_RESTART`. Нужен владелец подсистемы, а не
   попутная правка.
5. **Бот `@testpodpiska12_bot`** для боевых оповещений — имя говорит «тестовый».
   Стоит решить, тот ли это бот, которым школа хочет получать тревоги.

## 7. Если сервер снова начнёт пухнуть

```bash
ssh root@193.232.229.92
tail -40 /var/log/memwatch.log                 # память по минутам: видно тренд
tail -60 /var/log/memwatch-pressure.log        # кто именно ест, топ по RSS
journalctl -u earlyoom --since '2 hours ago'   # кого и когда прибил earlyoom
systemctl status cron                          # не в OOM-цикле ли планировщик
grep -E 'SKIP:|TIMEOUT:|REAP ' /var/www/html/storage/logs/schedule.log | tail -20
sar -r -s 00:00                                # история памяти за сутки
pgrep -fc 'artisan'                            # норма ~16-22
```

Три числа нормы на 16 ГиБ: `avail` ≈ 14–15 ГБ, `procs` ≈ 60–70, `php` ≈ 22.
Строки `SKIP:` — это обёртка отработала штатно. Строки `REAP` — она подобрала
зависший процесс: не авария, но повод посмотреть, какая команда его оставила.

_Dr. Mārcis Gasūns_
