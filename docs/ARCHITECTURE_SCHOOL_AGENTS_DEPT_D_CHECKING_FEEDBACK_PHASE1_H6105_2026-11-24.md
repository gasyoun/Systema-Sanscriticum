# Архитектура: школа-агенты, департамент D «Проверка и фидбек» — фаза-1 (H6105)

_Created: 24-11-2026 · Last updated: 24-11-2026_

Исполнение handoff [H6105-OxAlpha_…school-agents-dept-d-check-feedback](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6105-OxAlpha_Systema-Sanscriticum_school-agents-dept-d-check-feedback_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента D): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «D. Проверка и фидбек (10) — УТП на санскрит-движках».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.
- Prior art департаментного контура: [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md) и [ARCHITECTURE_SCHOOL_AGENTS_DEPT_E_CONTENT_METHOD_PHASE1_H6107](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_E_CONTENT_METHOD_PHASE1_H6107_2026-11-18.md) (тот же паттерн; департаменты A/D — разные юниты, `--allow-dup` против topic-overlap H6100 подтверждён условиями H6105).

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25. Департамент D (10 ролей, ID 35–44) **входит** в кандидатский блок фазы-1, но его запуск отложен на **W3**: волна v2 — «учебка-проверка D (движки Q10 как ядро)», гейт выхода — «золотые наборы собраны, выборка MG работает». W2 добирает остальные роли поддержки B/C до 25-квоты; состав D на старте и его место в квоте определяются квотной машиной H6174 и решением MG на пороге W2→W3.

⇒ Фаза-1 департамента D — это **подготовка под W3, а не запуск**: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. По диспозиции все 10 ролей D — профиль УЧ, волны 2–3; две роли исключены статусом `excluded` (см. §2). Ни одна D-роль не получает флаг запуска, планировщика, LLM-вызова или исходящих сообщений в этой фазе. Любой будущий запуск D-роли — только после W1/W2-гейтов (2 недели без Sev + доказанное снижение человеческой нагрузки), golden-наборов D и выборки MG, со всеми гейтами Q3–Q9.

Изменений в ростере это не вносит — фиксирует его следствие на уровне репозитория: фаза-1 департамента D = подготовка, запуск W3 по гейту золотых наборов.

## 2. Роли департамента D и estate-поверхности

| # | Роль (ID) | Тип | Волна (диспозиция) | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|---|
| D1 | hw-screener (35) | 🧠 | 2 | `HomeworkSubmission`/`HomeworkFile`/`HomeworkComment`, skill student-homework-help; ограниченный LLM-контур prior art: `student_agent` homework_hint (config/features.php:1809–1826, H3231 — тело работы в LLM не уходит, флаг OFF) |
| D2 | sandhi-checker (36) | ⚙️ | 2 | skills sandhi-check / sandhi-split / sandhi-council (индуцированные правила + корпусная частота) |
| D3 | parser-tutor (37) | 🧠 | 2 | skills sanskrit-parse, panini-sutra-lookup, panini-commentary-corpus |
| D4 | devanagari-grader (38) | 🧠 | 3 | skill sanscritica-ocr (vision-OCR прописей) |
| D5 | pronunciation-coach (39) | — | **Исключена** | `excluded` — STT-произношение, kill-candidate: политика H6090 §1.9 + kill-candidates V2; активация блокируется |
| D6 | essay-referee (40) | 🧠 | 2 | paper-referee-паттерн (рецензия по рубрике); калибровка на golden-наборе D — гейт Q8 |
| D7 | quiz-generator (41) | 🧠 | 3 | `CourseQuiz`/`CourseQuizQuestion`/`CourseQuizAttempt`, движки `public/lila` (cloze/match/sort/table), `app/Services/Srs/DistractorSampler.php` (дистракторы из типовых ошибок) |
| D8 | plagiarism-sniffer (42) | — | **Исключена** | `excluded` — плагиат-скоринг, kill-candidate: политика H6090 §1.9 + kill-candidates V2; активация блокируется |
| D9 | progress-reporter (43) | 🧠 | 2 | поверхности прогресса student dashboard / `ExternalLearningProgress`; персональные данные студента — под PII-гейтом Q5 |
| D10 | srs-coach (44) | ⚙️ | 2 | `SrsDeck`/`SrsCard`/`SrsReviewState`, `app/Services/Srs/*` (DistractorSampler, LessonFlashCardsSync, ReviewService), skill anki-srs-import ([MANUAL_AGENT_ANKI_SRS_IMPORT](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md)) |

