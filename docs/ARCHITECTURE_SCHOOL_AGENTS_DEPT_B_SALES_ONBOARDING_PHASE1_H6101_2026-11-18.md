# Архитектура: школа-агенты, департамент B «Продажи и онбординг» — фаза-1 (H6101)

_Created: 18-11-2026 · Last updated: 18-11-2026_

Исполнение handoff [H6101-OxAlpha_…school-agents-dept-b-sales-onboarding](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6101-OxAlpha_Systema-Sanscriticum_school-agents-dept-b-sales-onboarding_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md). Сестринский контур департамента A: [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md).

Ростеры и решения:

- Ростер v1 (роли департамента B, 13–22): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «B. Продажи и онбординг (10)».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта read-only поверхностей W2: [SCHOOL_AGENTS_W2_READONLY_SURFACE_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/SCHOOL_AGENTS_W2_READONLY_SURFACE_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25. Департамент B **входит в фаза-1 квоту**: W1 = роли 13 (first-contact-247), 19 (onboarding-greeter), 20 (faq-rag); остальные семь (14–18, 21, 22) — W2, где W2 = 18 B/C-ролей (W2-карта §1/§3). Фаза-1 департамента B на сегодня = **приёмка child-evidence W1 + гейто-упаковка W2-ролей + нулевой прод-след**: три W1-роли уже доставлены детьми пакета Hermes (§4), зонтик второй вариант не пишет.

Два открытых вопроса квотной арифметики (W2-карта §1) закрыты здесь:

1. **Маршрутизация «13|59»** (маршрутизация обращений из W1-строки ростера v2; errata H6142: C37→13|59) — вердикт H6101: это **общий слой H6190** (`app/Services/Support/Hermes/Routing/`) без канонического role ID. Evidence: приёмка H6190 прямо фиксирует «не создаёт седьмой канонический role ID». Значит это не дубль роли 13 и не роль 59 (community-host, департамент F).
2. **Свободный слот квоты** (6+18 = 24 из 25 при маршрутизации без ID): слот добирается **W3 D-ролями (учебка-проверка, движки Q10 как ядро) по визе MG на гейте волны**, а не седьмой W1-ролью. Основание: календарь измерительной программы имеет приоритет перед темпом (уточнение 05-10); W1 ещё не запускался — расширять первую волну нечем подкрепить (гейт W1: базлайны KPI + зелёный pii-guard). Перераспределение слота остаётся за MG — гейт волны человеческий.

## 2. Роли департамента B и estate-поверхности

| ID | Роль | Тип | Волна | Фаза-1 | Estate-поверхность (prior art — не строить заново) |
|---|---|---|---|---|---|
| 13 | first-contact-247 | 🧠 | W1 | ✅ child H6184 | @samskrte_bot; `app/Services/Support/Hermes/FirstContact/` |
| 14 | consult-seller | 🧠 | W2 | зарезервирована | `LeadResource`/lead_signup, consult-практики школы; W2-карта §3 (K0+K2) |
| 15 | trial-scheduler | ⚙️ | W2 | зарезервирована | trial_schedule + H5864 readiness, `scheduled_reminders`; W2-карта §3 (K2) |
| 16 | no-show-rescuer | 🔄 | W2 | зарезервирована | `zapisi_class_schedules`, записи эфиров в кабинете; W2-карта §3 (K2) |
| 17 | payment-concierge | ⚙️ | W2 | зарезервирована | Tochka checkout probes, `payments`/`payment_webhook_events` (read-only); W2-карта §3 (K3) |
| 18 | dunning-agent | ⚙️ | W2 | зарезервирована | `debt_reminders`/`payment_promises`/`payment_promise_suggestions` (read-only); W2-карта §3 (K3) |
| 19 | onboarding-greeter | ⚙️ | W1 | ✅ child H6186 | @samskrtamru_bot /telegram/connect; `EnrollContactBinder`; `app/Services/Support/Hermes/Onboarding/` |
| 20 | faq-rag | 🧠 | W1 | ✅ child H6185 | `app/Services/Support/Faq`, corpus-drift гейт H6917; `app/Services/Support/Hermes/` |
| 21 | upsell-path | 🧠 | W2 | зарезервирована | `course_favorites`/`lesson_user`/`courses` (read-only), custdev upsell puller; W2-карта §3 (K2+K3) |
| 22 | objection-log | 🧠 | W2 | зарезервирована | `lead_notes`/`deals`/`deal_transitions` (read-only), сводка MG по классам возражений; W2-карта §3 (K2) |

Read-only таблицы, классы данных K0–K4 и правила слоя — канон [W2-карта §2–§4](https://github.com/gasyoun/Uprava/blob/main/docs/SCHOOL_AGENTS_W2_READONLY_SURFACE_2026-10-05.md); послолбцовая PII-верификация и построчная проводка гейтов — задача детей при запуске каждой роли (§4.8 карты).

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту B

| Гейт | Правило (решения 05-10) | Для B в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Ни одного исходящего до гейта волны; потом — human-send-слой H6183 (`SupportHumanSendService`, флаг `hermes_human_send` дефолт OFF) |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для всех B-ролей с K2/K3/K4 (13–22, кроме чистого K0); готовый маскер `SupportStudentPiiMasker`, proof H6182; перед W2 закрыть швы H6140 (§6) |
| Q6 скидки | полный запрет автономии, решает MG | Без исключений: 14/17/18/21 касаются денег — только предлагать, никогда не ставить цену/скидку/рассрочку; 17/18 не мутируют платежи и тарифы (только чтение статуса + штатный путь) |
| Q7 токены | killgate-слой H5867 + лимиты по ролям; «без новых трат» — подписочная квота z.ai | Для 🧠/🔄 ролей B — killgate (интеграция H6173) + лимиты из квотной машины H6174; бюджеты в реестре — настраиваемые дефолты, не новые траты |
| Q8 учебка | золотые наборы + выборка MG | Для B действует golden-контур W1 H6181 (роли 13/19/20) + score-floor и corpus-drift гейт FAQ (H6917); для W2-ролей B свой golden-cut собирается к их запуску — D/J-наборы учебки и W1-набор не наследуются |
| Q9 голос | tone-гайд + редколлегия скиллов; litredaktor-пасс отменён | Исходящие B (в т.ч. сводка 22 для MG): черновик по tone-гайду → редколлегия → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Замеряет H6120-weekly; роли сами не самоотчитываются |
| Q4 канал | TG + веб сразу | Черновики в TG-лейну + web через очередь H6183 (web) |

## 4. Приёмка evidence детей — дублей не пишем (пакет Hermes 06-10)

Принято как реализация W1-ролей департамента B (разделение scope зонтика: принимать evidence детей, не писать второй вариант):

| Щит | Роль / слой | Evidence (принято) |
|---|---|---|
| [H6184](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6184-GLM_Systema-Sanscriticum_hermes-w1-first-contact-role13_06.10.26.md) | 13 first-contact-247 | PR [#4153](https://github.com/gasyoun/Systema-Sanscriticum/pull/4153) (merge `73df093c`) + evidence PR [#4154](https://github.com/gasyoun/Systema-Sanscriticum/pull/4154) (`fed95a10`); 14 тестов / 81 assertion |
| [H6185](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6185-GLM_Systema-Sanscriticum_hermes-w1-faq-rag-role20_06.10.26.md) | 20 faq-rag | PR [#4156](https://github.com/gasyoun/Systema-Sanscriticum/pull/4156) (`9622933b`) + PR [#4158](https://github.com/gasyoun/Systema-Sanscriticum/pull/4158) (`c5a7af64`); 7 тестов / 114 assertions; `check_faq_corpus_drift.py` OK |
| [H6186](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6186-GLM_Systema-Sanscriticum_hermes-w1-onboarding-role19_06.10.26.md) | 19 onboarding-greeter | PR [#4157](https://github.com/gasyoun/Systema-Sanscriticum/pull/4157) (`5fc0ab3b`) + PR [#4159](https://github.com/gasyoun/Systema-Sanscriticum/pull/4159) (`0120115d`); 18 тестов / 184 assertions |
| [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) | маршрутизация (без role ID) | PR [#4152](https://github.com/gasyoun/Systema-Sanscriticum/pull/4152) (`4b7c8cdf`); 7 тестов / 102 assertions |

Общая техника (не реализуется повторно): human-send H6183, PII на границе LLM H6144/H6182, квотная машина H6174, killgate H5867/H6173, golden-набор W1 H6181.

## 5. Нулевой прод-след фазы-1 (центза)

- Реестр `config/school_agents_dept_b.php` — описательный конфиг фазы-1: статусы только `reserved`/`child`, `prod_write_enabled => false`; к feature-флагам, планировщикам и роутам не подключён.
- Новых флагов в `config/features.php`: **0**. Известные флаги-омонимы контура estate (не агенты, не тронуты): `trial_grant_hardening`, `trial_auto_advance` (денежный контур, дефолт OFF), `checkout_promo_reservations`, `checkout_integrity_safe_repairs`.
- Новых Schedule-заданий и LLM-вызовов: **0**. Исходящих сообщений агентом: **0** (Q3 не активирован; `hermes_human_send` дефолт OFF; child-сервисы W1 не подключены к прод-путям — grep в приёмке §4).

## 6. Границы

- Перед запуском W2 закрыть швы H6140 (вердикт по [PR #3027](https://github.com/gasyoun/Systema-Sanscriticum/pull/3027#issuecomment-5992844827)): (а) второй шов `SupportDmLlmReplyComposer` без маскера, (б) форматированные телефоны в обоих реализациях контракта, (в) regression-тест fail-closed ветки.
- B-роли не наследуют msi-online; gate зонтика E026 этим дополнением не меняется (scope-заметка 06-10).
- 90-дневная измерительная программа — claimed H6120; владение не переносится; границы H6176–H6180/H6191 не пересматриваются.
- Расширение после W1: две недели без Sev-инцидентов **и** доказанное снижение человеческой нагрузки (уточнение 05-10); без измерения — INCONCLUSIVE.
- Kill-candidates 8/39/42 к департаменту B не относятся и не включаются.

## Acceptance (lock before work) · Evidence · Delivery

- **Done looks like:** реестр `config/school_agents_dept_b.php` (10 ролей B с гейтами Q3–Q9) + тест `tests/Feature/SchoolAgents/H6101SchoolAgentsDeptBRegistryTest.php` + этот документ.
- **Prove with:** `php artisan test --filter=H6101SchoolAgentsDeptBRegistryTest`; центза §5.
- **On our data:** канон ростера v1 §B (10 ролей), ростер v2 (волны/гейты), диспозиция 92ID, W2-карта §3.
- **Fail =:** остановка без закрытия handoff, блокер фиксируется в GTD.
- **Evidence:** артефакт 1 — реестр + тест (7 тестов инвариантов гейтов); артефакт 2 — этот документ + PR; **own-data canary** — `test_gate_surfaces_bind_to_real_estate_mechanics` (human-send H6183 / pii-маскер / `EnrollContactBinder` обязаны существовать в owning repo — гейты ссылаются на реальную механику).
- **Changed:** `config/school_agents_dept_b.php` (реестр фазы-1 B), `tests/Feature/SchoolAgents/H6101SchoolAgentsDeptBRegistryTest.php`, этот документ.
- **Unchanged:** все 10 ролей — ровно то же поведение estate; child-реализации 13/19/20 и слой H6190 не тронуты (evidence принято, дублей нет); прода, исходящих, флагов, планировщиков — 0.
- **Checks:** `php -l config/school_agents_dept_b.php tests/Feature/SchoolAgents/H6101SchoolAgentsDeptBRegistryTest.php` → PASS; `php artisan test --filter=H6101SchoolAgentsDeptBRegistryTest` → см. PR checks; центза §5 (grep флагов/планировщиков) → PASS.
- **Risks:** drift между реестром и ростером при новой визе MG — лечится ссылкой на канон в шапке реестра; слот квоты и швы H6140 — активные ограничения до гейта волны.
- **Inspect:** `config/school_agents_dept_b.php` → `tests/Feature/SchoolAgents/H6101SchoolAgentsDeptBRegistryTest.php` → этот документ → PR checks.

_Гасунс_ · H6101 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
