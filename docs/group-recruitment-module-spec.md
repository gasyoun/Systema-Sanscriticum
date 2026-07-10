# Архитектурная спецификация: модуль «Набор курсов» (формирование групп + уведомления)

_Created: 04-07-2026 · Last updated: 04-07-2026_

Триггер: в чате куратора 04-07-2026 студентка (Ольга Горбунова) написала утром в день
предполагаемого старта курса — узнать, началось ли занятие. Ответ куратора: «группа
недонабрана». Сейчас это чисто ручной процесс — нет системного объекта «идет набор» и
нет автоматического уведомления уже оплативших студентов о том, что старт под вопросом
или переносится. Спека фиксирует, чего не хватает, и проектирует минимальный модуль поверх
уже существующих сущностей — **не строить с нуля**.

## Зафиксированные решения (интервью 04-07-2026)

| Развилка | Выбор |
|---|---|
| Этап работы | **Только спроектировать** — код не пишем в этой сессии |
| К чему привязан «набор» | **К группе** (`Group` получает статус набора — новая сущность-состояние, не новый курс-уровень флаг) |
| Дата, от которой считать «за N дней» | **Обе** — плановая дата по умолчанию (`planned_start_date`), ручной override при переносе (`start_date_override`) перезапускает отсчет |
| Сбор предпочтений «кому когда удобно» | Открытый вопрос — MG проверит сам, есть ли уже что-то (чат-бот/форма); эта спека не предполагает, что их нет, и оставляет точку интеграции |

## Reuse-first — что уже есть и переиспользуется

| Потребность | Уже есть | Источник |
|---|---|---|
| Учебная группа как сущность | ✅ `Group` (name, slug, users pivot с `left_at`/`left_reason`, `courses()` many-to-many) | [app/Models/Group.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Group.php) |
| Одна группа может обслуживать курс, несколько групп — один курс параллельно | ✅ уже `belongsToMany` в обе стороны через `course_group` | там же |
| Мягкий выход/восстановление участника без потери доступа | ✅ `GroupMembershipManager` | [app/Services/GroupMembershipManager.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/GroupMembershipManager.php) |
| Оплата курса до появления группы | ✅ `Payment.course_id` (группа привязывается позже, отдельно от оплаты) | [app/Models/Payment.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Payment.php) |
| Глобальные тумблеры + lead-time для авто-уведомлений (паттерн «включено/выключено + N дней/минут») | ✅ `MarketingSetting` singleton: `debt_reminder_lead_days`, `class_reminder_lead_minutes`, `absent_notify_delay_minutes` | [app/Models/MarketingSetting.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/MarketingSetting.php) |
| Точечная рассылка студентам в мессенджер (не кураторам) | ✅ `SendMessengerAlerts` job — шлет по `telegram_id`/`vk_id`, пропускает без мессенджера | [tests/Feature/ClassReminderTest.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/ClassReminderTest.php) |
| Scheduled artisan-команда с дедупом «не слать дважды» + «перенос дела снимает флаг» | ✅ `classes:remind-upcoming` / `Schedule.reminded_at`, сбрасывается при `update(['start' => ...])` | там же |
| Уведомление персонала (кураторов) о денежных событиях | ✅ `CuratorNotifier` — паттерн для симметричного добавления «Группа недобрана» | [app/Services/CuratorNotifier.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/CuratorNotifier.php) |
| Read-only витрина группы для преподавателя | ✅ `StudentGroupResource` — для куратора нужен **обратный**, редактируемый ресурс | [app/Filament/Resources/StudentGroupResource.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/StudentGroupResource.php) |

**Вывод:** ядро (группа, участники, оплата, точечные уведомления, scheduled-команды, тумблеры
настроек) уже есть. Не хватает четырех вещей:

1. Статус набора и плановая/фактическая дата старта на `Group`.
2. Минимальный размер группы (`min_size`) для решения «набрана / недобрана».
3. Таблица предпочтений по времени (если ее правда нет — см. открытый вопрос).
4. Scheduled-команда + шаблон уведомления «за N дней до старта, группа еще недобрана».

## Что чего не хватает

### 1. Новые поля на `Group`

```
status              string  default 'forming'   // forming | active | archived
min_size            unsignedTinyInteger nullable // порог «набрана»; null = не проверяем размер
planned_start_date  date    nullable             // ожидаемая дата — дефолт для отсчета «за N дней»
start_date_override date    nullable             // ручной override при переносе; приоритет над planned
recruitment_notified_at  timestamp nullable       // дедуп, как Schedule.reminded_at
```

`effectiveStartDate()` аксессор → `start_date_override ?? planned_start_date`.
Смена `start_date_override` (перенос) обнуляет `recruitment_notified_at` — точно так же,
как в `ClassReminderTest::rescheduling_a_class_re_arms_the_reminder`.

