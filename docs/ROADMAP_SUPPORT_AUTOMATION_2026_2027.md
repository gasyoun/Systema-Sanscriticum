_Created: 06-07-2026 · Last updated: 05-09-2026_

# Roadmap: автоматизация поддержки 2026–2027 (Q3 2026 → Q2 2027)

_Created: 06-07-2026 · Last updated: 21-08-2026 (year-start RAG overlay: #1633 vs двое в Telegram)_

> **Truth-pass 19-08-2026 (H3072, Opus 5 `claude-opus-5`):** самый живой из «июльских» — по нему продолжают отгружать (двухканальные сводки и канальный сплит ранжирования 30-07, шаблоны canreply H2339 07-08, статус доставки ответа куратору 15-08, cooldown после kill watchdog-а синка H2988 17-08). Плана-двойника нет и не нужно: документ ведут правками по месту.

> Узкий roadmap **support-автоматизации** — как за год снять с людей автоматизируемые 38.5 % вопросов
> рабочего чата «Отдел заботы». Общий продуктовый roadmap —
> [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
> (этот документ его детализирует по одному направлению, не заменяет). Этот же документ — **WS1**
> зонтичного [`docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md)
> (вся Telegram-поверхность: inbound/outbound/надёжность/student-AI). Геймификация — отдельно в
> [`PRANA_ROADMAP.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/PRANA_ROADMAP.md).
> Ground truth по support-коду — [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md):
> сверяться с ним ПЕРЕД реализацией любого тикета отсюда.

**Источник данных:** полная LLM-классификация всех 16 962 вопросов чата «Отдел заботы» за 34 месяца
(19-10-2023 → 06-07-2026), 85 батчей, покрытие 99.7 % — Opus 4.8 (`claude-opus-4-8`), 06-07-2026:
[`Uprava/telegram-zabota-export/ANALYSIS.md`](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/ANALYSIS.md)
(помесячные ряды и разбивка по категориям/авторам —
[`agg_full.json`](https://github.com/gasyoun/Uprava/blob/main/telegram-zabota-export/agg_full.json)).
Roadmap составлен Fable 5 (`claude-fable-5`), 06-07-2026, по хэндоффу
[H243](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H243-Fable_Systema-Sanscriticum_support_automation_year_roadmap_06.07.26.md).

---

## 1. Резюме — цель года

Из 16 918 классифицированных вопросов **38.5 % автоматизируемы** (~6 507 за 34 мес ≈ **190/мес** в
среднем; в сезонный пик сентября–октября — до ~400/мес). P0 (авто-постинг Zoom-ссылки в чат группы)
уже в проде-коде: [PR #333](https://github.com/gasyoun/Systema-Sanscriticum/pull/333), выключен флагом.

**Цель года (к 30-06-2027): закрыть автоматизированным путем ≥ 60 % автоматизируемого потока**
(≈ 115 из ~190 вопросов/мес; ≈ 23 % всех вопросов чата). «Закрыть» = вопрос либо не возникает
(автопостинг, self-service), либо отвечается черновиком/командой быстрее чем за минуту без ручного
поиска фактов. 60 % — реалистично: категории A/B/C/G/H (~20.8 % корпуса) автоматизируются почти
целиком дешевыми средствами, D/E/F/I (~17.5 %) — частично (черновики + self-service), K — пилот.

Почему это достижимо малыми силами: **инфраструктура уже построена** (unified inbox, `SupportAiService`,
топик-таксономия, `ReminderRequestDetector`-паттерн, `DebtorsReport`, self-service долгов/доступа) —
год уходит не на строительство платформы, а на прицельные PR-размерные расширения, каждое из которых
выполняет AI-агент за одну сессию.

### Сводная таблица тикетов

| # | Квартал | Тикет | Категории (доля) | Сводный приоритет | Усилие |
|---|---|---|---|---|---|
| S1 | Q3 2026 | Включить P0 в проде + наблюдение | A + часть C (≈5 %) | ★★★★★ | S |
| S2 | Q3 2026 | Инструментовка deflection (счетчики по категориям) | все (база измеримости) | ★★★★★ | M |
| S3 | Q3 2026 | SupportAnswerSuggester v1 — факт-черновики без LLM (A/B/C) | 10.4 % | ★★★★☆ | M–L |
| S4 | Q3 2026 | Бот-команда `/долги <группа>` поверх DebtorsReport | G (4.9 %) | ★★★★☆ | S–M |
| S5 | Q4 2026 | SupportAnswerSuggester v2 — LLM-черновики (D/E/F) | 11.6 % | ★★★★☆ | L |
| S6 | Q4 2026 | Ростер-дашборд «группа → куратор → студенты» + бот-команды | H (5.5 %) | ★★★★☆ | M |
| S7 | Q4 2026 | Квартальный deflection-отчет №1 (по данным S2 за Q3) | — | ★★★☆☆ | S |
| S8 | Q1 2027 | Self-service доступа «не могу попасть / дайте ссылку» | I (5.9 %) + E | ★★★★☆ | L |
| S9 | ✅ 30-07-2026 (H1838) | Suggester ↔ библиотека шаблонов (MessageTemplate) | D/E/F дешевле | ★★★☆☆ | M |
| S10 | ✅ 29-07-2026 (H1837) | Веб-чат-аналитика (rollup'ы на веб-стороне) | измеримость | ★★★☆☆ | M |
| S11 | Q2 2027 | RAG-пилот по контенту санскрита — GO/NO-GO | K (2.3 %) | ★★☆☆☆ | M (пилот) |
| S12 | Q2 2027 | Годовой ретро-анализ чата (повтор классификации) | все | ★★★★☆ | M |

---

## 2. Метод приоритизации — 4 метрики сразу

Каждый тикет оценен по всем четырем (1–5), сводный приоритет — с перевесом «часов кураторов»
и «простоты внедрения» (быстрые дешевые победы вперед, до сентябрьского пика):

1. **Часы кураторов** — сколько человеко-часов/мес высвобождает (частотность категории × среднее время ответа с поиском фактов, ~3–5 мин на вопрос с ручным поиском).
2. **Выручка / LTV** — влияние на продажи и удержание (скорость ответа на вопрос об оплате = конверсия; «потерянный» студент без ссылки = отвал).
3. **Удовлетворенность студентов** — скорость и полнота ответа, меньше «повисших» вопросов.
4. **Простота внедрения** — дешево (без/мало LLM), переиспользует существующий код, низкий риск.

Жесткие принципы (MG, 06-07-2026, унаследованы от кодовой базы):

- **Минимум внешних расходов**: сначала regex/LMS-факты, LLM — только где regex не тянет, и всегда
  с дешевым префильтром (паттерн [`ReminderRequestDetector`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reminders/ReminderRequestDetector.php), H187).
- **Боты НЕ отвечают студентам сами** — только pending-черновики куратору (как `ReminderSuggestion`
  и `SupportAiService::suggestReply`). Автоотправка — нигде в этом roadmap.
  **Исключение уже в проде:** кабинетный бот (`CuratorAi`) отвечает студенту сам. 21-08-2026 overlay
  (двое кураторов в Telegram, подсказки на сложные, счётчик 🍎) зафиксирован в
  [docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md);
  автоответ в личках саппорт-аккаунта — отдельный флип, не молчаливое расширение этого пункта.
- **Приватность**: импортированные TG-ЛС не уходят во внешний LLM без `support_ai_include_telegram`
  (MG разрешил включить в проде 02-07-2026, но дефолт в коде остается ВЫКЛ).
- **Всё за фича-флагами** (`config/features.php` + `MarketingSetting`), выключено по умолчанию,
  включение — осознанный прод-шаг.

---

## 3. Q3 2026 (июль – сентябрь) — база + быстрые победы ДО сезонного пика

Сезонность диктует дедлайн: пики вопросов — сентябрь–октябрь (сен-2024: 1 155, окт-2025: 1 105).
Всё в Q3 должно быть смержено к **01-09-2026**.

### S1. Включить P0 (авто-постинг Zoom-ссылки) в проде + наблюдение

- **Категории:** A «Zoom/ссылка» (2.7 %) + часть C «расписание» (5.3 %) ≈ 5 % корпуса, ~10 ч/мес кураторов.
- **4 метрики:** часы 4 · выручка 3 (не потерянные на первом занятии) · удовлетворенность 5 (ссылка приходит сама, до вопроса) · простота 5 (код готов).
- **Усилие:** S (конфигурация, не код).
- **Переиспользуется:** всё из [PR #333](https://github.com/gasyoun/Systema-Sanscriticum/pull/333) — команда `classes:post-group-link`, [`SendTelegramChatMessageJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/SendTelegramChatMessageJob.php), гейты `class_link_autopost_enabled` + env `CLASS_LINK_AUTOPOST`.
- **Agent-шаги:** (1) агент готовит список групп с пустым `telegram_chat_id` + инструкцию получения chat_id; (2) человек добавляет бота админом в чаты групп и заполняет поле в Filament; (3) включение флагов; (4) агент через неделю сверяет `schedules.group_link_posted_at` с расписанием.
- **Метрика успеха:** ≥ 90 % занятий групп с заполненным chat_id получили автопост; вопросы категории A в штабном чате в сентябре −50 % к сен-2025.
- **Зависимость:** ручное заполнение `telegram_chat_id` (человек) — это же prerequisite для S3/S5.

### S2. Инструментовка deflection — счетчики «задано vs автоотвечено» по категориям

Без этого тикета прогресс года непроверяем — он идет вторым, не последним.

- **Категории:** все (база измеримости).
- **4 метрики:** часы 2 (сам не экономит) · выручка 2 · удовлетворенность 1 · простота 4; сводно ★★★★★ как prerequisite всего остального.
- **Усилие:** M.
- **Переиспользуется:** [`SupportTopicRule`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportTopicRule.php)/[`SupportTopicAssignment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportTopicAssignment.php) (keyword→category уже есть), [`SupportAiReplyEvent`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAiReplyEvent.php) (лог событий), `SupportDailyRollup`.
- **Agent-шаги:** (1) завести категории A–I из анализа как канонические `SupportTopicRule.category` + сид ключевых слов из n-грамм анализа; (2) per-message классификация входящих (regex по правилам) в `SupportTopicAssignment` для обоих сторов; (3) новые `event_type` в `SupportAiReplyEvent`: `suggested` / `accepted` / `edited` / `discarded` (частично есть); (4) artisan-отчет `support:deflection-report --month=` — по категориям: входящих / с черновиком / черновик принят / закрыто self-service; (5) тесты.
- **Метрика успеха:** отчет за первый полный месяц выдает долю по категориям, согласующуюся с бейзлайном анализа (±5 п.п. на крупных категориях).

### S3. SupportAnswerSuggester v1 — факт-черновики БЕЗ LLM (категории A/B/C)

- **Категории:** A «Zoom» (2.7 %) + B «записи» (2.4 %) + C «расписание» (5.3 %) = 10.4 %, ~20 ч/мес.
- **4 метрики:** часы 5 · выручка 3 · удовлетворенность 4 · простота 4 (ноль LLM-расходов — ответы фактологические, факты в LMS есть).
- **Усилие:** M–L.
- **Переиспользуется:** паттерн [`ReminderRequestDetector`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Reminders/ReminderRequestDetector.php) (regex-префильтр + хай-вотер-марк курсор + pending-очередь); правила S2; факты: `Schedule` (ближайшее занятие), `groups.telegram_chat_id`/ссылки занятий, записи уроков (`Lesson`); показ черновика — существующие кнопки ИИ-ассиста в [`Helpdesk`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php) + `SupportAiService`-инфра (лог в `SupportAiReplyEvent`).
- **Agent-шаги:** (1) сервис `SupportAnswerSuggester` с резолвером фактов по категории (A → ссылка+время ближайшего занятия группы студента; C → расписание группы; B → ссылка на запись урока, если опубликована); (2) шаблоны ответов (Blade/строки, не LLM); (3) сканер обоих сторов по курсору (15-мин cron, как `reminders:detect-requests`); (4) pending-черновик в Helpdesk с Принять/Изменить/Отклонить (реюз баннера `ReminderSuggestion`); (5) флаг `support_answer_suggester` (ВЫКЛ) + `MarketingSetting`-гейт; (6) тесты по образцу `ReminderSuggestion` (31 тест — эталон).
- **Метрика успеха:** ≥ 30 % входящих A/B/C получают черновик; ≥ 50 % черновиков принимаются (по S2-событиям `accepted`/`suggested`).
- **Зависимости:** S2 (категории), частично S1 (маппинг группа→чат). Хэндофф: **H247**.

### S4. Бот-команда `/долги <группа>` поверх DebtorsReport (первый P2-шаг)

- **Категории:** G «кто оплатил / долги» (4.9 %, ~830 вопросов — заметная доля вопросов самого MG).
- **4 метрики:** часы 4 (в т.ч. время MG) · выручка 4 (быстрее видны должники → быстрее напоминание) · удовлетворенность 2 (внутренний инструмент) · простота 4.
- **Усилие:** S–M.
- **Переиспользуется:** [`DebtorsReport`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorsReport.php) (весь расчет), страница [`Debtors`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Debtors.php), Telegram-webhook `/api/telegram/webhook`, `User::sendTelegramMessage()`.
- **Agent-шаги:** (1) обработчик команды в webhook-контроллере: `/долги [группа]` → сводка из `DebtorsReport` (счет должников, сумма, топ-5) с ссылкой на полную страницу `/admin/debtors`; (2) авторизация: только `telegram_id` админов/кураторов (whitelist по ролям), чужим — молчание; (3) `/группа` заготовка под S6 (заглушка «скоро»); (4) тесты webhook-команды (по образцу `tests/Feature/Webhooks/`).
- **Метрика успеха:** вопросы категории G в штабном чате −30 % за квартал после включения; команда используется ≥ 20 раз/мес (лог).
- **Зависимость:** прод-БД (локально расчеты проверяются на сиде). Хэндофф: **H250**.

---

## 4. Q4 2026 (октябрь – декабрь) — LLM-черновики + ростер, работа в сезон

### S5. SupportAnswerSuggester v2 — LLM-черновики для D/E/F

- **Категории:** D «оплата/цена/тарифы» (7.4 %!) + E «доступ/группа/кабинет» (1.5 %) + F «материалы/ДЗ/сертификаты» (2.7 %) = 11.6 %, ~22 ч/мес. D — самая частотная одиночная категория FAQ.
- **4 метрики:** часы 5 · выручка 5 (вопрос о цене = горячий лид; скорость ответа = конверсия) · удовлетворенность 4 · простота 3 (LLM-расход, но с префильтром и капом).
- **Усилие:** L.
- **Переиспользуется:** S3-каркас (сканер, очередь, UI); [`SupportAiService::suggestReply`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAiService.php) + `CuratorAi::chat()` (OpenRouter); факты: `Tariff::calculateFinalPriceForUser()` (цена с учетом лояльности/депозита/upgrade-кредита — НЕ пересчитывать руками), self-service долгов ([`DebtPaymentResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtPaymentResolver.php)), `CertificateService`.
- **Agent-шаги:** (1) расширить префильтр правилами D/E/F; (2) прокинуть LMS-факты студента в промпт (тариф, курс, статус оплат — цифры из кода, LLM только формулирует); (3) стоимость-контроль: дневной cap LLM-вызовов в `MarketingSetting`, счетчик в `SupportAiReplyEvent.meta`; (4) приватность: TG-контент в LLM только при `support_ai_include_telegram`; (5) тесты с фейковым LLM-клиентом.
- **Метрика успеха:** ≥ 40 % входящих D/E/F с черновиком; ≥ 50 % принимаются; LLM-расход ≤ установленного капа (черновик ≈ $0.001–0.01, при ~80 вопросах D–F/мес — единицы долларов).
- **Зависимости:** S3 (каркас), `OPENROUTER_API_KEY` на проде, решение MG о включении `support_ai_include_telegram` (уже разрешено 02-07-2026, дефолт ВЫКЛ).

### S6. Ростер-дашборд «группа → куратор → студенты» + бот-команды `/группа`, `/кто`

- **Категории:** H «кто в группе / кто куратор» (5.5 %, ~930 вопросов — второй по частоте LMS-запрос, включая вопросы MG «а Анатолий через какой заходит?»).
- **4 метрики:** часы 4 · выручка 3 · удовлетворенность 3 · простота 4 (чистый LMS-запрос, ноль LLM).
- **Усилие:** M.
- **Переиспользуется:** модели `Group`/`UserGroup`/`Course`, паттерн EdTech-сайдбара Helpdesk (student-info-modal), `RoleGate`, webhook-каркас из S4.
- **Agent-шаги:** (1) Filament-страница «Ростер»: группа → куратор(ы) → студенты со статус-цветом (оплата/долг/доступ — реюз `DebtorsReport`/`DisciplineScoreService`); (2) поиск по студенту: «в каких группах, какой тариф, какой куратор»; (3) бот-команды `/группа <название>` и `/кто <имя/username>` с той же авторизацией, что S4; (4) тесты.
- **Метрика успеха:** вопросы категории H −40 % за два квартала; страница открывается ≥ 50 раз/мес.

### S7. Квартальный deflection-отчет №1

- **Что:** прогнать `support:deflection-report` за сен–ноя, сравнить с бейзлайном ANALYSIS.md, скорректировать план Q1 (какие категории отстают, где черновики отклоняются — правится префильтр/шаблоны).
- **4 метрики:** часы 1 · выручка 2 · удовлетворенность 1 · простота 5. Ценность — управленческая: без него roadmap слепой.
- **Усилие:** S. **Переиспользуется:** S2 целиком.
- **Agent-шаги:** прогон, короткий MD-отчет в `docs/`, ревизия правил, корректировка Q1-тикетов.
- **Метрика успеха:** отчет существует, категории с deflection < 20 % получили конкретный корректирующий тикет.

---

## 5. Q1 2027 (январь – март) — self-service доступа + удешевление

### S8. Self-service доступа — «не могу попасть / дайте ссылку» решается без куратора

- **Категории:** I «кому выдать ссылку/доступ» (5.9 % — самая частотная одиночная автоматизируемая категория) + E-остаток. ~1 000 вопросов за период анализа.
- **4 метрики:** часы 5 · выручка 4 (студент без доступа = кандидат в отвал) · удовлетворенность 5 · простота 3 (трогает доступный контур — осторожный review).
- **Усилие:** L.
- **Переиспользуется:** [`docs/access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md) (спека готова, 3 @DECIDE внутри); `PaymentObserver::grantAccess()`, `Lesson::isUnlockedBy()`/`unlockingKeys()` (единственный источник истины по доступу), self-service-паттерн долгов (Phase 1+2 в проде-коде), кабинет-бот (`docs/cabinet-bot.md`).
- **Agent-шаги:** (1) закрыть 3 @DECIDE спеки через `/decision-record`; (2) страница/бот-флоу «Проверить мой доступ»: диагностика (оплата есть? группа есть? ключ покрывает урок?) → само-починка где безопасно (переслать ссылку группы, показать оплаченное) → эскалация куратору с диагнозом, где нет; (3) событие в S2-счетчики (`self_served`); (4) тесты доступного контура (регресс `HalfBlockPurchaseTest`-класса обязателен).
- **Метрика успеха:** ≥ 30 % обращений I-класса закрываются self-service без куратора; ноль инцидентов ложной выдачи доступа.
- **Зависимость:** @DECIDE-развилки спеки (человек решает до реализации).

### S9. Suggester ↔ библиотека шаблонов MessageTemplate — удешевление и стабилизация ✅ 30-07-2026

> **Сделано (H1838, Fable 5 `claude-fable-5`, см. CHANGELOG).** Шаги (1)–(2): привязка
> «категория → шаблон» — колонка `message_templates.suggester_category` + селектор в
> [`MessageTemplateResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/MessageTemplateResource.php)
> (только D/E/F — фактовые A/B/C и так без LLM); резолвер
> [`SupportTemplateDraftResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportTemplateDraftResolver.php)
> стоит перед LLM-композером — привязанная категория LLM не вызывает и cap не
> расходует. Шаг (3) — A/B-сравнение принимаемости — обеспечен данными: событие
> `answer_template_drafted` (зеркало `answer_llm_drafted`) + `facts.type='template'`
> на суггесте; сам отчёт — по накоплении S2-событий. Флаг `support_template_drafts`,
> ВЫКЛ по умолчанию.

- **Что:** для категорий с устоявшимся ответом черновик собирается из шаблона `MessageTemplate` (H221/H223 canned replies) с подстановкой LMS-фактов — LLM выпадает из цикла там, где ответ шаблонизируем.
- **4 метрики:** часы 2 · выручка 2 · удовлетворенность 3 (консистентные ответы) · простота 4.
- **Усилие:** M. **Переиспользуется:** `MessageTemplate` (H221), canned-reply dropdown в Helpdesk (H223), S3/S5-каркас.
- **Agent-шаги:** (1) привязка «категория → шаблон» в админке; (2) резолвер: если у категории есть шаблон — черновик из шаблона, LLM не вызывается; (3) A/B-сравнение принимаемости шаблонных vs LLM-черновиков по S2-событиям.
- **Метрика успеха:** доля LLM-вызовов на черновик −50 % при не худшей принимаемости.

### S10. Веб-чат-аналитика — rollup'ы и топики на веб-стороне ✅ 29-07-2026

> **Сделано (H1837, Opus 5 `claude-opus-5[1m]`, PR см. CHANGELOG 1.69.0).** Решение по
> шагу (1) — «агрегировать по `SupportConversation`-тредам **с запасным ключом
> `user_id`**»: не всякое веб-сообщение принадлежит треду (VK-бот, TG-student-бот и
> ручные ответы из `UserResource\Pages\Dialogs` пишут `chat_messages` без
> `support_conversation_id`), поэтому агрегация только по треду не дала бы обещанных
> «100 % входящих». Одна таблица `support_daily_rollups` с колонкой `channel` вместо
> параллельной — иначе шаг (3) требовал бы ручной сверки двух источников. Арифметика
> метрик вынесена в общий [`SupportRollupMetrics`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/SupportRollupMetrics.php),
> поэтому шаг (2) (unresolved-after-N-hours) по построению считается одинаково в двух
> каналах. Запись — почасовая команда `support:rollup-web` за флагом
> `features.support_web_rollups` (OFF по умолчанию). Хранилища сообщений НЕ слиты.
>
> **ВКЛЮЧЕНО НА ПРОДЕ 30-07-2026** (`SUPPORT_WEB_ROLLUPS=true` в `/var/www/html/.env`,
> `config:cache` пересобран; бэкап `.env.bak.h1837` рядом). Бэкфилл — 31 день,
> 20 строк. Измерено на живых данных:
>
> | Метрика | До включения | После |
> |---|---|---|
> | `support_daily_rollups` всего | 3 876 | 3 913 |
> | Разбивка по каналам | `{telegram: 3876}` | `{telegram: 3893, web: 15, telegram_bot: 5}` |
> | `unresolved_after_hours` | 0 (колонка только что добавлена) | 53, из них 9 веб-строк |
> | Топики веб-строк | нет вовсе | `access 5 · schedule 4 · payment 3 · materials 1 · uncategorized 13` |
>
> **Метрика успеха S10 («отчёт покрывает 100 % входящих обоих каналов») проверена
> арифметически, а не на глаз:** сумма `incoming_count`/`outgoing_count` веб-строк за
> окно против прямого счёта `chat_messages` по `role` — **46/46 и 41/41, delta 0**.
> Первый прогон дал delta −3/−3: `--days=30` покрывает 01–30.07, а окно проверки
> начиналось 30.06 — off-by-one в проверке, не в агрегаторе; `--days=31` закрыл его
> ровно.
>
> **Что подтвердил прод про выбор ключа субъекта:** из 175 строк `chat_messages`
> **162 (93 %) не имеют `support_conversation_id`** — распределение веб-строк по
> ключу вышло `{user: 15, thread: 5}`. Агрегация только по треду (первый из двух
> вариантов в шаге (1) ниже) покрыла бы меньшинство потока и отчиталась бы об этом
> как о покрытии. Запасной ключ `web_user_id` здесь несущий, а не косметический.
>
> ### H1938 — unattended scheduled path verified (30-07-2026 ~12:50 MSK)
>
> **Verdict: PASS on scheduler fire; metrics flat because no new web traffic (not a fault).**
>
> H1837 left open: the 31-day backfill was hand-run, and at enablement time
> `schedule:run` was locked out by a live H1914 guard test (`debug:hang` +
> `timeout`, pid holding the lock). Until a *scheduled* `support:rollup-web`
> ran, “web channel is measured” rested on a human typing the command.
>
> | Check | Result |
> |---|---|
> | Host health (`uptime` load) | `0.15, 0.30, 0.36` — healthy; not the 28–29.07 livelock class |
> | `schedule:list` | `25 * * * * php artisan support:rollup-web` — Next Due ~33 min at probe |
> | Scheduled fires in `storage/logs/schedule.log` | **10× DONE** at `:25` from `06:25` through `15:25` MSK (durations 346–391 ms) — unattended, not hand-run |
> | `flag_support_web_rollups` | still `true` |
> | `rollups_total` / by channel | **3913** / `{telegram:3893, telegram_bot:5, web:15}` — **identical to H1837 enablement baseline ~12:20 MSK** |
> | `chat_messages_total` / without thread | **175** / **162** — unchanged → no new web messages to roll up |
> | Coverage arithmetic (`/tmp/s10_coverage.sh`) | still **EXACT** — incoming 46/46, outgoing 41/41, delta 0 |
> | Residual `SKIP:` lines in `schedule.log` | 41 total (overlapping `schedule:run` holders for some slots); **does not block** `support:rollup-web` — every `:25` slot since 06:25 completed |
>
> **Stop condition met:** at least one scheduled (not hand-run) `support:rollup-web`
> has executed on prod. Row counts did not advance *because* `chat_messages_total`
> is still 175 — the hourly job re-aggregates the same two-day window over the same
> messages; that is success of a no-op pass, not a silent failure. When new web
> traffic arrives, the same path will write new/updated `web` / `telegram_bot` rows
> without a manual command.
>
> **Residual RULED 30-07-2026:** keep `support.rollup.web_backfill_days` default **2**.
> If the scheduler is ever down longer than two days, a one-shot manual
> `php artisan support:rollup-web --days=N` (or a future catch-up) is acceptable —
> no widen / no automated walk-back unless a multi-day outage actually bites.
> No further human decisions remain from H1938.
>
> Executor: Grok 4.5 (`grok-4.5`), handoff
> [H1938](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1938-Sonnet_Systema-Sanscriticum_s10-scheduled-run-verify-web-rollups_30.07.26.md)
> (intended Sonnet 5; run authorized on Grok).

- **Что:** закрыть известную асимметрию (`support-subsystem-map.md` «Actually open»): `SupportDailyRollup`/топики покрывают только TG-сторону; веб-`ChatMessage` не агрегируется — S2-метрики видят не весь поток.
- **4 метрики:** часы 1 · выручка 2 · удовлетворенность 1 · простота 3. Ценность — полнота измерений + закрытие техдолга.
- **Усилие:** M. **Переиспользуется:** `SupportDailyRollupAggregator` (обобщить), `SupportConversation`-тред (уже общий для обоих сторов).
- **Agent-шаги:** (1) агрегатор по `ChatMessage` (или по `SupportConversation`-тредам — решить по коду); (2) unresolved-after-N-hours KPI; (3) единый дашборд обоих каналов.
- **Метрика успеха:** deflection-отчет покрывает 100 % входящих обоих каналов.

---

## 6. Q2 2027 (апрель – июнь) — пилот RAG + годовое измерение

### S11. RAG-пилот по контенту санскрита — GO/NO-GO

- **Категории:** K «эксперт — контент по санскриту» (2.3 %, ~395 вопросов за 2.7 года ≈ 12/мес).
- **4 метрики:** часы 2 (объем мал!) · выручка 3 (качество обучения, retention) · удовлетворенность 4 · простота 2 (индекс материалов, LLM-расход).
- **Усилие:** M (ограниченный пилот). **Сознательно последний:** самая низкая частотность на самое дорогое решение.
- **Переиспользуется:** материалы лекций (lecture-builder), `CuratorAi`, S3/S5-очередь черновиков; prior-art — KB кабинет-бота (`docs/cabinet-bot.md`) и ORS-FAQ.
- **Agent-шаги:** (1) сначала посчитать по S2, сколько K-вопросов реально в потоке — если < 10/мес, честный NO-GO (зафиксировать решением); (2) при GO: индекс по опубликованным материалам курса → черновик с цитатой источника; (3) пилот на одном курсе.
- **Метрика успеха:** решение GO/NO-GO задокументировано данными; при GO — ≥ 50 % K-черновиков принимаются куратором без правки фактов.

### S12. Годовой ретро-анализ — повторная классификация чата

- **Что:** повторить пайплайн анализа (ANALYSIS.md §6: извлечение вопросов → батчи → LLM-классификация → агрегация) на сообщениях 07-2026 → 06-2027 и сравнить доли категорий с бейзлайном. Это внешняя валидация всей программы: S2-счетчики меряют «что мы автоматизировали», ретро-анализ — «что стало с потоком на самом деле».
- **4 метрики:** часы 1 · выручка 3 (решение о продолжении инвестиций) · удовлетворенность 1 · простота 4 (пайплайн отработан).
- **Усилие:** M. **Переиспользуется:** пайплайн Opus-классификации 06-07-2026 (85 батчей), таксономия A–M.
- **Agent-шаги:** (1) свежий экспорт чата; (2) прогон пайплайна на новом периоде; (3) сравнительный отчет: категории × (бейзлайн-доля, новая доля, deflection по S2); (4) roadmap 2027–2028 (новый документ).
- **Метрика успеха года (главная):** автоматизируемые категории (A–I) в штабном чате сократились так, что закрыто ≥ 60 % бейзлайн-потока; сентябрь-2026-пик пройден без роста незакрытых вопросов к сен-2025.

---

## 7. Что НЕ автоматизируем и почему

**59.3 % чата не трогаем принципиально:**

- **Координация/делегирование — 41.1 % (~6 950).** «Справитесь?», «какие наши шаги?», «что ответить?» —
  это живое управление командой, суть работы руководителя. Автоматизация здесь = имитация менеджмента;
  вред превышает пользу. Единственное допустимое касание — LMS-справки (S4/S6) сокращают *поводы*
  для части таких вопросов («кто оплатил?» перестает быть вопросом к человеку).
- **Шум/риторика — 18.2 % (~3 070).** Не запросы: восклицания, риторика, обрывки. Отвечать на них
  ботом — генерировать шум в квадрате.
- **Автоответ студенту без человека — нигде.** Унаследованный принцип: бот готовит черновик, человек
  отправляет. Пересматривается не раньше, чем deflection-данные двух кварталов покажут стабильную
  принимаемость черновиков ≥ 80 % в категории (и тогда — отдельным решением MG, не тикетом).

---

## 8. Метрики и инструментовка (сквозная)

Что должно логироваться в LMS, чтобы прогресс был проверяем (детали — S2/S10):

| Сигнал | Где живет | Тикет |
|---|---|---|
| Входящий вопрос × категория A–I | `SupportTopicAssignment` per-message (оба стора) | S2 |
| Черновик предложен / принят / отредактирован / отклонен | `SupportAiReplyEvent.event_type` | S2 |
| Автопост ссылки состоялся | `schedules.group_link_posted_at` (есть, PR #333) | S1 |
| Self-service закрыл обращение | событие `self_served` + существующие маркеры self-service (`payments.is_self_service`-паттерн) | S8 |
| Использование бот-команд | лог команд webhook | S4/S6 |
| LLM-расход на черновики | `SupportAiReplyEvent.meta` + дневной cap | S5 |
| Сводка | `support:deflection-report --month=` | S2, читается в S7/S12 |

**Deflection по категории** = (черновик принят + self-service + вопрос не задан против бейзлайна) / бейзлайн-частота категории.
Отчет — ежемесячно; управленческий разбор — раз в квартал (S7, аналог в Q1/Q2).

---

## 9. Риски и зависимости

| Риск / зависимость | Касается | Митигция |
|---|---|---|
| `groups.telegram_chat_id` не заполнены (ручной шаг, человек) | S1 → S3/S5 | список-инструкция от агента; заполнение до 15-08-2026 |
| Дедлайн сезона: Q3-тикеты не успевают к 01-09 | S1–S4 | Q3-объем нарезан S/M; S3 деградирует до подмножества категорий (сначала A/C) |
| LLM-расходы поплывут | S5, S11 | префильтр обязателен; дневной cap; S9 выводит LLM из шаблонизируемых категорий |
| Приватность TG-ЛС во внешнем LLM | S5 | флаг `support_ai_include_telegram` (дефолт ВЫКЛ; включение — прод-решение, MG разрешил 02-07-2026) |
| Прод-миграции стоят (блокер деплоя, см. `.ai_state.md` WIP) | S2, S3, S6 (новые таблицы/колонки) | те же fallback-SQL-паттерны, что H204; тикеты не начинать раскатывать до снятия блокера |
| Доступный контур (S8) — денежно-критичный | S8 | обязательный регресс access-сьютов + осторожный review; self-починка только read-only и переотправка уже положенного |
| Открытые находки [SECURITY_AUDIT_money_2026-07-02.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY_AUDIT_money_2026-07-02.md) | S8 особенно | закрыть до включения self-service доступа в проде |
| Reply-out доставка не проверена живьём | S3/S5 (доставка ответа в TG-support) | **Import-путь userbot ЖИВОЙ в проде** (Иван операционализировал — сессия+воркер, подтв. 11-07-2026); не проверена именно **доставка reply-out**. Черновики работают и без него (куратор шлёт как сейчас); контролируемая канарейка — WS1.3 зонтичного [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md) |
| Прод-данные недоступны агентам локально | S4, S6, S7 | расчеты тестируются на сиде; для аналитики есть локальный ledger-аналог (H138/H240-прецедент) |

---

## 10. Провязка

- Хэндоффы: **H247** (S3, SupportAnswerSuggester v1), **H250** (S4, `/долги`-команда) —
  [реестр](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md).
- Топ-3 Q3-тикета отражены в [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) (Tier 0, Systema).
- Статус-журнал: [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md) § Now-SUPPORT-AUTO.

_Dr. Mārcis Gasūns_
