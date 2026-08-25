# Ресурсные предохранители прода: почему сервер зависал и что теперь этого не даёт

_Created: 29-07-2026 · Last updated: 25-08-2026_

> **Язык для человека:** разделы «что делать / чего не делать» (профилактика, запреты, runbook для Ивана/MG) — **всегда по-русски**. Технические идентификаторы, пути и shell-команды можно оставлять как есть.

Разбор зависаний samskrte.ru 23–24.07 и 28–29.07.2026 и перечень предохранителей,
поставленных на прод 29-07-2026. Диагностика — Opus 5 (`claude-opus-5[1m]`),
handoff [H1904](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1904-Opus_Systema-Sanscriticum_server-oom-scheduler-pileup-guards_29.07.26.md).

Сервер — LXC-контейнер `samskrtam150` на Proxmox, 8 vCPU, Debian 13. Было 8 ГиБ
RAM, с 29-07-2026 — 16 ГиБ. **Свопа нет и внутри контейнера он не заводится**
(см. §6).

> **Этот документ описывает ОДНУ машину и только уже случившиеся классы аварий.**
> Аудит **обеих** продовых машин (`.92` и n8n-хост `.91`), четыре живых дефекта,
> которых ни одна здешняя проверка не видит, и план на четыре волны до планки
> «ни одного простоя без человека дольше 15 минут» —
> [PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md)
> (19-08-2026). Разбираете инцидент — сначала §7 ниже; думаете, чего ещё не хватает, — туда.

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

### Конкретный спусковой крючок

Не «когда-нибудь заход мог затянуться» — вот он, в `schedule.log`:

```
2026-07-28 21:25:04 Running ['artisan' telegram-support:sync]
 2 ч. 54 мин. 30 сек. DONE
2026-07-29 00:19:34 Running ['artisan' zapisi:remind-classes] Killed
Killed
Killed
```

**Один заход прожил 10 470 секунд вместо штатных 36** и всё это время держал
`schedule:run` в foreground. `cron` заводил новый каждую минуту — около **174**
цепочек по ~200 МБ. Три `Killed` подряд в 00:19:34 — это OOM-killer, тот же
момент, что и `oom-kill` в journald.

Отсюда точный порядок смерти, и он важнее самой причины:

| Время (МСК) | Что умерло |
|---|---|
| **21:25** | Планировщик — завис на синке |
| **22:37** | Сайт — перестал отдавать страницы наружу |
| **00:19** | Контейнер — OOM-kill `cron`, дальше livelock |

**Оповещение умерло на час раньше сайта.** Почему это решает всё — §3.