Запускаемый состав D — 8 ролей (D5/D8 исключены статусом `excluded`, в волны не включаются). Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после гейта W3 и гейтов ниже.

## 3. Гейто-упаковка (Q2–Q9) — как применяется к департаменту D

| Гейт | Правило (решения 05-10) | Для D в фазе-1 |
|---|---|---|
| Q2 квота/волны | фаза-1 = 25 агентов, запуск 5–7/нед | D в кандидатском блоке B+C+D (32→25), запуск — волна W3; фактический состав и лимиты — квотная машина H6174 + решение MG на пороге W2→W3 |
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Любое сообщение студенту/куратору из D-роли (оценка, фидбек, отчёт) — только черновик до визы, потом human-send-слой H6183. **0 прод-писем до гейта волны** (H6084) |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | D — самый персонально-данный департамент после поддержки: тела домашек (D1), фото прописей (D4), личные ошибки SRS (D10), персональные отчёты (D9), учебные тексты студента (D6). Обязателен маскер `app/Services/Support/SupportStudentPiiMasker.php` до любого LLM-вызова, fail-closed (H6144/H6182); «чистых от PII» ролей в D нет — корпусные проверки (D2/D3) тоже идут под обёрткой по правилу фазы 0 |
| Q6 скидки | полный запрет автономии, решает MG | Запрещено без оговорок: вердикты D (оценка, замечание, отчёт) не касаются денег — ни одной роли D не предлагать скидку/рассрочку/баланс; только предлагает, решает MG |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для всех 🧠/🔄 ролей D — слой H5867 + per-role лимиты из квотной машины H6174 (`config/school_role_quota.php`, `app/Services/Support/Hermes/Quota/SchoolRoleQuotaService.php`); генеративные роли D (D1, D3, D4, D6, D7, D9) — основной потребитель квоты департамента |
| Q8 учебка | золотые наборы + выборка MG (gold-adjudicate-паттерн) | **Центральный гейт департамента и гейт выхода волны W3**: ни одна D-роль не стартует, пока golden-наборы D не собраны и выборка MG не работает. W1 golden-набор H6181 обслуживает только первую волну поддержки и **не заменяет D/J-наборы учебки**. D6 essay-referee калибруется на golden-cut D; расхождение агента с эталоном = роль не запускается |
| Q9 голос | tone-гайд + редколлегия скиллов; обязательный litredaktor-пасс отменён | Исходящий фидбек D (оценка домашки, разбор, отчёт студенту): черновик по tone-гайду → редколлегия скиллов (litredaktor — один из инструментов, не гейт) → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Применяется при запуске W3 к ролям с внешним студенческим следованием (D1, D6, D9); «доля закрытых без человека» измеряется как human-send-статистика, не как автономная отправка |
| Q4 канал | TG + веб сразу | Фидбек D — параллельно на web-поверхности (кабинет, проверка ДЗ) и TG-лейне; web-поверхность строится параллельно, не вместо |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в этом репозитории (origin/main на 24-11-2026), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` дефолт OFF | `config/features.php:2154`, `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну, **не заменяет D/J-наборы учебки** | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт D-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

Общие инструменты и проверки департамента берутся у детей из карты Hermes ([HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md)) — в этом пакете собственных новых ролей департамента D нет, повторная реализация запрещена.

## 5. Нулевой прод-след фазы-1 (ценз 24-11-2026)

