# Роадмап: Система Праны (Gamification)

> Прана — не игровая монета. Это визуализация когнитивного усилия.  
> Система должна ощущаться как монастырская дисциплина, а не мобильная игра.

---

## Философия системы

Из `gemini.md`: *«Высокое трение — Престиж доступа к высшим уровням обеспечивается сложностью прохождения фильтров»*.

Отсюда три принципа проектирования Праны:

1. **Прана зарабатывается усилием, не активностью.** Открыть урок — не то же самое, что понять его. Прана начисляется за качество, а не за клики.
2. **Прана рассеивается без практики.** Как в йоге: накопленная энергия требует поддержания. Недельная пауза — начало угасания.
3. **Прана открывает знание, не дает скидки.** Трата Праны — это доступ к редким текстам, инструментам ИИ, режимам практики. Не купоны.

---

## Архитектура данных

### Новые таблицы

```sql
-- Кошелек: текущий баланс + несгораемый опыт
ALTER TABLE users ADD COLUMN prana_balance      INT UNSIGNED DEFAULT 0;
ALTER TABLE users ADD COLUMN lifetime_prana     INT UNSIGNED DEFAULT 0;
ALTER TABLE users ADD COLUMN prana_rank_id      BIGINT UNSIGNED NULL;
ALTER TABLE users ADD COLUMN last_prana_activity_at TIMESTAMP NULL;

-- Все транзакции — append-only лог (как activity_events)
CREATE TABLE prana_transactions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    amount        INT NOT NULL,                  -- отрицательное = трата/сгорание
    type          ENUM(
                    'lesson_complete',           -- завершение урока
                    'lesson_quality_bonus',      -- бонус за безошибочность
                    'ai_score',                  -- пропорционально оценке ИИ
                    'live_session',              -- участие в Organon
                    'daily_practice',            -- ежедневная практика
                    'p2p_received',              -- подарок от другого студента
                    'p2p_sent',                  -- отправка другому студенту
                    'unlock_spent',              -- трата на разблокировку контента
                    'decay',                     -- сгорание за бездействие
                    'admin_grant',               -- ручное начисление от преподавателя
                    'admin_deduct',              -- ручное списание
                    'mora_bypass'                -- пропуск паузы (Mora Didactica)
                  ) NOT NULL,
    context_type  VARCHAR(100) NULL,             -- Lesson, Schedule, LectureDraft...
    context_id    BIGINT UNSIGNED NULL,          -- ID контекстной записи
    sender_id     BIGINT UNSIGNED NULL,          -- для p2p_sent/received
    note          TEXT NULL,                     -- комментарий (для admin_grant/deduct)
    balance_after INT UNSIGNED NOT NULL,         -- снапшот баланса после транзакции
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_type (type)
);

-- Конфигурируемые правила начисления (редактируются из админки)
CREATE TABLE prana_rules (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type    VARCHAR(100) NOT NULL UNIQUE,  -- совпадает с type транзакции
    base_amount   INT UNSIGNED NOT NULL,         -- базовое кол-во Праны
    multiplier_1st_attempt DECIMAL(3,1) DEFAULT 2.0, -- множитель за первую попытку
    is_active     BOOLEAN DEFAULT TRUE,
    updated_at    TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Ранги (Шишья → Пандит)
CREATE TABLE prana_ranks (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug                VARCHAR(50) NOT NULL UNIQUE,
    title_ru            VARCHAR(100) NOT NULL,   -- «Шишья», «Пандит» и т.д.
    title_sa            VARCHAR(100) NOT NULL,   -- деванагари
    title_iast          VARCHAR(100) NOT NULL,   -- транслитерация IAST
    min_lifetime_prana  INT UNSIGNED NOT NULL,   -- порог для получения ранга
    badge_icon          VARCHAR(255) NULL,        -- путь к иконке
    sort_order          TINYINT UNSIGNED DEFAULT 0
);

-- Разблокированный контент пользователя
CREATE TABLE user_unlocked_assets (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    asset_type   VARCHAR(100) NOT NULL,          -- 'lesson', 'lecture_draft', 'ai_tool'...
    asset_id     BIGINT UNSIGNED NOT NULL,
    prana_spent  INT UNSIGNED NOT NULL,
    unlocked_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_unlock (user_id, asset_type, asset_id)
);
```

### Изменения в существующих таблицах

