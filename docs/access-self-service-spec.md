# Самообслуживание по доступу (Access self-service) — Phase 1

_Created: 05-07-2026 · Last updated: 05-07-2026_

Студент сам разбирается «почему у меня нет доступа / не могу войти / где записи»
из личного кабинета, без обращения к куратору. Это **access**-контрагент
платежного самообслуживания [`docs/debtor-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-spec.md):
там студент гасит долг сам — здесь студент чинит доступ сам.

## Зачем именно это (приоритет)

По анализу обращений в поддержку (`php artisan support:topic-ranking`) **access**
— тема №2 по стоимости кураторского времени: запросы «оплатил, а доступа нет»,
«не могу войти», «где записи», «какой бот подключать» дороги на запрос (куратор
руками сверяет оплату ↔ ключи доступа ↔ привязку аккаунта) и часто эскалируют.
Тема №1, **payment**, уже закрыта долговым самообслуживанием (Phase 1
[PR #293](https://github.com/gasyoun/Systema-Sanscriticum/pull/293) merged; Phase 2
— [`docs/debtor-self-service-phase2-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-phase2-spec.md)),
поэтому эта спека — про доступ, а не про деньги.

## Что уже есть (не переписываем)

| Кусок | Где | Роль в Phase 1 |
|---|---|---|
| Гейтинг урока по ключу тарифа | [`app/Models/Lesson.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Lesson.php) `isUnlockedBy`, [`app/Models/Tariff.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Tariff.php) `accessKey()` (`full`/`block_N`/`block_N_hH`) | Источник истины «что открыто». Диагностика читает это, не дублирует правило. |
| Оплаты студента (диапазон блоков) | [`app/Services/StudentDebtsService.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/StudentDebtsService.php) `forUser()` (`start_block..end_block`, реальные/conditional) | «За что заплачено». Сверка оплата↔ключ выявляет «оплачено, но блок закрыт». |
| Привязка Telegram/VK | [`app/Http/Controllers/TelegramController.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramController.php) `connect()` (deep-link, route `telegram.connect`), [`SocialAccount`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SocialAccount.php) | Готовый механизм привязки. Панель показывает статус и переотдает ссылку. |
| Сброс пароля | [`PasswordResetController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php) (routes `password.request`/`password.reset`) | Штатный флоу «не могу войти». Панель ведет сюда, не изобретает. |
| Дашборд: вкладки + статус ботов | [`resources/views/student/dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php) (`$tgConnected`/`$vkConnected`, `bot_status`, `password_status`) | Куда встает панель «Мой доступ». Плитки статуса уже есть — гап в связывании их в диагностику. |
| Ключ→доступ через sibling-строки | [`BlockAccessMaterializer`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/BlockAccessMaterializer.php) (debtor Phase 2 #1) | Уже чинит «оплачен диапазон, а ключ один». Диагностика переиспользует его как «самолечение», а не пишет ключи заново. |

Ключевой факт из долговой Phase 2: **доступ гейтится по ключу тарифа, а оплата
считается по диапазону блоков** — рассинхрон именно здесь («оплачено 5–7, ключ
только `block_5`»). `BlockAccessMaterializer` — канонический фикс; access-self-service
его *вызывает*, не переизобретает.

## Гап Phase 1

Студент видит «урок закрыт», но не знает **почему** и что нажать. Сегодня единственный
путь — куратор вручную сверяет три вещи (оплата, ключи доступа, привязка аккаунта).
Нужен **read-only диагност** + узкий набор безопасных «самолечащих» действий, каждое
из которых уже существует как механизм.

## Открытые решения (нужен человек — @DECIDE)

1. **Куда встает диагностика** — отдельная вкладка «Мой доступ» рядом с «Мои курсы»,
   ИЛИ раскрывающийся блок на карточке закрытого урока «почему закрыто?». Рекомендация:
   блок на уроке (контекст под рукой) + сводка на вкладке профиля.
2. **Порог авто-самолечения без куратора.** «Переотдать deep-link бота», «сброс пароля»,
   «пересобрать ключи для уже оплаченного диапазона (`BlockAccessMaterializer`)» —
   безопасны и идемпотентны → разрешаем студенту. Всё, что меняет деньги/долг —
   в долговой сервис, не сюда. Рекомендация: три перечисленных действия автоматизируем,
   остальное — кнопка «позвать куратора» (штатный хендовер кабинет-бота).
3. **«Оплачено, но доступа нет» без покрывающего платежа** (реально не оплачено /
   оплата не долетела вебхуком) — показываем ссылку на чекаут (долговой сервис) или
   «проверить оплату»? Рекомендация: если есть `pending`/`failed` платеж на этот блок —
   «проверить статус оплаты»; если платежа нет — вести в долговое самообслуживание.

## Архитектура Phase 1

### 1. `AccessDiagnosticsService` (новый, read-only)

Вход — `User` (+ опц. `Lesson`/`Course`). Выход — список «находок доступа», каждая с
кодом, человекочитаемым текстом и (если есть) безопасным действием:

- `key_missing_for_paid_range` — диапазон оплачен (`StudentDebtsService`), но ключа
  `block_X` нет (`Lesson::isUnlockedBy` = false) → действие **«Открыть оплаченные блоки»**
  (вызов `BlockAccessMaterializer` для primary-платежа диапазона; идемпотентно).
- `not_connected_bot` — нет привязки TG/VK (`$tgConnected`/`$vkConnected` = false) →
  действие **«Подключить бота»** (переотдать `telegram.connect` deep-link).
- `login_issue` — маркер «не могу войти» (пришел с логин-экрана) → действие
  **«Сбросить пароль»** (ссылка `password.request`).
- `not_paid` — блок закрыт и покрывающего реального платежа нет → маршрут в долговое
  самообслуживание (ссылка на резолвнутый чекаут) ИЛИ «проверить оплату» при
  `pending`/`failed` (см. решение #3).
- `no_finding` — доступ в порядке; если студент всё равно не видит урок — «позвать куратора».

Сервис **только читает и классифицирует**; сами действия — отдельные, уже существующие
эндпоинты. Никакой записи в БД из диагноста.

### 2. Панель «Мой доступ» (front)

В [`dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php):
- блок «Почему закрыто?» на карточке закрытого урока → находки из `AccessDiagnosticsService`
  с кнопкой-действием;
- сводная плитка в секции ботов/профиля: «Доступ: N курсов открыто · бот подключен · пароль ок».

Каждая кнопка = POST на существующий безопасный эндпоинт (materialize / connect / reset),
с флеш-статусом (как уже сделано для `bot_status`/`password_status`).

### 3. «Самолечение» — тонкие обертки, без новой логики

- `POST /student/access/materialize/{course}` — переиспускает `BlockAccessMaterializer`
  для оплаченных диапазонов курса (авторизация `payment.user_id === auth()->id()`, 403 иначе;
  идемпотентно — повтор не плодит sibling-строк).
- «Подключить бота» / «Сбросить пароль» — редиректы на `telegram.connect` /
  `password.request` (существуют, новых роутов нет).

## Граница Phase 1 (что НЕ входит)

- **Любые изменения денег/долга** — только через долговое самообслуживание, не здесь.
- **Записи занятий** как отдельный флоу — если «где записи» окажется весомым в
  `support:topic-ranking` по проду, это Phase 2 (нужно подтвердить, где записи гейтятся
  и хранятся — `CourseMaterialsArchiver`/`Lesson`).
- **Авто-повтор вебхука Точки** при «оплата не долетела» — остается серверной задаче.
- **Смена email/телефона** студентом — остается куратору.

## Done when

- На карточке закрытого урока есть блок «Почему закрыто?» с корректной находкой и
  рабочей кнопкой-действием для трех безопасных случаев (materialize / connect / reset).
- «Открыть оплаченные блоки» реально открывает уроки уже оплаченного диапазона через
  `BlockAccessMaterializer`, идемпотентно, без искажения финансов — фич-тест.
- `AccessDiagnosticsService` покрыт юнит-тестом на каждый код находки.
- `php artisan test` (затронутые чанки) зеленый; `./vendor/bin/pint` чист; обновлены
  [`docs/onboarding-student.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md)
  (раздел «если нет доступа») и `.ai_state.md`.

_Dr. Mārcis Gasūns_
