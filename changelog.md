# Changelog

All notable changes to Systema-Sanscriticum are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(since `v1.0.1`, 2026-07-03). Keep upcoming work under `[Unreleased]`; each
release is promoted to a version, tag, and GitHub release in the same pass.
Sections dated 2026-07-09 (`[1.1.0]`) and earlier were reconstructed from git
history on 2026-07-12 (backfill) — they document work that already shipped.

## [Unreleased]

### Added
- **FAQ-суггестер v2 — LLM-черновики для категорий D/E/F (H816 PR 1, тикет S5).**
  Расширяет фактологический суггестер v1 (A/B/C, без LLM) на самые частотные
  «человеческие» категории: D «оплата/цена/тарифы» (7.4% FAQ), E «доступ/группа/
  кабинет», F «материалы/ДЗ/сертификаты». Детект — дешёвым regex-префильтром;
  ЦИФРЫ берутся из кода LMS (тариф через `Tariff::calculateFinalPriceForUser()` —
  единственный источник истины по цене, активные группы, число опубликованных
  уроков), а внешний LLM (`CuratorAi`/OpenRouter) лишь ФОРМУЛИРУЕТ из них черновик.
  Как и v1, бот ничего не отправляет — только заводит pending
  `SupportAnswerSuggestion` куратору. Три страховки: флаг `support_ai_assist`
  (иначе категория опознана, но черновик не строится); дневной cap LLM-вызовов
  (`MarketingSetting.support_ai_daily_cap` → дефолт `config('features.support_ai_daily_cap')`,
  считается по событиям `answer_llm_drafted`); приватность — сырой текст
  импортированного Telegram-ЛС уходит в LLM только при `support_ai_include_telegram`
  (факты LMS — всегда). Новый `SupportLlmDraftComposer`; миграция
  `marketing_settings.support_ai_daily_cap` (nullable, аддитивная). Всё за флагами,
  OFF по умолчанию — прод не затронут. Feature-тесты с фейковым LLM (Http::fake),
  19/19 green; полный `tests/Feature/Support` — 79/79.
- **Планировщик анонсов — `scheduled_at` (H816 PR 2).** Раньше анонс
  рассылался СИНХРОННО при создании (`CreateAnnouncement::afterCreate`) — отсюда
  аврал перед запуском. Теперь у анонса есть `scheduled_at` (пусто = «отправить
  сразу»): рассылка по каналам email/Telegram/VK уходит, когда наступит срок,
  командой `announcements:dispatch-due` (в `Kernel::schedule()`, каждые 5 минут).
  Логика рассылки вынесена из Filament-страницы в переиспользуемый
  `App\Services\AnnouncementDispatcher`; идемпотентность — по `dispatched_at`
  (один анонс не уходит дважды). Поле «Запланировать рассылку на» + колонка
  «Запланировано» в админке (Рассылки). Аддитивная миграция
  `announcements.scheduled_at`/`dispatched_at` (обе nullable) — существующие
  немедленные рассылки идут тем же путём, ничего не ломается. Feature-тесты
  `AnnouncementSchedulerTest` — 6/6 (due→рассылка+дедуп, future→тишина,
  unpublished/без-канала→тишина, немедленная через диспетчер).

## [1.3.0] - 2026-07-13

