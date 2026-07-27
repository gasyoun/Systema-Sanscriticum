# Telegram userbot / MTProto runner — inventory (Phase 0.1)

_Created: 11-07-2026 · Last updated: 27-07-2026 (§4.1 — инцидент EMFILE и предохранители синка)_

> **P0.1** узел карты
> [`IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md)
> над зонтичным
> [`ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md).
> Иван операционализировал userbot **вне git** (`telegram-userbot/` в
> [`.gitignore`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.gitignore)),
> поэтому серверную часть агент видеть не может. Этот док — **инвентарь того, что
> ЕСТЬ в репозитории** (единственная сессия, три консумента, cross-command lock,
> что реально в планировщике), **фиксация решения MG о едином раннере (D2)**, и
> **список серверных фактов, которые подтверждает Иван** (уже вынесены ему в
> [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
> §Telegram, T1–T3, [PR #455](https://github.com/gasyoun/Systema-Sanscriticum/pull/455)).
>
> Провенанс: [H570](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H570-Opus_Systema-Sanscriticum_telegram_p01_ivan_userbot_inventory_11.07.26.md),
> Opus 4.8 (`claude-opus-4-8`), 11-07-2026. Составлен по коду на `main`.

---

## 1. Резюме

- Аккаунт **один**, MadelineProto-**сессия одна** (support-сессия). Все MTProto-пути
  репозитория переиспользуют её через
  [`MadelineClientFactory`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineClientFactory.php).
- В репозитории **три** консумента этой сессии (все — artisan-команды, все под общим
  cross-command lock`ом), см. §2.
- В планировщике ([`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php))
  живёт **ровно один** из них — `telegram-support:sync` (`everyMinute`, при
  `TELEGRAM_SUPPORT_ENABLED=true`). Харвестер и peer-discovery **не в планировщике** —
  запускаются вручную с хоста.
- **Решение MG (D2, закреплено):** канонический раннер синка — **cron/supervisor Ивана
  запускает Laravel-команду `php artisan telegram-support:sync`** (один код-путь).
  Отдельный самостоятельный MadelineProto-скрипт **не держим**.
- **Ключевой риск (§4):** cross-command lock защищает от `AUTH_RESTART` только те три
  artisan-команды, что делят одну кэш-блокировку. **Отдельный внешний демон
  (`telegram-userbot/`, вне git), открывающий ту же сессию, через lock НЕ проходит** —
  это ровно тот сценарий «двух демонов», что даёт разлогин. Поэтому безопасность зависит
  от того, ЧТО именно крутит cron Ивана — что и спрашивает T1.

---

## 2. Инвентарь консументов MTProto-сессии (репо-сторона)

Все три открывают одну и ту же support-сессию и обязаны сериализоваться через
[`LocksMadelineSession`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/LocksMadelineSession.php).

| # | Команда | Класс | В планировщике? | Lock | Назначение |
|---|---|---|---|---|---|
| 1 | `telegram-support:sync` | [`SyncTelegramSupport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SyncTelegramSupport.php) | **Да** — `everyMinute`, `withoutOverlapping(<таймаут+5 мин>)`, `onOneServer` ([`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php)); TTL выведен из watchdog-таймаута, см. §4.1 | `withMadelineSessionLock()` (live-путь) | Импорт чата «Отдел заботы» + пересборка дневной support-аналитики; reply-out за флагом |
| 2 | `telegram-harvest:sync` | [`SyncTelegramHarvest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SyncTelegramHarvest.php) | **Нет** — только ручной запуск с хоста | `withMadelineSessionLock()` (live-путь) | Track B: персональный харвест санскрит-групп/каналов/ЛС в корпус вне git |
| 3 | `telegram-harvest:peers` | [`DiscoverTelegramHarvestPeers`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DiscoverTelegramHarvestPeers.php) | **Нет** — ручной запуск | `withMadelineSessionLock()` | Read-only список диалогов аккаунта для наполнения `TELEGRAM_HARVEST_PEERS` |

**У всех трёх есть офлайн-путь `--payload=<json>`**, который вообще не открывает MadelineProto
(локальный импорт нормализованных сообщений) — этот путь безопасен всегда и lock не берёт.
Гонка возможна только на **live**-пути (когда сессия реально открывается).

---

## 3. Модель единой сессии

### 3.1. Одна сессия на аккаунт
[`MadelineClientFactory`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineClientFactory.php)
открывает единственный залогиненный клиент на support-сессию
(`services.telegram_support.session`, абсолютный путь). Комментарий класса прямо
фиксирует: **вторая параллельная сессия на том же аккаунте → `AUTH_RESTART` / авто-логаут**.
Архитектурное решение «одна сессия» — D1 в
[`Uprava/docs/DECISIONS_telegram_harvester.md`](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_telegram_harvester.md).

### 3.2. Cross-command lock
[`LocksMadelineSession`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Concerns/LocksMadelineSession.php)
оборачивает live-работу в `Cache::lock('madeline-session', 900)` (TTL 900 с
авто-освобождается при смерти держателя; ожидание блокировки — 5 с, потом «busy» →
no-op с `Log::warning`). Это осознанно **сильнее**, чем Laravel `->withoutOverlapping()`:
последний защищает команду только от самой себя, а не от **другой** команды на той же
сессии — именно так на проде и появились два IPC-демона (см. docblock трейта).

### 3.3. Планировщик
Единственная запись MTProto в
[`Kernel.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Kernel.php):

```php
$syncLockMinutes = (int) ceil(((int) config('services.telegram_support.sync_timeout_seconds', 120)) / 60) + 5;
$schedule->command('telegram-support:sync')
    ->everyMinute()
    ->withoutOverlapping($syncLockMinutes)
    ->onOneServer()
    ->name('telegram-support-sync');
```

TTL замка не константа: он **выводится** из потолка времени самого захода, иначе
зависший заход переживает собственный замок и на сессии появляется второй экземпляр
(так и случилось 27.07.2026 — см. §4.1).

Команда — **no-op**, пока `TELEGRAM_SUPPORT_ENABLED=true` не выставлен и не заданы
Client-API credentials. Харвестер (`telegram-harvest:sync`/`:peers`) в планировщик
**намеренно не внесён** — запускается человеком с хоста и только под общим lock`ом.

---

## 4. Единый раннер и риск «двух демонов»

**Решение MG (D2, §8 roadmap):** канонический раннер синка — **cron/supervisor Ивана
запускает `php artisan telegram-support:sync`** (то есть Laravel-команду, через
`schedule:run` или напрямую). `TELEGRAM_SUPPORT_ENABLED` остаётся `true`. Отдельный
самостоятельный MadelineProto-скрипт-демон **не держим**.

**Почему это критично.** Кэш-lock `madeline-session` сериализует только те процессы,
которые **проходят через него** — а это исключительно три artisan-команды из §2.
Внешний, вне-git демон `telegram-userbot/`, открывающий ту же сессию своим кодом,
**lock не берёт** и потому может работать параллельно с планировщиковым
`telegram-support:sync` → **два демона на одной сессии → `AUTH_RESTART`** (разлогин,
нужен повторный интерактивный вход с кодом). Это ровно тот отказ, ради предотвращения
которого lock и написан.

**Отсюда безопасная конфигурация:** cron Ивана **драйвит Laravel-команду** (`schedule:run`
раз в минуту → срабатывает `everyMinute`-`telegram-support:sync`, под lock`ом), и **нет**
отдельного долгоживущего MadelineProto-процесса на той же сессии. Подтверждение того, что
на проде именно так, — это ответ Ивана на **T1** (§5).

