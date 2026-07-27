# Реализация: волны A и B, пошагово

_Created: 27-07-2026 · Last updated: 27-07-2026_

Слой «в каком порядке и какие файлы» для [PLAN_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS_2026H2.md). Обоснование каждого решения — в [ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TEACHER_STUDENT_SETTLEMENT_GROUP_REVIEWERS.md).

Перед первым шагом: своя worktree от `origin/main`, собственный `composer install`, `git config core.hooksPath .githooks` если клон свежий.

---

## Волна A — групповые проверяющие ([H1729](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1729-Sonnet_Systema-Sanscriticum_group-reviewers-homework-access_27.07.26.md))

### A1. Миграция и модели

1. `database/migrations/2026_07_XX_000000_create_group_reviewer_table.php` — колонки по таблице архитектуры, уникальный индекс `(group_id, user_id)`, внешние ключи с каскадом на `groups` и `users`, `assigned_by` с `nullOnDelete`.
2. `app/Models/Group.php` — добавить `reviewers(): BelongsToMany` на `User` через `group_reviewer` с `withPivot(['can_review', 'notify', 'assigned_by', 'assigned_at'])`.
3. `app/Models/User.php` — добавить `reviewedGroups(): BelongsToMany`, `reviewableGroupIds(): array` (мемо на инстанс, пустой массив для не-преподавателей), `canReviewGroup(int $groupId): bool` с учётом пивота `can_review`.
4. `config/homework.php` — добавить секцию `reviewers`: `enabled` (env, default true), `notify_channels` (default `['database', 'mail', 'telegram']`), `digest_enabled`, `digest_day`, `digest_time`. Никаких значений в коде команд.

Зависит от: ничего. Дальше всё опирается на этот шаг.

### A2. Назначение гранта в админке

5. `app/Filament/Resources/GroupResource.php` — RelationManager или действие «Проверяющие»: выбор пользователя, переключатели «может выносить вердикт» и «уведомлять», запись `assigned_by`/`assigned_at`. Доступно только админу — раздавать гранты преподаватель не может.

Зависит от A1.

### A3. Видимость очереди проверки

6. `app/Filament/Resources/HomeworkSubmissionResource.php`:
   - `getEloquentQuery()` — существующую ветку преподавателя превратить в группу условий: `where(fn ($q) => $q->where(<курс мой>)->orWhere(<работа из моей подшефной группы>))`. Условие подшефной группы: если у урока проставлен `group_id` — сверять по нему; иначе автор работы состоит в группе из гранта **и** курс работы привязан к этой группе через `course_group`. Добавить `where('user_id', '!=', auth()->id())` в ветке проверяющего.
   - `canReview($record)` — разрешить, когда работа проходит условие подшефной группы и пивот `can_review` истинен.
   - Фильтры «Курс» и «Урок» — их выборки сейчас жёстко фильтруют по `teacher_id`; расширить теми же грантами, иначе в фильтре не будет курсов, работы по которым видны в таблице.
   - `getNavigationBadge()` — правок не требует, считается от `getEloquentQuery()`.

Зависит от A1. Отдельной правки под A4 не требует.

### A4. Состав группы и посещаемость

7. `app/Filament/Resources/StudentGroupResource.php` — в `getEloquentQuery()` и `canView()` добавить `orWhereIn('id', $user->reviewableGroupIds())`.
8. `app/Filament/Pages/AttendanceDashboard.php` — там, где страница ограничивает набор групп по `teacher_id`, добавить объединение с грантами.

Зависит от A1. От A3 независимо, можно делать параллельно.

### A5. Оповещение проверяющему

9. `app/Services/HomeworkNotifier.php` — новый сервис. Метод `submitted(HomeworkSubmission $s, bool $isResubmission)`: резолвит группу работы (тем же правилом, что A3), собирает проверяющих с `notify = true`, шлёт по каналам из конфига — `Filament\Notifications\Notification::make()->...->sendToDatabase($user)`, `HomeworkSubmittedMail`, `User::sendTelegramMessage()`. Преподавателю курса письмо шлётся как раньше, **кроме** случая, когда у группы есть активный проверяющий с `notify = true` — тогда он получит дайджест.
10. `app/Services/HomeworkService.php` — `notifyTeacher()` заменить вызовом `HomeworkNotifier::submitted()`. Вызов остаётся там же, где стоит сейчас: внутри `submit()` под `if ($finalize)`, но **за** пределами критичной части транзакции по тому же принципу, что уже применён для письма студенту.

Зависит от A1, A3 (правило резолва группы переиспользуется).

### A6. Недельный дайджест

