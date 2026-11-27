# Архитектура: школа-агенты, департамент B «Продажи и онбординг» — фаза-1 (H6101)

_Created: 24-11-2026 · Last updated: 24-11-2026_

Исполнение handoff [H6101-OxAlpha_…dept-b-sales-onboarding](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6101-OxAlpha_Systema-Sanscriticum_school-agents-dept-b-sales-onboarding_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md). Сестринские контуры: [A — маркетинг](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md), [G — финансы](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_G_FINANCE_ADMIN_PHASE1_H6109_2026-11-18.md). `--allow-dup` против H6100: департаменты — разные юниты.

Ростеры и решения:

- [Ростер v1, роли B13–B22](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «B. Продажи и онбординг (10)».
- [Ростер v2, волны и гейты](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- [Диспозиция 92 ID, строки 13–22](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- [Карта детей Hermes 24](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант H6084: ничего из ростера не пишет в прод школы без гейта волны.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; департамент B в неё входит. W1 явно называет first-contact-247 (B13), faq-rag (B20) и «привязку-к-боту» (B16 по тексту строки; errata 05-10 → **B19** `onboarding-greeter`), плюс «маршрутизацию обращений» (13/59). Диспозиция подтверждает строки 13/19/20 как кандидатов W1 «запуск — дети E026».

⇒ Фаза-1 B = **три роли в квоте 25 (B13, B19, B20; W1) + общий слой маршрутизации 13/59 + гейто-упаковка семи остальных**:

- **B13 `first-contact-247`** — квота 25, W1; реализация ребёнка [H6184](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6184-GLM_Systema-Sanscriticum_hermes-w1-first-contact-role13_06.10.26.md).
- **B19 `onboarding-greeter`** — квота 25, W1; ребёнок [H6186](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6186-GLM_Systema-Sanscriticum_hermes-w1-onboarding-role19_06.10.26.md).
- **B20 `faq-rag`** — квота 25, W1; ребёнок [H6185](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6185-GLM_Systema-Sanscriticum_hermes-w1-faq-rag-role20_06.10.26.md).
- **Маршрутизация 13/59** — канонический ID подтверждает [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md); общий слой не создаёт новых ID и не добавляет роли к квоте.
- **B14, B15, B16, B17, B18, B21, B22** — подготовка, не запуск: ни флага, ни планировщика, ни исходящих.

Зонтик **принимает evidence детей H6184/H6186/H6185/H6190 и не пишет второй вариант** (scope-разделение пакета Hermes 06-10). Стратегические волны диспозиции (13–16/19/20/22 → 2, 17/18/21 → 3) — планка трекера H6120, не состав волн запуска: канон — ростер v2, где эти роли в W1–W3 не названы ⇒ запуск после W1–W3 по визе MG. В ростер и диспозицию изменений нет.

## 2. Роли B и estate-поверхности (prior art — не строить заново)

| # | Роль | Тип/профиль | Поверхность |
|---|---|---|---|
| B13 | first-contact-247 | 🧠 ОП | H6184: `app/Services/Support/Hermes/FirstContact/W1FirstContactService.php` + `W1FirstContactResult`, `tests/Feature/Support/H6184HermesAcceptanceTest.php`; слой H6190, PII H6182, mock LLM, квоты H6174, эскалация H6133 |
| B14 | consult-seller | 🧠 ОП | `app/Support/UnifiedSalesStage.php`, `app/Models/Deal.php`, `app/Support/NextIntroSession.php`, CRM_SALES_FORECAST_METHODOLOGY |
| B15 | trial-scheduler | ⚙️ ОП | `app/Services/Crm/TrialBookingService.php`, `TrialController.php`, `Api/PublicTrialBookController.php`, `AdvanceTrialSchedule.php`, `CheckTrialFreshness.php`, `SchedulesStudentsAndContent.php` (`trial:auto-advance` под `features.trial_auto_advance` OFF), `TrialZoomLinkMail.php` |
| B16 | no-show-rescuer | 🔄 ОП | `ReconcileTrialAttendance.php`, `WatchTrialTargets.php`, `CheckTrialFreshness.php`; записи занятий кабинета |
| B17 | payment-concierge | ⚙️ ДЕН | `CheckoutController.php`, `app/Services/Checkout/*`, `AuditCheckoutIntegrity.php`, `ExpireStaleCheckouts.php`, Tochka-вебхук, CHECKOUT_PURCHASE_UX_AUDIT_2026 |
| B18 | dunning-agent | ⚙️ ДЕН | `app/Support/DunningStage.php`, `DebtorReminderDispatcher.php`, `RemindDebtors.php`, `DebtorReminderMail.php`, `DebtReminder.php`, `docs/copy/money-installments-no-shame-checkout-ask.md` |
| B19 | onboarding-greeter | ⚙️ ОП | H6186: `Onboarding/W1BotBindingPrepService.php` + `W1BotBindingTokenIssuer`, `W1BotBindingChecks`, `W1BindingTokenState`, `tests/Feature/Support/H6186HermesAcceptanceTest.php` + `Concerns/H6186*Scenarios.php`, `docs/onboarding-student.md` |
| B20 | faq-rag | 🧠 ОП | H6185: `FaqRag/W1FaqRagAnswerComposer.php` + `W1FaqRagCitationVerifier`, `W1FaqRagGoldenScenarioRunner`, `tests/Feature/Support/H6185HermesAcceptanceTest.php`; prior art `FaqRagScoreFloor.php`, `BotFaqRetrievalSmoke.php`, `FaqEvalSetBuild.php`, `tests/fixtures/faq_rag_eval.json` |
| B21 | upsell-path | 🧠 БР | `app/Models/Course.php`, `MarketingSettingResource.php`, custdev-upsell-puller |
| B22 | objection-log | 🧠 АН | возражения в `Deal.php` / `UnifiedSalesStage.php`; отдельной поверхности нет — будет новой к визе |

Профиль ДЕН (B17/B18) = денежный контур, гарды §6. Все поверхности обслуживаются людьми/скриптами; агент-фаза стартует только после визы волны и гейтов.

## 3. Гейто-упаковка (Q3–Q9) для B

| Гейт | Правило | Применение в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; 30 дней human-send | **0 прод-писем до гейта волны**; затем — через [H6183](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6183-GLM_Systema-Sanscriticum_hermes-tg-web-human-send-evidence_06.10.26.md), `HERMES_HUMAN_SEND` OFF. Дети дают черновики, не отправку |
| Q5 pii-guard | маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, H2641) | Все B с данными людей: `SupportStudentPiiMasker.php`; ребёнок H6182 доказал fail-closed TG+web; H6186 — ownership/чужой-аккаунт/expired/replay для токенов B19 |
| Q6 скидки | полный запрет автономии, решает MG | B14/B21 только предлагают; B17/B18 — деньги: никаких скидок, рассрочек, списаний, изменений условий агентом |
| Q7 токены | killgate H5867 + лимиты по ролям; без новых трат (z.ai) | Все 🧠/🔄 роли: H5867 + квотная машина [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md); дети OFF log-only, без платного fallback |
| Q8 учебка | золотые наборы + выборка MG | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) — сценарии 13/19/20 + routing; только W1, не D/J-наборы; для остальных golden собирается к своей волне |
| Q9 голос | tone-гайд + редколлегия скиллов; litredaktor отменён | черновик по tone-гайду → редколлегия (`pishi`, `sokratil`) → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека, NPS | Базлайны при запуске W1 для B13/B19/B20; 30 дней human-send считают правки/минуты, не автономные закрытия |
| Q4 канал | TG + веб сразу | Черновики в TG + web-очередь H6183 |

## 4. Карта переиспользования (общая техника уже в репозитории)

| Техника | Статус |
|---|---|
| Роли 13 / 19 / 20 / routing | реализации H6184 / H6186 / H6185 / H6190 в `app/Services/Support/Hermes/{FirstContact,Onboarding,FaqRag,Routing}` — evidence принимается, второй вариант не строится |
| Killgate, квоты, PII, human-send, golden, измерительная программа | H6173, H6174, H6144+H6182, H6183 (`features.php:2154`), H6181, H6120 (claimed — владение не переносится) |
| Ссылки на children | все полные URL — в §1 и §3 этого документа |

## 5. Нулевой прод-след фазы-1 (центза 24-11-2026)

- Флагов agent-запуска B14–B18/B21/B22: **0** — этот PR не добавляет флагов. Существуют, все дефолт OFF: `hermes_schedule_keeper` (`config/features.php:2140`), `hermes_human_send` (2154), `hermes_faq_rag` (2167, роль B20 ребёнка H6185), `hermes_access_diagnostics` (2183), `hermes_payment_reconciler` (2196). B13/B19 и H6190 отдельного флага не имеют; денежных флагов B17/B18 нет. Омоним `student_agent` (H3231) — не роль ростера.
- Новых Schedule-заданий и LLM-вызовов: **0**. Существующие утилиты (`TrialBookingService`, `AdvanceTrialSchedule`, `CheckTrialFreshness`, `ReconcileTrialAttendance`, `AuditCheckoutIntegrity`, `ExpireStaleCheckouts`, `DebtorReminderDispatcher`, `RemindDebtors`) обслуживают людей; агент-обёрток нет.
- Исходящих сообщений: **0** (Q3/Q9 не активны, `HERMES_HUMAN_SEND` OFF, инвариант 0 писем до гейта волны).
- Прод-изменений привязки B19: **0** — H6186 работает с чистыми данными, ничего не применяется и не отправляется.
- Финансовых мутаций: **0** — B17/B18 read-only-предложения (money-safety H6188); Q6 запрещает скидки/рассрочки автономно.

## 6. Границы

- B-роли не наследуют msi-online; gate зонтика E026 этим не меняется (заметка H6100 от 06-10).
- 90-дневная программа — внутри claimed H6120; новые дети её владение не наследуют. H6176–H6180 и H6191 закрыты на согласование границ с H6120; golden W1 H6181 обслуживает только первую волну.
- B13/B19/B20 и routing 13/59 принимаются как evidence детей; канонические ID 92-ростера не меняются.
- Расширение после W1 — двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки; без измерения INCONCLUSIVE.
- Hard guards: B не проводит платежи, возвраты, выплаты, скидки, рассрочки и не выдаёт доступы — только read-only-предложение; нестандартные условия решает MG. Money-contour PR никогда не форс-мержится.

## Evidence (фаза-1)

- Этот документ — гейто-упаковка B13–B22 + карта переиспользования + нулевой прод-след (artifact 1).
- Центза §5: 0 новых agent-флагов для B14–B18/B21/B22, 0 планировщиков/LLM-вызовов, 0 исходящих, 0 финансовых мутаций (artifact 2).
- Prior-art implementations детей на месте: `app/Services/Support/Hermes/*` + `tests/Feature/Support/H618*HermesAcceptanceTest.php` — принимаются, не дублируются.
- Own-data canary: паритет ростера v1 §B (10 ролей) ↔ диспозиции строк 13–22, `diff` выжимок пуст, **PASS 10/10** (24-11-2026).

## Delivery (five fields)

- **Changed:** этот документ; ничего в коде, флагах, планировщиках.
- **Unchanged:** B13/B19/B20 — evidence детей приняты без второй реализации; B14–B18/B21/B22 — подготовка; прод, исходящих, денежных мутаций 0.
- **Checks:** центза §5 PASS; own-data canary PASS 10/10; prior-art детей найден.
- **Risks:** drift при новой визе MG лечится ссылкой на источник и центзой; расхождение стратегических волн диспозиции и ростера v2 остаётся за MG/H6120; B16→19 учтено по errata 05-10.
- **Inspect:** `grep -n "hermes_" config/features.php`; `find app/Services/Support/Hermes -maxdepth 2 -type f | sort`; `git diff --stat origin/main..HEAD` (один файл); этот документ.

_Гасунс_ · H6101 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
