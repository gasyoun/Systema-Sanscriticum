# Student cabinet — live EdTech comparison (H822)

_Created: 12-07-2026 · Last updated: 12-07-2026_

Phase 2 of [H822](https://github.com/gasyoun/Uprava/blob/main/handoffs/H822-Fable_Systema-Sanscriticum_student-cabinet-custdev-ux-remake_12.07.26.md).
All observations made **live on 12-07-2026** by Fable 5 (`claude-fable-5`) through the public web
interfaces and vendor documentation of each platform; each section links its primary sources.
No screenshots committed (logged-out surfaces only; screenshots added no evidence over the cited text).
Companion evidence ledger: [STUDENT_CABINET_REMAKE_RESEARCH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md).

**Access notes / substitutions.** The handoff's suggested mix was GetCourse, Stepik,
Skillbox/Netology, Coursera, Udemy, Duolingo/Maven. Actually compared: **GetCourse, Stepik,
Netology, Coursera, Khan Academy, Duolingo** (6). Substitutions: **Udemy** was unreachable —
its site served a bot-verification interstitial that I do not bypass ([udemy.com](https://www.udemy.com/), attempted 12-07-2026);
**Khan Academy** substitutes as the recordings-library/self-paced archetype (its learner UI is fully
public, unlike Udemy's). **Skillbox** was unreachable from this session (navigation denied);
**Netology** (same segment, same buyer, reachable) covers the RU career-school slot. Logged-in
member areas of Coursera/Netology/Duolingo were not entered (no accounts used); for those, vendor
documentation and public surfaces are cited and marked as such.

## 1. Per-platform observations

### 1.1 GetCourse — the RU online-school default (closest structural comparable)

Sources: [getcourse.ru/blog/632059 «Как ученику войти в онлайн-школу и как пользоваться личным кабинетом»](https://getcourse.ru/blog/632059) (vendor doc, read in full 12-07-2026); [getcourse.ru/help](https://getcourse.ru/help).

- **Cabinet IA (canonical 7 sections):** Профиль · Поиск (по тренингам и урокам) · Информер (уведомления, счетчик непрочитанного) · Обучение (Тренинги / Расписание / Лента ответов / Тестирования) · Служба поддержки · Покупки · Mobile (привязка приложения).
- **Dashboard/home job:** «Тренинги» = the home; a flat trainings grid. No unified next-action layer — the student re-orients per visit (same weakness as Systema's, at bigger scale).
- **Support:** first-class *section*, not a buried tab: «Новый разговор» with **department picker** (отделы клиентской поддержки), full dialog history. Exactly the structured-entry pattern Systema's audit D asks for.
- **Payments/renewal:** «Покупки» holds bought products, **unfinished orders with a «Перейти к оплате» button**, personal promo codes, and — where enabled — **self-service renewal / auto-renewal from the purchase card**. A lapsed product remains VISIBLE with a renewal path (vs Systema's silent vanish, ledger F3).
- **Notifications:** per-channel opt-in (информер/email/Telegram/VK/Viber/app) managed by the student.
- **Search across trainings and lessons** — a feature Systema entirely lacks.
- **Transferable:** purchase-card renewal, department-picker support entry, honest notification center, lesson search. **Unsuitable:** GetCourse ID multi-school layer (single-school product); their flat trainings grid (the exact no-next-action weakness the remake fixes).

### 1.2 Stepik — RU course platform, self-paced at scale

Sources: [stepik.org/catalog](https://stepik.org/catalog), [course 58852 promo](https://stepik.org/course/58852/promo) (read 12-07-2026; syllabus and learner home are login-gated — observations below the fold are from the public course surface only).

- **Course page as contract:** «Чему вы научитесь» outcomes list · level badge · certificate availability · rating + review count + learner count · «Как проходит обучение» module map with hour estimates · instructor cards with social proof. Dense but scannable.
- **Trust mechanics visible logged-out:** образовательная лицензия number in the course description; 25 542 reviews surfaced at the top; free/paid clearly split; promo pricing shown with explicit dates.
- **Learner model (documented on public surfaces):** linear steps inside lessons inside modules; progress per module; auto-graded exercises with instant feedback dominate the experience.
- **Transferable:** module map with time estimates on the course home («4 модуля · 2 недели · 10 ч/нед» in one glance); outcomes-first course framing; license number as a trust element (Systema HAS a license — ИП Гасунс edu-license — and the cabinet never shows it). **Unsuitable:** auto-graded-exercise centricity (Systema is live-teacher + homework-review pedagogy); catalog-first home (Systema students own 1–5 courses, not 50).

### 1.3 Netology — RU career school (Skillbox-segment substitute)

Sources: [netology.ru](https://netology.ru/) (read 12-07-2026; learner cabinet is login-gated — marketing surface + published support claims only).

- **Support as the headline:** the #1 hero claim is «4,88 из 5 — оценка нашей команды поддержки, согласно опросу 200 000 студентов» — a support-quality metric promoted above course count. They also headline «Учим — и помогаем на каждом этапе» and «Формат "живого" общения с экспертами».
- **IA of the offer:** goal-first navigation («Освоить профессию / Освоить навыки / Определиться с профессией») and curated «подборки под любые цели» rather than a raw catalog.
- **Transferable:** support quality as a *promoted trust asset* (Systema's curator care is arguably its strongest differentiator and the cabinet hides it behind the last tab); goal-first grouping language. **Unsuitable:** career-outcome framing (профессия/трудоустройство) — wrong register for a scholarly/practice audience.

### 1.4 Coursera — global MOOC, cohort+self-paced hybrid

Sources: [coursera.org/learn/learning-how-to-learn](https://www.coursera.org/learn/learning-how-to-learn) (read 12-07-2026, RU locale; learner home is login-gated — course surface + published patterns).

- **Course home as contract:** modules with «N часа до завершения» each; «Гибкий график — учитесь в удобном темпе» as a first-class badge; «2 недели, 10 часов в неделю» expectation-setting; skills tags; shareable certificate with LinkedIn CTA; financial aid link visible («Доступна финансовая помощь»).
- **The «Гибкий график» pattern** is precisely the «recordings = свой темп» reassurance Systema's objection data (ledger M6) says matters most — Coursera prints it on every course.
- **Subscription upsell (Coursera Plus)** is a persistent but single, dismissible banner — one offer surface, not a shop shelf.
- **Transferable:** per-module time estimates; own-pace badging on everything recorded; financial-aid/benefit-price visibility (maps to Systema's льготная цена rule, ledger M5); single-banner upsell discipline. **Unsuitable:** AI-graded assignments messaging; deadline-reset mechanics (Coursera's soft deadlines still exist — Systema's no-urgency guardrail is stricter).

### 1.5 Khan Academy — mastery-based free library (Udemy-slot substitute)

Sources: [khanacademy.org](https://www.khanacademy.org/), [khanacademy.org/math/arithmetic](https://www.khanacademy.org/math/arithmetic) (fully public learner UI, read 12-07-2026).

- **Course home = mastery map:** «19 UNITS · 203 SKILLS», a unit rail, per-skill states (**Mastered / Proficient / Familiar / Attempted / Not started**), «20 300 possible mastery points», quizzes and unit tests interleaved in the sequence, and a course challenge. Progress is the navigation.
- **Progress semantics are honest and granular** — the anti-pattern of Systema's manual-only «Завершить» (ledger F9): state is derived from what you actually did.
- **Transferable:** progress-as-navigation for the recordings library; explicit per-unit skill states; «course challenge» as an optional attestation surface (maps to M.G.'s learning-contour «measurable goal + date» ruling). **Unsuitable:** full gamified mastery-points economy (Systema already has prana; two point systems would collide), K-12 tonality.

### 1.6 Duolingo — habit-loop consumer learning

Sources: [duolingo.com](https://www.duolingo.com/) (read 12-07-2026; app home is login-gated — public surface + published design).

- **Public promise:** «короткие уроки», streaks/points («Зарабатывайте очки»), «напоминания от совёнка Duo… превратить ежедневные занятия в привычку», adaptive difficulty («Сложность и темп подбираются индивидуально»).
- **The path model** (one linear next lesson, everything else locked/de-emphasized) is the purest «single obvious next action» implementation in the industry — the maximal version of Systema's continue-card idea.
- **Transferable:** ONE primary action per session; streak-style *gentle* rhythm feedback (maps to the churn model's «personal cadence» — ledger M7 — better than calendar deadlines); reminder tone that is playful, never urgent. **Unsuitable:** the full gamification register (mascot pressure, leagues) — the strict-register buyer (highest cheque) reads it as unserious; Duolingo's own dark-pattern-adjacent streak guilt violates the no-pressure guardrail.

## 2. Comparison matrix

Columns abbreviated: GC = GetCourse, ST = Stepik, NE = Netology, CO = Coursera, KA = Khan Academy, DU = Duolingo, SY = Systema today (from the [evidence ledger](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md)).

| Dimension | GC | ST | NE | CO | KA | DU | SY today |
|---|---|---|---|---|---|---|---|
| Home's job | trainings grid | catalog | goal-first funnel | in-progress list | course library | ONE next lesson | 6-tab mega-page + continue card |
| Continue-learning | weak | per-course | n/o (gated) | «Resume» pattern | progress-as-nav | the whole product | card on dashboard only |
| Course home | lesson list | module map + hours | n/o | modules + time + own-pace badge | mastery map | path | flat lesson list, no next signal |
| Live events/schedule | Расписание tab | n/a | live-communication promise | deadlines (soft) | n/a | n/a | calendar + iCal + Zoom join |
| Recordings/resources | trainings | steps | n/o | videos + transcripts | video+practice library | n/a | lessons; no own-pace framing |
| Progress | Лента ответов | per-module | n/o | per-module % | 5-state skill mastery | streak/xp | manual «Завершить» only |
| Support | section + dept picker + history | tickets | headline metric (4.88) | help center | help center | in-app | last tab, free-text, magic phrase |
| Payments/renewal | purchases + unfinished orders + self-renewal | receipts | n/o | subscription mgmt | n/a | subscription | payments tab + debts self-service; expiry = silent vanish |
| Search | trainings+lessons | catalog | catalog | catalog | site-wide | n/a | none |
| Mobile | native app + web | app + web | app | app + web | app + web | app-first | responsive web only (Tailwind CDN); H824 wrapper planned |
| Upsell placement | promo codes in Покупки | promo banners | course recommendations | ONE Plus banner | donate | ONE super banner | shop button in header; none contextual |
| Trust/cancellation | offer docs footer | license in course text | support metric hero | financial aid visible | non-profit framing | free-first | license/offer NOT surfaced in cabinet |

n/o = not observable logged-out; n/a = not applicable to the model.

## 3. Copy / adapt / reject matrix (for Systema)

| Pattern | Source | Verdict | Note |
|---|---|---|---|
| Purchase card with visible expiry + self-service renewal (lapsed stays visible) | GC | **copy** | Directly closes ledger F3, the critical gap |
| Support entry with structured picker + dialog history | GC | **copy** | Audit D's flow C, confirmed at RU-market scale |
| Per-module/lesson time estimates on course home | ST/CO | **copy** | Cheap, honest, serves the time-objection (M6) |
| «Свой темп» badge on every recorded unit | CO | **copy** | The measured reassurance, printed where the anxiety lives |
| Progress derived from behavior (multi-state, honest) | KA | **adapt** | Watch-derived signal + manual confirm hybrid; don't import mastery points (prana exists) |
| ONE primary next action per session | DU | **adapt** | Continue card already half-built; extend to course page + mobile; skip gamified pressure |
| Personal-rhythm (streak-like) feedback, no guilt | DU | **adapt** | Align with churn model's personal-cadence math (M7); tone gentle, opt-out easy |
| Goal-first grouping language («освоить навык / определиться») | NE | **adapt** | For the discovery layer labels, not the learning layer |
| Support quality as promoted trust asset | NE | **adapt** | Surface curator care in cabinet (response-time honesty), don't fake a metric |
| License + offer visibility in cabinet footer | ST | **copy** | Zero-cost trust for the 35–55 buyer; data already exists |
| Financial-aid / benefit-price visibility at price surfaces | CO | **adapt** | Maps to льготные цены + installments (measured positive lift, ledger M5) |
| Single-banner subscription upsell, dismissible | CO | **adapt** | For membership (M10) — lapsed/completion contexts only |
| Catalog-first home | ST/NE | **reject** | Students own few courses; the home must serve continuation, not discovery |
| Deadline mechanics, streak guilt, scarcity | CO/DU | **reject** | Violates the measured no-pressure guardrail (ledger M5) |
| Full gamification economy (leagues, mascots) | DU | **reject** | Register mismatch with the strict/academic buyer (highest cheque) |
| Multi-school identity layer | GC | **reject** | Single-school product |
| AI-graded assignment messaging | CO | **reject** | Systema's differentiator is the live teacher and curator review |

## 4. What no comparator does (Systema's open ground)

- None of the six shows a **debt/installment self-service** as humane as Systema's promise/reschedule flow — that asset should be kept and made legible, not redesigned away.
- None ties offers to **genuine progress moments** (completion, block-end) with suppression rules; the industry default is banners. The contextual-offer bet has no proven pattern to copy — it must be instrumented as an experiment (measurement contract in the evidence ledger).
- None serves a **35–55 scholarly/practice audience in Russian**; register, type size, and density decisions cannot be borrowed — they are design decisions for the mockup phase.

_Dr. Mārcis Gasūns_