- Флаги agent-запуска ролей D в `config/features.php`: **0**. Ценз: `grep -nE "hw_screener|sandhi|parser_tutor|devanagari|pronunciation|essay_referee|quiz_gen|plagiarism|progress_report|srs_coach" config/features.php` → нет совпадений (exit 1).
- Известные флаги-омонимы (не агенты D): `kosha_srs` (586) / `subhashita_srs` (598) / `hindi_my_srs_deck` (1745) — deterministic SRS-утилиты estate; `f2_placement_quiz` (1225) — deterministic-тест при приёме; `support_answer_suggester` (168) и `student_agent` (1826) — существующие ограниченные LLM-контуры поддержки/клуба, оба дефолт OFF, к департаменту D не относятся. Все дефолт OFF.
- Флаги `hermes_*` (2140–2196): ровно 5 — `HERMES_SCHEDULE_KEEPER`, `HERMES_HUMAN_SEND`, `HERMES_FAQ_RAG`, `HERMES_ACCESS_DIAGNOSTICS`, `HERMES_PAYMENT_RECONCILER` — роли поддержки W1, все `env(…, false)`, к департаменту D не относятся.
- SRS-контур (`app/Services/Srs/*`, `/koloda`, `public/lila`), quiz-таблицы, домашние сабмиты — существующие таблицы/движки, обслуживаются людьми. **Агент-обёрток над ними в фазе-1 не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова, ни одного нового флага в этом изменении.
- Исходящих сообщений и публикаций агентом: 0 (Q3/Q9 не активированы; human-send-слой OFF дефолтом, 0 прод-писем до гейта волны — H6084).

## 6. Границы

- D-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6105 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение.
- Golden-набор W1 (H6181) обслуживает только первую волну; **не заменяет D/J-наборы учебки** — департаменту D нужен отдельный golden-cut, без которого W3 не стартует.
- Исключённые роли D5 `pronunciation-coach` (39) и D8 `plagiarism-sniffer` (42) остаются `excluded` (политика H6090 §1.9 + kill-candidates V2); этот документ не реабилитирует их и не создаёт обходного контура. Смежно: `exam-session-agent` (34, департамент C) — «без автоматических обвинений в списывании».
- Любое расширение после первых семи расширений требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к D-ролям на их W3.
- Департамент D не разделяет topic-overlap с H6100 (департамент A): это разные юниты ростера, `--allow-dup` против наложения подтверждён условиями H6105.
- Правовая дисциплина: домашки и прописи студентов — ПДн 152-ФЗ; без PII-обёртки (Q5, фаза 0) роль D не запускается вообще.

## Delivery

- **Changed:** этот документ (департаментный контур H6105); ничего в коде, флагах, планировщиках.
- **Unchanged:** все 10 D-ролей — подготовка под W3, две исключены; прода, исходящих сообщений и LLM-вызовов 0; SRS/koloda/lila/quiz/домашки/Hermes-W1 не тронуты.
- **Checks:** ценз §5 — agent-флаги D = 0 (`grep -nE "hw_screener|sandhi|parser_tutor|devanagari|pronunciation|essay_referee|quiz_gen|plagiarism|progress_report|srs_coach" config/features.php` → exit 1), `hermes_*` = 5 флагов всех дефолт OFF, омонимы `kosha_srs`/`f2_placement_quiz`/`student_agent` дефолт OFF; новых Schedule-заданий/LLM-вызовов в этом изменении нет (diff = ровно 1 doc-файл).
- **Risks:** drift между этим документом и ростером при новой визе MG; лечится ссылкой на источник и центзой при каждом гейте W3. Риск подмены: golden-набор W1 (H6181) могут принять за D-набор учебки — в §4/§6 явно зафиксировано, что это не так. Риск скрытого обхода исключений 39/42 — в §6 явный запрет обходного контура.
- **Inspect:** `grep -nE "hw_screener|sandhi|parser_tutor|devanagari|pronunciation|essay_referee|quiz_gen|plagiarism|progress_report|srs_coach" config/features.php` (нет совпадений); `grep -n "hermes_" config/features.php` (5, все OFF); `git show --stat` этого PR (ровно один doc-файл); этот документ §5.

_к.ф.н. М.Ю. Гасунс_ · H6105 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
