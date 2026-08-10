_Created: 10-08-2026 · Last updated: 10-08-2026_

# Season Live-Service — архитектура игрового сезона Сентябрь 2026

**Статус:** архитектурное решение зафиксировано · реализация: H2549 (Opus 5)
**Ритм:** Сезон 1 — 01.09.2026–01.01.2027 (4 месяца)

---

## Контекст: откуда пришли

До сезона — 24 тренажёра в 7 семействах (`/lila/`), Прана с 5 рангами,
append-only логом, стриками, лидербордами Week/Month/All-Time (H2051, H2054 —
Opus 5), витрина перков, воронка `game_events`, импорт лемм в SRS при
регистрации (H1680 — Opus 5). Детали live-service без оркестровки: каталог
игрушек без ритма и ожидания следующего события. Анализ X5 (пост 10-08-2026)
показал: у нас механики сильнее, оркестровки нет совсем.

---

## Зафиксированные решения (16/16, Rounds 1–4, 10-08-2026)

| # | Развилка | Решение |
|---|---|---|
| R1-1 | Цель | Двухконтурная: revenue + retention, оба равнозначно |
| R1-2 | Метрика | Строим «свои 7,4%» первым шагом, до сезонных механик |
| R1-3 | Контейнер | Полный live-service |
| R1-4 | Поверхность | Кабинет, авторизованные пользователи |
| R2-1 | Хранение сезона | Сезонная таблица + отдельная таблица сезонного лидерборда |
| R2-2 | Ритм | 4 месяца, начало отсчёта 1 сентября |
| R2-3 | Decay | Включить decay в сезоне (явная авторизация, см. §5) |
| R3-1 | Decay = money fence | Явная авторизация получена (MG, 10-08-2026) |
| R3-2 | Season leaderboard | Отдельная таблица кэша (не CACHE_DRIVER, SQL) |
| R3-3 | Season rewards | Отдельная таблица |
| R3-4 | Ротация паков | Ротация `/lila` паков по сезонам |
| R4-1 | Decay authorization | Отменить README §731 «не включаем» (решение 26-06-2026); в сезоне включить |
| R4-2 | Метрика-рельс | Добавить `user_id` nullable в `game_events` |
| R4-3 | Season lifecycle | Artisan-команда + Laravel scheduler |
| R4-4 | Decay floor | 50 % от ранговой стоимости следующего ранга (см. §5.2) |

---

## §1 — Метрика: «свои 7,4%»

**Что измеряем.** Доля авторизованных студентов, сыгравших хотя бы одну
игру `/lila` в неделю W, у которых в ту же неделю W есть хотя бы одно ДЗ
со статусом `submitted` или `accepted` в `homework_submissions`.

Текущий KPI D6 («≥15 % кликнувших CTA завершают регистрацию») измеряет
*вход*, метрика X5-аналога — *что происходит после входа*. Строим оба.

**Рельс (R4-2).** `game_events` намеренно создавалась без `user_id` (152-ФЗ,
анонимная воронка). Добавляем nullable-колонку: при авторизованном запросе
сервер пишет `auth()->id()`, при анонимном — NULL. Анонимная семантика таблицы
не ломается, PII-периметр не расширяется (запись делает сервер из web-сессии,
не клиент).

```sql
ALTER TABLE game_events ADD COLUMN user_id BIGINT UNSIGNED NULL
    REFERENCES users(id) ON DELETE SET NULL AFTER authenticated;
CREATE INDEX idx_game_events_user ON game_events(user_id, created_at);
```

**Запрос метрики** (еженедельно, по когорте авторизованных):

```sql
SELECT
    COUNT(DISTINCT ge.user_id) AS played_this_week,
    COUNT(DISTINCT hs.user_id) AS played_and_submitted,
    ROUND(100.0 * COUNT(DISTINCT hs.user_id)
          / NULLIF(COUNT(DISTINCT ge.user_id), 0), 1) AS hw_rate_pct
FROM game_events ge
LEFT JOIN homework_submissions hs
    ON hs.user_id = ge.user_id
    AND hs.last_activity_at >= ge.created_at - INTERVAL 7 DAY
    AND hs.status IN ('submitted', 'accepted')
WHERE ge.event = 'complete'
  AND ge.user_id IS NOT NULL
  AND ge.created_at >= NOW() - INTERVAL 7 DAY;
```

