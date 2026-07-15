# Student cabinet remake — M.G. rulings and four architecture directions (H822)

_Created: 12-07-2026 · Last updated: 14-07-2026_

Phase 3–4 of [H822](https://github.com/gasyoun/Uprava/blob/main/handoffs/H822-Fable_Systema-Sanscriticum_student-cabinet-custdev-ux-remake_12.07.26.md).
Authored by Fable 5 (`claude-fable-5`) after a 20-question / 5-round decision interview with M.G.
on 12-07-2026. Evidence base: [STUDENT_CABINET_REMAKE_RESEARCH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md);
comparators: [STUDENT_CABINET_EDTECH_COMPARISON_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md).

## 1. M.G. rulings (12-07-2026) — the design constitution

Every direction below honors all of these; they are settled, not open.

| # | Ruling | Answer | Note |
|---|---|---|---|
| R1 | North-star metric | **Выручка на активного ученика** (через продолжение → продление → следующий шаг) | design conflicts resolve toward continuity+renewal surfaces |
| R2 | Commercial presence | **Офферы только в моменты прогресса** | no persistent commercial surfaces except R8; debt/frustration = unconditional suppression |
| R3 | Primary segment | **Возвращающееся ядро 35–55, мобайл** | defaults tuned for this segment |
| R4 | Expired access | **Видимая карточка + продление** (цена/рассрочка на карточке) | closes ledger F3; GetCourse pattern |
| R5 | 30-second job | **Продолжить + ближайшее живое занятие** — оба видимы сразу | not the single-action Duolingo extreme |
| R6 | Product model | **Живое первично, записи равноправны** («в своем темпе» на всем записанном) | |
| R7 | Lesson progress | **Гибрид**: авто-«просмотрено» (heartbeat) + ручное «усвоено» одним кликом | honest multi-state, no gamified mastery |
| R8 | Membership (priced by a private M.G. ruling; not launched) | **Постоянный слот в библиотеке записей** | diverges from my recommendation (lapsed/completion-only); accepted as ruling — the ONLY persistent offer surface |
| R9 | Architecture depth | **Полная пересборка вокруг курса** (course workspace) | the boldest option, chosen deliberately |
| R10 | Tools (прана, словари, SRS) | **Вторичная секция «Инструменты»** | out of the learning/money first plane |
| R11 | Navigation language | **Переименовать по работам** (Сегодня / Мои курсы / Расписание / Записи и материалы / Помощь / Платежи) | Sanskrit stays in URLs/decor, not nav |
| R12 | Onboarding checklist | **Компактный прогрессивный чеклист** («шаг 2 из 4») ПОД continue-картой | not dissolved — kept as a distinct compact entity |
| R13 | Mobile navigation | **Нижняя панель внутри курса**, переключатель курсов сверху | preps the Capacitor wrapper (H824) |
| R14 | Visual tone | **Светлый академизм** (воздух, серифные акценты, сдержанная палитра) | respects the strict register without alienating the warm |
| R15 | Sanskrit identity | **Минимальная** — логотип и контент курсов; интерфейс нейтрален | diverges from my recommendation (деликатная); accepted |
| R16 | Density/accessibility | **Крупная база 18px+**, контраст AA+, цели ≥44px | 45+ eyes, mobile |
| R17 | Offer types allowed | **Все четыре**: следующий блок/продление · следующий курс лестницы · записи+книги · консультация+реферал | still ≤1 primary offer per state (handoff guardrail) |
| R18 | First mockup | **Course workspace** | not the winner — the first exploration |
| R19 | Support scope | **Полный пакет**: пикер тем + web-FAQ + «Почему закрыто?» | adopts audit D flows A+B+C |
| R20 | Rollout | **Большой релиз после утверждения** | diverges from my staged-canary recommendation; accepted with one engineering consequence — instrumentation must ship BEFORE the switch, or the release is unmeasurable (ledger F12). Recorded, not re-litigated. |

Second round (14-07-2026, after reviewing mockup #1, [Systema PR #514](https://github.com/gasyoun/Systema-Sanscriticum/pull/514) merged):

| # | Question | M.G. ruling | Notes |
|---|---|---|---|
| R21 | Mockup #2 | **Итерация направления B** («Курс как дом») — не новое направление | A/C/D остаются документированными, без мокапов до следующего рулинга |
| R22 | Iteration scope | **Добить недостающие слои**: библиотека записей + слот членства, календарь, прогресс+сертификат, сообщения | v2 = 8 страниц против 4 в v1 |
| R23 | Critique of v1 | Менять **всё три**: визуальный тон/плотность · структуру страниц · тексты и названия | v2 — издательский академизм, композитная лента «Сегодня», навигация по работам |
| R24 | Membership card | **Полноценная карточка с ценой** (2000 ₽/мес · 20 000 ₽/год) + состав + честное «скоро» | цена публикуется по прямому рулингу (альтернатива «без цены» была явно предложена и отклонена) |
| R25 | Fidelity | **Лёгкий JS**: рабочие табы (hash-адресация), переключатель темы (localStorage), сворачиваемые блоки | по-прежнему без внешних запросов, console-clean |

Offer guardrails (handoff-mandated, constant across directions): no dark patterns; no
irrelevant shop carousel; at most one primary contextual offer per state; paid access/support
problems take precedence over any offer; purchased content never looks locked to manufacture
urgency; clear price/entitlement/cancellation language; installments and benefit price always
shown with the price (measured positive lift, ledger M5); no deadlines/scarcity ever
(measured negative lift, ledger M5).

## 2. The four directions

All four honor R1–R20; they differ on the **architectural center of gravity** — what the
cabinet fundamentally *is*. Direction B is the first mockup per R18; A, C, D remain
documented alternatives whose strongest organs can be grafted onto B later.

Common shell (all directions): job-named navigation (R11), tools demoted to «Инструменты»
(R10), expired-access visible cards (R4), hybrid progress (R7), support package (R19),
light academism 18px+ (R14–R16), messages with honest server-side unread (fixes F11),
cabinet moved onto the Vite build (fixes F13).

### Direction A — «Сегодня» (Today-first learning coach)

**Center of gravity:** the session, not the course. The home is an adaptive «Сегодня» screen
computing a prioritized day plan: continue-point + nearest live + due homework + one recovery
action. Courses are secondary destinations reached through the plan.

- **Sitemap:** Сегодня → (Мои курсы / Расписание / Записи / Помощь / Платежи / Инструменты / Профиль). Course pages exist but are thin lesson lists.
- **Key flows:** login → Сегодня (continue card + live chip visible ≤30 с, R5); homework returned → Сегодня shows «доработать»; debt → Сегодня leads with the debt banner (offers suppressed).
- **Mobile:** bottom bar = Сегодня · Курсы · Расписание · Помощь; course switcher unnecessary (the plan spans courses).
- **Components:** ContinueCard (exists, PR #353) promoted to page spine; DayPlanList; LiveChip; compact checklist (R12) as a plan item group.
- **Selling model:** offers appear only as plan items after progress events («блок 3 завершен → следующий блок») — the purest R2 reading.
- **Data/engineering:** cheapest build — `buildContinueLearningAction()` already computes the spine; needs plan-item ranking + per-tab page extraction.
- **Answers evidence:** F1/F2 (competition at top), M1 (continuity=money), M7 (rhythm).
- **Sacrifices:** course identity is weak — a student «living in» Yoga-Sutras never gets a home; multi-course students (a large share, ledger M2) see a merged plan that can feel noisy.
- **Risk:** the plan algorithm becomes an opaque authority; a wrong priority erodes trust exactly where the audience punishes it.

### Direction B — «Курс как дом» (Course workspace) — FIRST MOCKUP (R18/R9)

**Center of gravity:** the course. Each course is a coherent workspace — уроки, расписание
потока, записи, материалы, домашние, помощь и продление живут ВНУТРИ курса. The cabinet home
shrinks to a course switcher + cross-course essentials.

- **Sitemap:** Главная (карточки курсов с continue-точкой каждого + ближайшее живое поверх, R5) → Курс {slug}: Обзор («продолжить здесь», прогресс, ближайшее занятие потока) / Уроки (live-vs-запись бейджи, R6) / Материалы / Домашние / Доступ и продление / Помощь-в-курсе. Глобально: Расписание (все курсы), Платежи, Инструменты (R10), Профиль.
- **Key flows:** returning learner → Главная → карточка курса «Продолжить: Урок 7» (one tap); recording-only buyer → the same workspace, schedule pane collapsed, «в своем темпе» badge on; expired → the course card stays with «доступ истек · продлить / рассрочка» (R4, price shown in-product) and the workspace opens in archive mode (materials list visible, lessons locked with «Почему закрыто?» explainers, R19).
- **Mobile (R13):** inside a course — bottom bar «Обзор · Уроки · Материалы · Помощь»; course switcher = top dropdown; global home = card stack.
- **Components:** CourseCard (continue + status + expiry), WorkspaceTabs (server-routed, URL-per-tab — kills the untrackable-tabs defect), LessonRow (state: живое/запись/заблокировано + «почему»), AccessPanel (оплачено-до, продление, рассрочка), InCourseSupport (picker pre-scoped to the course, R19).
- **Selling model:** the offer moment lives at block/course completion inside the workspace («следующий блок» / «следующий курс лестницы», R17); membership slot only in the global Записи library (R8). One primary offer per state.
- **Data/engineering:** biggest routing change — per-course sub-routes; access/expiry needs `Group`-lapse detection surfaced (today it's a silent query filter); support picker needs conversation topics. Payments stay global (money code untouched).
- **Answers evidence:** F3/F4 (access states live where the course lives), F5 (continue on course page), C-series lesson findings, M2/M3 (ladder offers at the completion moment), the multi-course reality — a large share of students own several courses (each course a home, switcher for the rest).
- **Sacrifices:** cross-course «what now?» requires the extra home hop; day-level rhythm is less visible than in A; more routes to build.
- **Risk:** for 1-course students the switcher is overhead; workspace must degrade gracefully to «the course IS the cabinet».

### Direction C — «Библиотека» (Learning library)

**Center of gravity:** ownership. The cabinet is a personal library of владения — live cohorts,
owned recordings, materials, tools — with strong shelf distinctions and the membership slot
as the library's standing upgrade (R8 fits natively here).

- **Sitemap:** Библиотека (полки: Идут сейчас / Мои записи / Материалы / Завершенные / Истекшие-с-продлением) → предмет → уроки; Расписание; Помощь; Платежи.
- **Key flows:** recording-only buyer lands on «Мои записи» with progress-as-navigation (Khan pattern); lapsed sees the «Истекшие» shelf with renewal cards (R4 made structural); membership renders as a shelf-level card «вся библиотека записей по подписке» (R8).
- **Mobile:** bottom bar = Библиотека · Расписание · Помощь · Профиль; shelves are horizontal scrollers.
- **Components:** Shelf, OwnedItemCard (with expiry ribbon), ProgressRail, MembershipCard.
- **Selling model:** ownership-expansion first (записи прошедших курсов, книги, R17), membership persistent (R8) — the most commercially legible direction.
- **Data/engineering:** needs an «owned recordings» catalog distinct from group-access lessons (today recordings are just lessons in groups) — a real model change; expiry states as in B.
- **Answers evidence:** M6 (time objection → своя библиотека, свой темп), M2 (multi-course ownership = real libraries), R8's membership economics.
- **Sacrifices:** live cohort rhythm becomes one shelf among several — weakens R6's «живое первично»; homework/curator interaction has no natural home.
- **Risk:** frames the school as a content archive, diluting its differentiator (живой учитель) — the strict register may read it as a video shop.

### Direction D — «Путь» (Journey & membership hub)

**Center of gravity:** the trajectory. The cabinet renders the school's ladder
(письмо → грамматика → тексты; measured bridge M3) as a visible path; courses are stations,
membership is continuity between them.

- **Sitemap:** Мой путь (карта уровней: пройдено/текущее/следующее) → станция = курс (внутри — уроки/расписание/материалы); Записи; Помощь; Платежи.
- **Key flows:** completion → the path lights the next station with the ladder offer (M3: grammar entry yields the deepest, highest-LTV ladders); lapsed → the path shows the gap honestly + return point (M7); newcomer → the path shows where the trial sits.
- **Mobile:** the path is the home scroller; bottom bar as in B inside stations.
- **Components:** PathMap, StationCard, LadderOfferCard, MilestoneRow (attestation dates — the learning-contour deadline M.G. ruled IS allowed as motivation).
- **Selling model:** the ladder is the product — the most aggressive legitimate monetization; membership as path-continuity between paid stations.
- **Data/engineering:** requires an explicit curriculum graph (today only `TrajectoryPaths` on the public homepage approximates it) — the heaviest data-model lift; risk of inventing a taxonomy the catalog doesn't really have.
- **Answers evidence:** M3 (ladder LTV), the marathon funnel's «цель → маршрут» quiz, learning-contour deadlines ruling.
- **Sacrifices:** students who deliberately zig-zag (yoga → chants → jyotish) don't fit a linear path; recording-only buyers see a path they never asked to walk.
- **Risk:** prescriptive framing can read as upsell pressure despite R2 — the path must never shame the off-path student.

## 3. Page-layer matrix

How each cabinet surface changes per direction (rows = the handoff's mandatory page family):

| Surface | A «Сегодня» | B «Курс как дом» | C «Библиотека» | D «Путь» |
|---|---|---|---|---|
| Global shell/nav | Сегодня-first, 6 job items | thin home + in-course bottom bar | shelf nav | path-first |
| Home | adaptive day plan | course switcher + live chip | shelves | path map |
| Course home | thin list | **workspace: обзор/уроки/материалы/доступ/помощь** | shelf item → list | station |
| Lesson | unchanged player + R7 progress | player inside workspace, R7, transcript above homework on mobile (fixes C-L1) | player + progress rail | player |
| Calendar/live | merged into Сегодня + global page | per-course pane + global page | global page | per-station + global |
| Recordings/resources | plan items | Материалы tab per course | **first-class shelves** | station resources |
| Messages/support | Помощь global | in-course picker + global FAQ | global | global |
| Payments/access | global page | **Доступ и продление in-course** + global | expiry ribbons on shelves | station access |
| Progress/completion | plan history | workspace обзор ring | progress rails | **the path itself** |
| Discovery/offers | plan-item offers | completion-moment offers in-course; membership in Записи (R8) | ownership expansion + persistent membership | ladder stations |
| Mobile nav | bottom bar global | bottom bar in-course + switcher | bottom bar global | path scroller + station bars |

## 4. What happens next (decision-gated)

Per R18 the **Course workspace** mockup is built first —
[docs/mockups/student-cabinet-remake/course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace).
The remaining three directions stay paper until M.G. reacts to the first mockup (handoff:
«do not build all four in one uninterrupted burst»). The winning direction is an explicit
M.G. `@DECIDE` — this document does not rank the four.

_Dr. Mārcis Gasūns_
