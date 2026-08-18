# Архитектура: взаимозачёт и групповые проверяющие

_Created: 27-07-2026 · Last updated: 18-08-2026_

Слой «как устроено» для [PLAN_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS_2026H2.md).

---

## Что уже есть (проверка прежде постройки)

Аудит 27-07-2026 по текущему `origin/main`. Ничего из перечисленного заново не пишется.

| Кусок | Где живёт | Вердикт |
|---|---|---|
| Связка User ↔ Teacher | `users.teacher_id` + `role = teacher` | **Переиспользуем** — новой связи не заводим |
| Начисление ЗП, accrual по блокам | [`TeacherSalaryService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TeacherSalaryService.php) | **Переиспользуем как есть** — единственный источник зарплатной цифры |
| Зачёт прямых платежей преподавателю | [`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php), `direct_offset_*` | **Копируем паттерн**, не расширяем: там деньги миновали кассу, у нас — нет |
| Зачёт аванса | `TeacherPayout.type=advance` + `settled_amount` | **Не переиспользуем** — исказит отчётность по авансам |
| Персональная скидка ученику | [`StudentDiscount`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/StudentDiscount.php) | **Не переиспользуем** — уронит выручку школы и не сопоставит две цифры |
| Очередь проверки домашек, роль teacher, бейдж в меню | [`HomeworkSubmissionResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/HomeworkSubmissionResource.php) | **Расширяем выборку**, каркас готов |
| Вердикт и комментарии с файлами | [`HomeworkService::recordReview`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/HomeworkService.php) | **Переиспользуем без правок** |
| Колокольчик уведомлений в админке | `->databaseNotifications()` в `AdminPanelProvider` | **Переиспользуем** — канал уже включён |
| Личные сообщения в Telegram | `User::sendTelegramMessage()` | **Переиспользуем** |
| Группы преподавателя без денормализации | `Teacher::groupsLed()` (H1426) | **Образец для подражания** — гранты тоже считаем на лету |

## Волна A — доступ к проверке по группе

### Почему не со-преподаватель

Самый дешёвый путь — добавить Ольгу в pivot `course_teacher` — отвергнут: `Course::salaryTermsFor()` возвращает для со-препода `['type' => $co->pivot->salary_type, ...]`, то есть **не** `null`, а значит `TeacherSalaryService` не отфильтрует её на строке-гейте и попытается начислить ей зарплату с выручки курсов Гасунса. Плюс грант получился бы по курсу целиком, а не по группам 60/61.

### Модель

Новая таблица-связка `group_reviewer`:

| Колонка | Тип | Смысл |
|---|---|---|
| `group_id` | FK groups, cascade | Какая группа |
| `user_id` | FK users, cascade | Кто проверяет |
| `can_review` | bool, default true | Может выносить вердикт (false = только смотреть и комментировать) |
| `notify` | bool, default true | Получает оповещения о новых работах |
| `assigned_by` | FK users, nullable | Кто выдал грант |
| `assigned_at` | timestamp | Когда |

Уникальность по паре `(group_id, user_id)`.

**Ключевое решение: грант привязан к `user_id`, а не к `teacher_id`.** Проверяющий — это тот, кто заходит в админку; `HomeworkSubmission.reviewed_by` уже указывает на `users.id`. Привязка к `teacher_id` втянула бы зарплатный контур ровно туда, откуда мы его выводим.

Связи: `Group::reviewers()` → belongsToMany(User), `User::reviewedGroups()` → belongsToMany(Group), `User::reviewableGroupIds()` — мемо на запрос.

### Правило видимости

Работа попадает в очередь проверяющего, когда выполнено всё:

1. `lesson.group_id` входит в его гранты — **или**, если `lesson.group_id` пуст, автор работы состоит в группе из его грантов **и** курс работы привязан к этой группе через `course_group`;
2. статус не `draft`;
3. автор работы — не сам проверяющий.

Пункт 1 — не педантизм: ученик может состоять в нескольких группах, и без проверки «курс принадлежит этой же группе» проверяющий группы 60 увидел бы работы того же ученика по совершенно чужому курсу. Пункт 3 — дефолт, выбранный агентом: свои работы в собственной очереди проверки выглядят как приглашение принять их самому.

Итог для выборки: `HomeworkSubmissionResource::getEloquentQuery()` получает ветку `orWhere` рядом с существующей проверкой по `course.teacher_id`. Преподаватель может одновременно вести свои курсы и проверять чужие группы — это ИЛИ, не ИЛИ-ИЛИ. Бейдж в меню считается от той же выборки и становится корректным сам собой.

### Ширина гранта

Расширяются ровно три поверхности: очередь домашек, состав группы ([`StudentGroupResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/StudentGroupResource.php)), посещаемость (`AttendanceDashboard`). Не расширяются: курсы, уроки, расписание, сертификаты, карточки учеников, нагрузка преподавателей. Роль `teacher` открывает эти разделы в принципе, но их выборки продолжают фильтровать по `teacher_id`, и грант проверяющего туда не попадает.

### Уведомления

`HomeworkService::notifyTeacher()` сейчас шлёт письмо на `course.teacher.email` и всё. Заменяется на `HomeworkNotifier`, который собирает получателей:

