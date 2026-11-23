# Плейбук: уход сотрудника и аварийная передача дел

_Created: 23-11-2026 · Last updated: 23-11-2026_ · [метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_STAFF_OFFBOARDING_EMERGENCY_HANDOVER_RU.meta.md)

Операторская процедура для двух сценариев: **плановый уход сотрудника** (куратор,
преподаватель, админ-контур) и **аварийная недоступность** ответственного человека.
Контуры: обучение (группы, домашки, записи), поддержка (первая линия, эскалация)
и сервисный доступ (админ-поверхности, серверы, боты, секреты).

Правила-якоря, без которых плейбук не читать:

- **Никаких кредов и персональных данных** в этом документе и в его уликах: только
  названия ролей и систем. Реальные доступы живут в окружении и панелях, не в тексте.
- **Сначала доступ замены — потом отзыв уходящего.** Ни один отзыв/ротация не
  выполняется, пока замена не прошла проверку доступа (§A3).
- **Явная полномочность.** Каждое изменение доступа/секрета проходит под записанной
  визой владельца контура (MG) — одна строка в ops-канале: кто, что, когда, на
  основании чего ([лестница эскалации](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md)).
- Плейбук сам ничего не ротирует и не отзывает: это порядок действий для оператора,
  выполняющего их по существующим ранбукам.

## Роли и контуры (инвентарь имён, без персоналий)

| Контур | Кто/что | Источник истины |
|---|---|---|
| Владелец контура | MG — деньги, DNS/reg.ru, визы на списания/исключения, 2-я очередь эскалации | [RUNBOOK_SUPPORT_ESCALATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md) · [SERVER_INCIDENT_MANUAL](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_INCIDENT_MANUAL.md) |
| Инфра/ops, 1-я очередь | Иван — прод `.92`, вебхук-релей, деплой, допаковка | та же лестница; актуальный статус доступности людей сверять с SERVER_INCIDENT_MANUAL, не копировать сюда |
| Супер-админ | `RoleGate`-поверхности, impersonation `MODE_TEACHER` — **только super_admin**, деньги в имперсонации заблокированы | [STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md) |
| Админ / менеджер / бухгалтер | мутационные поверхности за 2FA (роль-скоуп: SUPER_ADMIN, ADMIN, MANAGER, ACCOUNTANT) | [TWO_FACTOR_ROUTE_AUDIT_H7830](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TWO_FACTOR_ROUTE_AUDIT_H7830_2026-11-08.md) |
| Куратор / первая линия | ИИ-куратор в кабинете; эскалация выше; выдача magic-ссылок | [RUNBOOK_SUPPORT_ESCALATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md) · [MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md) |
| Преподаватель | свои группы/уроки; свою зарплату видит только свою (`seesOwnSalary`); чужие payouts — нет | [TEACHER_PAYROLL_OPERATOR_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_PAYROLL_OPERATOR_PLAYBOOK.md) |
| Системы | прод `.92` (193.232.229.92), n8n `.91`, вебхук-релей `103.112.71.201`, боты `@samskrtamru_bot` (кабинет), `@samskrte_ops_bot` (ops), `@zapisi_ORSbot` (записи), `@samskrte_bot` (лидовый); живой аккаунт `@rusamskrtam` — **одна** MadelineProto-сессия | [telegram-bots-inventory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-bots-inventory.md) · [telegram-userbot-inventory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md) |
| Секретные поверхности | переменные окружения (классификация feature-switch vs credential), вебхук-секреты Tochka/Telegram/VK/MAX/Zoom с fail-policy, деплой-ключ GitHub, пароль бэкап-архива | [ENVIRONMENT_VARIABLES](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md) · [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md) · [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) · [ops/backup](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/ops/backup) |

## Процесс A — плановый уход

### A1. Фиксация и инвентарь обязанностей (T−14 … T−7)

1. Уходящий пишет в ops-канал план ухода (срок, обязанности). Виза MG на план —
   **до** любых передач: это и есть явная полномочность старта.
2. Оператор собирает **инвентарь обязанностей**, не трогая доступы:
   учебные группы и их TG-чаты, дежурства первой линии, расписания,
   незакрытые кейсы поддержки, регулярные проходы (напоминания, отчёты).
3. Таблица передачи: `обязанность → замена → срок → признак готовности`.
   Замена назначается MG; одну обязанность не оставляют без владельца.

### A2. Передача дежурств и курсов (T−7 … T−3)

