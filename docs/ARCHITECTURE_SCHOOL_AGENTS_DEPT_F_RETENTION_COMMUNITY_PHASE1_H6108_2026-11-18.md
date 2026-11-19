# Архитектура: школа-агенты, департамент F «Удержание и комьюнити» — фаза-1 (H6108)

_Created: 18-11-2026 · Last updated: 18-11-2026_

Исполнение handoff [H6108-OxAlpha_…school-agents-dept-f-retention-community](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6108-OxAlpha_Systema-Sanscriticum_school-agents-dept-f-retention-community_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента F): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «F. Удержание и комьюнити (10)».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID (канонические ID 55–64): [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.
- Прецедент формы: [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md).

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; строка W4+ — «контент/комьюнити/аналитика/E–J — за квотой фазы-1, отдельно по визе» (решение MG по итогам W1–W3). Департамент F «Удержание и комьюнити» в состав фазы-1 квоты **не входит**. Стратегические волны 2/3 из диспозиции (колонка «Волна (страт.)») — план 90-дневной программы, не маппинг на квоту 25.

⇒ Фаза-1 департамента F — это **подготовка, а не запуск**: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. Ни одна из 10 ролей F не получает флаг запуска, планировщика или исходящих сообщений в этой фазе. Любой будущий запуск F-роли — W4+ по отдельной визе MG со всеми гейтами Q3–Q9 и, где уместно, за общую квоту 25 (Q2) только по явному решению MG.

Изменений в ростер это не вносит — фиксирует его следствие на уровне репозитория: фаза-1 департамента F = подготовка, прод-запуск W4+ по визе.

## 2. Роли департамента F и estate-поверхности

Канонические ID 55–64 (диспозиция); «Волна» — стратегическая волна диспозиции, не квота v2.

| ID | Роль | Тип | Волна | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|---|
| 55 | churn-predictor | 🔄 | 2 | анкета «Почему остановились» `/anketa/churn-block` ([CURATOR_ADMIN_GUIDE_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CURATOR_ADMIN_GUIDE_RU.md):718); renewal-напоминания H5823 (`membership-renewal-*`, `app/Console/Concerns/SchedulesOpsAndMembership.php`:232, [REPORT_H5823](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/REPORT_H5823_RENEWAL_REMINDERS_04-10-2026.md)); сигналы посещаемость/домашки/`Payment` |
| 56 | win-back-agent | 🧠 | 3 | `crm:reactivation-wave` + `app/Services/Crm/Reactivation/*` (H5294, Filament-страница `reactivation`); дожим-лестница [money-dunning-escalation-ladder](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-dunning-escalation-ladder.md) («Win-back после отчисления»); `dozhim_queue`/`dozhim_drip` ([ARCHITECTURE_Systema_NOBORING_DOZHIM](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_Systema_NOBORING_DOZHIM.md)) |
| 57 | nps-surveyor | ⚙️ | 2 | анкетный конвейер `/anketa/post3m`; NPS в TG отсутствует — задокументированный gap ([BOTS_MAP_RESEARCH_H5135](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BOTS_MAP_RESEARCH_H5135_18.09.26.md) V10, PARKED) |
| 58 | review-harvester | ⚙️ | 3 | библиотека отзывов `testimonials`/`course_testimonial` ([course-landing-spec](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/course-landing-spec.md):64–88); publish-safety для публичных + согласие на публикацию |
| 59 | community-host | 🧠 | 2 | TG-поддержка: `SupportAnswerSuggester`, `faq_rag_suggester` (features.php:202, OFF на проде), [ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_TELEGRAM_RAG_SUPPORT.md); чаты потоков — [COURSE_DEBT_CHAT_REINSTATEMENT_LEDGER](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_COURSE_DEBT_CHAT_REINSTATEMENT_LEDGER_2026.md) |
| 60 | speaking-club-host | 🧠 | 3 | **gap**: поверхности нет; только recurring Zoom-ссылки new-group-контура; темы/пары/тайминг/фидбек не построены |
| 61 | gamification-steward | ⚙️ | 3 | `config/badges.php` + `App\Support\Badges` (метрики `lifetime_prana`, `gifts_given`, бейдж `prana_keeper`), `resources/views/student/partials/prana-streak.blade.php`; «без токсичной конкуренции» — дизайн-ограничение роли |
| 62 | scholarship-agent | ⚙️ | 3 | `App\Models\PromoCode` + [`app/Support/CourseFinalRewards.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/CourseFinalRewards.php); депозит/лояльность/referral credit — денежный контур (Q6) |
| 63 | parent-liaison | 🧠 | 3 | **deferred** (диспозиция: работа с несовершеннолетними — вне взрослого пилота, нужен отдельный допуск) |
| 64 | daily-digest-student | ⚙️ | 2 | `support:daily-digest` 08:10 (`SchedulesSupportAndPayments.php`:68, features.php:476 — дайджест куратору, не студенту); reminders «Скоро занятие» (new-group-контур); таблица `schedules` |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после визы W4+ и гейтов ниже. ID 63 не получает даже подготовки к запуску без отдельного допуска (несовершеннолетние).

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту F

| Гейт | Правило (решения 05-10) | Для F в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | F — самое «исходящее» депо (реанимационные цепочки, NPS-опросы, приветствия чата, дайджест, линия родителей): **ни одного F-исходящего не существует до визы**; потом — только через human-send-слой H6183 (`HERMES_HUMAN_SEND` дефолт OFF) |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для всех F-ролей с данными ученика: 55 (посещаемость/оплаты/причины ухода), 56 (причины ухода), 57 (цитаты NPS), 58 (отзывы с именами), 59 (TG-ники), 63 (контакты родителей — двойной фенс), 64 (расписание ученика); готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php` |
| Q6 скидки | полный запрет автономии, решает MG | **Ядро Q6 для F**: 62 (scholarship-agent) — только сбор кейсов, решение = MG; 56 (win-back) — скидочные предложения в цепочках вне автономии; 61 — бейджи/строки не выдают скидок |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для всех 🧠/🔄 ролей F — слой H5867 + per-role лимиты из квотной машины H6174; «без новых трат» — подписочная квота z.ai |
| Q8 учебка | золотые наборы + выборка MG | W1 golden-набор H6181 обслуживает только первую волну поддержки и **не заменяет D/J-наборы учебки**; для F при визе — отдельный golden-cut (NPS-цитаты, отзывы, FAQ-ответы community-host); публикация отзывов — только с согласием + publish-safety |
| Q9 голос | tone-гайд + редколлегия скиллов; litredaktor-пасс отменён | Исходящие F: черновик по tone-гайду → редколлегия → human-send; 59 (community-host) говорит голосом школы в чате — редколлегия обязательна; 60 (фидбек клуба) — тоже |
| Q13 KPI | скорость первого ответа + доля закрытых без человека (ведущая), NPS (стража) | 57 (nps-surveyor) — источник NPS-стражи; KPI применяется к ролям с внешними ответами (55/59/64 — черновики) при визе W4+ |
| Q4 канал | TG + веб сразу | F-исходящие: черновики в TG-лейне + web-черновики через очередь H6183 (web); 64 (дайджест) — web-кабинет + TG |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Новых собственных ролей/инструментов пакет Hermes для F не создал; общая техника берётся у детей из карты, не реализуется повторно. Лежит в этом репозитории (origin/main на 18-11-2026):

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` дефолт OFF | `config/features.php`:2101, `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну, не D/J-наборы учебки и не F | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт F-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится; H6176–H6180 и H6191 закрыты на согласование границ с ним | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

## 5. Нулевой прод-след фазы-1 (центза 18-11-2026)

- Флаги agent-запуска ролей F в `config/features.php`: **0**. Известные флаги-омонимы (не агенты F): `support_daily_digest` (476) и `support_hint_daily_digest` (496) — детерминированные служебные дайджесты поддержки/куратору; `faq_rag_suggester` (202) — suggester W1 поддержки, OFF на проде; `hermes_schedule_keeper` (2087) и `hermes_human_send` (2101) — флаги W1 поддержки, оба дефолт OFF; `config/badges.php` — детерминированные метрики бейджей (`App\Support\Badges`), не агент; `dozhim_queue`/`dozhim_drip` (ARCHITECTURE_Systema_NOBORING_DOZHIM) — операторский дожим, дефолт false.
- Существующие deterministic-конвейеры F-контура (`crm:reactivation-wave` dry-run, `membership:renewal-reminders` в `SchedulesOpsAndMembership.php`:232, `support:daily-digest` в `SchedulesSupportAndPayments.php`:68, анкеты `/anketa/*`) — утилиты estate, их сегодня обслуживают люди; это ровно те поверхности, на которых завтра стартуют 55/56/57/64. **Агент-обёрток над ними в фазе-1 не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова в этом PR.
- Исходящих сообщений агентом: 0 (Q3/Q9 не активированы; human-send-слой OFF дефолтом). Никаких писем ученикам, родителям, чатам потоков — 0 прод-писем до гейта волны (H6084).

## 6. Границы

- F-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6108 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение (H6176–H6180, H6191 — scope hold).
- ID 63 (parent-liaison) — deferred до отдельного допуска на работу с несовершеннолетними; в волны до допуска не включается.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к F-ролям на их W4+.
- Topic-overlap с H6100 (департамент A) просмотрен при `--allow-dup`: департаменты — разные юниты (миссия H6108); win-back/дожим F против маркетинговых цепочек A — разные поверхности (реактивация ушедших vs лид-ворки).

## Delivery

- **Changed:** этот документ (департаментный контур H6108); ничего в коде, флагах, планировщиках.
- **Unchanged:** все 10 F-ролей — подготовка (63 — вне допуска); прода и исходящих сообщений 0; Hermes/поддержка W1 не тронута.
- **Checks:** центзы §5 — agent-флаги F = 0, новые планировщики/LLM-вызовы = 0, hermes/support-флаги дефолт OFF; `git diff --stat origin/main` — только этот документ. PASS.
- **Risks:** drift между этим документом и ростером при новой визе MG; лечится ссылкой на источник и центзой при каждом W4+ визе. NPS-поверхность F3 — задокументированный gap (BOTS_MAP_RESEARCH V10), не строится здесь.
- **Inspect:** `grep -n "hermes\|churn\|winback\|nps\|scholarship\|community\|digest\|parent\|speaking\|gamif\|review\|reactivat\|dunning\|prana\|streak" config/features.php`; `grep -rn "reactivation\|renewal\|daily-digest\|anketa" app/Console/Concerns/`; этот документ.

_Гасунс_ · H6108 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