---

## §2 — Таблица `seasons`

```php
Schema::create('seasons', function (Blueprint $table) {
    $table->id();
    $table->string('title');                        // «Сезон 1: Осень 2026»
    $table->string('slug', 40)->unique();           // autumn-2026
    $table->dateTime('started_at')->nullable();
    $table->dateTime('ended_at')->nullable();
    $table->boolean('is_active')->default(false);
    // JSON: ['verb-roots','ligatures',...] — активные /lila паки в сезоне
    $table->json('enabled_packs')->nullable();
    // JSON: [{position:1,type:'prana',amount:5000},{...}]
    $table->json('rewards_config')->nullable();
    $table->timestamps();

    $table->index('is_active');
});
```

---

## §3 — Season leaderboard cache

Отдельная SQL-таблица (не Redis, не CACHE_DRIVER — R3-2). Пересчитывается
scheduled job'ом каждые N часов в течение сезона.

```php
Schema::create('season_leaderboard_cache', function (Blueprint $table) {
    $table->id();
    $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('prana_earned')->default(0); // lifetime earned за период сезона
    $table->unsignedInteger('rank_position')->default(0);
    $table->dateTime('computed_at')->useCurrent();

    $table->unique(['season_id', 'user_id']);
    $table->index(['season_id', 'rank_position']);
});
```

Пересчёт: `prana_transactions` WHERE `created_at` BETWEEN `season.started_at`
AND `season.ended_at` (или NOW()), group by `user_id`, amount > 0, sum → ранг.

---

## §4 — Season rewards

```php
Schema::create('season_rewards', function (Blueprint $table) {
    $table->id();
    $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->unsignedInteger('position');
    // prana | badge | discount_percent | custom
    $table->string('reward_type', 40);
    $table->unsignedInteger('reward_value')->default(0);
    $table->dateTime('awarded_at')->nullable();
    $table->dateTime('claimed_at')->nullable();
    $table->timestamps();

    $table->unique(['season_id', 'user_id']);
    $table->index(['season_id', 'position']);
});
```

Награды раздаются командой `season:close` по `rewards_config` из таблицы
`seasons`; для типа `prana` — вызов `PranaService::adminAdjust()`.

---

## §5 — Decay: включение и параметры

### §5.1 — Явная авторизация (отмена README §731)

README.md строка 731 (решение 26-06-2026): *«Decay остается выключенным
(`PRANA_DECAY_ENABLED=false`); сгорание не включаем»*.

**Это решение ОТМЕНЕНО** явной авторизацией MG 10-08-2026. Обоснование:
сезонный ритм требует давления возврата; без decay лидерборд накопительный
и новый студент не может догнать старого. Decay ограничен периодом сезона —
при `season:close` он отключается обратно (`PRANA_DECAY_ENABLED=false`).

`prana_balance` конвертируется в рубли (rate 10 ₽/прана, max_share 0.30).
Decay уменьшает `prana_balance` — это денежный контур. Авторизация явная,
разовая, документирована здесь.

### §5.2 — Decay floor: 50% от ранговой стоимости следующего ранга

Ранги (по `lifetime_prana`, траты не уменьшают):

| Ранг | min | Следующий | Floor (50% next.min) |
|---|---|---|---|
| Śiṣya | 0 | 200 | 100 |
| Adhyāyin | 200 | 1 000 | 500 |
| Snātaka | 1 000 | 3 000 | 1 500 |
| Ācārya | 3 000 | 8 000 | 4 000 |
| Paṇḍita | 8 000 | — (топ) | 0 |

Конфиг: добавить `'floor_mode' => env('PRANA_DECAY_FLOOR_MODE', 'rank_based')`
и `'floor_fixed' => (int) env('PRANA_DECAY_FLOOR', 0)`.

