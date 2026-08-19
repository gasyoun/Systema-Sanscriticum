# Исключение из учебного TG-чата за курсовой долг и взнос за возврат

_Created: 19-08-2026 · Last updated: 19-08-2026_

Opus 5 (`claude-opus-5`) · [H2746](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2746-Codex_Systema-Sanscriticum_course-debt-telegram-reinstatement-ledger_14.08.26.md).
Волна 1 — **операторская**: контур считает, объясняет и ведёт реестр, но сам в
Telegram не ходит.

**Где в админке:** Filament → Пользователи → **Исключения из чатов** (`/admin/chat-removals`)
**Отчёт:** `php artisan debts:chat-removal-report [--all] [--reveal] [--json]`
**Смежное:** [Должники](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md)
· [Платёжная дисциплина](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026.md)

---

## 1. Правило

Студента можно исключить из учебного Telegram-чата, когда сходится **всё сразу**:

| # | Условие | Откуда берётся |
|---|---|---|
| 1 | он должник по курсу | [`DebtorsReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php) — единственный источник истины по долгу |
| 2 | просрочка **≥ 30 дней** от старта reference-блока | `DebtorsReport::daysOverdueFor` |
| 3 | **два последних** контакта остались без ответа | `debt_reminders` + `debt_win_back_attempts` против входящих сообщений, обещаний и платежей |
| 4 | у группы курса заполнен `telegram_chat_id` | [`ZapisiChatMemberService::studyGroupsWithChat`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Telegram/ZapisiChatMemberService.php) |
| 5 | у студента привязан **однозначный** `telegram_id` | `users.telegram_id`, не встречающийся у второй учётки |
| 6 | долг не под договорённостью | нет активного `PaymentPromise` по паре (студент, курс) |
| 7 | по этому чату нет открытого эпизода | реестр `course_debt_chat_removals` |

Возврат в чат требует **двух** закрытых позиций: курсовой долг **и** взнос
**1 000 ₽ за каждый чат**, из которого студента исключили за неоплату и
молчание. Взнос — не часть долга и не скидка с него.

Пороги живут в [`config/chat_removal.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/chat_removal.php),
а не в коде: правило человеческое, и «30 дней» MG вправе поменять, не трогая
уже записанные строки.

## 2. Что считается контактом и что — ответом

**Контакт** — только то, что оставило запись:

- строка `debt_reminders` (авто-лестница `debts:remind`, стадии [`DunningStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/DunningStage.php));
- строка `debt_win_back_attempts` (шаблон реактивации, отправленный куратором).

**Ответ** — любой признак, что студент на связи по этому долгу: входящее
сообщение в helpdesk (`chat_messages` роли `user`, `telegram_support_messages`
с `direction=incoming` и связанным `linked_user_id`), созданное обещание
оплаты, реальный (не conditional, не членский) платёж по курсу.

Каждая попытка размечается по окну **до следующей попытки**, а решает
**хвост подряд неотвеченных**. Студент, ответивший между двумя письмами и
снова замолчавший, начинает счёт заново — «два обращения без ответа» это
именно два подряд, а не два за всю историю.

### Пробел: ручные напоминания

Кнопка «Напомнить в TG/VK» на странице «Должники» **не пишет** `debt_reminders` —
она сразу зовёт [`DebtorReminderDispatcher`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorReminderDispatcher.php).
Такой контакт для правила невидим. Контур намеренно **не додумывает** его:
исключение человека из чата должно опираться на запись, а не на память куратора.
Практическое следствие — пока кнопка не логируется, кандидатами становятся
только те, кого догнала авто-лестница. Закрывать этот пробел логированием
ручной отправки следует отдельным решением: строка в `debt_reminders`
одновременно включит анти-спам-cadence и подавит следующее авто-напоминание.

## 3. Guardrail: членство — никогда не долг

Курсовой долг считается по блокам курса и блок-тарифам. Клубное членство живёт
в `club_memberships` и в платежах с тарифом вида `club_3m` / `membership_club_12m`.
Пересечение возможно ровно в одном месте — таблице `payments`, — поэтому проверка
вынесена в [`MembershipDebtGuard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Discipline/MembershipDebtGuard.php)
и вызывается везде, где контур смотрит на платежи. Паттерн тарифов — в конфиге:
коды уже переименовывались (`club_*` → `membership_*_*`), и следующее
переименование не должно тихо превратить членский платёж в курсовой.

Закреплено двумя тестами: просроченное членство при оплаченном курсе не даёт
строки вовсе; членский платёж не закрывает курсовой долг и не считается ответом
студента.

## 4. Реестр

Одна строка `course_debt_chat_removals` = один эпизод «выгнали из этого чата».
Не пара (студент, курс): исключают из **чата**, чатов может быть несколько, и
взнос — за каждый.

| Стадия | Что значит | Куда можно дальше |
|---|---|---|
| `qualified` | кандидат подтверждён, из чата ещё не исключён | `removed`, `cancelled` |
| `removed` | оператор выгнал (кнопкой в «Должники») | долг / взнос / `cancelled` |
| `debt_settled` | курсовой долг закрыт, взнос — нет | взнос |
| `fee_settled` | закрыты оба — можно возвращать | `restored`, `cancelled` |
| `restored` | возвращён в чат, эпизод закрыт | — |
| `cancelled` | основание отпало (ошибка, спорный долг) | — |

Порядок «сначала долг, потом взнос» и «сначала взнос, потом долг» оба
допустимы; `fee_settled` наступает, когда закрыты оба. Возврат раньше этого
[`ChatRemovalLedger::markRestored`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Discipline/ChatRemovalLedger.php)
отклоняет — это и есть правило, ради которого реестр заведён.

**Что иммутабельно.** Снимок основания (`debt_amount`, `debt_block_numbers`,
`days_overdue`, `contact_attempts`, `telegram_chat_id`, `qualified_at`) пишется
один раз; попытка изменить любое из этих полей у существующей строки падает
исключением. Спор «за что меня выгнали» разрешается строкой реестра, а не
пересчётом сегодняшних платежей. Ошибочный эпизод **отменяется**, а не
переписывается.

**Аудит-след** `course_debt_chat_removal_events` — append-only: событие нельзя
ни отредактировать, ни удалить (модель бросает исключение на `updating` и
`deleting`). Журнал, который можно подчистить, доказательством не является.

## 5. Волна 1 не трогает Telegram

`config('chat_removal.auto_telegram_mutation')` = `false`, и ни один класс
контура не обращается к Bot API. Исключение и разбан по-прежнему делает
человек: кик — кнопкой «Исключить из TG-чата» в «Должники», разбан — в дашборде
«Записи (бот)». Страница `/admin/chat-removals` фиксирует, что кик состоялся, и
дальше ведёт долг, взнос и возврат.

Проверено тестом: прогон отчёта и запись эпизода идут под
`Http::preventStrayRequests()` — любой исходящий вызов уронил бы тест.

Автоматизация (авто-кик, авто-разбан после оплаты взноса) — предмет отдельного
явного handoff'а, а не флага в этом конфиге.

## 6. Личные данные

Отчёт по умолчанию печатает `user#123` вместо имени: он уезжает в лог, в чат и
в тикет, а фамилия рядом с суммой долга там быть не должна. `--reveal` включает
имена осознанно, для работы в админке. Страница `/admin/chat-removals` закрыта
`RoleGate::adminOnly()` — куратор (manager) туда не проходит.

Инструкция оператору живёт **на самой странице**, а не в этом файле: список
строится из живых запросов и разойтись с экраном не может.

## 7. Карта файлов

| Файл | Роль |
|---|---|
| [`config/chat_removal.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/chat_removal.php) | пороги, взнос, выключатель мутаций Telegram |
| [`app/Services/Discipline/ChatRemovalEligibility.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Discipline/ChatRemovalEligibility.php) | правило в исполняемом виде + расшифровка отказов |
| [`app/Services/Discipline/DebtContactEvidenceCollector.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Discipline/DebtContactEvidenceCollector.php) | ленты «мы писали» / «он отвечал» |
| [`app/Services/Discipline/ChatRemovalLedger.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Discipline/ChatRemovalLedger.php) | единственная точка записи в реестр + аудит |
| [`app/Services/Discipline/MembershipDebtGuard.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Discipline/MembershipDebtGuard.php) | «это членство?» — один ответ на весь контур |
| [`app/Models/CourseDebtChatRemoval.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/CourseDebtChatRemoval.php) | строка реестра, иммутабельный снимок основания |
| [`app/Filament/Pages/CourseDebtChatRemovals.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/CourseDebtChatRemovals.php) | рабочий экран оператора |
| [`app/Console/Commands/ReportCourseDebtChatRemovals.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ReportCourseDebtChatRemovals.php) | сухой прогон |

Тесты: [`CourseDebtChatRemovalRuleTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseDebtChatRemovalRuleTest.php)
(матрица правила) ·
[`CourseDebtChatRemovalLedgerTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseDebtChatRemovalLedgerTest.php)
(жизненный цикл, арифметика взноса, иммутабельность) ·
[`CourseDebtChatRemovalReportCommandTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseDebtChatRemovalReportCommandTest.php)
(обезличивание, JSON, отсутствие вызовов Telegram) ·
[`CourseDebtChatRemovalsPageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseDebtChatRemovalsPageTest.php)
(доступ, запись эпизода, запрет раннего возврата).

## 8. Побочная находка

`Debtors::debtBlocks()` — канонический расчёт неоплаченных блоков, и второй мы
не заводим. Но его статические кэши ключуются как `user_id:course_id` и живут
дольше одного HTTP-запроса. В консоли, в очереди и в тестах (где после отката
БД идентификаторы начинаются заново) ключ второго прогона попадает в кэш
первого, и долг молча считается по чужим платежам. Добавлен
`Debtors::flushPairCaches()`; контур зовёт его перед каждым проходом.

## 9. Первый прогон на проде — 19-08-2026

Выложено `deploy.sh` (`d51c3e52 → 78dde526`), миграция прошла за 66 мс, смоук
кабинета и `guards:verify` зелёные. Затем `debts:chat-removal-report --all` под
`www-data` (обезличенный режим, ничего не меняет).

**Подлежащих исключению сегодня — ноль.** 83 строки должников, ни одна не
проходит. Разбор причин (одна строка может иметь несколько):

| Причина | Строк |
|---|---|
| меньше двух зафиксированных контактов | 80 |
| не привязан `telegram_id` | 77 |
| просрочка меньше 30 дней | 62 |
| у курса нет группы с `telegram_chat_id` | 24 |
| студент отвечал — не молчание | 3 |
| сумма долга не посчитана (нет блок-тарифов) | 3 |

**Связывающее ограничение — не строгость правила, а данные.** На проде
`users.telegram_id` заполнен ровно у **28 человек** во всей базе, при 58 строках
`debt_reminders` и нуле попыток реактивации. Пока Telegram не привязан, студента
нельзя ни однозначно опознать в чате, ни исключить — и правило справедливо
молчит. Второе по весу — тот самый пробел §2: авто-лестница включена
(`debt_reminders_enabled = 1`), но на конкретную пару (студент, курс) двух
записанных обращений почти нигде ещё не накопилось, а ручные напоминания следа
не оставляют.

Практический вывод: до массовой привязки `telegram_id` и до логирования ручных
напоминаний контур будет отчитываться пустым списком. Это **правильное**
поведение — исключать человека из чата на основании ненаписанного нельзя, — но
означает, что первую реальную когорту стоит ждать не раньше, чем закроется хотя
бы один из двух пробелов.

_Dr. Mārcis Gasūns_