11. `app/Console/Commands/HomeworkReviewerDigest.php` — команда `homework:reviewer-digest`: по каждому курсу, у групп которого есть проверяющие, собрать за неделю сданное / проверенное проверяющим / до сих пор ждущее и отправить письмо преподавателю курса. Пустую сводку не слать.
12. `app/Mail/HomeworkReviewerDigestMail.php` + шаблон в `resources/views/emails/`.
13. `app/Console/Kernel.php` — `->weeklyOn(1, '09:00')->withoutOverlapping(10)`, рядом с `finance:kpi-digest`.

Зависит от A5.

---

## Волна B — акт взаимозачёта ([H1730](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1730-Opus_Systema-Sanscriticum_teacher-student-mutual-settlement_27.07.26.md))

### B1. Сервис сверки

1. `app/Services/MutualSettlementService.php`:
   - `tuitionFor(User $user, ?Carbon $from, ?Carbon $to): array` — сумма и построчная расшифровка её платежей как ученика; исключить тарифы `Расход` и `salary_payout` теми же константами, что использует `TeacherSalaryService`, а не своей копией списка;
   - `salaryFor(Teacher $teacher, ?Carbon $from, ?Carbon $to): array` — делегировать `TeacherSalaryService::totalForTeacher()`, добавить разбивку по курсам;
   - `preview(User $user, ?int $courseId, ?int $blockNumber): array` — обе суммы, зачёт (минимум), направление и остаток;
   - `needsReview(MutualSettlement $s): bool` — живое начисление против живых оплат за период акта.
2. Инвариант «один человек»: приватный гард, падающий с осмысленным исключением, если `users.teacher_id !== teacher.id`.

Зависит от: ничего.

### B2. Разовая сверка

3. `app/Console/Commands/SettlementPreview.php` — `settlement:preview {user} {--course=} {--block=} {--from=} {--to=}`, печатает обе суммы, расшифровку и разницу. Только чтение, ни одной записи. Это инструмент первой сверки на проде, когда появится доступ.
   - `sys.stdout`-аналог для PHP не нужен, но вывод должен корректно печатать кириллицу в консоли Windows.

Зависит от B1.

### B3. Модель акта

4. `database/migrations/2026_07_XX_000001_create_mutual_settlements_table.php` — колонки по таблице архитектуры; `breakdown` как JSON; индексы по `(user_id, status)` и `payout_id`.
5. `app/Models/MutualSettlement.php` — константы статусов, касты `decimal:2` на все денежные поля и `array` на `breakdown`, связи `user()`, `teacher()`, `course()`, `payout()`, скоупы `fixed()`, `unconsumed()`.
6. `MutualSettlementService::fix(...)` — в транзакции: перевести прежний активный акт того же человека и периода в `superseded`, создать новый со `status = fixed`, записать `fixed_at`/`fixed_by` и `breakdown` **на момент фиксации**.

Зависит от B1.

### B4. Страница «Взаимозачёт»

7. `app/Filament/Pages/MutualSettlements.php` — группа «Финансы». Гейт: **прочитать фактический гейт `TeacherSalaries` и поставить такой же.** Выбор человека — только пользователи с непустым `teacher_id`; выбор курса/блока и периода; две суммы крупно, под каждой — таблица расшифровки; разница и направление; действие «Зафиксировать» с подтверждением и полем примечания; список прежних актов со статусами.
8. Локализация подписей — в `lang/`, если раздел так устроен; иначе по образцу соседних страниц.

Зависит от B3.

### B5. Зачёт до выплаты

9. `app/Filament/Pages/TeacherSalaries.php` — по образцу существующего блока `direct_receipt_ids` / `direct_offset_preview` добавить строку зачёта по зафиксированному неизрасходованному акту выбранного преподавателя: показать сумму, вычесть из итога, при создании выплаты записать `mutual_settlement_id` и сумму в `breakdown` и проставить акту `payout_id`.
10. Однократность: акт с непустым `payout_id` в выборку доступных не попадает — тот же приём, что уже применён к прямым платежам.

Зависит от B3, B4. **Это единственная точка касания зарплатного контура во всём плане** — правка аддитивная, существующие ветки расчёта не переписываются.

### B6. Триггер пересмотра

11. `MutualSettlements::getNavigationBadge()` — количество зафиксированных актов, по которым `needsReview()` истинно; цвет `warning`.

Зависит от B4.

---

## Порядок, если исполнять одной сессией

A1 → A3 → A5 → A2 → A4 → A6, затем B1 → B3 → B4 → B5 → B6, а B2 в любой момент после B1. A1 и B1 ни от чего не зависят и могут идти параллельно в двух worktree.

_Dr. Mārcis Gasūns_