- Сеанс передачи по каждой обязанности: замена прогоняет живой кейс под
  присмотром уходящего (одна домашка, один платёжный спор, одно ДЗ-расписание —
  что реально входит в обязанность).
- Процессы передаются **ссылками на канонические доки**, не пересказом:
  платёжная дисциплина — [PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_PAYMENT_DISCIPLINE_CURATOR_STUDENT_2026.md),
  долги/исключение из TG-чата — [debtors-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md),
  запуск/ведение курса — [PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md),
  зарплатный контур — [TEACHER_PAYROLL_OPERATOR_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_PAYROLL_OPERATOR_PLAYBOOK.md).

### A3. Проверка доступа замены — ДО любых отзывов (T−3 … T−1)

Порядок железный: пока замена не прошла этот пункт, §A5–A6 запрещены.

1. Роль назначена по минимуму (least privilege): куратору — кураторское, без
   админ-грейда; **никакого** `MODE_TEACHER`/super_admin вне standing policy.
2. 2FA пройдена на мутационных поверхностях роли (роль-скоуп из
   [TWO_FACTOR_ROUTE_AUDIT_H7830](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TWO_FACTOR_ROUTE_AUDIT_H7830_2026-11-08.md)).
3. Смоук первой линии: замена отвечает на тестовое обращение в @samskrtamru_bot,
   выдаёт test-magic-ссылку ([MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md)),
   видит свои группы/дз в Filament ровно в объёме роли.
4. Признак готовности записывается в таблицу передачи (ссылка на смоук-тикет),
   виза MG: «доступ замены подтверждён».

### A4. Коммуникации (T−2 … T−0)

Черновики (адаптировать, не копировать вслепую; без имен и сумм в общих каналах):

> **Команда (внутренний):** С {дата} дежурство {обязанность} переходит от
> {роль} к {замена}. Передача подтверждена: {ссылка на тикет/чек-лист}.
> Вопросы по живым кейсам — в этот чат.

> **Студентам группы:** Здравствуйте! С {дата} занятия и домашки группы
> «{курс}» ведёт {имя замены}. Всё расписание и ссылки остаются прежними;
> домашки и отметки — там же, в кабинете. Если что-то не откроется —
> напишите в поддержку, поправим.

> **Ops-канал (факт доступа):** Доступ {замена} на роль {роль} проверен и
> подтверждён {дата}; отзыв доступа уходящего — по визе, окно {дата/время}.

### A5. Отзыв доступа уходящего (T−0)

Только после §A3. Механика — существующие поверхности, плейбук новых экранов
не изобретает (сверяется с [admin-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md)):

1. Деактивация учётной записи в Filament (users) — вместо «оставить на всякий
   случай»; least privilege: закрывшееся лицо не держит запасного входа.
2. Выход живых сессий; отзыв грантов по строке («Отзыв» на строке гранта —
   та же механика, что описана в [debtors-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtors-manual.md) для студентов).
3. Если уходящий знал долгосрочные индивидуальные доступы (SSH-ключ, токен
   панели) — они попадают в §A6, не сюда.

### A6. Ротация общих секретов (T−0 … T+2, по визе, по одному за окно)

Порядок — от дешёвого к дорогому; ротировать **только то, до чего уходящий
дотягивался**, и **только после** подтверждённого доступа замены (§A3):

1. **Индивидуальные 2FA / magic-link привычки** — закрываются самим отзывом
   (§A5); отдельно ничего вращать не надо.
2. **Вебхук-сексты Tochka/Telegram/VK/MAX/Zoom** — по одному, с двойным окном
   (новое+старое) согласно fail-policy [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md);
   после каждого — платёжный/ботовый смоук.
3. **Деплой-ключ GitHub** — ротация по [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)
   (там же lock-дисциплина и риск `AUTH_RESTART`); сразу проверка, что деплой-лок жив.
4. **MadelineProto-сессия `@rusamskrtam`** — если уходящий имел доступ к сессии:
   восстановление по [RUNBOOK_USERBOT_SESSION_RECOVERY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_USERBOT_SESSION_RECOVERY.md).
5. **Секреты окружения** — по классификации
   [ENVIRONMENT_VARIABLES](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md);
   `APP_KEY` рутинно **не** ротируется (инвалидирует шифрованные данные) — только
   отдельным решением с визой MG.

Не более одного секрета за окно; после каждого шага — соответствующая проверка
непрерывности из §A7.

### A7. Проверки непрерывности (T−1 … T+7)

Чек после каждой ротации/отзыва и контрольный на T+7:

- оплатный смоук проходит (вебхук fail-policy, [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md));
- боты отвечают (кабинет/ops/записи) — тестовое сообщение по каждому;
- записи занятий появляются в кабинетах (нет recording-gap алертов);
- эскалационная лестница живая: тестовое обращение закрывается за окно ответа
  ([RUNBOOK_SUPPORT_ESCALATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md));
- uptime-страница зелёная: https://samskrte.ru/uptime
  ([UPTIME_BETTERSTACK_MONITORING_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md));
- деплой-лок доступен ([deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)).

## Процесс B — аварийная недоступность ответственного

Триггер: ответственный молчит сверх своего окна ответа из
[RUNBOOK_SUPPORT_ESCALATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md)
(или пропущено два подряд дежурства).

1. **Констатация.** Заметивший пишет в @samskrte_ops_bot: кто, какая обязанность,
   с какого времени тишина, что уже просело. Это строка-факт, не паника.
2. **Явная полномочность.** Владелец контура (MG, 2-я очередь) даёт записанную
   визу на замещение: «{замена} берёт {обязанность} на {срок}, права —
   минимальные». Без этой строки замещение не начинается.
3. **Замещение с минимальными правами.** Замена получает роль по объёму
   обязанности, не «на всякий случай» super_admin; имперсонация — только по
   standing policy. Проверка доступа замены (§A3) выполняется и здесь: сначала
   работающий доступ — потом всё остальное.
4. **Секреты НЕ ротировать вслепую.** Ротация без участия владельца ломает
   непрерывность чаще, чем спасает: вебхук, к которому нет нового секрета,
   уронит платежи. Каждая ротация в этом режиме — отдельная виза MG и отдельное
   окно (§A6).
5. **Коммуникации.** Студентам — только если учебный процесс реально задет
   (перенос занятия, задержка проверки ДЗ); тон — как шаблоны §A4; общий тон:
   «{обязанность} временно ведёт {замена}, расписание без изменений».
6. **Возврат.** При возвращении ответственного: разжалование временных прав
   (обратно по least privilege), ретро-строка в ops-канал: что просело, что
   помогло, что добавить в этот плейбук.

## Настольные учения (синтетические кейсы)

Имена синтетические; кейсы прогоняются по документу, без прод-мутаций.

### Кейс 1 — плановый уход куратора «К.»

Куратор «К.» ведёт две группы и дежурство первой линии; уходит с 01-12.

| Шаг | Действие | Улика (без секретов) |
|---|---|---|
| 1 | «К.» анонсирует уход в ops-канал; MG визирует план | строка-виза в ops-чате, дата |
| 2 | Инвентарь: 2 группы, 1 дежурство, 3 незакрытых кейса поддержки | таблица передачи в тикете |
| 3 | Замена «М.» назначена; передача: «М.» закрывает один живой кейс под присмотром «К.» | ссылка на кейс в тикете |
| 4 | **Доступ «М.» проверен ДО отзыва «К.»**: роль куратора, 2FA пройдена, test-magic-ссылка выдана, тестовое обращение закрыто | смоук-тикет со статусом и временем |
| 5 | Виза MG: «доступ замены подтверждён» — только теперь отзыв | строка-виза |
| 6 | Учётная запись «К.» деактивирована, сессии завершены, гранты отозваны | changelog-строка тикета, без содержимого грантов |
| 7 | «К.» знал вебхук-секрет Tochka → ротация №2 из §A6 по двойному окну; платёжный смоук зелёный | смоук-тикет, время ротации |
| 8 | Коммуникации: группам — шаблон §A4; ops — факт доступа/отзыва | копии сообщений без имен сумм |
| 9 | T+7: все проверки §A7 зелёные | чек-лист со ссылками |

Итоги кейса: **непрерывность** — группы и первая линия ни дня без дежурного
(шаги 3–4 до шага 6); **доступ замены до отзыва** — шаг 4 строго раньше шага 6;
**явная полномочность** — визы на шагах 1 и 5; **улики без секретов** — только
ссылки, даты и статусы.

### Кейс 2 — недоступность админа «А.» (48 часов)

Админ «А.» (деплой + допаковка) молчит вторые сутки; деплой-окно в пятницу.

