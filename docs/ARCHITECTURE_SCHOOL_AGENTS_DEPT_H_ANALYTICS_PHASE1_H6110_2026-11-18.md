# Архитектура: школа-агенты, департамент H «Аналитика и дирекция» — фаза-1 (H6110)

_Created: 18-11-2026 · Last updated: 18-11-2026_

Исполнение handoff [H6110-OxAlpha_…school-agents-dept-h-analytics](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6110-OxAlpha_Systema-Sanscriticum_school-agents-dept-h-analytics_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента H): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «H. Аналитика и дирекция (8)», ID 73–80.
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md) — строки 73–80, все `не запущено`.
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.
- Соседний департамент A (topic-overlap допущен явно, `--allow-dup`: департаменты — разные юниты): [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md).

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
| 77 | dash-generator | ⚙️ | Filament-поверхности: `AttendanceDashboard` (features.php:629), `ActivationCompletionMetrics`, `StudentStatsOverview`; паттерн viz-page |
| 78 | competitor-price-watch | 🔄 | [docs/COMPARISON_MOYKLASS_LANGUAGE_SCHOOL_CRM_21-08-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/COMPARISON_MOYKLASS_LANGUAGE_SCHOOL_CRM_21-08-2026.md); spider MCP / venue-scout паттерн |
| 79 | exec-question-rag | 🧠 | паттерн ask-the-wiki; `ReportChannelRoi`, метрические страницы Filament как источник цифр |
| 80 | board-prep | 🧠 | паттерн review-sheet; `app/Services/Support/AutoReplyWeeklyReport.php`, методолог-weekly E005 как форма периодического борда |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после виза W4+ и гейтов ниже.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту H

| Гейт | Правило (решения 05-10) | Для H в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Ни одно исходящее H-сообщение (включая борд-материалы за пределы школы) не существует до визы; потом — через human-send-слой H6183 |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для 73 (лиды — ПДн), 74 (платежи студентов), 79/80 (ответы по всем данным школы); готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php` |
| Q6 скидки | полный запрет автономии, решает MG | Без оговорок для всех H-ролей: цифры, варианты и риски — да; решение о скидке/рассрочке — только MG |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для 🧠/🔄 ролей H (73, 75, 76, 78, 79, 80) — слой H5867 + per-role лимиты из квотной машины H6174; ⚙️-роли 74/77 — deterministic, без LLM |
| Q8 учебка | золотые наборы + выборка MG | W1 golden-набор H6181 обслуживает только первую волну поддержки; для H — отдельный golden-cut по своим числам (own-data-canary / gold-adjudicate-паттерн) на цифровые утверждения 73–80; D/J-наборы учебки не наследуются |
| Q9 голос | tone-гайд + редколлегия скиллов; litredaktor-пасс отменён | Исходящие H (борд-пакет, ответы на вопросы): черновик по tone-гайду → редколлегия → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | У H внешних ответов нет; ведущая метрика при визе W4+ — снижение человеческой нагрузки аналитики (минуты, по правилу 05-10), учёт черновиков/минут через `app/Services/Support/DraftReviewMinutesLedger.php` |
| Q4 канал | TG + веб сразу | Для H-исходящих: черновики в TG-лейне + web-черновики через очередь H6183 (web) |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в этом репозитории (origin/main на 18-11-2026), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `hermes_human_send` дефолт OFF | `config/features.php:2069`, `app/Services/Support/HumanSend/` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну, не D/J-наборы учебки | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт H-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Учёт черновиков и человеческих минут | `DraftReviewMinutesLedger.php`, счётчик пилотного потока `PilotStreamInquiryCounter.php` — детерминированные утилиты, H-роли их потребляют, не переписывают | `app/Services/Support/` |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

## 5. Нулевой прод-след фазы-1 (центза 18-11-2026)

- Флаги agent-запуска ролей H (73–80) в `config/features.php`: **0** — флагов с именами ролей департамента нет. Известные флаги-омонимы (все дефолт OFF, не агенты H): `crm_sales_forecast` (features.php:131, CRM Wave 3 H2485, read-only прогноз + менеджер-дашборд); `attendance_dashboard` (629, Filament-страница являемости); `marketing_segments` (1118, GC-A1 сегментный движок H1637); `membership_funnel_analytics` (1565, H2745 append-only размерности воронки); `hermes_*` (2055–2111: `hermes_schedule_keeper`, `hermes_human_send`, `hermes_faq_rag`, `hermes_access_diagnostics`, `hermes_payment_reconciler`) — флаги поддержки W1, к департаменту H не относятся.
- Существующие deterministic-контуры (`crm:forecast-report`, `InferLeadSources`, `ReportChannelRoi`, Filament-виджеты/страницы статистики, `AutoReplyWeeklyReport`) — утилиты estate, их сегодня обслуживают люди; это ровно те поверхности, на которых завтра стартуют роли 73–80. **Агент-обёрток над ними в фазе-1 не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова в этом PR.
- Исходящих сообщений агентом: 0 (Q3/Q9 не активированы; human-send-слой OFF дефолтом).

## 6. Границы

- H-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6110 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение; статусы строк 73–80 в диспозиции заполняет владелец трекера.
- Golden-набор W1 H6181 обслуживает только первую волну поддержки; D/J-наборы учебки им не заменяются (scope-заметка H6110 от 06-10).
- Topic-overlap с департаментом A (H6100) допущен явно (`--allow-dup`): департаменты — разные юниты; общая техника берётся только из карты §4.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к H-ролям на их W4+.

## Delivery

- **Changed:** этот документ (департаментный контур H6110); ничего в коде, флагах, планировщиках.
- **Unchanged:** все 8 H-ролей — подготовка; прода и исходящих сообщений 0; Hermes/поддержка W1 не тронута; диспозиция 73–80 остаётся `не запущено`.
- **Checks:** центза §5 — agent-флаги H = 0, омонимы дефолт OFF, новые планировщики/LLM-вызовы = 0; `git diff --check` PASS (только этот файл).
- **Risks:** drift между этим документом и ростером при новой визе MG; лечится ссылкой на источник и центзой при каждом W4+ визе.
- **Inspect:** `grep -n "crm_sales_forecast\|attendance_dashboard\|marketing_segments\|membership_funnel_analytics\|'hermes_" config/features.php`; `grep -rn "funnel-analyst\|cohort-ltv\|forecast-registrar\|ab-judge\|dash-generator\|competitor-price-watch\|exec-question-rag\|board-prep" config/features.php app/Console/` (ожидается пусто); этот документ.

_Гасунс_ · H6110 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
