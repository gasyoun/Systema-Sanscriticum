# Архитектура: школа-агенты, департамент H «Аналитика и дирекция» — фаза-1 (H6110)

_Created: 18-11-2026 · Last updated: 27-11-2026_

Исполнение handoff [H6110-OxAlpha_…school-agents-dept-h-analytics](https://github.com/gasyoun/Veche/blob/main/handoffs/H6110-OxAlpha_Systema-Sanscriticum_school-agents-dept-h-analytics_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента H): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «H. Аналитика и дирекция (8)», ID 73–80.
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md) — строки 73–80, все `не запущено`.
- Карта детей Hermes 24: HERMES_GLM_24_HANDOFFS_2026-10-06 (upstream-документ вынесен в DataLake; живые доставки детей перечислены в §4).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.
- Соседний департамент A (topic-overlap допущен явно, `--allow-dup`: департаменты — разные юниты): [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md).

> **Верификация 27-11-2026.** Документ впервые доставлен 18-11-2026 (PR [#4319](https://github.com/gasyoun/Systema-Sanscriticum/pull/4319)). Настоящий проход — сверка с текущим `origin/main` (`5e246c7f`) и закрытие H6110: выводы §1–§4 подтверждены, ценза §5 переснята на 27-11, добавлены границы H6176–H6180/H6191 и dual-run-проверка. Вердикт и scope не меняются.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; контент/комьюнити/**аналитика**/E–J идут W4+ «за квотой фазы-1, отдельно по визе». Департамент H в состав фазы-1 квоты **не входит**.

⇒ Фаза-1 департамента H — это **подготовка, а не запуск**: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. Ни одна из 8 ролей H (funnel-analyst … board-prep) не получает флаг запуска, планировщика или исходящих сообщений в этой фазе. Любой будущий запуск H-роли — W4+ по отдельной визе MG со всеми гейтами Q3–Q9 и, где уместно, за общую квоту 25 (Q2) только по явному решению MG.

Изменений в ростер это не вносит — фиксирует его следствие на уровне репозитория: фаза-1 департамента H = подготовка, прод-запуск W4+ по визе.

## 2. Роли департамента H и estate-поверхности

| # | Роль | Тип | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|
| 73 | funnel-analyst | 🧠 | `app/Support/AcquisitionAttribution.php`, `InferLeadSources`, `ReportChannelRoi`, виджет `SalesFunnelChart`; anons-attribution H5934 |
| 74 | cohort-ltv | ⚙️ | виджеты `RetentionChart`, `StudentStatsOverview` поверх payments/групп; LTV-разреза нет — строить read-only поверх этих данных, не новую базу |
| 75 | forecast-registrar | 🧠 | CRM Wave 3 (H2485): `app/Services/Crm/SalesForecastService.php`, `app/Filament/Pages/SalesForecast.php`, `crm:forecast-report` (read-only, никогда не пишет payments) |
| 76 | ab-judge | 🧠 | `app/Services/Support/ReplyVariantSampler.php`; паттерны ab-experiment / eval-harness; урок SLI-1 hard-share (ранжирование только по hard-метрикам, анти-Гудхарт) |
| 77 | dash-generator | ⚙️ | Filament-поверхности: `AttendanceDashboard` (features.php:677), `ActivationCompletionMetrics`, `StudentStatsOverview`; паттерн viz-page |
| 78 | competitor-price-watch | 🔄 | [docs/COMPARISON_MOYKLASS_LANGUAGE_SCHOOL_CRM_21-08-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/COMPARISON_MOYKLASS_LANGUAGE_SCHOOL_CRM_21-08-2026.md); spider MCP / venue-scout паттерн |
| 79 | exec-question-rag | 🧠 | паттерн ask-the-wiki; `ReportChannelRoi`, метрические страницы Filament как источник цифр |
| 80 | board-prep | 🧠 | паттерн review-sheet; `app/Services/Support/AutoReplyWeeklyReport.php`, методолог-weekly E005 как форма периодического борда |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после виза W4+ и гейтов ниже.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту H

| Гейт | Правило (решения 05-10) | Для H в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Ни одно исходящее H-сообщение (включая борд-материалы за пределы школы) не существует до визы; потом — через human-send-слой H6183 (`config/features.php:2183`, дефолт OFF). **0 прод-писем до гейта волны** (H6084) |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для 73 (лиды — ПДн), 74 (платежи студентов), 79/80 (ответы по всем данным школы); готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php` (H6144/H6182, fail-closed) |
| Q6 скидки | полный запрет автономии, решает MG | Без оговорок для всех H-ролей: цифры, варианты и риски — да; решение о скидке/рассрочке — только MG |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для 🧠/🔄 ролей H (73, 75, 76, 78, 79, 80) — слой H5867 + per-role лимиты из квотной машины H6174; ⚙️-роли 74/77 — deterministic, без LLM; H-allowances в квотной машине не введены, «без новых трат», платного fallback нет |
| Q8 учебка | золотые наборы + выборка MG (gold-adjudicate-паттерн) | W1 golden-набор H6181 обслуживает **только** первую волну и **не заменяет D/J-наборы учебки**; для H нужен отдельный golden-cut по своим числам (own-data-canary) на цифровые утверждения 73–80 — без него W4+ не стартует |
| Q9 голос | tone-гайд + редколлегия скиллов; обязательный litredaktor-пасс отменён | Исходящие H (борд-пакет, ответы на вопросы): черновик по tone-гайду → редколлегия скиллов → human-send. Ни одной публикации агентом |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | У H внешних ответов нет; ведущая метрика при визе W4+ — снижение человеческой нагрузки аналитики (минуты, по правилу 05-10), учёт черновиков/минут через `app/Services/Support/DraftReviewMinutesLedger.php`; «доля закрытых без человека» = human-send-статистика, не автономная отправка |
| Q4 канал | TG + веб сразу | Для H-исходящих: черновики в TG-лейне + web-черновики через очередь H6183 (web); в фазе-1 ни того, ни другого агент не пишет |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в этом репозитории (origin/main), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` (`config/features.php:2183`) дефолт OFF | `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну, не D/J-наборы учебки | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт H-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Учёт черновиков и человеческих минут | `DraftReviewMinutesLedger.php`, счётчик пилотного потока `PilotStreamInquiryCounter.php` — детерминированные утилиты, H-роли их потребляют, не переписывают | `app/Services/Support/` |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

Общие инструменты и проверки департамента берутся у детей из карты Hermes (24 юнита, upstream-документ вынесен в DataLake; живые доставки — строки §4); в пакете 06-10 **собственных новых ролей департамента H нет** — повторная реализация запрещена, исходный scope 8 ролей (ID 73–80) сохранён.

## 5. Нулевой прод-след фазы-1 (ценз 27-11-2026)

Все команды выполнены в корне этого worktree против текущего `origin/main` (`5e246c7f`):

- `grep -nE "crm_sales_forecast|attendance_dashboard|marketing_segments|membership_funnel_analytics|funnel_analyst|cohort_ltv|forecast_registrar|ab_judge|dash_generator|competitor_price|exec_question|board_prep|'hermes_" config/features.php` → **10 строк** (9 определений флагов + 1 комментарий-рубильник), все — омонимы или флаги W1: `crm_sales_forecast` (131, CRM Wave 3 H2485, read-only прогноз), `attendance_dashboard` (677), `marketing_segments` (1193, GC-A1 H1637, не агент), `membership_funnel_analytics` (1675, H2745) и ровно 5 `hermes_*` (2169 `schedule_keeper`, 2183 `human_send`, 2196 `faq_rag`, 2212 `access_diagnostics`, 2225 `payment_reconciler`) — поддержка W1. **Ни одного флага запуска ролей H (73–80).**
- `grep -n "hermes_" config/features.php | grep -v "env(.*false)"` → **нет вывода** (все 5 флагов `env(…, false)`); `hermes_human_send` OFF дефолтом.
- `grep -rn "funnel-analyst\|cohort-ltv\|forecast-registrar\|ab-judge\|dash-generator\|competitor-price-watch\|exec-question-rag\|board-prep" config/features.php app/Console/` → **пусто**: роли 73–80 не зарегистрированы ни как флаги, ни как команды.
- `config/school_role_quota.php`: `enabled=false` (29); `allowances` (86–93) — 5 строк фазы-1 из H6103 (`first-contact-247` 13, `onboarding-greeter` 19, `access-provisioner` 85, `faq-rag` 20, `payment-reconciler` 65; «Total 12 units within Q2 quota of 25»). **Ни одной H-роли в allowances нет**; `excluded_role_ids` содержит `lead-scorer` (ID 8). В этом проходе файл не изменяется.
- Детерминированные контуры (`crm:forecast-report`, `InferLeadSources`, `ReportChannelRoi`, Filament-виджеты статистики, `AutoReplyWeeklyReport`, `DraftReviewMinutesLedger`, `PilotStreamInquiryCounter`) — утилиты estate, их сегодня обслуживают люди; это поверхности для будущих 73–80. **Агент-обёрток над ними не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова.
- Исходящих сообщений и публикаций агентом: **0** (Q3/Q9 не активированы, human-send OFF, 0 прод-писем до гейта волны — H6084).

## 6. Границы

- H-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6110 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение; статусы строк 73–80 в диспозиции заполняет владелец трекера.
- Golden-набор W1 H6181 обслуживает **только** первую волну; **не заменяет D/J-наборы учебки** и не является H-cut — департаменту H нужен отдельный golden-cut перед визой W4+.
- **H6176–H6180 и H6191 закрыты на согласование границ с claimed H6120; их 90-дневное владение не переносится.** Настоящий документ эти границы не переопределяет и не наследует.
- Stenogrammy: локальное чтение разрешено (H8050), публикация/экспорт тел и списков путей в публичную поверхность закрыты; H-роли 73/74/79/80 не должны выносить ПДн в публичные черновики без маскера фазы 0.
- Topic-overlap с департаментом A (H6100) допущен явно (`--allow-dup`): департаменты — разные юниты; общая техника берётся только из карты §4.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к H-ролям на их W4+.
- **Dual-run проверка 27-11:** веток с префиксом `h6110`, правящих этот документ, нет — `origin/h6110-drain` не содержит коммитов сверх `origin/main`, `origin/salvage/Systema-Sanscriticum-h6110-drain` хранит только лэпс-дубль доставки (0c732f60). Dual-run residual нет; чужой live-claim не снимался. Предыдущая доставка — PR [#4319](https://github.com/gasyoun/Systema-Sanscriticum/pull/4319) (18-11).

## Delivery

- **Changed:** этот документ (департаментный контур H6110): обновлён ценз §5 на 27-11, исправлены номера строк `config/features.php`, уточнён human-send-флаг (2183), добавлены границы H6176–H6180/H6191 и dual-run-проверка; восстановлены мёртвые ссылки (handoff → Veche, дети Hermes → `handoffs/archive/`, карта Hermes → DataLake). Ничего в коде, флагах, планировщиках, квотных числах.
- **Unchanged:** все 8 H-ролей остаются в подготовке; прод, исходящих сообщений и LLM-вызовов 0; `config/features.php`, `config/school_role_quota.php`, Hermes/W1 не тронуты; диспозиция 73–80 остаётся `не запущено`; чужой claim не снят.
- **Checks:** цензы §5 — `grep -nE …` → 10 строк, все омонимы/W1; H-флагов и H-allowances 0; `hermes_*` → все `env(…, false)`; role-name grep → пусто; `git diff --check` PASS (только этот файл).
- **Risks:** drift между документом и ростером при новой визе MG — лечится ссылкой на источник и повторным цензом при каждом W4+; риск принять W1 golden за H-cut — явно запрещено в §3/§6; риск утечки ПДн в аналитические черновики — задан pii-guard фазы 0.
- **Inspect:** `git show --stat` этого PR; этот документ §5 (ценз) и §6 (границы/dual-run); `grep -n "crm_sales_forecast\|attendance_dashboard\|marketing_segments\|membership_funnel_analytics\|'hermes_" config/features.php`; `grep -rn "funnel-analyst\|cohort-ltv\|board-prep" config/features.php app/Console/` (ожидается пусто).

_к.ф.н. М.Ю. Гасунс_ · H6110 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