В `decayInactive()`: перед вычислением `$burn` проверить floor по текущему
рангу пользователя; если `$u->prana_balance - $burn < $floor`, урезать `$burn`
до `max(0, $u->prana_balance - $floor)`.

### §5.3 — Параметры по умолчанию для Сезона 1

| Параметр | Env | Значение |
|---|---|---|
| Enabled | `PRANA_DECAY_ENABLED` | `true` (только в период сезона) |
| Inactive days | `PRANA_DECAY_INACTIVE_DAYS` | `14` (было 30; сезон 4 мес., нужен ритм) |
| Percent / неделя | `PRANA_DECAY_PERCENT` | `10` |
| Floor mode | `PRANA_DECAY_FLOOR_MODE` | `rank_based` |

---

## §6 — Ротация `/lila` паков

Поле `enabled_packs` (JSON) в таблице `seasons` — список активных ключей
паков (`verb-roots`, `ligatures`, `genders`, `sandhi`, …). При старте сезона
фронт/API читает `Season::current()->enabled_packs` и скрывает /показывает
паки в каталоге.

В Сезоне 1 — весь Wave 1 каталог активен. Механика ротации вступает в силу
с Сезона 2.

---

## §7 — Lifecycle: Artisan-команды + scheduler

### Команды

```
php artisan season:open  {season_id?}   # создать/активировать; включить decay
php artisan season:close {season_id?}   # закрыть; раздать награды; выключить decay
```

`season:open` выполняет:
1. Создать запись в `seasons` (или активировать существующую).
2. Записать `started_at = NOW()`, `is_active = true`.
3. Обнулить/проинициализировать `season_leaderboard_cache` для этого сезона.
4. Установить `PRANA_DECAY_ENABLED=true` через `Artisan::call` на `.env`
   (или через config cache flush + DB-driven config override).

`season:close` выполняет:
1. Вычислить финальный рейтинг из `season_leaderboard_cache`.
2. Раздать награды по `rewards_config` → `season_rewards` + `PranaService`.
3. Установить `ended_at = NOW()`, `is_active = false`.
4. Выключить decay (`PRANA_DECAY_ENABLED=false`).

### Расписание (Kernel.php)

```php
// Сезон 1: старт 01.09.2026 00:00 MSK (UTC+3 → UTC 21:00 31.08)
$schedule->command('season:open 1')
         ->cron('0 21 31 8 *')
         ->onOneServer()
         ->name('season-1-open');

// Сезон 1: закрытие 01.01.2027 00:00 MSK (UTC 21:00 31.12.2026)
$schedule->command('season:close 1')
         ->cron('0 21 31 12 *')
         ->onOneServer()
         ->name('season-1-close');

// Пересчёт лидерборда каждые 4 часа в период сезона
$schedule->command('season:refresh-leaderboard')
         ->everyFourHours()
         ->when(fn() => \App\Models\Season::isActive())
         ->name('season-leaderboard-refresh');
```

---

## §8 — Что НЕ входит в эту архитектуру

- Публичная страница сезона (только кабинет, R1-4)
- Анонимный сезонный лидерборд
- Сезонные бейджи (отдельная задача, не заблокирована)
- Decay для незарегистрированных / trial-пользователей

---

## §9 — Open questions / Prerequisites

- [ ] `PRANA_DECAY_ENABLED` в проде сейчас `false` — нужен `.env`-апдейт
  при `season:open` или DB-driven config override (не hardcode в Artisan).
  Выбор механизма: `@DECIDE` перед имплементацией `season:open`.
- [ ] Рейтинг-снимок: считать `prana_earned` как сумму `prana_transactions`
  за период сезона (sum amount > 0) или как прирост `lifetime_prana`?
  Первое точнее (не зависит от стартового баланса). Уточнить перед миграцией.
- [ ] Notify студентам о старте сезона (email / Telegram) — отдельный
  handoff, не блокирует архитектуру.

---

_Dr. Mārcis Gasūns_
