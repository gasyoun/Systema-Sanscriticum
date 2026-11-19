# Архитектура: школа-агенты, департамент G «Финансы и админ» — фаза-1 (H6109)

_Created: 18-11-2026 · Last updated: 18-11-2026_

Исполнение handoff [H6109-OxAlpha_…school-agents-dept-g-finance-admin](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6109-OxAlpha_Systema-Sanscriticum_school-agents-dept-g-finance-admin_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md). Сестринский контур департамента A: [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md).

Ростеры и решения:

- Ростер v1 (роли G65–G72): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «G. Финансы и админ (8)».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID (строки 65–72): [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на общую квоту 25 (Q2). В составе W1 ростера v2 явно названа одна роль департамента G — «оплаты-сверка» (эrrata 05-10: G60→65, т.е. G65 `payment-reconciler`). Диспозиция 92 ID подтверждает: строка 65 — «кандидат первой пятёрки-семёрки W1 (ростер V2); запуск — дети E026», стратегическая волна 1.

⇒ Фаза-1 департамента G — это **одна роль в квоте 25 (G65, W1) плюс гейто-упаковка семи остальных**:

- **G65 `payment-reconciler`** — в квоте 25 (Q2), волна W1. Реализация принадлежит ребёнку [H6188 — GLM — W1 сверка оплат — read-only предложение, ID 65](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6188-GLM_Systema-Sanscriticum_hermes-w1-payment-reconcile-role65_06.10.26.md). Этот зонтик **принимает evidence H6188 и не пишет второй вариант** (scope-разделение пакета Hermes 06-10): `app/Services/Support/Hermes/PaymentReconcile/*`, `tests/Feature/Support/H6188HermesAcceptanceTest.php`, флаг `hermes_payment_reconciler` — уже в этом репозитории.
- **G66–G72** — подготовка, а не запуск: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. Ни одна из семи ролей не получает флага запуска, планировщика или исходящих сообщений в этой фазе.

Стратегические волны диспозиции (72→1, 69/71→2, 66/67/68/70→3) — планка трекера H6120, а не состав волн запуска: живой канон волн — ростер v2, и в его W1–W3 роли G66–G72 не названы ⇒ их запуск — после W1–W3 по отдельной визе MG со всеми гейтами Q3–Q9. Расхождение «G72 в стратегической волне 1 ↔ отсутствие в составе W1 ростера v2» фиксируется здесь и **не разрешается агентом**: канон волн остаётся за ростером v2, диспозиция — трекер H6120.

Изменений в ростер и диспозицию это не вносит — фиксирует их следствие на уровне репозитория: фаза-1 G = G65 в квоте W1 (evidence H6188) + подготовка G66–G72, прод-запуск по визе.

## 2. Роли департамента G и estate-поверхности

| # | Роль | Тип | Профиль | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|---|
| G65 | payment-reconciler | ⚙️ | ДЕН | ребёнок H6188: `app/Services/Support/Hermes/PaymentReconcile/W1PaymentReconcileService.php` (+ Scenario/Result, `PaymentReconcileGoldenScenarioRunner`), `tests/Feature/Support/H6188HermesAcceptanceTest.php`, `H6188MoneySafetyScenarios`; money-truth-pass-паттерн; `app/Models/Payment.php`, `app/Models/MutualSettlement.php`, Tochka-вебхук |
| G66 | invoice-clerk | ⚙️ | ДЕН | `app/Http/Controllers/CompanyInvoiceController.php`, `app/Http/Requests/StoreCompanyInvoiceRequest.php`, `CompanyInvoiceReceivedMail` / `CompanyInvoiceStudentAckMail`; [docs/ACCOUNTANT_CABINET_GUIDE_RU](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md) |
| G67 | payout-comptroller | ⚙️ | ДЕН | `app/Models/TeacherPayout.php`, `TeacherPayoutPackage(+Line)`, `TeacherPayoutEvidenceLink`, `TeacherPayoutAttributionSuggestion`, `TeacherCompensationTerm`, `SalaryClosedPeriod`; `app/Services/Payout/PayoutPackageService.php`, `app/Services/PayrollContourService.php`, `TeacherPayoutReportMail`; экран «Подтверждение выплат преподавателям»; skill teacher-payout-record |
| G68 | refund-handler | ⚙️ | ДЕН | `app/Models/Payment.php` (возвратные статусы), `PaypalClaimController` / bank-claim контур; [docs/ARCHITECTURE_SYSTEMA_ACCOUNTANT_MONEY_MAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ACCOUNTANT_MONEY_MAP.md) |
| G69 | ad-budget-watch | ⚙️ | ДЕН | `app/Models/Expense.php` + `app/Enums/ExpenseCategory.php` (opex), Filament LeadCost, `app/Support/AcquisitionAttribution.php` (H5934), tracked_links (anons-attribution) |
| G70 | tax-reminder | ⚙️ | ДЕН | `app/Enums/IpExpenseCategory.php` (ИП-контур), accountant-money-map, ИП-книга (tails teacher-payout-record); самого календаря напоминаний сегодня нет — детерминированная поверхность будет новой к визе роли |
| G71 | contract-keeper | ⚙️ | ДЕН | `app/Models/Consent.php` (append-only журнал согласий 152-ФЗ), `BotSubscriber.consent_at`, `AdminDocumentResource`, Filament `DocumentationCatalog`; фенс H2641 |
| G72 | kpi-tabler | ⚙️ | АН | `app/Support/FinanceCockpitReport.php` («Финансовый штурвал»: ОПиУ/ДДС/Баланс), Filament `FinanceCockpit` / `FinancePlanning`, `app/Services/UnitEconomicsService.php`, `RevenueScheduleService`, `DelegationKpi`; недельный расчёт пользы [H6178](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6178-GLM_Uprava_hermes-role-weekly-benefit-compute_06.10.26.md); CoS brief-формат |

Все восемь ролей — ⚙️ (детерминированный контур, LLM только как подстраховка внутри гейтов). Профиль ДЕН (G65–G71) = денежный контур: жёсткие гарды раздела 6. Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после визы волны и гейтов ниже.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту G

| Гейт | Правило (решения 05-10) | Для G в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Исходящие G (счета G66, ответы по возвратам G68, налоговые напоминания G70, рассылки обновлений оферт/согласий G71) не существуют до визы; **0 прод-писем до гейта волны**; потом — через human-send-слой [H6183](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6183-GLM_Systema-Sanscriticum_hermes-tg-web-human-send-evidence_06.10.26.md) |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для всех G-ролей с данными людей: G65 (платежи), G66 (контакты компаний), G67 (ПДн преподавателей и выплаты), G68 (плательщики), G71 (согласия ПДн — роль держит сам 152-ФЗ-контур, `Consent.php` append-only). Готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php`; сквозная проверка границ TG+web — evidence [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Q6 скидки | полный запрет автономии, решает MG | Без оговорок для всех G-ролей: refund-handler (G68) и любой пересчёт/условие — **только предложение, решение MG**. Никаких финансовых мутаций агентом (формат H6188 money-safety) |
| Q7 токены | killgate-слой H5867 + лимиты по ролям; «без новых трат» — подписочная квота z.ai | Для всех G-ролей: killgate H5867 + per-role лимиты квотной машины [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) на любой LLM-шаг |
| Q8 учебка | золотые наборы + выборка MG (gold-adjudicate-паттерн) | G-роли учебный контент не верифицируют; их «золотой» контур — golden-кейсы роли: для G65 их нёс ребёнок H6181/H6188 (`PaymentReconcileGoldenScenarioRunner`, `H6188MoneySafetyScenarios`). Для G66–G72 golden-наборы собираются к своей волне и **не наследуются** от W1-набора [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Q9 голос | tone-гайд + редколлегия скиллов; обязательный litredaktor-пасс отменён | Исходящие G (документы, счета, напоминания): черновик по tone-гайду → редколлегия → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека (ведущая), NPS (стража) | К ролям с внешними ответами (G66/G68/G70/G71) при визе их волны |
| Q4 канал | TG + веб сразу | Исходящие G: черновики в TG-лейну + web-черновики через очередь H6183 (web) |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

| Общая техника / результат | Готовность | Ссылка |
|---|---|---|
| Роль 65 (сверка оплат) | реализация H6188 в этом репозитории; evidence принимается, второй вариант не строится | [H6188](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6188-GLM_Systema-Sanscriticum_hermes-w1-payment-reconcile-role65_06.10.26.md) |
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` дефолт OFF | `config/features.php:2101` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну (G65 в их числе); не заменяет golden-наборы G66–G72 и D/J-наборы учебки | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

## 5. Нулевой прод-след фазы-1 (центза 18-11-2026)

- Флаги agent-запуска ролей G66–G72 в `config/features.php`: **0** (этот PR не добавляет ни одного флага). Существующие связанные флаги: `hermes_payment_reconciler` (`config/features.php:2143`, роль G65 — реализация ребёнка H6188, дефолт OFF); W1-флаги поддержки `hermes_schedule_keeper` (2087), `hermes_human_send` (2101), `hermes_faq_rag` (2114), `hermes_access_diagnostics` (2130) — все дефолт OFF, к департаменту G66–G72 не относятся. Флаг-омоним: `student_agent` (1777, H3231 bounded student agent — не роль ростера, не G).
- Новых Schedule-заданий и LLM-вызовов в этом PR: **0**. Существующие детерминированные финансы-утилиты (`PayoutPackageService`, `PayrollContourService`, `BlockMonthRecognition`, `RevenueScheduleService`, `UnitEconomicsService`, CompanyInvoice-контур) — утилиты estate, ими пользуются люди; агент-обёрток над ними в фазе-1 не добавлено.
- Исходящих сообщений агентом: **0** (Q3/Q9 не активированы; `HERMES_HUMAN_SEND` дефолт OFF; инвариант «0 прод-писем до гейта волны»).
- Финансовых мутаций агентом: **0** — роли G работают read-only-предложениями (формат H6188 money-safety).

## 6. Границы

- G-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6100 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневного владения. Диспозиция 92 ID (строки 65–72) остаётся трекером H6120.
- H6176–H6180 и H6191 закрыты на согласование границ с claimed H6120; их владение не переносится. Golden-набор W1 H6181 обслуживает только первую волну.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к G-ролям на их визе.
- Деньги и доступы (hard guards): роли G не проводят платежей, возвратов, выплат, скидок и рассрочек — только read-only-предложение; скидки/возвраты нестандартного вида решает MG (Q6). Money-contour PR никогда не форс-мержится.

## Evidence (фаза-1)

- Этот документ — гейто-упаковка G65–G72 + карта переиспользования + нулевой прод-след (artifact 1).
- Центза §5 — greps по `config/features.php` и diff PR: 0 новых agent-флагов, 0 новых планировщиков/LLM-вызовов, 0 исходящих (artifact 2).
- Own-data canary: паритет ростера v1 §«G. Финансы и админ (8)» ↔ диспозиции строк 65–72 — 8 ролей, имена идентичны (`diff` двух выжимок = пусто, **PASS 8/8**, 18-11-2026).

## Delivery (five fields)

- **Changed:** этот документ (департаментный контур H6109); ничего в коде, флагах, планировщиках.
- **Unchanged:** G65 — evidence H6188 принят без второй реализации; G66–G72 — подготовка; прод, исходящих и финансовых мутаций 0; Hermes/поддержка W1 и финансы-утилиты не тронуты.
- **Checks:** центза §5 — PASS (agent-флаги G66–G72 = 0, новые планировщики/LLM = 0, исходящие = 0); own-data canary паритета ростер↔диспозиция — PASS 8/8.
- **Risks:** drift между этим документом и ростером при новой визе MG — лечится ссылкой на источник и центзой при каждом визе; расхождение стратегических волн диспозиции и состава волн ростера v2 (G72) остаётся за MG/H6120, агент его не разрешает.
- **Inspect:** `grep -n "hermes_payment_reconciler\|hermes_" config/features.php`; `rg -n "PaymentReconcile" app tests`; `git diff --stat origin/main..HEAD` (ровно один файл — этот документ); этот документ.

_Гасунс_ · H6109 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
