# Self-serve: углубить и расширить — детерминированная классификация до LLM

_Created: 21-08-2026 · Last updated: 21-08-2026_

**Model:** Grok 4.6 (`grok-4.6`)
**Question:** как углубить и расширить self-serve в Systema; какая классификация из Telegram и [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ) возможна **до** LLM; что можно рассортировать сразу и бесплатно.

**Verdict:** PARTIAL. Классификатор, факты LMS и кабинетные self-serve двери уже есть. Не хватает **по-сообщению** (не по дню), **состояния CRM как признака** и **импорта типичных фраз ORS-FAQ**. LLM нужен только на хвосте.

---

## 1. Две оси, которые нельзя смешивать

| Ось | Что это | Уже есть | Как углублять |
|---|---|---|---|
| **Кабинет / бот студента** | Студент сам видит группы, ДЗ, «почему закрыто», платит долг | [`StudentSelfService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/StudentSelfService.php) · [`AccessDiagnosticsService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/AccessDiagnosticsService.php) · debtor Phase 1–2 | Больше **намерений**, которые отвечают данными LMS, не текстом FAQ |
| **Лички саппорт-аккаунта** | Человек пишет куратору в Telegram | Keyword rollup · regex A–F · [H3233 (Grok 4.6) — B: auto-send simple LMS facts on support DMs](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3233-Grok_Systema-Sanscriticum_support-dm-simple-auto-reply_21.08.26.md) влито ([PR #1939](https://github.com/gasyoun/Systema-Sanscriticum/pull/1939)), флаг `SUPPORT_DM_AUTO_REPLY` default **OFF** | Включить флаг после смоука; расширить A/B/C фразами ORS-FAQ; D/E/F — дверь в кабинет, не автоответ деньгами |

Документ-рамка на сегодня: [docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GAP_RAG_YEAR_START_CURATOR_CAPACITY_21-08-2026.md). Кабинетный бот уже отвечает сам (OpenRouter). Лички — нет, пока флаг OFF.

---

## 2. Что уже классифицируется без LLM (бесплатно)

Порядок — от дешёвого к дорогому. LLM не входит ни в один слой.

### Слой 0 — состояние CRM, текст не нужен

Источник: [`HelpdeskStudentContextService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/HelpdeskStudentContextService.php) и [`AccessDiagnosticsService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/AccessDiagnosticsService.php). Сейчас это **контекст для куратора**, не вход классификатора.

| Признак | Что можно сделать сразу |
|---|---|
| Нет `User` / нет `social_accounts` | Лид или гость → публичный каталог / запись, не Zoom группы |
| Есть долг (`StudentDebtsService`) | Не спрашивать «сколько стоит» — дать «Мои долги» / checkout |
| `key_missing_for_paid_range` | Кнопка «Открыть оплаченные блоки», не куратор |
| Нет TG/VK привязки | Deep-link `telegram.connect` |
| Есть занятие в ближайшие N часов | Запрос «ссылка» = Zoom этой группы |
| ДЗ в статусе rework / overdue | Запрос «домашка» = [`homeworkSummary`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/StudentSelfService.php) |
| Photo / document без текста | С высокой вероятностью **чек** (ORS-FAQ тема 01, 1 из 5 диалогов) |

### Слой 1 — команды и тип сообщения

[`StudentSelfService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/StudentSelfService.php) уже ловит до `CuratorAi`:

- группы / расписание: `/groups`, «мои группы», «моё расписание»
- ДЗ: `/homework`, «домашка», «статус ДЗ»
- меню: `/help`, `/menu` (**не** слово «помощь» — оно эскалирует человеку)
- предупреждение о занятии: `/absent` `/late` `/early` `/maybe` `/coming` (флаг `ATTENDANCE_NOTICES`)

Расширить теми же фразами: «ссылка на зум», «где запись», «мои долги», «magic-link / не могу войти».

### Слой 2 — regex A–F (уже в коде, не на проде как автоответ)

[`SupportAnswerSuggester::RULES`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerSuggester.php) — **никакого LLM в v1**. Порядок важен: «ссылка на запись» → B, не A.

| Код | Категория | Regex-якоря (сжато) | Ответ без LLM |
|---|---|---|---|
| **A** | Zoom / ссылка | зум, zoom, подключ, ссылк, войти в занятие | [`SupportAnswerFactResolver::resolveZoom`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerFactResolver.php) |
| **B** | Запись урока | запис, пересмотр, rutube, youtube | `resolveRecording` |
| **C** | Расписание | расписан, во сколько, перенос | `resolveSchedule` |
| **D** | Оплата / цена | сколько стоит, тариф, рассрочк, скидк | **не автоответ**; публичные тарифы только гостю; иначе кабинет / куратор |
| **E** | Доступ / кабинет | нет доступа, логин, пароль, какая группа | AccessDiagnostics / magic-link |
| **F** | Материалы / ДЗ / сертификат | материал, дз, сертификат | homeworkSummary / FAQ chunk |

H3233 шлёт студенту только A/B/C **и только если** факт LMS не пустой. D и пустые факты → подсказка на `ADMIN_TELEGRAM_ID`.

### Слой 3 — 11 тем ORS-FAQ (корпус 3 064 диалога)

Канон: [ors_faq/wiki/topics](https://github.com/gasyoun/ORS-FAQ/tree/main/ors_faq/wiki/topics). Частоты — поле `source_dialogs` + строка «Частота» в теле. **Типичные фразы учеников** — готовый словарь точных совпадений.

| # | Тема | Диалоги | Сообщения | Бесплатный слот | Автоответ студенту? |
|---|---|---|---|---|---|
| 01 | Оплата / чек | 720 | 2 553 | Слой 0: photo/document + «чек», «оплатил», «перевёл» | **Нет** (деньги). Ack + «Мои долги» / витрина |
| 02 | Стоимость | 973 | 1 744 | Слой 2 D + каталог | **Нет**. Каталог целиком, не сгенерированная цена |
| 03 | Запись на курс | 654 | 967 | «хочу записаться», «как присоединиться» + нет User | Ссылка на `samskrte.ru/online` |
| 04 | Zoom | 338 | 621 | Слой 2 A | **Да**, если есть группа и ссылка |
| 05 | Записи уроков | 549 | 905 | Слой 2 B | **Да**, если запись опубликована |
| 06 | Расписание | 319 | 527 | Слой 2 C + StudentSelfService | **Да** |
| 07 | Пауза | 114 | 133 | «пауза», «больничный», «догоню» ∧ ДЗ | CRM-note (правило homework-pause), не бот-юрист |
| 08 | Возврат | 56 | 69 | «возврат», «верните» | **Нет**. Куратор |
| 09 | Депозит | 24 | 25 | редкое | FAQ-чанк или куратор |
| 10 | Техпроблемы | 164 | 237 | «не открывается», «не грузится» | Self-serve: пароль / бот / Zoom-инструкция |
| 11 | Материалы | 313 | 638 | Слой 2 F | Ссылка на чат курса / кабинет |

Сумма `source_dialogs` > 3 064: темы пересекаются (один диалог — несколько тем). Для ранжирования self-serve это нормально.

**Сразу бесплатно закрываются как факты LMS (A+B+C+часть 03/06/11):** порядка **1 200+ диалогов** из 3 064, если студент идентифицирован и факт есть. Плюс **чеки (01)** как *маршрут*, не как авто-зачёт оплаты.

### Слой 4 — keyword rollup (грубый, не для автоответа)

[`SupportTopicClassifier`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/SupportTopicClassifier.php) клеит **весь день** чата в одну строку и ставит одну категорию. Прод-выгрузка 30-07-2026 ([docs/DEFLECTION_BASELINE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DEFLECTION_BASELINE_2026.md)):

| Категория | queries | deflection | Замечание |
|---|---|---|---|
| schedule | 597 | **617** | №1; Zoom/запись в таксономии **нет** — они свалены сюда и в uncategorized |
| payment | 1 157 | 538 | №2; автоответ запрещён |
| materials | 268 | 106 | |
| technical | 111 | 69 | |
| refund | 59 | 30 | |
| uncategorized | 878 | 4 | хвост; «llm fallback reserved» **не реализован** и не должен быть первым шагом |
| access | 1 040 | 1 | огромный объём, низкий deflection-score — кабинетный диагност важнее бота |

Это **аналитика**, не роутер. Углубление: те же правила, что слой 2, **на каждое входящее**, не на день.

### Слой 5 — BM25 по FAQ (всё ещё без LLM)

[`Bm25FaqRetriever`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Faq/Bm25FaqRetriever.php): 82 чанка, eval 20/20 top-3 ([docs/FAQ_RAG_EVAL_H2448.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FAQ_RAG_EVAL_H2448.md)). Флаг `FAQ_RAG_SUGGESTER` default OFF. Подходит как **подсказка куратору** и как отказ «нет чанка → человек». Не как генерация.

---

## 3. Как углубить (уже построенное — включить и сузить)

1. **Пер-сообщение, не пер-день.** `SupportTopicClassifier` оставить для rollup. Роутер автоответа — `SupportAnswerSuggester::categorize` (уже так в H3233).
2. **Развести Zoom и запись** в аналитике. Baseline прямо говорит: таксономия rollup не видит A/B, поэтому D1 «начни с Zoom» спорил с «schedule №1». Считать A/B/C из regex, не из `SupportTopicRule`.
3. **CRM как tie-break.** Один текст «ссылка» + занятие через час → A. Тот же текст + нет группы → запись на курс (03), не Zoom.
4. **Чек = тип вложения**, не NLP. Photo/document во входящем саппорта → очередь «подтверждение оплаты», куратор видит сумму/курс из CRM.
5. **Кабинетные двери вместо ответа.** D/E: не генерировать «вам надо оплатить X». Кнопка «Мои долги» / «Почему закрыто» / magic-link.
6. **Фразы ORS-FAQ → правила.** Секция «Типичные фразы учеников» в 11 файлах — импорт в `SupportTopicRule` **или** в `RULES` (один канон). Не второй словарь в n8n.
7. **Флаги.** `SUPPORT_DM_AUTO_REPLY` и `FAQ_RAG_SUGGESTER` default OFF. Код H3233 инертнен, пока №79 в [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) не включён. Смоук на A/B/C с известным студентом, затем ON.

---

## 4. Как расширить (новые self-serve двери, всё ещё без LLM)

Приоритет по ORS-FAQ диалогам и по deflection.

| # | Дверь | Данные | Канал |
|---|---|---|---|
| 1 | «Где Zoom / запись / во сколько» | FactResolver (уже) | Личка саппорта (H3233) + кабинет-бот (те же интенты, что SelfService) |
| 2 | «Вот чек» | тип вложения + долг/pending Payment | Очередь куратору с карточкой; студент не ждёт «принято?» вслепую — статус платежа в кабинете |
| 3 | «Сколько стоит / как записаться» | каталог `CourseCatalogProvider`, не LLM | Гость: публичные тарифы. Студент: его курс |
| 4 | «Не могу войти / нет доступа» | AccessDiagnostics (уже в кабинете) | Повторить 3 действия в боте: materialize / connect / password |
| 5 | «Мои долги» | debtor self-service | Интент в `StudentSelfService` → ссылка на вкладку |
| 6 | «Не приду / опоздаю» | attendance notices (флаг OFF) | Включить №66, если кураторы ещё отвечают на это руками |
| 7 | «Где материалы / ДЗ» | homeworkSummary + чат курса | Расширить F-фразы из темы 11 |
| 8 | Пауза / возврат | 07+08 | **Не** self-serve-юрист. Классифицировать → куратор + CRM-note |

Не строить: Ollama / bge-m3 / `knowledge_chunks` до железа Ивана (H3234, 01-10-2026). Не кормить `docs/**` студенту. Цены только из каталога.

---

## 5. Что **не** сортировать бесплатно «как ответ»

Это можно **пометить** без LLM, но ответ — человек или кабинет, не шаблон:

- любая сумма, скидка, рассрочка, зарубежный платёж (H218-контур)
- «оплатил, откройте» без строки Payment
- возврат, перевод в другую группу «по договорённости»
- юридическое «пауза на N месяцев»
- нецензурный / конфликтный тред
- вопрос без идентификации и без публичного факта

Пометка ≠ автоответ. Пометить D/E/refund детерминированно — уже победа: куратор не читает очередь сверху вниз.

---

## 6. Зазор относительно H300

[H300 (Sonnet 5) — self-service support UX audit](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/handoffs/H300-Sonnet_Systema-Sanscriticum_self-service-support-ux-audit_07.07.26.md) всё ещё 🟡: UX-аудит кабинета («не могу войти / где курс / почему закрыто»). Этот документ — **классификация и маршруты**, не аудит экранов. H300 не закрыт этим файлом.

Связанный исследовательский вопрос: [Q2607-48](https://github.com/gasyoun/Uprava/blob/main/QUESTIONS_LOG.md) (self-serve vs curator load).

---

## 7. Следующий инженерный срез (не этот документ)

Импорт «Типичные фразы» 04/05/06 → тесты `SupportAnswerSuggesterTest` / `StudentSelfServiceIntentTest`; photo→чек как слой 0; интент «мои долги» в кабинет-боте. Флаг H3233 не включать из этого брифa — это отдельный смоук на проде.

_Dr. Mārcis Gasūns_
