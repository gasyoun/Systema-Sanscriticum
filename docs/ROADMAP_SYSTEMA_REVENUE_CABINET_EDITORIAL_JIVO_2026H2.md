# ROADMAP — больше продаж, нативное обучение, CRM и полный JIVO · 2026H2

_Created: 07-08-2026 · Last updated: 30-08-2026_

> **Truth-pass 30-08-2026 (Fable 5 `claude-fable-5`, `/ask` H3760):** ставки 1, 2, 4, 5 исполнены — H2378,
> H2379, H2381, H2382 в архиве реестра. Открытой остаётся ставка 3:
> [H2380 (Grok, 🔴3 hard) — cabinet adoption/KPI experiment](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2380-Grok_Systema-Sanscriticum_cabinet-adoption-kpi-experiment_07.08.26.md)
> — ⛔ human-gated (DEPLOY №52 / флаг H1582). Аналитический слой activation/completion заминчен отдельно:
> [H3764 (Opus 5, 🟡2 medium) — O2+C4 activation/completion metrics page](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3764-Opus_Systema-Sanscriticum_activation-completion-metrics-o2-c4_30.08.26.md).

**Рамка:** AARRR (Acquisition → Activation → Revenue → Retention → Referral).
**Контур:** samskrtam.ru/FAQ → samskrte.ru → checkout → кабинет `/dvaram` → support/повторная покупка.
**Провенанс:** Codex Sol (`gpt-5.6-sol`), code-grounded аудит `origin/main` на 07-08-2026.

Этот roadmap не заменяет годовой
[`ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
или Tier-0
[`ROADMAP_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SAMSKRTE_TIER0_2026_2027.md).
Он соединяет четыре уже построенных, но разнесённых контура в одну выручечную последовательность:
витрина, покупка, кабинет и JIVO-подобная поддержка.