| Шаг | Действие | Улика (без секретов) |
|---|---|---|
| 1 | Заметивший пишет в @samskrte_ops_bot: «А.» молчит 24ч, деплой-окно под угрозой | строка-факт с временем |
| 2 | MG даёт визу: «оператор О.» берёт деплой-окно, права — минимальные, срок — до связи с «А.» | строка-виза |
| 3 | «О.» проверяет свой доступ: деплой-лок жив, смоук первой линии отвечает | смоук-тикет |
| 4 | Деплой выполнен по [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md) (lock-дисциплина соблюдена) | ссылка на деплой-запись |
| 5 | Секреты НЕ ротируются: у «О.» нет визы на ротацию деплой-ключа — окно проходит на существующих доступах | отсутствие ротационных записей в окне |
| 6 | Если в окне был живой кейс «деньги/доступ» — прямая виза MG по триггеру из [RUNBOOK_SUPPORT_ESCALATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md) | строка-виза на кейс |
| 7 | Связь с «А.» восстановлена: временные права сняты, ретро-строка: «деплой-окно выстоял, добавить в плейбук X» | ретро-строка в ops-чате |

Итоги кейса: **непрерывность** — деплой-окно не пропало (шаги 3–4); **доступ
замены до отзыва** — ничего у «А.» не отзывалось вовсе, временные права выданы
минимально и сняты на шаге 7; **явная полномочность** — визы на шагах 2 и 6;
**улики без секретов** — ни один шаг не требует цитировать кред или лог-строку
секретов.

## Чек-лист одной строкой

```
виза на план → инвентарь обязанностей → передача по каноническим докам
→ доступ замены проверен (роль+2FA+смоук) → виза «доступ подтверждён»
→ коммуникации → отзыв (учётка/сессии/гранты) → ротация по порядку, по одному
→ проверки непрерывности → T+7 контрольный прогон
```

## Покрытие критериев приёмки

| Критерий миссии | Где покрыт |
|---|---|
| Русскоязычный операторский плейбук: плановый уход + аварийная недоступность | весь документ: §A (план), §B (авария) |
| Контуры обучение / поддержка / сервисный доступ | §A1 инвентарь, §A2 передача, §A6–A7 доступ и секреты |
| Повторное использование контрактов доступа и кредов | «Роли и контуры», §A6: ENVIRONMENT_VARIABLES, webhook-security, deploy.md |
| Инвентарь только ролей и систем — без кредов и персональных данных | правило-якорь в шапке; проверка grep на секретные паттерны при приземлении |
| Передача дежурств/курсов | §A1–A2 |
| Проверка доступа замены | §A3 (и его приоритет над отзывом — правило-якорь) |
| Порядок ротации общих секретов | §A6 (нумерованный порядок, не более одного за окно) |
| Отзыв сессий/токенов | §A5 |
| Наименьшие права | §A3.1, §A5.1, §B3 |
| Черновики коммуникаций | §A4, §B5 |
| Проверки непрерывности | §A7 |
| Ссылки на существующие мануалы владельцев/доступа | «Связанные документы», таблица контуров |
| Два синтетических настольных кейса | «Настольные учения»: кейс 1 (плановый уход), кейс 2 (недоступность админа) |
| Непрерывность, доступ до отзыва, явная полномочность, улики без секретов | строки «Итоги» в обоих кейсах |

## Связанные документы

- Лестница эскалации и окна ответа: [RUNBOOK_SUPPORT_ESCALATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_SUPPORT_ESCALATION.md)
- Роли и teacher-поверхности: [STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STANDING_POLICY_ADMIN_SEES_TEACHER_SURFACES_2026.md) · [admin-manual](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md)
- 2FA-гейты по ролям: [TWO_FACTOR_ROUTE_AUDIT_H7830](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TWO_FACTOR_ROUTE_AUDIT_H7830_2026-11-08.md)
- Секретные поверхности: [ENVIRONMENT_VARIABLES](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md) · [webhook-security](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/webhook-security.md) · [deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)
- Telegram-контуры и сессии: [telegram-bots-inventory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-bots-inventory.md) · [telegram-userbot-inventory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md) · [RUNBOOK_USERBOT_SESSION_RECOVERY](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_USERBOT_SESSION_RECOVERY.md)
- Инциденты и мониторинг: [SERVER_INCIDENT_MANUAL](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_INCIDENT_MANUAL.md) · [SERVER_SOFT_ALERT_PLAYBOOK](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) · [UPTIME_BETTERSTACK_MONITORING_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING_RU.md)
- Аудит фонда (этот плейбук закрыл пробел «передача дел»): [AUDIT_PLAYBOOK_RUNBOOK_GAPS_04-10-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_PLAYBOOK_RUNBOOK_GAPS_04-10-2026.md)

_Гасунс_