Группа считается **набранной**, когда `activeUsers()->count() >= min_size` (или `min_size`
не задан — тогда проверка размера не блокирует). При достижении порога `status` → `active`
(вручную куратором или авто-триггером на прикрепление участника — решить при реализации).

### 2. Таблица предпочтений — `enrollment_preferences`

```
id
user_id         foreignId constrained cascadeOnDelete
course_id       foreignId constrained cascadeOnDelete   // до появления группы студент привязан к курсу
group_id        foreignId nullable constrained          // проставляется, когда куратор собрал слот в группу
days_of_week    json     // [1,3,5] — Пн/Ср/Пт
time_ranges     json     // [{"from":"19:00","to":"21:00"}]
ready_from_date date     // «готов начать не раньше»
note            text nullable
timestamps
```

⚠️ **Перед созданием — прогнать `/prior-art`.** Возможно, эти данные уже собираются
(бот-опрос, Google/Яндекс.Форма, CRM-поле) — MG сам проверит в отдельном заходе. Если
механизм найдется, эта таблица не нужна — годится импорт/интеграция вместо новой сущности.
Если механизма нет — таблица собирает сырые данные; куратор в Filament агрегирует их
(day×time heatmap) и вручную формирует `Group` под самый популярный слот.

### 3. Уведомление студентов — `groups:notify-forming-shortfall`

Scheduled-команда (аналог `classes:remind-upcoming`), ежедневный прогон:

```
Group::where('status', 'forming')
    ->whereNotNull('planned_start_date')  // effectiveStartDate() учитывает override
    ->whereNull('recruitment_notified_at')
    ->get()
    ->filter(fn ($g) => $g->effectiveStartDate()->isSameDay(today()->addDays($leadDays)))
    ->filter(fn ($g) => $g->min_size && $g->activeUsers()->count() < $g->min_size)
```

Для каждой такой группы — `SendMessengerAlerts`-подобная рассылка всем `activeUsers()`
(включая уже оплативших через `Payment.course_id`, даже если формально еще не в `group_user` —
уточнить на реализации, кого именно считаем адресатом: участников группы или всех
оплативших курс без группы). Текст: «Группа [курс] пока набирается, старт [дата] под
вопросом — как только наберем минимум, сообщим точную дату» — **не молчание**, а честный
статус, закрывающий именно тот кейс, из-за которого студентка писала утром в день старта.

Настройки — новые поля на `MarketingSetting` по существующему паттерну:
```
recruitment_notify_enabled       boolean default true
recruitment_notify_lead_days     unsignedTinyInteger default 2
```

При **переносе** (`start_date_override` меняется) — отдельное немедленное уведомление
(не ждать нового lead-window), плюс сброс `recruitment_notified_at`, чтобы за 2 дня до
*новой* даты цикл повторился. Это закрывает кейс «перенесли на сентябрь/октябрь/январь» —
каждый новый ожидаемый старт получает свой цикл предупреждений.

### 4. Curator-facing Filament — «Набор курсов»

Новый раздел (или новая вкладка в существующем `GroupResource`, если он есть, иначе новый
ресурс параллельно read-only `StudentGroupResource`):
- Список групп со `status = forming`: план. дата, override, `min_size`, текущий размер,
  превью агрегированных предпочтений (день/время heatmap), кнопка «зафиксировать дату»
  (пишет `start_date_override`, триггерит немедленное уведомление).
- Симметричное дополнение `CuratorNotifier`: `groupUnderEnrolled(Group $group)` — сообщение
  в общий чат кураторов, когда автоматика обнаружила недобор за N дней (тот же прогон, что
  шлет студентам) — куратор видит проблему одновременно со студентами, а не узнает из
  входящего вопроса.

## Вне объема этой спеки (следующие развилки)

- Кто именно получатель уведомления — участники еще-не-существующей группы или все
  оплатившие курс без `group_id`? (Зависит от того, когда студент физически попадает в
  `group_user` — до или после того, как куратор зафиксировал слот.)
- Формат сбора предпочтений (бот-диалог vs Filament-форма vs внешняя форма) — гейт на
  находку MG о существующем механизме.
- Авто-перевод `status: forming → active` при достижении `min_size` — ручной шаг или
  триггер на observer `Group::activeUsers()` change.
- Повторные напоминания (не только «за 2 дня», но и «в день Х», «через неделю») —
  сознательно исключены по решению интервью («не в день ожидаемого начала и не через
  неделю в тот же день, а за два дня») — если MG захочет расширить каденс, это отдельная
  настройка `recruitment_notify_cadence_days` по паттерну `debt_reminder_cadence_days`.

---

_Dr. Mārcis Gasūns_