```sql
-- Добавить к lesson_views: качество прохождения
ALTER TABLE lesson_views ADD COLUMN attempts_count   TINYINT UNSIGNED DEFAULT 1;
ALTER TABLE lesson_views ADD COLUMN hints_used       TINYINT UNSIGNED DEFAULT 0;
ALTER TABLE lesson_views ADD COLUMN ai_score         DECIMAL(5,2) NULL; -- 0-100, от ИИ

-- Пометить контент, требующий Праны
ALTER TABLE lessons ADD COLUMN prana_unlock_cost INT UNSIGNED NULL;  -- NULL = бесплатно
ALTER TABLE lecture_drafts ADD COLUMN prana_unlock_cost INT UNSIGNED NULL;
```

---

## Ранги: иерархия от Шишьи до Пандита

| Ранг | IAST | Деванагари | Порог (lifetime_prana) | Описание |
|---|---|---|---|---|
| 0 | Śiṣya | शिष्य | 0 | Ученик — только приступил |
| 1 | Abhyāsin | अभ्यासिन् | 150 | Практикующий — регулярная работа |
| 2 | Adhyāyin | अध्यायिन् | 500 | Изучающий — глубокое погружение |
| 3 | Vidyārthī | विद्यार्थी | 1 500 | Стремящийся к знанию — серьезный студент |
| 4 | Vyutpanna | व्युत्पन्न | 4 000 | Искусный — уверенное владение |
| 5 | Paṇḍita | पण्डित | 10 000 | Ученый — достигший глубины |

Ранг **никогда не понижается** — он привязан к `lifetime_prana`, а не к `prana_balance`.

---

## Таблица начислений

| Событие | Базовая Прана | Множитель | Условие множителя |
|---|---|---|---|
| Завершение урока | +5 | ×2.0 | 1-я попытка, без подсказок |
| Ответ в сократическом диалоге | +3–10 | пропорц. | `ai_score / 100 × max` |
| Участие в живом занятии (Organon) | +15 | — | — |
| Ежедневная практика (streak) | +2 | ×1.5 | streak ≥ 7 дней |
| Точный разбор самасы (1-я попытка) | +10 | ×2.0 | `attempts == 1 and hints == 0` |
| Получение Праны от студента (P2P) | = отправленному | — | — |
| Ручное начисление преподавателем | любое | — | — |

---

## Таблица трат

| Действие | Стоимость | Что дает |
|---|---|---|
| Разблокировать редкий текст/лекцию | 50–200 | Постоянный доступ к `user_unlocked_assets` |
| Пропустить Mora Didactica | 20 | Снять паузу после ошибки досрочно |
| Сессия AI-проверки произношения | 5 | Один сеанс фонетического разбора |
| Доступ к режиму Conditio Rigida | 30 | Разблокировать «жесткий режим» урока |
| Передать студенту (P2P) | = отправляемому | — |

---

## Фазы разработки

---

### Фаза 0 — Основание (2–3 недели)

**Цель:** Модели, миграции, сервис-ядро. Ничего не видно пользователю, но всё работает.

#### Задачи

**1. Миграции**
```bash
php artisan make:migration create_prana_ranks_table
php artisan make:migration create_prana_rules_table
php artisan make:migration create_prana_transactions_table
php artisan make:migration create_user_unlocked_assets_table
php artisan make:migration add_prana_fields_to_users_table
php artisan make:migration add_quality_fields_to_lesson_views_table
php artisan make:migration add_prana_unlock_cost_to_lessons_table
```

**2. Модели**
- `PranaRank` — порядок, пороги, иконки
- `PranaRule` — конфиг начислений
- `PranaTransaction` — только `create()`, никогда `update()`
- `UserUnlockedAsset` — с методом `isUnlocked(User, assetType, assetId)`

**3. Сервис `PranaService`**
```php
// app/Services/PranaService.php
class PranaService {
    public function award(User $user, string $type, int $amount, array $context = []): PranaTransaction;
    public function spend(User $user, string $type, int $amount, array $context = []): PranaTransaction;
    public function transfer(User $from, User $to, int $amount, int $contextId): void;
    public function checkRankUp(User $user): ?PranaRank;   // вызывается async из Job
    public function canAfford(User $user, int $cost): bool;
    public function getBalance(User $user): int;
}
```

Все изменения баланса — **только через `PranaService`**. Прямой `$user->prana_balance += X` запрещен.