### 4.1. Инцидент 27.07.2026 — «два демона» изнутри репозитория (EMFILE)

_Дописано 27-07-2026 по факту разбора на проде._

Риск §4 сработал, но **не** от внешнего вне-git демона, как ожидалось, — источником
параллельных сессий оказался сам планировщик. Цепочка:

1. Заход `telegram-support:sync` завис (сеть/IPC) и не завершился ни за минуту, ни за час.
2. `->withoutOverlapping(10)` снимает замок по TTL, **даже если держатель жив**. Через
   10 минут стартовал второй экземпляр, ещё через 10 — третий. К 07:10 их было **десять**,
   каждый со своим IPC-демоном на одной сессии.
3. `killStaleMadelineDaemon()` тогда искал процессы по `pgrep -f madeline`, то есть каждый
   экземпляр убивал демонов **всех остальных**; те немедленно поднимались заново —
   самоподдерживающийся цикл (в `ps` это видно как пачка воркеров с одинаковым временем
   старта).
4. Дескрипторы кончились (`LimitNOFILE` = 1024 у `cron.service`): упал даже `include()`
   автозагрузчика, и в `last_sync_error` легло
   `include(.../revolt/event-loop/.../UncaughtThrowable.php): Failed to open stream: Too many open files`.
   Распознавание мёртвого IPC при этом не сработало — оно ищет Amp-обёртки, которые PHP
   в тот момент уже не мог загрузить.