- **преподаватель курса** — как раньше, но если у группы есть активный проверяющий с `notify = true`, его персональные письма по каждой работе заменяются недельным дайджестом; для групп без проверяющих поведение не меняется вовсе;
- **проверяющие группы** с `notify = true` — колокольчик в админке, письмо (`HomeworkSubmittedMail`), Telegram при наличии привязки.

Дайджест — команда `homework:reviewer-digest`, понедельник 09:00, тот же слот, что `finance:kpi-digest`: что сдано за неделю, что проверил проверяющий, что до сих пор ждёт. Каналы, включение и время — в `config/homework.php`, не в коде команды.

**Доставка уведомления не зависит от сборки PDF (H3095, 18-08-2026).** До 18-08-2026 `notifyTeacher()` стоял в `recordSubmission()` **за** синхронной сборкой `combined-images.pdf`, и фатальная ошибка сборки (OOM, `try/catch` её не ловит) уносила уведомление с собой — работа сохранялась, проверяющий о ней не узнавал. Теперь сборка идёт отдельной job'ой, а флаг вложения в `HomeworkSubmittedMail` считается лениво на воркере. Где этот раздел и решение разойдутся — **выигрывает решение**: [DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DECISION_HOMEWORK_IMAGES_PDF_OFF_REQUEST_PATH_2026.md).

### Включение «только для неё»

Механизм общий, включение — данными: у кого нет строк в `group_reviewer`, для того ничего не изменилось. Плюс аварийный выключатель `homework.reviewers_enabled` в конфиге на случай, если понадобится погасить фичу без отката миграции.

## Волна B — акт взаимозачёта

### Модель

Новая таблица `mutual_settlements` — фиксированный снимок двух цифр за период:

| Колонка | Смысл |
|---|---|
| `user_id`, `teacher_id` | Один и тот же человек в двух ипостасях; инвариант `users.teacher_id === teacher_id` проверяется при сохранении |
| `course_id`, `block_number` | Какой блок/поток фиксирует условия (решение 9) |
| `period_from`, `period_to` | Границы расчёта |
| `tuition_amount` | Сколько она заплатила как ученик |
| `salary_amount` | Сколько ей начислено как преподавателю |
| `offset_amount` | Зачитываемое = минимум из двух |
| `net_direction` | `student_pays` / `school_pays` / `zero` |
| `net_amount` | Остаток после зачёта |
| `status` | `draft` / `fixed` / `superseded` |
| `fixed_at`, `fixed_by`, `note` | Кто и когда зафиксировал |
| `payout_id` | Выплата, в которой зачёт израсходован (nullable, гарантия однократности) |
| `breakdown` | JSON-расшифровка обеих сумм на момент фиксации |

**Фиксация означает заморозку чисел, а не пересчёт.** После `status = fixed` суммы читаются из строки, а не считаются заново, — иначе «зафиксировали» ничего не значит, поскольку поздняя оплата задним числом сдвинет уже подписанную цифру. Пересмотр создаёт новую строку и переводит прежнюю в `superseded`; история полная.

### Откуда берутся цифры

- **Обучение** — платежи Ольги как ученика: `Payment` со статусом оплаченного, за вычетом тарифов `Расход` и `salary_payout` (те же исключения, что уже применяет `TeacherSalaryService` для базы выручки).
- **Зарплата** — `TeacherSalaryService::totalForTeacher()` за тот же период, accrual по блокам (решение 7). Второй реализации расчёта ЗП не появляется.

### Зачёт до выплаты

Зафиксированный неизрасходованный акт показывается на [`TeacherSalaries`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherSalaries.php) отдельной строкой рядом с существующим блоком «Прямые платежи преподавателю (зачёт)» и вычитается из итога до создания выплаты. Созданная `TeacherPayout` уносит `mutual_settlement_id` и сумму зачёта в `breakdown`; акт помечается израсходованным через `payout_id`. Двойной зачёт исключён на уровне данных.

Выручка школы не трогается: её платежи остаются доходом в полном объёме, зачёт живёт исключительно на выплатной стороне.

### Триггер пересмотра

`MutualSettlementService::needsReview()` сравнивает **живые** цифры за текущий период с зафиксированными и возвращает истину, когда начисление перевешивает оплаты, — ровно условие MG «если её доход будет больше оплат курсов, пересмотрим». Выводится жёлтым бейджем у пункта меню, чтобы условие пересмотра не жило в чьей-то памяти.

### Гейт доступа

Страница — money-поверхность, закрывается тем же гейтом, что и страница зарплат. Исполнителю: прочитать фактический гейт `TeacherSalaries` и поставить такой же, а не выбирать между `RoleGate::finance()` и `RoleGate::accounting()` по догадке.

## Границы между волнами

Волна A не касается ни одного файла в `app/Services/TeacherSalaryService.php`, `app/Filament/Pages/TeacherSalaries.php`, `app/Models/Payment.php`. Волна B не касается ничего в `app/Filament/Resources/HomeworkSubmissionResource.php`, `app/Services/HomeworkService.php`, `app/Models/Group.php`. Пересечение — только чтение `users`.

_Dr. Mārcis Gasūns_