При этом у команды **есть** watchdog (`sync_timeout_seconds = 120`,
[`MadelineSyncWatchdog`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSyncWatchdog.php)
на `pcntl_alarm`), и `pcntl` на хосте загружен — то есть будильник **должен** был
взвестись и не сработал: 10 470 с против потолка 120 с, превышение в 87 раз.
Инвариант, на который прямо опирается `Kernel.php` («пока таймаут < TTL, зависший
заход умирает первым»), не выполняется. Разбор и гипотезы —
[#840](https://github.com/gasyoun/Systema-Sanscriticum/issues/840).

- `memory_limit = -1` в CLI — потолка на процесс нет;
- `pids.max = max` — потолка на число процессов нет;
- свопа нет — упругости нет;
- `earlyoom`/`systemd-oomd` не стояли — некому убить раньше ядра.

`->withoutOverlapping()` здесь не помогает и не должен: он защищает команду от
самой себя, но **никак не мешает копиться самому `schedule:run`**. Именно этот
разрыв и был дырой.

### 2.1. Проверено по исходникам, а не по памяти

Независимая перепроверка (Opus 5 `claude-opus-5[1m]`, отдельный проход) подняла
вендорный код и нашла ещё три факта, которые стоит знать:

- `vendor/laravel/framework/src/Illuminate/Console/Scheduling/Event.php:199-210` —
  `execute()` это `Process::fromShellCommandline(...)->run(...)`, то есть
  **блокирующий** вызов; `ScheduleRunCommand.php:120-138` обходит задания
  строго последовательно в одном процессе. Механизм подтверждён на уровне
  исходников, а не выведен из наблюдений.
- **`runInBackground` не встречается в `app/` ни разу** — все ~45 команд
  расписания блокируют планировщик.
- **У шести команд нет `->withoutOverlapping()` вообще:** `archives:cleanup`,
  `promises:expire`, `mail:scan-bounces`, `unreliable:recount`,
  `promises:remind-tomorrow`, `onboarding:weekly-digest`. И самая опасная из них —
  `mail:scan-bounces`: [`ScanBounces.php:82`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ScanBounces.php)
  зовёт `@imap_open()` к удалённому хосту, а `imap_timeout()` в `app/` **не
  вызывается нигде**. Сетевой вызов без таймаута в ежечасной команде без замка —
  это готовый источник вечно висящего захода. Сейчас он ограничен обёрткой
  (`timeout 900s`), но правильное лечение — таймаут в самой команде.
- `->withoutOverlapping()` держит замок **в Redis** (`CACHE_DRIVER=redis`). В ночь
  аварии в логе 94 ошибки `read error on connection to 127.0.0.1:6379` — то есть
  единственная защита приложения от наложения отказывает ровно тогда, когда она
  нужнее всего. Предохранитель обязан жить **вне** приложения; `flock` и лимиты
  systemd этому условию удовлетворяют, Redis-замок — нет.

Попутно: `composer.json` пинит `laravel/framework ^12.61.1`, тогда как
[`CLAUDE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/CLAUDE.md)
всё ещё говорит «Laravel 10» — документация протухла.

### 2.2. Почему watchdog не сработал — ответ (H1915, 30-07-2026)

Вопрос, оставленный открытым выше, закрыт замером на живом хосте
(`samskrtam150`, PHP 8.3.32, `pcntl` загружен, Revolt использует
`StreamSelectDriver` — ни `ev`, ни `event`, ни `uv` в сборке нет). Диагностика —
Opus 5 1M (`claude-opus-5[1m]`), handoff
[H1915](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1915-Opus_Systema-Sanscriticum_madeline-sync-watchdog-not-enforced_29.07.26.md).

**Сигнал доставлялся. Исключение — нет.** Все четыре рабочие гипотезы о
«будильник не взвёлся» опровергнуты: SIGALRM приходит вовремя в каждой форме
блокировки, в которой заход мог его застать.

| Форма блокировки захода | Обработчик вошёл | Заход умер вовремя |
|---|---|---|
| Busy-цикл (то, что покрывал старый тест) | да | да |
| `sleep()` | да | да |
| Внутри `EventLoop::run()` | да | да |
| То же + зарегистрированный signal-watcher | да | да |
| Блокирующий `flock(LOCK_EX)` | да | да |
| **Внутри callback'а цикла, error handler «логировать и продолжить»** | **да** | **НЕТ** |

Последняя строка — прод. Сигнал прилетает в произвольной точке, и для сетевого
синка это почти всегда callback событийного цикла или файбер. Revolt отдаёт
такое исключение в `EventLoop::setErrorHandler()`, а MadelineProto ставит туда
обработчик, который **логирует и продолжает** (`AbstractAPI.php:41`,
`API.php:429`); сверх того в библиотеке **76** блоков `catch (Throwable)`, и
исключение обязано пережить произвольный из них. А `pcntl_alarm` **одноразовый**:
проглоченный один раз потолок исчезает насовсем, и остаток захода идёт вообще без
ограничения.

Отсюда та самая пара, которая иначе не сходится: **10 470 с И код возврата 0**
(`DONE` в `schedule.log`, не `FAIL`). Контрольный прогон старой версии на той же
форме: пережила потолок в 6 раз (12,04 с при потолке 2 с), исключение проглочено
один раз, наружу не вышло ничего, остаток будильника 0.

**Что сделано.** Обработчик больше не полагается на раскрутку стека: он выполняет
переданную уборку (отпустить замок сессии, прибрать демона, пометить аккаунт) и
завершает процесс сам, кодом `75`
([`MadelineSyncWatchdog::EXIT_TIMED_OUT`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSyncWatchdog.php)).
`exit()` из обработчика проверен во всех трёх формах, в которых он может застать
заход, — в callback'е цикла, на приостановленном главном файбере и внутри тела
файбера, — и работает везде. Цена: `finally` вызывающего не отработает, поэтому
вся уборка обязана жить в замыкании `$onTimeout`; замок `madeline-session` без
этого провисел бы свои 900 с TTL после КАЖДОГО таймаута.

Регрессия закреплена тестом, который воспроизводит именно проваливавшую форму
(`MadelineSyncGuardsTest::test_watchdog_kills_a_run_hung_inside_the_event_loop`,
отдельным процессом — проверяется смерть процесса, а не исключение).

### 2.3. TTL замка планировщика больше не выводится из watchdog-таймаута

`Kernel.php` строил на потолке команды **аргумент безопасности**: «пока таймаут <
TTL, зависший заход умирает первым». 28.07 инвариант не выполнился — TTL был
`ceil(120/60)+5 = 7` мин, заход прожил 174 мин, то есть замок протух
**двадцать пять раз**.

Watchdog починен и снова надёжен, но выводить границу из него нельзя: без
расширения `pcntl` он честный no-op. Поэтому TTL теперь считается от
**гарантированной** верхней границы жизни процесса, которая держится в любом
случае — GNU `timeout` запускает `schedule:run` в отдельной группе процессов,
посылает группе `TERM` на `SCHEDULE_MAX_SECONDS` и `KILL` ещё через 30 секунд.
Формула TTL оставляет прежний консервативный запас 2×:

```
TTL = ceil(max(watchdog_timeout, 2 × SCHEDULE_MAX_SECONDS) / 60) + 5
```

При 900 с это **35 мин против прежних 7** — и, что важнее, это верно и тогда,
когда watchdog не взвёлся. Размен сознательный: заход, убитый жёстко (без своей
уборки), задерживает синк до получаса, зато двух экземпляров на одной
MTProto-сессии не бывает никогда. Для минутной команды это правильная сторона
размена — пауза видна healthcheck'у, а `AUTH_RESTART` роняет живой аккаунт
поддержки. Число живёт в
[`scripts/server_guards.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf);
[`config/schedule_guard.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/schedule_guard.php) —
копия для расчёта без дискового разбора каждую минуту, расхождение ловит тест.

### 2.4. Ruling по `->runInBackground()` — НЕТ для команд общей MTProto-сессии

Решается здесь же сознательно: правка TTL выше и `runInBackground` трогают
семантику замка одной и той же сессии, а два экземпляра на ней дают
`AUTH_RESTART` на живом аккаунте.

**Решение: `->runInBackground()` НЕ применяется к `telegram-support:sync` и
`telegram-harvest:roster-groups`.** Три причины, по убыванию веса:

1. **Замок снимается `schedule:finish`, которого при убийстве не будет.** Убийство
   — теперь ШТАТНЫЙ путь завершения (watchdog и `timeout` обёртки). Фоновый
   заход, снятый `kill`, не доживёт до `schedule:finish`, и замок провисит весь
   TTL — с новыми 35 мин это заметная пауза после каждого таймаута, тогда как в
   foreground `finally` отпускает его сразу.
2. **Ломается единственная надёжная ограда.** `flock -n` в обёртке гарантирует
   «не более одного `schedule:run` одновременно», но фоновые дети **переживают**
   родителя, и гарантия перестаёт что-либо значить именно для долгих команд —
   ровно тех, ради которых `runInBackground` и предлагался. Redis-замок этой дыры
   не закроет: в ночь аварии он сам отказывал (§2.1, 94 ошибки соединения).
3. **Выгода уже получена без риска.** Побочный эффект, ради которого фон и
   рассматривался — долгая команда держит весь планировщик, — снят H1914: обёртка
   не даёт заходу жить дольше 900 с, а `flock -n` превращает медленный проход в
   **пропуск** следующей минуты, а не в накопление цепочек.

Для прочих долгих команд (`mail:scan-bounces`, `backup:run`, `avatars:sync`) этот
ruling ничего не решает: общей MTProto-сессии у них нет, причина (1) и (2) к ним
применима слабее, и они разбираются в
[H1916](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1916-Sonnet_SanskritLexicography_scheduled-command-timeout-overlap-sweep_29.07.26.md).
Правильное лечение `mail:scan-bounces` всё равно другое — таймаут внутри самой
команды (§2.1), а не фоновый запуск.

### 2.5. Замок обёртки: TTL-подстраховка на случай зависшего держателя (H1973, 30-07-2026)

`flock -n` в `systema-schedule-run.sh` снимается ровно в момент закрытия держащего
файлового дескриптора — на Linux это происходит при выходе процесса **всегда**,
даже по `SIGKILL`, если только тот же дескриптор не унаследовал ещё живой
процесс. `runInBackground` запрещён (§2.4), поэтому такого наследника внутри
приложения быть не должно — но 30-07-2026 обёртка тем не менее выдавала
`SKIP: previous schedule:run still holds the lock` **два часа подряд** во время
всплеска из 13 деплоев (19:02–20:28 МСК), молча приглушив ВСЕ команды
планировщика, не только Telegram.

Правдоподобная причина — держатель, застрявший в состоянии `D` (непрерываемое
ожидание дискового I/O, вероятное при тяжёлой дисковой нагрузке от параллельных
деплоев): такой процесс не отпустит дескриптор, пока не завершится сам I/O, и
никакой сигнал этого не ускорит. Подтвердить это на живом проде без повторной
аварии нельзя, поэтому обёртка больше не полагается на диагноз — она лечит
симптом при любой причине:

- Файл замка больше не открывается с усечением (`>>`, не `>`) — усечение
  сбрасывало бы `mtime` при КАЖДОМ проходе, включая неудачные, и делало бы возраст
  замка неизмеримым.
- Держатель замка обновляет `mtime` файла (`touch`) сразу после захвата — так
  `mtime` значит «с какого момента держит текущий владелец», а не «когда сюда в
  последний раз кто-то заглядывал».
- Если `flock -n` проваливается и возраст файла превышает
  `SYSTEMA_SCHEDULE_STALE_SECONDS` (по умолчанию `2 × SCHEDULE_MAX_SECONDS + 60` —
  заведомо позже `timeout` + его 30-секундного окна до `KILL`),
  обёртка закрывает свой дескриптор, удаляет файл замка и открывает его заново.
  Новый `open()` — это новый inode; замок, который всё ещё держит (возможно,
  наглухо застрявший) старый процесс, отныне относится к удалённому файлу, на
  который никто больше не претендует, поэтому новый `flock -n` проходит
  безусловно.

Разбор + патч: `scripts/server_guards/sbin/systema-schedule-run.sh`; воспроизведение —
`scripts/server_guards/sbin/test_systema_schedule_run.sh` (запускается локально на
Linux/Sail, требует `flock` из util-linux — на Windows-машине разработки его нет,
поэтому синтаксис проверяется `bash -n`, а поведение — в Linux CI). Тест отдельно
держит живым посторонний процесс с argv `php artisan unrelated:work` и доказывает,
что обёртка его не сигналит: глобального `pgrep`/reaper в ней больше нет.
Диагностика — Sonnet 5 (`claude-sonnet-5`), handoff
[H1973](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1973-Sonnet_Systema-Sanscriticum_scheduler-wrapper-stale-lock-skip_30.07.26.md).

## 3. Почему никто не узнал

Отдельный вопрос и отдельный ответ: **каналов оповещения было три, и молчали все три.**

| Канал | Сработал? | Почему |
|---|---|---|
| Внешний монитор GitHub Actions | **Да, полностью** | Завёл issue [#823](https://github.com/gasyoun/Systema-Sanscriticum/issues/823) в 22:44 **и отправил сообщение в Telegram** — шаг «Сообщить в Telegram о простое» в прогоне `30392777459` завершился `success`; при восстановлении шаг «Закрыть тревогу и сообщить о восстановлении» (прогон `30466135990`) тоже `success`. Итого ровно **два** сообщения, как и задумано анти-спам-логикой. Секреты `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` заведены и работают. |
| Пульс на внешнем heartbeat (`heartbeat:ping`) | **Нет (на момент 29-07)** | `HEARTBEAT_PING_URL` в `.env` был пуст → fail-open. С 30-07-2026 URL заведён на **Better Stack** (не healthchecks.io) — актуальный inventory: [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md). |
| Внутренние Telegram-алерты приложения (`cabinet:probe`) | **Нет — и вот почему** | Канал **настроен и работает**: «каждые 15 минут система входит smoke-менеджером», сообщения доходят. Но `cabinet:probe` стоит `*/15` **внутри того же `schedule:run`**, который завис на синке в 21:25. За окно 21:25 → 00:19 он не выполнился **ни разу**, поэтому падение сайта в 22:37 не заметил никто. Сторож находился внутри того, что он сторожит. |

> **Поправка от 29-07-2026, вечер.** В первой редакции этого раздела было
> написано, что Telegram-шаг внешнего монитора не сработал из-за отсутствия
> Actions-секретов. **Это неверно.** Основанием был пустой вывод `gh secret list`,
> но у токена, которым я ходил, просто нет права читать секреты — отсутствие
> вывода было принято за отсутствие секретов. Проверка по самим прогонам
> (`gh run view --json jobs`) показывает `success` на обоих Telegram-шагах.
> Внешний монитор отработал полностью; молчал другой канал — см. строку
> `cabinet:probe` ниже. Урок общий: **вывод инструмента без прав ≠ факт о мире**;
> проверять надо по следам самого действия, а не по тому, что видно наблюдателю.

Отсюда два вывода, и первый оказался важнее, чем выглядел.

**1. Сторож не должен делить судьбу с тем, что он сторожит.** Не «алерты не
настроены» — они настроены и работают. Проблема в том, что `cabinet:probe` жил
в том же `schedule:run`, который и завис. Исправлено 29-07: обе сторожевые
команды вынесены **отдельными строками cron**, со своим замком, своим таймаутом
и своей судьбой (§4). Теперь висящий синк не может их заглушить.

**2. `HEARTBEAT_PING_URL` — не «оповещения не работают», а «оповещения приходят
поздно».** Сообщение о простое пришло в 22:44 — через **1 ч 19 мин** после того,
как в 21:25 встал планировщик, и только потому, что к тому моменту погас сайт.
Заметить смерть самого планировщика было нечем: единственное, что могло это
увидеть, находилось внутри него. Внешний монитор к тому же ходит по расписанию
GitHub, реальная частота которого измерена и составляет ~8 % от заявленной
(медианный разрыв 122 мин). Пульс на **внешнем heartbeat** (Better Stack Uptime
с 30-07-2026; раньше планировался healthchecks.io) закрывает обе дыры сразу:
период наш, а тревогу поднимает **молчание**, поэтому он переживает и «планировщик
встал», и «контейнер лёг». Канон: [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md).

Проверено 29-07-2026: токен бота в `.env` сейчас **рабочий** (`getMe` → `ok`), и
чат `5487293147` («Куратор курсов») **достижим**. То есть 404-е 28-го числа
относятся к прежнему значению токена, заменённому вручную 29-07.

## 4. Что поставлено на прод 29-07-2026

**Источник правды — не этот документ, а два файла в репозитории** ([H1914](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1914-Opus_Systema-Sanscriticum_codify-server-guards-in-repo-drift-verify_29.07.26.md), 30-07-2026):

| Что | Файл | Роль |
|---|---|---|
| Значения (числа памяти, пороги, таймауты) | [scripts/server_guards.conf](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf) | **одно** место, откуда их берут и applier, и проверка |
| Сами предохранители | [scripts/server_guards/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/scripts/server_guards) + [manifest.psv](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/manifest.psv) | шаблоны с `@@ПОДСТАНОВКАМИ@@` и список «файл → куда → права» |
| Применить | `sudo bash `[scripts/server_guards_apply.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_apply.sh) | идемпотентно; второй прогон ничего не меняет; бэкап в `/root/preflight-backup-<stamp>/` |
| Проверить | `php artisan guards:verify` ([VerifyServerGuards.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/VerifyServerGuards.php)) | non-zero + имя пропавшего; висит на `cabinet:probe` и на [deploy.sh](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/deploy.sh) |

Документ ниже объясняет **почему** каждый предохранитель такой; менять значения
надо в `server_guards.conf`, а правку на самой машине руками `guards:verify`
покажет как расхождение. До H1914 предохранители жили ТОЛЬКО на машине:
пересборка LXC, восстановление из бэкапа или переезд (в этом проекте уже был,
июль 2026) сносили их молча, и заметить это было нечем — ни один тест, гейт или
монитор этого не видел. Теперь пропажу видит проба каждые 15 минут: критичная
находка идёт в Telegram как `critical`, расхождение — как `soft`, и то и другое
пишется в `cabinet_probe_runs`. Своп — единственное исключение: внутри LXC он не
заводится, поэтому его отсутствие проверка сообщает как `info`, не портя код
возврата (§6, пункт 1).

Всё ниже — уровень ОС, кода приложения не касается, деплой не требуется.

| Предохранитель | Где | Что делает |
|---|---|---|
| `systema-schedule-run.sh` | `/usr/local/sbin/` | Обёртка планировщика: `flock -n` (одновременно **ровно один** прогон), `timeout 900s` (зависший не держит замок вечно), жнец осиротевших `artisan`-процессов старше лимита, TTL-подстраховка на застрявшего держателя замка (§2.5) |
| `systema-watchdog-run.sh` | `/usr/local/sbin/` | Отдельный раннер для сторожей: свой замок, свой короткий таймаут. **Не может быть заблокирован основным планировщиком** |
| crontab `www-data` | — | Зовёт обёртку вместо голого `php artisan schedule:run`, плюс **две отдельные строки** для `cabinet:probe` (`*/15`) и `heartbeat:ping` (`*/5`) |
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

Сторожа вынесены из планировщика — тот самый урок 28-07:

```cron
* * * * *    /usr/local/sbin/systema-schedule-run.sh   >> …/schedule.log 2>&1
*/15 * * * * /usr/local/sbin/systema-watchdog-run.sh "cabinet:probe"  cabinet   120 >> …/watchdog.log 2>&1
*/5  * * * * /usr/local/sbin/systema-watchdog-run.sh "heartbeat:ping" heartbeat  60 >> …/watchdog.log 2>&1
```

Дублирование с копиями в `Kernel.php` сознательно и безвредно: `cabinet:probe`
только читает (плюс одна строка истории и сообщение в Telegram не чаще раза в
60 мин), `heartbeat:ping` — идемпотентный HTTP-пинг. **Сторож, отработавший
дважды, — не проблема; сторож, не отработавший ни разу, — это авария выше.**

**Побочный эффект, который надо знать:** пока идёт долгая команда, минутные
задания этой минуты **пропускаются**, а не копятся. Для
`telegram-harvest:roster-groups` (до 10 мин раз в час) это до десяти
пропущенных минут в час. Это сознательный размен: пропуск против зависания.
Оповещения он больше не задевает — они вне планировщика. Полностью его снимает
`->runInBackground()` на долгих командах — см. §6.

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
| `watchdog.log` | `storage/logs/watchdog.log` | срабатывания сторожевых строк cron (пишется только при таймауте — тишина = норма) |
| logrotate | `schedule.log`, `watchdog.log`, `madelineproto.log`, `horizon.log`, `reverb.log` | раньше не ротировались вообще |

Пример строки `memwatch.log`:

```
2026-07-29T16:24:01Z avail=14906MB/16384MB (90%) load=0.28 0.44 2.74 procs=63 php=22 schedule_run=0
```

Именно этой строки и не хватало 28-го числа, чтобы за секунду увидеть, кто ест
память.

## 6. Что осталось человеку

Это единственное, что агент сделать не может — нужны доступы и решения.
Статус на 29-07-2026 вечер.

1. 🟡 **Своп на хосте Proxmox** — *запрошено у Артёма (`@t3t3r1n`)*. Внутри LXC
   своп не заводится; это делается на хосте: `pct set <vmid> -swap 4096`. Без
   свопа у ядра нет упругости, и livelock остаётся физически возможным —
   `earlyoom` его лишь предупреждает.
2. ✅ **`HEARTBEAT_PING_URL` + `CABINET_PROBE_PING_URL`** — *заведены 30-07-2026*
   на [Better Stack Uptime](https://uptime.betterstack.com) (period 5 / 15 мин).
   healthchecks.io **не** используем. Inventory, smoke, Cologne/samskrtam:
   [UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md).
   Единственная мера, переживающая **полную** смерть контейнера: тревогу
   поднимает **молчание** heartbeat. Вынесенные строки cron (§4) закрывают
   «планировщик встал», но не «контейнер лёг» без внешнего pulse.
3. ✅ **Actions-секреты `TELEGRAM_BOT_TOKEN` и `TELEGRAM_CHAT_ID` — заведены и
   работают, делать нечего.** Подтверждено по прогонам: оба Telegram-шага
   вернули `success`, за простой ушло ровно два сообщения (падение +
   восстановление). Ранее в этом документе стояло обратное — ошибка снята
   поправкой в §3.
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
6. **Таймаут в `mail:scan-bounces`** (§2.1): `imap_timeout(IMAP_OPENTIMEOUT, …)`
   перед `imap_open()`, плюс `->withoutOverlapping()` на саму команду. Обёртка её
   уже ограничивает, но команда не обязана полагаться на внешний предохранитель.
7. **Планировщик как `systemd`-таймер вместо cron + `flock`.** Архитектурно чище:
   `Type=oneshot` систем­д не запустит повторно, пока юнит активен, — защита от
   наложения становится свойством PID 1 и не зависит ни от Redis, ни от гигиены
   lock-файла. Текущая связка `flock` даёт ту же гарантию единственности и уже
   работает, так что это улучшение, а не долг.

## 7. Если сервер снова начнёт пухнуть

```bash
ssh root@193.232.229.92
cd /var/www/html && php artisan guards:verify  # ПЕРВЫМ ДЕЛОМ: все ли предохранители на месте
tail -40 /var/log/memwatch.log                 # память по минутам: видно тренд
tail -60 /var/log/memwatch-pressure.log        # кто именно ест, топ по RSS
journalctl -u earlyoom --since '2 hours ago'   # кого и когда прибил earlyoom
systemctl status cron                          # не в OOM-цикле ли планировщик
grep -E 'SKIP:|TIMEOUT:|REAP ' /var/www/html/storage/logs/schedule.log | tail -20
tail -20 /var/www/html/storage/logs/watchdog.log        # пусто = сторожа успевают
mysql -e 'select ran_at,healthy,summary from cabinet_probe_runs order by id desc limit 5' laravel
sar -r -s 00:00                                # история памяти за сутки
pgrep -fc 'artisan'                            # норма ~16-22
```

Три числа нормы на 16 ГиБ: `avail` ≈ 14–15 ГБ, `procs` ≈ 60–70, `php` ≈ 22.
Строки `SKIP:` — это обёртка отработала штатно. Строки `REAP` — она подобрала
зависший процесс: не авария, но повод посмотреть, какая команда его оставила.

## 8. Авто-деплой каждые 30 минут (H1933, 30-07-2026)

По решению MG деплой идет без человека: root-крон
(`scripts/server_guards/cron/root.crontab`, ставится `server_guards_apply.sh`)
каждые 30 минут зовет `/usr/local/sbin/systema-auto-deploy-run.sh`. Обертка
сверяет `HEAD` с `origin/main`; отстал — гонит штатный `deploy.sh` (ff-only,
миграции, OPcache, Horizon, смоук, `guards:verify`) и затем НЕЗАВИСИМО проверяет,
что сервер жив: смоук еще раз, `MemAvailable ≥ AUTO_DEPLOY_MIN_AVAILABLE_MB`,
`php-fpm`/`mysql`/`cron` active, Horizon RUNNING. Числа — в
`scripts/server_guards.conf` (`AUTO_DEPLOY_*`).

**Провал деплоя или здоровья → автооткат, если он безопасен.** Если диапазон
деплоя не содержит `database/migrations/` (подавляющее большинство выкладок),
обертка сама возвращает прежний коммит (`deploy.sh --rollback <sha>` — тот же
конвейер без pull и без миграций), снова проверяет здоровье, и сайт живет на
старом коде — человек чинит сломавший коммит без спешки. Если миграции были —
отката НЕТ (`migrate --force` необратим, автоматический реверс схемы может
съесть данные), нужен человек срочно.

**В любом провальном исходе ставится предохранитель** `storage/auto_deploy.disabled`
(внутри — причина с меткой времени) и будущие авто-деплои останавливаются.
Severity в `guards:verify` / `cabinet:probe` (H2066, 01-08-2026):

| Метка в причине | Severity | Когда |
|---|---|---|
| `[rolled-back]` | **warning** (soft) | откат на прежний SHA, сайт жив |
| `[blocked-preflight]` | **warning** (soft) | HEAD не сдвинулся, health чист (грязное дерево и т.п.) |
| `[timeout-alive]` | **warning** (soft) | deploy/rollback 124/137, но смоук 200 |
| без метки | **critical** | прод может быть нездоров / откат не помог |

**С H2149 (02-08-2026) мягкие метки перепроверяют себя сами** — см. §8.2. Жёсткое
(без метки) срабатывание по-прежнему ждёт человека и не снимается автоматически
никогда.

Без soft-меток `cabinet:probe` шлет critical в Telegram каждые 15 минут, пока
человек не разберется и не удалит файл. Пропажа cron-строки — warning.
Tracked dirty (не `public/docs/*.pdf`) на `APP_DIR` — warning **до** breaker,
чтобы не ждать следующего `*/30`.

**Зеркало root-crontab (H1941).** `cabinet:probe` / `guards:verify` крутятся от
`www-data` и не читают `/var/spool/cron/crontabs/root` (600). Снимок
`storage/app/server_guards/crontab-root.installed` (644) пишут: (1) эта
обёртка **каждый** `*/30` после flock — даже когда `HEAD` уже на `main` и
деплоить нечего; (2) `deploy.sh` перед `guards:verify`; (3)
`server_guards_apply.sh` после установки root-cron. Без (1) снимок старел
между деплоями, и soft-probe мог не увидеть ручное снятие auto-deploy.

```sh
tail -20 /var/www/html/storage/logs/auto_deploy.log   # что делал авто-деплой
cat /var/www/html/storage/auto_deploy.disabled        # почему стоит (если стоит)
rm /var/www/html/storage/auto_deploy.disabled         # снять предохранитель после разбора
cat /var/www/html/storage/app/server_guards/crontab-root.installed  # снимок root-cron для probe
```

### 8.1 Soft-alert playbook (agents)

Каталог причин, safe vs never-auto, incident log, `ops:soft-remediate`:

[SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md)  
(H2148 — обновлять при новых метках breaker / soft TG scopes).

### 8.2 Разбор случая — tracked dirty → soft-guards (01-08-2026)

**Это не падение кабинета.** В soft-TG заголовок
`Кабинет: soft-сбой (guards)` и строки вида
`guards/auto-deploy: … [blocked-preflight]`,
`tracked dirty … config/marathon_landing_copy.php`.
Главная и кабинет могут отдавать 200; critical «кабинет упал» — другой путь.

**Что случилось**

1. **19:28Z** — на проде правили tracked
   [`config/marathon_landing_copy.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/marathon_landing_copy.php)
   (честный framing post-5 / отзыв «Сакральная география»).
2. **19:30Z** — root auto-deploy (`852da14b` → main) упёрся в dirty-gate:
   рабочее дерево **≠** тогдашнему `origin/main` →
   `storage/auto_deploy.disabled` с `[blocked-preflight]`, HEAD не сдвинулся, health чист.
3. Позже [#1045](https://github.com/gasyoun/Systema-Sanscriticum/pull/1045) на
   `main` с **тем же** текстом → `git diff origin/main -- <file>` стал пустым.
4. Восстановление: убедиться, что dirty = origin → `rm storage/auto_deploy.disabled` →
   `bash deploy.sh` (H2066 сам сбрасывает dirty, совпадающий с origin). HEAD →
   `447bc544`; `guards:verify` + `cabinet:probe` зелёные.

**Что делать (человеку / агенту) — лестница**

```sh
ssh -o BatchMode=yes -o ConnectTimeout=10 root@193.232.229.92
cd /var/www/html
cat storage/auto_deploy.disabled          # ждать [blocked-preflight] / причину
git status --porcelain --untracked-files=no
git fetch origin
git diff origin/main -- <грязный-файл>    # пусто → безопасный путь ниже
# если пусто (dirty == origin/main):
rm -v storage/auto_deploy.disabled
bash deploy.sh
sudo -u www-data env HOME=/tmp php artisan guards:verify
sudo -u www-data env HOME=/tmp php artisan cabinet:probe
# если НЕ пусто: fuse НЕ снимать вслепую — сначала PR уникального hotfix
# или stash/checkout, потом deploy
```

**Что нужно и чего не нужно делать человеку**

| Нужно | Делать так | Не делать |
|---|---|---|
| Текст лендинга / постов в канал | PR → `main` → auto-deploy | `nano config/*.php` на VPS |
| Цитата отзыва | env `MARATHON_TESTIMONIAL` / MarketingSetting | правка tracked `config/` на проде |
| PDF оферты / политики | только `public/docs/*.pdf` (разрешённый dirty) | любые другие tracked-пути на проде |
| Снять soft-алерт | разобрать fuse, затем `rm …/auto_deploy.disabled` + deploy | удалять fuse, не читая причину и без smoke 200 |

Класс: [Uprava FINDINGS §280](https://github.com/gasyoun/Uprava/blob/main/FINDINGS.md).
Danger: [Uprava DANGER_FACTS — Systema](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md).

### 8.2 Мягкий предохранитель перепроверяет себя (H2149, 02-08-2026)

§8.1 — ручная лестница выхода из ситуации, которой **не должно было быть**.
Предохранитель это **файл**, а условие, из-за которого он встал, — **состояние**.
Состояние в том случае вылечилось само (грязный файл стал равен `origin/main`,
как только доехал [#1045](https://github.com/gasyoun/Systema-Sanscriticum/pull/1045)),
а файл остался. Старая строка

```sh
[ -f "$BREAKER" ] && exit 0
```

глушила **каждый** следующий тик молча и навсегда: прод простоял 5 коммитов
позади ~40 минут, пока человек не удалил файл руками. Ничего в системе не
перепроверяло причину — и не сообщало, что уже не перепроверяет.

**Теперь** обёртка на каждом тике смотрит на ПОСЛЕДНЮЮ строку предохранителя:

| Что видит | Что делает |
|---|---|
| нет мягкой метки (critical) | выходит молча, как раньше — **только человек** |
| мягкая метка, но прошло < `AUTO_DEPLOY_RETRY_AFTER_MINUTES` | ждёт следующий слот |
| мягкая метка, но `health_check` не чист | **не** снимает, пишет причину в лог |
| мягкая метка, машина жива, пауза прошла, повторов < `AUTO_DEPLOY_MAX_AUTO_RETRIES` | снимает предохранитель и **пробует деплой снова** |
| повторы исчерпаны | оставляет файл + дописывает строку `auto-retry исчерпан` |

Мягкие метки: `[blocked-preflight]`, `[blocked-dirty]`, `[timeout-alive]`,
`[rolled-back]` — ровно те, что `guards:verify` считает warning'ом.

**Три свойства, которые здесь важнее удобства:**

1. **В больную машину не возвращаемся.** Снятию всегда предшествует живой
   `health_check` (смоук + память + юниты + Horizon), а не только метка.
2. **Мигание доходит до человека.** Счётчик подряд идущих само-снятий живёт в
   `storage/framework/auto-deploy-retries` — **отдельным файлом**, потому что сам
   предохранитель мы удаляем; обнуляет его РОВНО одно событие — успешный деплой.
   Выбран лимит (3 ≈ полтора часа) — авто-повтор честно говорит, что сдался.
   Severity при этом сознательно НЕ повышается до critical: причина мягкая, сайт
   жив, а ложный «хост лежит» — ровно тот класс ошибки, что чинили H2066/H2104.
3. **След переживает снятие.** Текст снятого предохранителя уезжает в
   `storage/logs/auto_deploy_breaker_history.log`, иначе вопрос «почему прод не
   деплоился в 19:30» стал бы неотвечаемым.

```sh
tail -30 /var/www/html/storage/logs/auto_deploy_breaker_history.log  # что снималось само и почему
cat /var/www/html/storage/framework/auto-deploy-retries              # сколько повторов подряд
```

Регрессия закреплена
[`scripts/server_guards/sbin/test_systema_auto_deploy_run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_auto_deploy_run.sh)
(14 проверок, temp-dir, без сети и прода). Тест проверен на **обеих** версиях
обёртки: против до-H2149 копии он падает 5 из 14 — тест, зелёный на сломанном и
на починенном коде, не доказывает ничего.

> **Правка managed-файла: код выкладывается, apply — отдельный шаг (issue #1143).**
> `deploy.sh` в конце зовёт `guards:verify` и **никогда** не зовёт
> `server_guards_apply.sh` (выкладка кода не должна молча менять системный
> конфиг). После merge копии РАЗНЫЕ (в git — новая, на машине — старая) — это
> честный drift. С 06-08-2026 `deploy.sh` в этом случае выходит с кодом **0** (код жив; раньше был exit 1 → rollback)
> (код уже жив), а `systema-auto-deploy-run.sh` **не откатывает** HEAD. До фикса
> exit 1 вызывал цикл «накат → rollback → soft fuse» (05-08-2026, #1143).
>
> Apply по-прежнему нужен руками (или осознанным ops-шагом):
>
> ```sh
> cd /var/www/html && bash deploy.sh           # код доехал; exit 0 при drift — ОЖИДАЕМО
> bash scripts/server_guards_apply.sh          # ставит новый managed-файл
> sudo -u www-data env HOME=/tmp php artisan guards:verify   # «все проверки пройдены»
> ```
>
> `ops:soft-remediate` **не** предлагает снять fuse, если в причине есть
> GUARDS DRIFT / managed-file / предохранители — сначала apply, иначе цикл
> вернётся. Это относится к любой строке `manifest.psv`.

> **Ловушка при написании таких тестов.** Обёртка намеренно **сбрасывает `PATH`**
> (cron даёт куцый) и делает `exec 9>"$LOCK" 2>/dev/null`, что глушит stderr до
> конца прогона. Первый черновик теста из-за этого «прошёл» 9 проверок, не
> исполнив ни строки проверяемой логики: `flock` не нашёлся в сброшенном `PATH`,
> `|| exit 0` сработал, а сообщение об ошибке ушло в `/dev/null`. Поэтому тест
> подменяет строку `export PATH=` и проверяет, что подмена удалась.

## 9. Демон MadelineProto: своя cgroup, свой потолок (H3121, 19-08-2026)

Диагностика и лечение — Opus 5 (`claude-opus-5`), handoff
[H3121](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3121-Opus_Systema-Sanscriticum_madeline-daemon-pins-cron-cgroup-throttles-all-cron_19.08.26.md).

### 9.1 Что случилось

Ночью 18→19-08-2026 прод встал на семь часов **при 8.5 ГиБ свободной памяти на
хосте**. Ни одна существовавшая проверка этого не увидела: memwatch показывал
норму, earlyoom молчал, `health_check` в авто-деплое был зелёным, cabinet:probe
сообщил ровно одно — «авто-деплой остановлен предохранителем».

Причина: демон `MadelineProto worker`, поднятый 17-08 внутри `schedule:run`,
**остался в cgroup `system.slice/cron.service`**. cgroup наследуется на fork и
не меняется от того, что процесс отцепился к `PPID 1`. За 33 часа он вырос до
**2.29 ГиБ RSS + 2.0 ГиБ swap** и утёк **2 774 дескрипторами, все на один файл**
`storage/logs/madelineproto.log`. Он постоянно висел над `MemoryHigh=2G` — тем
самым потолком из §4, — и ядро тормозило **всю группу** в
`mem_cgroup_handle_over_high`.

| Что стояло | Как это выглядело |
|---|---|
| Авто-деплой (`*/30`, root) | `composer install` 3 с → **16 мин**, 5 провалов, предохранитель 4×, прод отстал на 3 коммита |
| `schedule:run` (`* * * * *`) | `TIMEOUT: exceeded 900s` каждую минуту — планировщик не доделал ничего за ~7 часов |
| `cabinet:probe` (`*/15`) | `WATCHDOG TIMEOUT: exceeded 120s` — лежал сам сторож |
| `heartbeat:ping` (`*/5`) | `WATCHDOG TIMEOUT: exceeded 60s` — пропущен пульс Better Stack |
| Хост | swap 8 191/8 192 МиБ (`SwapFree: 28 kB`), load ~3.9 при простаивающем CPU |

**Диагностический признак, который всё расшифровал:** один и тот же
`composer install --no-dev` шёл **3 секунды по SSH** (там своя slice) и
**16 минут под кроном**. Любой разбор «прод тормозит», который меряет только по
SSH, этот класс аварий не увидит никогда.

### 9.2 Чего делать НЕЛЬЗЯ

- **Поднимать `MemoryHigh`/`MemoryMax` у `cron.service`.** Потолок отработал
  ровно как задуман: превратил OOM (§1) в торможение. Виноват не он, а то, ЧЕЙ
  бюджет тратил демон.
- **Поднимать `SYSTEMA_AUTO_DEPLOY_MAX` выше 1500 с.** Деплой — жертва, не
  причина; каждый провалившийся прогон печатал `npm skip`.
- **Убивать демона по таймеру, не меняя cgroup.** Он родится там же и снова
  начнёт есть чужой бюджет.

### 9.3 Что поставлено

1. **Юнит `systema-madeline-daemon.service`** — главный процесс
   `php artisan telegram-support:daemon` живёт в собственной cgroup, и демон,
   рождаясь из него, наследует именно её. Числа —
   `MADELINE_MEMORY_HIGH` / `MADELINE_MEMORY_MAX` / `MADELINE_TASKS_MAX` в
   [`scripts/server_guards.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards.conf).
   Здоровый демон — ~106 МиБ, потолок 1 ГиБ.
2. **Потолки надзора** (`MADELINE_DAEMON_MAX_RSS_MB`, `..._MAX_FDS`,
   `..._MAX_AGE_HOURS`): демона гасят и поднимают заново, не дожидаясь ядра.
   Безопасно ровно потому, что демон теперь свой — перезапуск не задевает ни
   cron, ни supervisor. Течь дескрипторов — дефект vendor'а MadelineProto,
   починить нельзя, **ограничить можно**.
3. **`9>&-` во всех трёх обёртках** (`schedule`, `watchdog`, `auto-deploy`).
   Второй дефект того же инцидента: демон, рождённый внутри `schedule:run`,
   уносил с собой **открытый fd 9** — тот самый, на котором висит `flock`
   планировщика. `flock` отпускается только при закрытии ПОСЛЕДНЕГО fd, поэтому
   ни `timeout`, ни `kill`, ни `exit(75)` замок освободить не могли: планировщик
   деградировал до одного прогона в 31 минуту (реклейм по `STALE_SECONDS`).
   Регрессия закреплена — тесты 4 и 5 в
   [`test_systema_schedule_run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_schedule_run.sh)
   падают, если убрать `9>&-`.
4. **`guards:verify` научился видеть троттлинг и мёртвый планировщик:**
   - `cgroup` — `cron.service` над `memory.high` → critical (≥80 % → warning);
   - `scheduler-stamp` — возраст отметки завершённого `schedule:run`
     (`storage/framework/schedule-run.stamp`, пишет обёртка; SKIP её **не**
     обновляет) старше `SCHEDULER_STAMP_MAX_MINUTES` → critical.
   Обе находки уезжают в Telegram тем же плечом, что и все остальные
   (`cabinet:probe` → §5).
5. **`health_check()` авто-деплоя** больше не смотрит только на хост: строка
   `cgroup:cron-over-high(<current>/<high>)` появляется в `$fails`.
6. **Текст предохранителя называет реальный шаг.** Было — «разобрать npm/vite
   или MAX=1500s» при `npm skip` в том же логе. Стало — последняя строка
   `deploy.sh` из `storage/logs/auto_deploy_last_run.log` плюс приписка про
   троттлинг, когда он есть.

### 9.4 Четыре дефекта, которые нашлись только на живом проде

Ни один из них не виден ни в тесте, ни в code review — все четыре вылезли в
первые двадцать минут после установки юнита. Записаны здесь потому, что каждый
воспроизведётся в любом следующем «долгоживущий процесс под systemd» на этой
машине.

1. **Паттерн поиска демона нельзя строить на одном пути сессии.** Строка разбора
   `for p in $(pgrep -f "MadelineProto worker"); do cat /proc/$p/cgroup; done`
   содержит путь сессии в собственной командной строке и попадает в выборку.
   Реаперу это безразлично, а надзор гасит всё, что живёт в **чужой** cgroup —
   а оболочка администратора всегда живёт в чужой. Без маркера человек,
   посмотревший на демона, через минуту терял свой ssh. Паттерн требует
   `(MadelineProto worker|madeline-ipc) <session>`.
2. **`StartLimitIntervalSec` в `[Service]` systemd молча игнорирует** —
   `Unknown key 'StartLimitIntervalSec' in section [Service], ignoring` в
   journal. Предохранителя от start-limit фактически не было. Место директивы —
   `[Unit]`.
3. **`sleep($interval)` глухой к SIGTERM.** `systemctl restart` ждал полный
   `TimeoutStopSec` и добивал процесс SIGKILL (`Failed with result 'timeout'`).
   Ловушка сигнала + посекундный сон.
4. **Ждать мягкого выхода от демона бессмысленно.** Даже с ловушкой рестарт
   занимал 30 с: `KillMode=control-group` шлёт SIGTERM и демону, а его shutdown
   падает в `Revolt\EventLoop … DriverSuspension` — он уходит только по SIGKILL.
   `TimeoutStopSec=10s`. Каждая лишняя секунда этого окна — секунда, в которую
   заход cron поднимет демона под собой (и следующий заход надзора его погасит,
   но зачем).

Отдельно: **`deploy.sh` перезапускает надзор рядом с Horizon.** У долгоживущего
CLI-процесса та же болезнь — без рестарта он вечно крутит код, загруженный при
старте. Это наблюдалось прямо в проходе H3121: правка приехала, а поведение не
изменилось, потому что процесс был запущен до неё.

### 9.5 Замер после лечения (19-08-2026, 07:50 UTC)

| Метрика | Во время аварии | После |
|---|---|---|
| `cron.service` `memory.current` | 2.52 ГиБ | **164 МиБ** |
| `cron.service` `memory.swap.current` | 2.0 ГиБ | **152 КиБ** |
| `memory.events` `high` | +24 826 001 и растёт | **24 834 448, дельта 0 за 35 мин** |
| Хост `SwapFree` | 28 КБ | **1.9 ГиБ** |
| `schedule.log` за час | почти сплошной SKIP | **172 `Running` / 5 `SKIP`** |
| Возраст отметки планировщика | — | **52 с** |
| `WATCHDOG TIMEOUT` с 06:00 | каждые 5 и 15 мин | **0** |
| `telegram-support:sync` | таймаут 900 с | **37 с DONE** |
| Демон | 2.29 ГиБ, 2 774 fd, cgroup `cron.service` | **101 МиБ, 23 fd, cgroup `systema-madeline-daemon.service`** |

Пять `SKIP` за час — это рестарты юнита в этом же проходе, а не дефект: медленный
заход делает следующую минуту `SKIP`, ровно как задумано в §2.5.

### 9.6 Как проверить руками

```
systemctl status systema-madeline-daemon
cat /proc/$(pgrep -f 'MadelineProto worker')/cgroup     # НЕ cron.service
ls /proc/$(pgrep -f 'MadelineProto worker')/fd | wc -l  # должно быть ~120, не тысячи
cat /sys/fs/cgroup/system.slice/cron.service/memory.events   # high не растёт
journalctl -u systema-madeline-daemon -n 50
sudo -u www-data env HOME=/tmp php /var/www/html/artisan guards:verify
```

> **`env HOME=/tmp` здесь обязателен — без него проверка врёт КРАСНЫМ.**
> `sudo -u www-data` оставляет `HOME=/var/www`, и в таком окружении
> `Process::run()` внутри `ShellSystemInspector` не запускается вовсе: каждый
> `systemctl` и `crontab` возвращает null, а `guards:verify` печатает 14
> «пропавших предохранителей», включая «crontab www-data пуст» и «cron не
> active» — на совершенно здоровой машине. Настоящий крон эту ловушку обходит,
> потому что `HOME=/tmp` стоит первой строкой в
> [`cron/app-user.crontab`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/cron/app-user.crontab);
> руками про это забывают. Поймано 19-08-2026 (H3121): красный вывод сначала
> приняли за настоящую поломку прода. Проверка от root (`php artisan
> guards:verify` в `/var/www/html`) достоверна всегда.

Кто держит замок планировщика, если `schedule.log` снова полон `SKIP`:

```
ls -l /proc/*/fd 2>/dev/null | grep schedule-run.lock
```

## 10. Четыре живые опасности, найденные аудитом 19-08-2026 (H3181, волна 1)

Раздел 9 закрыл класс «cgroup-троттлинг». Читая ту же машину дальше, аудит
нашёл ещё четыре — **каждую замером, не рассуждением**. Ни одну из них не
поймал ни один существовавший предохранитель, и именно поэтому у всех четырёх
строк в журнале инцидентов стоит `guard = none`.

План, из которого выросла эта волна, и ещё три волны за ней:
[PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md)
· проверки и учения —
[VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md).

| # | Опасность | Замер 19-08-2026 | Что поставлено | Новая проверка |
|---|---|---|---|---|
| H-1 | `/tmp` — tmpfs без потолка | стоковый `size=50%` внутри LXC = 126 ГиБ (половина памяти **хоста**); `/tmp/hindi_reasr` 7.6 ГиБ + `/tmp/whisvenv` 452 МиБ держали своп на 5618/8192 МиБ | drop-in `size=@@TMP_TMPFS_SIZE@@` к `tmp.mount` + правило старения `tmpfiles.d` | `tmpfs-cap` |
| H-2 | Монитор здоровья Samudra падал каждые 15 минут | `PermissionError` на `/opt/samudra/logs/health_monitor.log`: каталог `root:root`, юнит ходит от `samudra`. Мёртв с 14-08, сказать было некому | `chown -R samudra:samudra /opt/samudra/logs` | `failed-units` |
| H-3 | Off-site бэкап был **ложно-зелёным** | см. ниже — отдельный разбор | пороги возраста **и размера** в `server_guards.conf` | `backup-fresh` |
| H-4 | `nginx` с `Restart=no` | всё остальное критичное перезапускается; единственный процесс, отдающий сайт, оставался лежать | drop-in `Restart=on-failure`, `RestartSec=5s` | манифест (`critical`) |

### 10.1 Почему `/tmp` — это память, а не диск

Внутри LXC `size=50%` считается от памяти **хоста** (252 ГиБ), а не гостя
(16 ГиБ). Файл в `/tmp` — это заявка на RAM, и её структурно не видел ни один
предохранитель: `earlyoom` смотрит на процессы, `memwatch` — на итог,
проверка `cgroup` — на бюджет крона. Потолок и старение живут в
`server_guards.conf` (`TMP_TMPFS_SIZE`, `TMP_AGE_DAYS`).

**Упёрся законный конвейер — поднимите значение и примените заново; не снимайте
потолок.**

#### Но на `.92` потолок изнутри контейнера поставить НЕЛЬЗЯ — замерено 19-08-2026

Это выяснилось только на живой машине, и это главный технический вывод волны.

```
# mount -o remount,size=4G /tmp
mount: /tmp: fsconfig() failed: tmpfs: Invalid uid '100000'.
```

`/tmp` несёт в опциях `uid=100000,gid=100000` — это **хостовый** id
отображённого root'а (idmap непривилегированного LXC). Значит, tmpfs создал
**хост при старте контейнера**, а не systemd гостя:

- `journalctl -b -u tmp.mount` — **ни одной записи**: systemd не монтировал, он
  ПОДОБРАЛ уже существующее монтирование (`active since 29-07-2026`);
- `/etc/fstab` пуст (`UNCONFIGURED FSTAB FOR BASE SYSTEM`);
- `remount` изнутри заново разбирает сохранённый в суперблоке `uid=100000`,
  который в пространстве имён контейнера не отображается, и падает —
  **в том числе при явном `uid=0,gid=0`**.

Отсюда три следствия, и все три надо знать, прежде чем «чинить»:

1. **Ни drop-in к `tmp.mount`, ни строка в `/etc/fstab` не помогут.** Обе
   поверхности принадлежат systemd контейнера, а монтирует не он. Поставить их
   и успокоиться означало бы завести декоративный предохранитель — ровно ту
   зелёную лампочку над пустым сейфом, против которой написан §10.2. Drop-in в
   манифесте оставлен намеренно: он ничего не стоит и заработает в тот день,
   когда `/tmp` начнёт монтировать сам контейнер.
2. **Потолок ставится на стороне хоста Proxmox** — это P5 плана, задача Артёма,
   и решение D4 (граница хоста) выносит её из работы агента.
3. **`umount` + свежий `mount` изнутри не годится**: он уничтожил бы всё
   содержимое `/tmp`, включая то, что решением D18b прямо НЕ покрыто, и рискнул
   бы живыми открытыми файлами на Tier-0 машине.

Что при этом **работает и уже стоит**: проверка `tmpfs-cap`, которая остаётся
`critical` — опасность-то никуда не делась — но в этом случае называет хост, а
не `server_guards_apply.sh`. Критическая тревога, посылающая человека к скрипту,
который заведомо бессилен, хуже отсутствия тревоги.

#### И вторая половина W1a оказалась не тем, чем её описывал план

Правило старения `tmpfiles.d` поставлено, но **поведения оно сегодня не
меняет**, и об этом надо сказать прямо. Стоковый Debian уже несёт ровно такое
правило:

```
/usr/lib/tmpfiles.d/tmp.conf:11:  q /tmp 1777 root root 10d
```

а `systemd-tmpfiles-clean.timer` **активен и ходит раз в сутки** (последний
прогон 19-08-2026 15:05 UTC). Наш `d /tmp 1777 root root 10d` выигрывает у
стокового лишь по алфавиту имён файлов — и задаёт ровно тот же срок.

Посылка [IMPLEMENTATION §1.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md)
«стоковый `systemd-tmpfiles-clean` их не вычистил» была неверна: он не вычистил
их потому, что они были **моложе десяти суток** — `hindi_reasr` сутки,
`whisvenv` пять. Ждать оставалось недолго; 7.6 ГиБ свопа — нет.

Файл всё же оставлен, и ровно ради трёх вещей: число переехало в
`server_guards.conf` (одно место для чисел), обновление дистрибутива больше не
может сменить его молча, и манифест замечает пропажу правила. Это скромный
выигрыш — но он настоящий, в отличие от «мы поставили старение».

**Чтобы правило стало настоящим предохранителем, `TMP_AGE_DAYS` надо снизить**
(3 суток поймали бы `whisvenv`). Это решение человека: оно про автоматическое
удаление чужих файлов на Tier-0 машине, решение D10 числа не называло, а
контракт автономии (D17) запрещает агенту придумывать третий путь мимо
названного планом умолчания. Пока потолка размера нет (P5), агрессивное
старение — единственный рычаг, который у нас вообще остаётся.

### 10.2 Ложно-зелёный off-site: возраст без размера — лампочка над пустым сейфом

Самая дорогая находка волны. `backup:monitor` **называл `yandex_disk`
здоровым**. Лежали там два файла по 11.7 МиБ — при локальном архиве в 1.33 ГиБ.
Это не копии, а обрезки оборвавшихся загрузок. История по логам:

| Когда | Что произошло |
|---|---|
| 10-08 02:02 | `backup:run`: локальный архив записан, `yandex_disk` — `Unauthorized` (WebDAV 401) |
| 13-08 14:25 | повтор руками — **HTTP 413**: WebDAV Яндекса не принимает файл в 1.4 ГиБ одним PUT |
| 13-08 14:27 и 15:00 | ещё два повтора — «Empty reply from server» на середине; каждый оставил обрезок в 11.7 МиБ |
| 17-08 02:01 | еженедельный прогон умер на `RecursiveDirectoryIterator … telegram-harvest/pilot/manifests: Permission denied` — архива не появилось **нигде** |

Отсюда `BACKUP_MIN_ARCHIVE_MB`: у обрезка дата свежая, и проверка одного лишь
возраста подтвердила бы его как здоровую копию. **Возраст обязан проверяться
вместе с размером.**

Проверка `backup-fresh` спрашивает off-site строже локального: локальная копия
делит судьбу с контейнером, который она защищает. Ответ назначения кешируется
на час — `cabinet:probe` крутится каждые 15 минут, и ходить по WebDAV так
часто не нужно.

**Починка самой доставки (413 на 1.4-ГиБ архиве) — не волна 1.** Здесь
поставлена громкая проверка; сама доставка остаётся сломанной, пока архив не
начнут резать на части или не сменят транспорт.

### 10.3 Восстановление проверено? Наполовину

19-08-2026 на архиве `2026-08-10-02-01-48.zip` проверены целостность (CRC всех
2242 записей), завершённость дампа и правдоподобие построчных счётчиков против
живой базы — путь целиком записан в
[docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)
(раздел «Восстановление из резервной копии»). **Само разворачивание в черновую
базу (шаг 4) ещё не выполнялось** — до тех пор «копия восстановима» остаётся
обоснованным ожиданием, а не проверенным фактом.

## 11. Waiver: осознанно терпимые находки (2026-08-25)

К 25-08-2026 `tmpfs-cap` был критичен шесть дней подряд и должен был оставаться
критичным, пока P5 (потолок `/tmp` на стороне хоста Proxmox) не сделает Артём.
Цена этой честности оказалась выше пользы:

1. каждый авто-деплой печатал красный блок «ПРЕДОХРАНИТЕЛИ ПРОДА РАСХОДЯТСЯ» и
   уходил в exit 75 (#1143) — хотя drift'а managed-файлов не было;
2. `cabinet:probe` каждые сутки напоминал о tmpfs-cap в три Telegram-чата —
   сигнал, который нельзя выполнить, приучает его игнорировать;
3. рядом с постоянным ложным криком тонет настоящий drift — тот самый класс,
   из-за которого сверка prod↔репо существует (sbin-drift 05-08).

Механизм: ключи `GUARD_WAIVERS` (имена guard'ов через запятую) и
`GUARD_WAIVERS_EXPIRES` (дата YYYY-MM-DD) в `scripts/server_guards.conf`.
Находки перечисленных guard'ов до указанной даты понижаются до info: видны с
«ℹ» в `guards:verify` и как комментарий в probe, но не роняют verify, не будят
Telegram и не дают деплою exit 75.

Три правила против гниения (проверены тестами):

| Правило | Поведение |
|---|---|
| fail-closed | EXPIRES нет / не дата / истекла — waiver НЕ действует, находка остаётся критичной |
| misconfig виден | GUARD_WAIVERS задан, EXPIRES сломан — отдельный warning поверх нетронутых находок |
| мёртвый текст виден | имя waiver без находки («снова здоров» или опечатка) — info «убери из GUARD_WAIVERS» |

Чего механизм НЕ делает: не глушит саму проверку (аудитор по-прежнему меряет),
не трогает warning/critical других guard'ов и не отменяет P5. После фикса хоста
секцию из conf снять — об этом напомнит info «waiver не понадобился».

## 12. Докатка Yandex-частей: свой юнит вместо cron.service (H3410, 25-08-2026)

Диагностика — SOS-ход 24-08-2026 (OxAlpha, `opencode/x-preview-f-free`), лечение
— Sonnet 5 (`claude-sonnet-5`), handoff
[H3410](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3410-Sonnet_Systema-Sanscriticum_yandex-offsite-resilience_24.08.26.md).

### 12.1 Что случилось

`backup:resume-yandex-parts` жила строкой `Kernel.php::schedule()` (ежедневно
04:10 MSK) — то есть исполнялась внутри `cron.service`, тем же поддеревом,
чей бюджет памяти чинили §1 и §9. 24-08-2026 группа
`2026-08-24-02-02-32.zip` (~2 ГиБ, 39 частей по 50 МиБ) уехала лишь одной
частью из 39: `strace` на прогоне докатки показал PUT, застрявший в TLS
`sendto()` EAGAIN-цикле **без байта прогресса 30+ минут**, хотя
`AppServiceProvider` уже нёс `CURLOPT_LOW_SPEED_LIMIT=1024`/`LOW_SPEED_TIME=180`
(§ниже). Manual-резюме той же ночью с отдельной SSH-сессии (вне cron-cgroup)
тоже вставало в стойку на PUT части 38.

Зависшая команда под `cron.service` — ровно тот класс, что уронил прод
28-07-2026: держит `schedule:run` в foreground, следующий минутный тик
накладывается. Часть класса уже смягчена (обёртка `timeout 900s`, §2.3), но
900-секундный потолок — это пятнадцать минут держать планировщик заложником
одной части, а MG 24-08-2026 уже принял решение о срезе частей 50→20 МиБ
(`4899c1bd`) отдельно от этого лечения.

### 12.2 Что поставлено

1. **Юнит `systema-yandex-resume.service` + `.timer`** — тот же рецепт, что
   §9 для демона MadelineProto: главный процесс живёт в собственной cgroup,
   свой `MemoryHigh`/`MemoryMax` (`YANDEX_RESUME_MEMORY_HIGH`/`_MAX` в
   `scripts/server_guards.conf`), свой `TimeoutStartSec`
   (`YANDEX_RESUME_TIMEOUT_SECONDS`, 1200 с — щедрый потолок ЦЕЛОГО прогона,
   не одного PUT). Зависший PUT теперь убивает только этот юнит, не крон.
2. **Такт — часовой, не суточный** (deliverable «cadence»):
   `resumeOffsite()` дёшев, когда докатывать нечего (один листинг off-site
   диска и выход), а окно «группа неполная и никто не знает» сжимается с
   суток до часа.
3. **Kernel.php больше не планирует эту команду** — строка удалена, на её
   месте комментарий-указатель сюда. Двойной запуск (крон + таймер) не
   тестировался и не нужен: весь смысл в том, что судьбы теперь разные.
4. **Жёсткий потолок на ВЕСЬ WebDAV-запрос** (`AppServiceProvider::boot()`):
   `CURLOPT_TIMEOUT=300` поверх уже стоявших `CONNECTTIMEOUT`/`LOW_SPEED_*`.
   Честно: 24-08-2026 не установлено, ПОЧЕМУ именно `LOW_SPEED_TIME=180` не
   оборвал конкретно эту EAGAIN-форму стагнации на живом проде (нужна была бы
   повторная провокация зависания, которую не стали устраивать на боевом
   канале) — новый потолок подстраховывает независимо от ответа на этот
   вопрос, а не заменяет его.
5. **Наблюдаемость** (`app/Listeners/Backup/SplitUploadToYandex.php`):
   каждый PUT части логирует `bytes`/`seconds`/`bytes_per_sec` — раньше
   единственным симптомом стагнации было отсутствие следующей строки лога.
   `resumeOffsite()` заканчивается ГРОМКОЙ строкой исхода (`Log::error`, если
   после прогона остались неполные группы; `Log::info`, если нет) вместо
   молчаливого выхода, плюс `register_shutdown_function`-канарейка на случай,
   если процесс убьют мимо `finally` (SIGKILL таймаутом/OOM) — раньше «докатка
   тихо не запустилась вовсе» была неотличима от «докатка идёт».

### 12.3 Что осталось человеку

Прямого доступа не требует — юниты ставит `server_guards_apply.sh` (root на
`.92`, `bash scripts/server_guards_apply.sh` после деплоя кода). Applied
managed-файл, как и `systema-madeline-daemon` (§9): код выкладывается,
`apply` — отдельный шаг.

_Dr. Mārcis Gasūns_