Что изменено в коде (все три — предохранители, а не косметика):

- **Потолок времени захода.** [`MadelineSyncWatchdog`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSyncWatchdog.php)
  (`pcntl_alarm`, `services.telegram_support.sync_timeout_seconds`, дефолт 120 с) бросает
  `MadelineSyncTimedOut` — именно исключение, а не `exit()`, иначе `finally` не отработал бы
  и кэш-замок `madeline-session` (TTL 900 с) завис бы на 15 минут. TTL замка планировщика
  теперь **выводится** из этого таймаута в `Kernel::schedule()`, так что инвариант
  «заход умирает раньше, чем протухнет замок» держится сам, а не держится на памяти автора.
- **Точечный сброс демона.** [`MadelineSessionReaper`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/MadelineSessionReaper.php)
  (вынесен из sync-сервиса) фильтрует `pgrep` по **пути сессии**, а не по слову «madeline».
- **Ветка EMFILE.** Исчерпание дескрипторов распознаётся отдельно от мёртвого IPC и пишет
  оператору честную причину со ссылкой на `LimitNOFILE`, а не уходит в общий `fail()`.

Серверная часть (потолок `LimitNOFILE` у `cron`/`supervisor`, наличие `pcntl` в CLI) кодом
не чинится — вынесена оператору как **T6** в
[`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
§Telegram. Заодно инцидент закрывает часть §5: раннер на проде — **cron `www-data` →
`schedule:run`** (как и предполагал D2), `horizon` и `reverb` живут под **supervisor**.

---

## 5. Серверные факты — подтверждает Иван (агент не имеет прод-доступа)

Уже вынесены Ивану в его канал —
[`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)
§«Telegram — вопросы и действия оператору», T1–T3 ([PR #455](https://github.com/gasyoun/Systema-Sanscriticum/pull/455)):

- **T1 — единый раннер.** Что запускает cron/supervisor: (1) Laravel-команду
  `php artisan telegram-support:sync` **или отдельный** самостоятельный MadelineProto-скрипт?
  (2) стоит ли `TELEGRAM_SUPPORT_ENABLED=true`? **← это и есть выход P0.1: подтверждение,
  что раннер один и это Laravel-команда, а отдельного демона на сессии нет.**
- **T2 — одна сессия.** Никогда не запускать `telegram-harvest:sync` одновременно с
  `telegram-support:sync` (делят одну сессию → `AUTH_RESTART`).
- **T3 — секрет вебхука** (позже, узел P0.2 / H590): перезапустить `setWebhook` с
  `secret_token`, когда выйдет соответствующий PR.

**Что ещё стоит зафиксировать в ответе Ивана** (для полноты инвентаря — то, чего в git нет):
имена systemd/supervisor-юнитов демона; точная каденция cron; абсолютный путь session-файла
на диске и его владелец/права (`chmod 600`); отдельный ли Redis/очередь у userbot-процесса.

---

## 6. Выход P0.1 и что дальше

**Репо-сторона инвентаря — закрыта этим документом** (единственная сессия, три консумента,
lock, что в планировщике, D2-раннер, риск двух демонов). **Серверная сторона** —
подтверждается ответом Ивана на T1; до ответа P0.1 держится в GTD как `@WAITING`.

Разблокирует по DAG карты:

- **P0.3 · H591** — формализация сериализации сессии (harvester уступает под lock`ом) —
  зависит от этого инвентаря.
- **W1.3 · H594** — reply-out канарейка — зависит от подтверждённого единого раннера.
- **W3.1 · H595** — userbot под супервизией (systemd/supervisor-юниты в репо/доке,
  healthcheck, алертинг) — прямое продолжение серверной стороны §5.

## 7. Провязка

- Карта исполнения: [`IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md) (§4, узел P0.1).
- Зонтичный roadmap: [`ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md) (§5 P0.1, §8 D2).
- Вопросы Ивану: [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) §Telegram (T1–T3).
- Архитектура харвестера/сессии: [`Uprava/docs/DECISIONS_telegram_harvester.md`](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_telegram_harvester.md).
- Хэндоф: [H570](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H570-Opus_Systema-Sanscriticum_telegram_p01_ivan_userbot_inventory_11.07.26.md).

_Dr. Mārcis Gasūns_