**4. Seeder для `prana_ranks` и `prana_rules`**
Стартовые значения из таблиц выше — вносятся через seeder, редактируются из Filament.

---

### Фаза 1 — Зарабатывание (2 недели)

**Цель:** Прана начисляется за реальную учебу.

#### Интеграция с существующим кодом

**`TrackLessonViewJob`** — уже создает/обновляет `LessonView`. Добавить после upsert:
```php
if ($lessonView->wasRecentlyCreated === false && $data['completed'] && !$wasCompleted) {
    $multiplier = ($lessonView->attempts_count === 1 && $lessonView->hints_used === 0)
        ? PranaRule::multiplierFor('lesson_complete')
        : 1.0;
    $base = PranaRule::baseFor('lesson_complete');
    app(PranaService::class)->award($user, 'lesson_complete', (int)($base * $multiplier), [
        'context_type' => 'Lesson',
        'context_id'   => $lessonView->lesson_id,
    ]);
}
```

**`ActivityTracker::handleLogin()`** — добавить проверку daily streak:
```php
if ($user->last_prana_activity_at?->isYesterday()) {
    app(PranaService::class)->award($user, 'daily_practice', PranaRule::baseFor('daily_practice'));
}
$user->update(['last_prana_activity_at' => now()]);
```

**`StudentController` (посещение живого занятия)** — при открытии события расписания:
```php
app(PranaService::class)->award($user, 'live_session', PranaRule::baseFor('live_session'), [
    'context_type' => 'Schedule',
    'context_id'   => $schedule->id,
]);
```

**AI-оценка** — когда LLM возвращает `accuracy_score` (будущая интеграция):
```php
$prana = (int) round(PranaRule::baseFor('ai_score') * ($score / 100));
app(PranaService::class)->award($user, 'ai_score', $prana, [...]);
```

---

### Фаза 2 — Трата и разблокировки (2 недели)

**Цель:** Прана дает доступ к контенту.

#### API эндпоинты

```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/prana/unlock',   [PranaController::class, 'unlock']);   // трата на контент
    Route::post('/prana/transfer', [PranaController::class, 'transfer']); // P2P
    Route::get('/prana/balance',   [PranaController::class, 'balance']);  // баланс + история
});
```

#### Gate для контента

Новый middleware или policy:
```php
// Проверка перед показом урока
if ($lesson->prana_unlock_cost && !UserUnlockedAsset::isUnlocked($user, 'Lesson', $lesson->id)) {
    return response()->json(['requires_prana' => true, 'cost' => $lesson->prana_unlock_cost], 403);
}
```

#### Mora Didactica + Прана

В логике паузы после ошибки:
```php
// При попытке пройти тест заново в период паузы
if ($inMoraPeriod) {
    if (app(PranaService::class)->canAfford($user, 20)) {
        // предложить потратить 20 Праны для обхода
        return response()->json(['mora_active' => true, 'bypass_cost' => 20]);
    }
}
```

---

### Фаза 3 — Статусы и ранги (1 неделя)

**Цель:** Несгораемый прогресс и социальное признание.

#### Job повышения ранга

```php
// app/Jobs/CheckPranaRankUpJob.php
class CheckPranaRankUpJob implements ShouldQueue {
    public function handle(PranaService $service) {
        $newRank = $service->checkRankUp($this->user);
        if ($newRank) {
            // уведомление студенту + запись в activity_events
            event(new PranaRankAchieved($this->user, $newRank));
        }
    }
}
```

Диспатч из `PranaService::award()` после каждого начисления (ставится в очередь `default`).

#### Filament

- `PranaRankResource` — CRUD рангов (пороги, иконки, названия)
- `PranaRuleResource` — редактирование базовых значений и множителей
- Колонка `prana_balance` / `lifetime_prana` / `rank` в `UserResource`
- Действие «Начислить Прану» и «Списать Прану» в `UserResource` (admin_grant / admin_deduct)

---

### Фаза 4 — Социальная механика P2P (1–2 недели)

**Цель:** Студенты могут поблагодарить друг друга Праной.

#### Защита от накрутки