Полный execution-пакет после интервью 07–08-08-2026:
[`PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).

## 1. Evidence inventory — только зафиксированные источники

| Источник | Дата среза | Что устанавливает |
|---|---:|---|
| [`ORS-FAQ/docs/roadmap_samskrte_sales.md`](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/roadmap_samskrte_sales.md) | 01-08-2026 | H1 2026: ≈23 новых плательщика и ≈1,37 млн ₽ positive cash/мес; `lead` и thank-you измеряются, цели card/checkout не срабатывают |
| [`benchmark_arzamas_sinhro_samskrte.md`](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/benchmark_arzamas_sinhro_samskrte.md) | 07-07-2026 | зафиксирован двойной benchmark: Синхронизация — funnel/UX, Arzamas — editorial authority |
| [`PUBLIC_STORE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PUBLIC_STORE_UX_AUDIT_2026.md) | 07-07-2026 | базовая структура каталога и course page сильная; пробелы — редакционные входы, контент флагманов, доверие и сквозная терминология |
| H323 / H387 на `main` | 07–08-07-2026 | «С чего начать», quiz, level badges, product ladder, отзывы и хаб «Материалы» уже построены; их нельзя планировать заново |
| [`CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md) | 24-07-2026 | кабинет R29 Phases 0–4 собран за флагом; остаток — prod proof, adoption и KPI, не новый remake |
| [`ORS-FAQ/CABINET_ADOPTION_ROADMAP.md`](https://github.com/gasyoun/ORS-FAQ/blob/main/CABINET_ADOPTION_ROADMAP.md) | 07-08-2026 | P0 почты и нормализации закрыт; P1 батч-приглашений/анонс, P2 adoption ≥2/3 ещё открыты |
| [`support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) | 31-07-2026 | unified inbox, identity, reply router, AI assist, visitor geo/presence и rollups уже есть; остатки JIVO точные и конечные |
| [`ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) | аудит 07-08-2026 | visitor-parity почти закрыт; «полный JIVO» теперь означает operator workflow + email + production acceptance |

## 2. Аудит: что действительно не работает

### Acquisition

- Цели Метрики для карточки и checkout показывают ноль при реальном трафике и оплатах. Пока это
  не исправлено, визуальные улучшения нельзя честно масштабировать или убивать.
- Витрина уже содержит beginner on-ramp, лестницу, отзывы и материалы. Новый redesign с нуля —
  дублирование. Нужен browser-verified polish: иерархия, ритм, реальные обложки/лица, кураторские
  полки и единый язык live/recorded.
- Контент-донор samskrtam.ru и магазин samskrte.ru связаны UTM, но card→course→checkout пока не
  образуют наблюдаемую воронку.

### Activation

- Новый кабинет построен, а план всё ещё местами описывает Phases 1–3 как будущие. Остаток —
  исполнить существующий H1582, затем довести adoption, а не строить второй shell.
- Доля когда-либо вошедших исторически была около 1/3; P0 причины (почта, email case/trim) уже
  закрыты. Следующий рычаг — безопасный батч приглашений + недельная метрика к цели ≥2/3.

### Revenue

- Order→pay уже имеет отдельный dozhim-контур; не нужен ещё один CRM. Проблема этого roadmap —
  связать измерение `course_page → checkout → paid → first cabinet action` и сделать следующий
  продукт видимым только после реального прогресса.
- Деньги/цены/скидки не меняются в этом проходе. Новые UI-офферы используют существующий checkout.

### Retention / support

- JIVO visitor intelligence закрыт: real-time, geo, presence, оператор пишет первым, lead capture,
  TG/VK badges, AI drafts, unified read и rollups существуют.
- Остатки: полная EdTech-панель рядом с диалогом; обязательная тема закрытия; support follow-up;
  outcome-correlation; inbound email; проверка, что нужные флаги и reply-out реально работают.
- Старое правило сохраняется: бот сам людям не пишет; AI предлагает, человек отправляет.

## 3. AARRR snapshot и дельты

| Стадия | Уже есть | Дельта 2026H2 | Primary metric |
|---|---|---|---|
| Acquisition | FAQ/SEO donor, UTM, каталог, on-ramp, материалы | починить card/checkout goals; browser-polish по Arzamas/Синхронизации | new visitor → course page |
| Activation | автоаккаунт после оплаты, magic/reset flows, hybrid cabinet | prod release proof + invitation batches + first-action path | paid user → first cabinet action ≤24h |
| Revenue | Tochka, tariffs, order→pay report, dozhim | сквозной funnel и progress-gated cross-sell | checkout→paid; revenue/active student |
| Retention | lessons, recordings, SRS, progress, support | hybrid adoption + support outcome loop | 30d active; repeat purchase; self-resolution |
| Referral | student + partner referrals | не расширять до измерения первых четырёх стадий | paid referrals / active student |

## 4. Revenue-ranked bets

| Rank | Bet / owner | Почему сейчас | Cost | Expected effect | Kill criterion |
|---:|---|---|---|---|---|
| 1 | **Measurement spine** — [H2378](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2378-Grok_Systema-Sanscriticum_sales-measurement-goals-dashboard_07.08.26.md) | без card/checkout знаменателя остальные ставки нефальсифицируемы | medium | наблюдаемый course→checkout→paid→cabinet funnel | kill выбранную реализацию, если synthetic hits не видны в отчёте и CRM reconciliation не сходится; не «чинить цифры» фильтрами |
| 2 | **Arzamas × Синхронизация polish** — [H2379](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2379-Grok_Systema-Sanscriticum_arzamas-sinhro-visual-polish-pass_07.08.26.md) | funnel-функции уже есть; слабое место — presentation/content completeness | hard | рост course-page CTR и begin-checkout без нового backend | revert/stop after one full 30–45d cohort if neither metric moves and traffic mix is comparable |
| 3 | **Cabinet adoption + KPI** — H1582 then [H2380](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2380-Grok_Systema-Sanscriticum_cabinet-adoption-kpi-experiment_07.08.26.md) | код уже оплачен; невключённый кабинет не создаёт activation/retention value | hard | login adoption toward ≥2/3; higher first action and revenue/active student | instant flag revert on access/payment regression; stop invitation escalation if complaint/bounce rate breaches the documented mail warm-up guard |
| 4 | **JIVO operator workflow** — [H2381](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2381-Grok_Systema-Sanscriticum_jivo-operator-workflow-completion_07.08.26.md) | закрывает потери после ответа и пять-screen lookup куратора | hard | lower first-response/handle time; fewer unresolved >24h | cut any field/workflow that operators do not use in ≥80% of a 2-week pilot or that adds more clicks than it removes |
| 5 | **JIVO production parity proof** — H1200 residual then [H2382](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2382-Grok_Systema-Sanscriticum_support-parity-production-acceptance_07.08.26.md) | merged ≠ deployed; full parity requires real channels and evidence | hard | one inbox handles web/TG/VK/email with explicit channel health | email/routing stays OFF if identity match or reply-out canary is ambiguous; no live recipient tests outside the approved canary |

## 5. Sequence

1. **Now / before the 28-08 marathon:** H2378 measurement; do not disturb the Wave-1 hard gate.
2. **Parallel, code-safe:** H2379 visual polish behind screenshots/feature-safe presentation;
   editorial completion of 3–5 flagship courses is a human content step, not new schema.
3. **After H1582 GO and smoke:** H2380 adoption experiment; invitation batches ramp 100 → 200/day
   only while deliverability and complaints remain acceptable.
4. **Native learning wave:** import one pinned VisualDCS release and ship verb trainer, nominal
   trainer and concordance→passage together, with independent flags and Systema-owned progress.
5. **School-operational JIVO parity:** H2381 operator workflow, H1200 email residual, then H2382
   production acceptance, flag inventory and 14-day readout.
6. **CRM Wave 1 immediately after parity:** unified customer timeline + pipeline stage + next
   action + support/conversion attribution on existing Lead/Deal/FollowUpTask/inbox owners.
7. **CRM Waves 2–3:** lifecycle automation through the existing Campaign stack (H2484), then
   forecasting and manager dashboards over canonical Deal/Payment denominators
   (**H2485 shipped 14-08-2026**, flag `crm_sales_forecast` default OFF).
8. **Literal-Jivo Wave 4+:** telephony/callback, departments and capacity routing only after the
   CRM spine and measured operator/voice volume; provider/legal packet before implementation.
   ✅ H2486 packet 14-08-2026 (**HOLD** — do not buy/activate):
   [PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md).

## 6. Definition of synchronization

«Синхронизация» в этом roadmap — одновременно benchmark `online.synchronize.ru` and a product
contract: the same course/tariff/ownership state must read consistently from public card to
course page, checkout, success/failure recovery, cabinet shelf and support sidebar.

One source per fact:

- price/access: `Tariff` + existing money/access services;
- live/recorded/owned/lapsed: course/tariff/access read models already used by shop + hybrid cabinet;
- person/channel identity: `User` + canonical `social_accounts`, never a fourth identity table;
- analytics: first-party events + Metrika/CRM reconciliation, with documented denominators.

## 7. NOT doing

- no new LMS, checkout, CRM, chat widget, identity table or cabinet remake;
- no copy of Arzamas/Synchronization branding; borrow hierarchy, editorial rhythm and funnel clarity;
- no fake urgency, invented testimonials or unsupported trust numbers;
- no pricing/discount change in this roadmap pass;
- no autonomous bot outreach and no AI write to payments/access/homework;
- no call-centre telephony or enterprise routing **before** school parity + CRM and real operator
  volume justify activation; these are later committed waves, not permanent non-goals;
- no claim of revenue lift before repaired measurement and a complete cohort window.

## 8. Human gates

- H1582 production flag flip and any live-send invitation batch;
- legal/privacy sign-off for geo/presence and inbound-email retention;
- 3–5 flagship courses: real preview lesson, teacher photo/bio, outcomes, FAQ and reviews;
- any price, membership or payment-policy change goes through the money workflow separately.

## 9. VisualDCS learner integration and CRM extension

- VisualDCS publishes versioned schemas, checksums, manifests and stable learning-object IDs;
  Systema imports a pinned release and renders native cabinet UI—no iframe or live `main` fetch.
- Public preview is acquisition; full access calls existing course/tariff entitlement services.
- Durable cross-device progress uses an idempotent external-learning projection plus existing
  `ActivityEvent` telemetry; raw person-level data never returns to VisualDCS.
- CRM builds on existing `Lead`, `Deal`, `FollowUpTask`, `Campaign`, attribution and support
  owners. Customer 360 comes first; automation and forecasting are explicitly wanted next.

_Dr. Mārcis Gasūns_