### Added
- **Разблокировка застрявшего студента одним кликом + лента «Проблемы со входом» (H849).**
  До сих пор неудачные попытки входа/восстановления НИГДЕ не логировались.
  Теперь: (1) новая таблица `access_attempts` собирает единой лентой неудачные
  логины (слушатель `Auth\Events\Failed` на `/login` и `/shop/login`) и запросы
  ссылки восстановления (`reset_sent`/`reset_not_found`/`reset_throttled`,
  логируются в [`PasswordResetController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php));
  (2) Filament-ресурс «Проблемы со входом» ([`AccessAttemptResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/AccessAttemptResource.php))
  с бейджем «застрявших» и разблокировкой из строки, плюс кнопка «Разблокировать»
  на карточке студента ([`UserResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource.php));
  (3) разблокировка = снять IP-троттл + выдать **одноразовую magic-ссылку для входа**
  (24 ч, hashed-at-rest, назначение `admin_unblock`, маршрут `/login-link/{token}`),
  которую админ передаёт студенту напрямую, минуя сломанную почту (+ опц. сброс
  пароля) — [`StudentUnblockService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Access/StudentUnblockService.php);
  (4) Telegram: проактивный алерт админам с inline-кнопкой «🔓 Выслать ссылку»
  при сигнале «застрял» (троттл восстановления / серия неудачных логинов) +
  текстовая команда `/unblock <email>` — авторизация строго `super_admin`/`admin`
  ([`UnblockBotCommand`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Bot/UnblockBotCommand.php),
  [`TelegramWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php)).
  Проактивные алерты идут на `ADMIN_TELEGRAM_ID`. Не устраняет корневую причину
  недоставки писем (боевой SMTP) — но даёт админу обойти её вручную. Документация:
  [`docs/student-unblock-access-feed.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-unblock-access-feed.md).

## [1.2.1] - 2026-07-13

### Fixed
- **Password-reset «Слишком много попыток» на первой попытке (H840).** Брокер
  Laravel возвращает `RESET_THROTTLED`, когда ссылку для входа уже отправили
  меньше минуты назад (per-email троттл, `config/auth.php`), — это НЕ перебор.
  Прежняя красная ошибка «Слишком много попыток. Подождите минуту» пугала
  студента на фактически первой попытке (письмо часто просто в «Спаме» или
  задержалось). Теперь этот случай показывает тот же зелёный блок «мы уже
  отправили ссылку — проверьте почту и „Спам“, не пришло за 5 минут — запросите
  снова», что и успешная отправка ([`PasswordResetController::sendResetLink`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PasswordResetController.php)).
  UX-правка формулировки; корневая причина недоставки писем (боевой SMTP/домен
  отправителя) остаётся отдельным серверным вопросом.

## [1.2.0] - 2026-07-12

### Added
- **Native live-chat support widget (H536), Phases 1–5 complete + observability.**
  Laravel Reverb WebSocket transport (`ChatMessageSent` on the private
  `support.conversation.{id}` channel, [PR #432](https://github.com/gasyoun/Systema-Sanscriticum/pull/432));
  guest identity — an anonymous samskrte.ru visitor owns a thread via a session
  `guest_token` (ephemeral ownership marker, **not** a 4th external-identity
  mapping; `chat_messages.user_id` now nullable; `chat_guest` broadcasting guard),
  [PR #461](https://github.com/gasyoun/Systema-Sanscriticum/pull/461); rate-limited
  public post endpoint (`POST /chat/message`, `GET /chat/history` via
  `PublicChatController`), [PR #463](https://github.com/gasyoun/Systema-Sanscriticum/pull/463);
  storefront visitor bubble, [PR #468](https://github.com/gasyoun/Systema-Sanscriticum/pull/468);
  guest web-chat in the operator inbox with live reply,
  [PR #470](https://github.com/gasyoun/Systema-Sanscriticum/pull/470); and a
  support observability dashboard — session health, sync lag, delivery rate, LLM
  volume (H597), [PR #469](https://github.com/gasyoun/Systema-Sanscriticum/pull/469).
  A guest never resolves to a `users` row (no account-takeover); output stays
  escaped via `ChatMessage::htmlForWeb()`. Live once Reverb is deployed on the host.
- **3-day diagnostic marathon «Консультация по онлайн-курсам ОРС» (H440), all 6 phases.**
  Landing + capture with a personal `day0_started_at` clock (anti-urgency),
  [PR #407](https://github.com/gasyoun/Systema-Sanscriticum/pull/407); drip engine
  with Day 1/2 Telegram content, [PR #410](https://github.com/gasyoun/Systema-Sanscriticum/pull/410);
  genuine tap-choice UI for Days 1/2, [PR #421](https://github.com/gasyoun/Systema-Sanscriticum/pull/421);
  paid track (₽500) checkout via Tochka, [PR #415](https://github.com/gasyoun/Systema-Sanscriticum/pull/415);
  live Day-3 consultation + recording delivery, [PR #423](https://github.com/gasyoun/Systema-Sanscriticum/pull/423);
  and a 13-day evergreen warm-tail (Days 4–16) that auto-stops once `paid_at` is
  set, [PR #424](https://github.com/gasyoun/Systema-Sanscriticum/pull/424).
- **Cohort-aware marathon engine (H445):** cohort core
  ([PR #436](https://github.com/gasyoun/Systema-Sanscriticum/pull/436)), a
  level-quiz for the Devanagari cohort ([PR #438](https://github.com/gasyoun/Systema-Sanscriticum/pull/438)),
  and Day-1 name-in-Devanagari for that cohort ([PR #446](https://github.com/gasyoun/Systema-Sanscriticum/pull/446)).
- Selling-layout journey layer on the homepage (H431 Phase 1): hero rebuilt around
  a three-path learning trajectory (Письмо/чтение → Грамматика → Тексты/чанты)
  resolved to real courses, a «Почему мы» credentials block, and a proof block
  (years/books/crowdfunding from `config/trust.php` + real testimonial slots).
  [PR #427](https://github.com/gasyoun/Systema-Sanscriticum/pull/427)
- Configurable CRM lead stages (GC-C1): a `lead_stages` table replaces the
  hardcoded `Lead::STATUSES`/`FINAL_STATUSES`, plus a Filament drag-drop kanban
  board (`/admin/leads-board`). [PR #408](https://github.com/gasyoun/Systema-Sanscriticum/pull/408)
- SRS «Saraswati» trainer suite, Phase 1 enable-and-connect (H447).
  [PR #442](https://github.com/gasyoun/Systema-Sanscriticum/pull/442)
- Sanskrit interactive exercises: a sort-into-groups engine + genders drill and
  generator (H551, [PR #441](https://github.com/gasyoun/Systema-Sanscriticum/pull/441))
  and a noun↔pronoun gender-agreement sort drill (H561,
  [PR #449](https://github.com/gasyoun/Systema-Sanscriticum/pull/449)).
- Consolidated attendance dashboard (GC-B2, H553).
  [PR #444](https://github.com/gasyoun/Systema-Sanscriticum/pull/444)
- Self-reported signup-source capture at registration (H476).
- Telegram support-userbot healthcheck + documented the missing `schedule:run`
  cron entry (H595, [PR #471](https://github.com/gasyoun/Systema-Sanscriticum/pull/471));
  class-link-autopost env killswitch wired (H593,
  [PR #467](https://github.com/gasyoun/Systema-Sanscriticum/pull/467)); MadelineProto
  IPC self-heal (kill a stale daemon on dead IPC instead of retrying in-process).
- Debt payment tariff keys so an installment opens only its own block and a real
  bundle tariff covers multi-block (H393). [PR #409](https://github.com/gasyoun/Systema-Sanscriticum/pull/409)
- A trial can now open a past class recording, not only an upcoming class.
- Mobile app (Android/iPhone student cabinet) roadmap 2026–2027
  ([docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_MOBILE_APP_STUDENT_CABINET_2026_2027.md)):
  decision-locked plan for a **Capacitor hybrid wrapper** around the existing web
  cabinet (reuse-not-rebuild). MG rulings (12-07-2026): hybrid wrapper; MVP =
  courses/lessons/progress + lesson video + push + live chat; purchases stay on
  web (no store 30% cut); login email+pw + Telegram + VK, iOS email-only (Apple
  4.8); Google Play first, App Store later. Wave 1 (Capacitor scaffold) queued as H824.
  [PR #485](https://github.com/gasyoun/Systema-Sanscriticum/pull/485)

### Fixed
- `GET /login` for an already-authenticated user rendered the login form instead
  of redirecting; `AuthController::showLoginForm()` now short-circuits logged-in
  visitors (student → `/dvaram`, admin → `/admin`). Regression test
  `tests/Feature/LoginRedirectTest.php` (H806). [PR #480](https://github.com/gasyoun/Systema-Sanscriticum/pull/480)
- Marathon warm-tail never fabricates testimonial quotes
  ([PR #434](https://github.com/gasyoun/Systema-Sanscriticum/pull/434)); off-by-one
  in Day-5 testimonial warm-tail tests ([PR #437](https://github.com/gasyoun/Systema-Sanscriticum/pull/437));
  three red-main test fixes ([PR #450](https://github.com/gasyoun/Systema-Sanscriticum/pull/450));
  regenerated `package-lock.json` for the Reverb deps ([PR #443](https://github.com/gasyoun/Systema-Sanscriticum/pull/443)).

## [1.1.1] - 2026-07-09

### Added
- Selling-layout roadmap adopted for samskrte.ru: 13-layer teardown vs
  sanskritorium.ru and samskrtam.ru + 6-phase plan (hero trajectory,
  «почему мы» + proof blocks, recorded-catalog conversion, free funnel,
  art direction, samskrtam.ru retrofit, book checkout). Spec:
  [SELLING_LAYOUT_COMPARISON_2026.md](https://github.com/gasyoun/Uprava/blob/main/custdev/SELLING_LAYOUT_COMPARISON_2026.md)
  (private hub); Phase 1 queued as H431.

## [1.1.0] - 2026-07-09

Large accumulated feature run merged to `main` (June–July 2026). Reconstructed
from git history on 2026-07-12 — the original one-line snapshot understated ~3
weeks of shipped work.

### Added
- **Financial cockpit (Финансовый штурвал).** Student unit economics — LTV/CAC/
  retention/churn/payback (H256, [PR #340](https://github.com/gasyoun/Systema-Sanscriticum/pull/340));
  accrual P&L (ОПиУ) + Expense/opex model (H207, [PR #311](https://github.com/gasyoun/Systema-Sanscriticum/pull/311));
  accrual revenue recognition via `RevenueSchedule` (H258, [PR #370](https://github.com/gasyoun/Systema-Sanscriticum/pull/370));
  receivables & installments governance — plan-fact + threshold + alert (H257);
  profit funds + delegation-KPI panel + review rhythm (H259,
  [PR #373](https://github.com/gasyoun/Systema-Sanscriticum/pull/373));
  order→payment conversion + unclosed-orders list (H262,
  [PR #378](https://github.com/gasyoun/Systema-Sanscriticum/pull/378));
  revenue-reversal of the unrecognized balance on refund (H352,
  [PR #376](https://github.com/gasyoun/Systema-Sanscriticum/pull/376)).
- **Payments & access.** Deposit transfer between courses
  ([PR #356](https://github.com/gasyoun/Systema-Sanscriticum/pull/356)); PayPal
  overseas payment claims ([PR #278](https://github.com/gasyoun/Systema-Sanscriticum/pull/278));
  Dolyame in the payment-method badge/filter; a payment-method column & filter
  (H226); corpus Sa→Ru glossary enrichment on `/slovar` entity pages (flag off,
  H344, [PR #372](https://github.com/gasyoun/Systema-Sanscriticum/pull/372)).
- **Debtor self-service.** Student debt pay-off Phase 1
  ([PR #293](https://github.com/gasyoun/Systema-Sanscriticum/pull/293)) and Phase 2
  — multi-block, bundle, prana, partial, reschedule (H171,
  [PR #295](https://github.com/gasyoun/Systema-Sanscriticum/pull/295)).
- **Support automation.** `SupportAnswerSuggester` v1 — LLM-free fact drafts of
  FAQ answers (H247/S3, [PR #339](https://github.com/gasyoun/Systema-Sanscriticum/pull/339));
  auto-post the Zoom link to the group chat before class (P0,
  [PR #333](https://github.com/gasyoun/Systema-Sanscriticum/pull/333));
  `support:topic-ranking` for self-serve prioritisation
  ([PR #301](https://github.com/gasyoun/Systema-Sanscriticum/pull/301)); scheduled
  per-student reminders + a curator approval queue.
- **Enrollment & groups.** Waitlist/intake module — data layer, Filament board,
  CSV importer (H230, [PR #330](https://github.com/gasyoun/Systema-Sanscriticum/pull/330));
  group-recruitment shortfall notifications (H162); CRM assistant ergonomics —
  fewer clicks, funnel guards, helpdesk tabs (H223,
  [PR #324](https://github.com/gasyoun/Systema-Sanscriticum/pull/324)).
- **Growth.** Registration/payment attribution — UTM/referrer → `Lead` + birth
  year (A1, [PR #347](https://github.com/gasyoun/Systema-Sanscriticum/pull/347));
  M1 sale of recordings of completed courses (flag off,
  [PR #344](https://github.com/gasyoun/Systema-Sanscriticum/pull/344)); B2B partner
  (agent) referral program (H292, [PR #349](https://github.com/gasyoun/Systema-Sanscriticum/pull/349))
  + SEO-clean referral path `/mitram/{code}` ([PR #350](https://github.com/gasyoun/Systema-Sanscriticum/pull/350));
  payment-discipline score per student/group ([PR #305](https://github.com/gasyoun/Systema-Sanscriticum/pull/305));
  a multi-channel weekly nudge for never-logged-in students ([PR #316](https://github.com/gasyoun/Systema-Sanscriticum/pull/316));
  email-only newsletter subscribe → magic-link cabinet user (H324,
  [PR #361](https://github.com/gasyoun/Systema-Sanscriticum/pull/361)).
- **SEO.** Dictionary entity pages `/slovar` (Wave 0, noindex, H204,
  [PR #308](https://github.com/gasyoun/Systema-Sanscriticum/pull/308)); structured
  data — Article author as Person + mainEntityOfPage ([PR #307](https://github.com/gasyoun/Systema-Sanscriticum/pull/307)),
  Course `hasCourseInstance` carousel ([PR #306](https://github.com/gasyoun/Systema-Sanscriticum/pull/306));
  P2 curated-core allowlist + Wikidata `sameAs` matcher (H210,
  [PR #374](https://github.com/gasyoun/Systema-Sanscriticum/pull/374)).
- **Backup & ops.** Weekly backup expanded from DB-only to DB + file storage with
  a Yandex Disk off-site destination (H364,
  [PR #377](https://github.com/gasyoun/Systema-Sanscriticum/pull/377) / [PR #343](https://github.com/gasyoun/Systema-Sanscriticum/pull/343));
  a goal check-in loop / standup rhythm for delegated leads (H376).
- **Telegram harvester (Track B).** Sync driver ([PR #286](https://github.com/gasyoun/Systema-Sanscriticum/pull/286))
  + media metadata / peer discovery / noforwards hardening ([PR #289](https://github.com/gasyoun/Systema-Sanscriticum/pull/289)).

### Fixed
- **Money-core.** Block a second pending order on the same course while a deposit
  is unspent (H071 #2, [PR #342](https://github.com/gasyoun/Systema-Sanscriticum/pull/342));
  partial deposit consumption + deposit-aware upgrade credit (H071 #9+#10);
  referral reward died from a relation shadowed by a `users.referrer` column (A1);
  reverse the referral reward on payment rollback ([PR #258](https://github.com/gasyoun/Systema-Sanscriticum/pull/258));
  reward only for a real course payment, not deposit/trial/conditional/₽0
  ([PR #251](https://github.com/gasyoun/Systema-Sanscriticum/pull/251)); a canceled
  payment refunds prana + referral credit ([PR #248](https://github.com/gasyoun/Systema-Sanscriticum/pull/248)).
- **Access.** A VIP/bundle tariff unlocks lessons via `accessKey()` not the raw
  type ([PR #250](https://github.com/gasyoun/Systema-Sanscriticum/pull/250)); the
  homework-submission gate honours `LessonAccessGrant` (paid trial etc.,
  [PR #255](https://github.com/gasyoun/Systema-Sanscriticum/pull/255)).
- **Security.** Wave 3 automated defense — PHP SAST + adversarial-review harness
  (H081); audit fixes — fail-closed webhooks, anti-takeover checkout, verified
  email in social auth; VK-IDOR closed via a one-time link token
  ([PR #173](https://github.com/gasyoun/Systema-Sanscriticum/pull/173)).

## [1.0.1] - 2026-07-03

Foundational LMS build (May–July 2026). Reconstructed from git history on
2026-07-12; this tag previously had no changelog section.

### Added
- **Mobile API** for the student cabinet on Sanctum personal-access tokens (`/api/v1`).
  [PR #167](https://github.com/gasyoun/Systema-Sanscriticum/pull/167)
- **Referral & prana gamification.** Referral program with a prana reward (H168,
  [PR #168](https://github.com/gasyoun/Systema-Sanscriticum/pull/168)) → money
  credit alternative ([PR #201](https://github.com/gasyoun/Systema-Sanscriticum/pull/201));
  achievement badges ([PR #204](https://github.com/gasyoun/Systema-Sanscriticum/pull/204)),
  leaderboard ([PR #202](https://github.com/gasyoun/Systema-Sanscriticum/pull/202)),
  streak rewards ([PR #206](https://github.com/gasyoun/Systema-Sanscriticum/pull/206)),
  a prana shop ([PR #207](https://github.com/gasyoun/Systema-Sanscriticum/pull/207)),
  a two-counter discount-wallet + accumulating rank ([PR #170](https://github.com/gasyoun/Systema-Sanscriticum/pull/170)),
  P2P transfer + weekly decay ([PR #171](https://github.com/gasyoun/Systema-Sanscriticum/pull/171) / [PR #180](https://github.com/gasyoun/Systema-Sanscriticum/pull/180)).
- **Social auth.** Socialite scaffold ([PR #169](https://github.com/gasyoun/Systema-Sanscriticum/pull/169))
  + VK / Yandex community drivers ([PR #208](https://github.com/gasyoun/Systema-Sanscriticum/pull/208)).
- **Webinars (Zoom).** Auto-create meetings from the schedule ([PR #194](https://github.com/gasyoun/Systema-Sanscriticum/pull/194)),
  auto-import recordings via the `recording.completed` webhook ([PR #195](https://github.com/gasyoun/Systema-Sanscriticum/pull/195)),
  attendance via participant webhooks ([PR #197](https://github.com/gasyoun/Systema-Sanscriticum/pull/197)).
- **Lecture editor.** Async pipeline ([PR #184](https://github.com/gasyoun/Systema-Sanscriticum/pull/184)),
  structural editing — move/delete/split/merge ([PR #186](https://github.com/gasyoun/Systema-Sanscriticum/pull/186)),
  advisory lock + backup rollback ([PR #189](https://github.com/gasyoun/Systema-Sanscriticum/pull/189)),
  add-block ([PR #210](https://github.com/gasyoun/Systema-Sanscriticum/pull/210)).
- **Shop / course pages.** Public course landing pages, schedule block + carousel
  ([PR #187](https://github.com/gasyoun/Systema-Sanscriticum/pull/187) / [PR #192](https://github.com/gasyoun/Systema-Sanscriticum/pull/192)),
  «Записаться/Купить» CTA cards ([PR #191](https://github.com/gasyoun/Systema-Sanscriticum/pull/191)),
  Arzamas-style category chips ([PR #174](https://github.com/gasyoun/Systema-Sanscriticum/pull/174)),
  a typographic cover fallback ([PR #175](https://github.com/gasyoun/Systema-Sanscriticum/pull/175)),
  a «next lesson» card on `/dvaram` ([PR #177](https://github.com/gasyoun/Systema-Sanscriticum/pull/177)).
- **Cabinet & CRM.** In-cabinet support web-chat ([PR #165](https://github.com/gasyoun/Systema-Sanscriticum/pull/165));
  a teacher student-analytics dashboard ([PR #166](https://github.com/gasyoun/Systema-Sanscriticum/pull/166));
  stuck-student signals for curators ([PR #163](https://github.com/gasyoun/Systema-Sanscriticum/pull/163));
  segment messenger broadcast from the student list ([PR #164](https://github.com/gasyoun/Systema-Sanscriticum/pull/164));
  a bot hybrid-persona ([PR #200](https://github.com/gasyoun/Systema-Sanscriticum/pull/200));
  a read-only reactivation report ([PR #203](https://github.com/gasyoun/Systema-Sanscriticum/pull/203)).
- **Salary / teacher payouts.** Two teachers per course with independent pay terms
  + access; direct-to-teacher receipts (schema → capture → revenue exclusion →
  auto-offset in the payout calculator); currency conversion (PayPal) + teacher
  report; a block-participants dashboard.
- **Onboarding.** Email normalization + login self-check + dormant-student mailing
  ([PR #218](https://github.com/gasyoun/Systema-Sanscriticum/pull/218)); avatars
  from Telegram/VK; `@username` capture; attendance under a unified course Zoom link.

## [1.0.0] - 2026-06-13

### Added
- Added this changelog so repository-level changes have a stable home.
- Recorded the current repository purpose: Laravel-приложение: учебный кабинет, магазин курсов, конструктор лендингов, редактор лекций и панель администратора.

### Recent Git History
- 2026-05-29 ai-wip: add .pre-commit-config.yaml (yaml-only)
- 2026-05-29 ai-wip: add CodeQL SAST workflow (php, javascript)
- 2026-05-29 ai-wip: add .github/dependabot.yml for GitHub Actions auto-updates
- 2026-05-29 ai-wip: add CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- 2026-05-29 fix(ci): proper Vite manifest stub with entry keys

[Unreleased]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.1
[1.0.0]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.0