```php
// PranaService::transfer()
$todaySent = PranaTransaction::where('user_id', $from->id)
    ->where('type', 'p2p_sent')
    ->whereDate('created_at', today())
    ->sum('amount');

if ($todaySent + $amount > config('prana.daily_p2p_limit', 30)) {
    throw new PranaTransferLimitException();
}

$recentToSame = PranaTransaction::where('user_id', $from->id)
    ->where('type', 'p2p_sent')
    ->where('context_id', $to->id)  // используем context_id как receiver_id для p2p
    ->where('created_at', '>=', now()->subHours(24))
    ->sum('amount');

if ($recentToSame + $amount > config('prana.daily_p2p_per_user_limit', 10)) {
    throw new PranaTransferLimitException('Лимит передачи одному студенту');
}
```

Лимиты конфигурируются в `config/prana.php`, не в коде.

---

### Фаза 5 — Сгорание (Decay) (1 неделя)

**Цель:** Регулярность важнее интенсивности.

#### CRON-задача

```php
// app/Jobs/PranaDecayJob.php — запускается ежедневно в полночь
class PranaDecayJob implements ShouldQueue {
    public function handle(PranaService $service) {
        $decayThreshold = config('prana.decay_after_days', 7);    // 7 дней бездействия
        $decayAmount    = config('prana.decay_amount', 5);         // -5 Праны/день
        $warningAt      = $decayThreshold - 1;                     // предупреждение за день

        // Предупреждение за 1 день
        User::whereNotNull('last_prana_activity_at')
            ->whereDate('last_prana_activity_at', now()->subDays($warningAt))
            ->where('prana_balance', '>', 0)
            ->each(fn($user) => $user->notify(new PranaDecayWarningNotification()));

        // Списание у давно неактивных
        User::whereNotNull('last_prana_activity_at')
            ->where('last_prana_activity_at', '<', now()->subDays($decayThreshold))
            ->where('prana_balance', '>', 0)
            ->each(function (User $user) use ($service, $decayAmount) {
                $actual = min($user->prana_balance, $decayAmount); // не уходим в минус
                $service->spend($user, 'decay', $actual);
            });
    }
}
```

Регистрация в `Console/Kernel.php`:
```php
$schedule->job(new PranaDecayJob)->dailyAt('00:05');
```

**Важно:** decay списывает только `prana_balance`, `lifetime_prana` не уменьшается никогда.

---

### Фаза 6 — UI в личном кабинете (2 недели)

**Цель:** Студент видит свое состояние и историю.

#### Компоненты (Livewire или Blade)

**Виджет в `/cabinet` (dashboard):**
```
┌─────────────────────────────────────────┐
│  विद्यार्थी  Vidyārthī                  │
│  ████████░░░░░░░░  620 / 1500 Праны    │
│  Баланс: 240 ◈  |  Всего: 620 ◈        │
│  До ранга Vyutpanna: 880 Праны         │
└─────────────────────────────────────────┘
```

**Страница `/cabinet/prana` — история транзакций:**
- Таблица: дата, тип, ±сумма, контекст (ссылка на урок/событие)
- Фильтр по типу транзакции

**Заблокированный контент:**
- Иконка замка + стоимость в Пране
- При клике — модальное подтверждение: «Потратить 50 Праны?»

---

## Конфигурационный файл

```php
// config/prana.php
return [
    'decay_after_days'         => env('PRANA_DECAY_DAYS', 7),
    'decay_amount'             => env('PRANA_DECAY_AMOUNT', 5),
    'daily_p2p_limit'          => env('PRANA_P2P_DAILY_LIMIT', 30),
    'daily_p2p_per_user_limit' => env('PRANA_P2P_PER_USER_LIMIT', 10),
    'mora_bypass_cost'         => env('PRANA_MORA_BYPASS', 20),
];
```

---

## Зависимости между фазами

```
Фаза 0 (Модели)
    └── Фаза 1 (Зарабатывание)
            └── Фаза 3 (Ранги)          ← параллельно с Фазой 2
    └── Фаза 2 (Траты/Разблокировки)
            └── Фаза 4 (P2P)
            └── Фаза 5 (Decay)
    └── Фаза 6 (UI) ← после Фаз 1, 2, 3
```

Фазы 1 и 2 можно вести параллельно разными разработчиками после завершения Фазы 0.

---

## Что намеренно не включено

- **Рейтинги и лидерборды** — противоречит принципу «без рыночных клише». Ранг виден только самому студенту.
- **Ежедневные бонусы за вход** — Прана зарабатывается усилием, не фактом открытия сайта.
- **Покупка Праны за деньги** — исключено. Прана не конвертируется в деньги и не покупается.
- **Достижения/ачивки** — отдельная система, не смешивать с Праной на первом этапе.
