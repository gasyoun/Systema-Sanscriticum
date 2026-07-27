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
- **Online Sanskrit games, Wave 2 — SRS onboarding deck + cabinet skill-drill strip (H1680).** On first login, `game_events` rows for this browser's `anon_id` are merged onto the new user (`GamesOnboardingImporter::mergeGuestEvents`, `login.blade.php` carries `anon_id` from localStorage), then up to **20 cards** are copied into a private per-user `onboarding-from-games` SRS deck from whichever completed `/lila` packs map to an existing canonical deck — `match/kochergina-l1` → `kochergina-lesson-1` (H1431), `roots/top-25|50|100` → `sanskrit-roots-frequency` (H1280); idempotent (`firstOrCreate` keyed by `fields->iast`, capped across calls). A toast ("Добавили N слов в повторения") flashes on the next dashboard render when cards were added. New cabinet **skill-drill strip** on the student dashboard links to five short `/lila` drills, independent of the FSRS review loop at `/dvaram/srs` — behind a new OFF-by-default flag `games_cabinet_skill_drills`. `SRS_ENABLED` untouched (deck data is written regardless; only route/UI visibility stays flag-gated, per the existing `SrsController` pattern). Deliberately reuses existing canonical decks rather than inventing new vocabulary data; `match/verb-roots` (real vocabulary, no canonical deck) is a known, logged gap — see Dev Notes. **Reopens the H1678 `game_events.user_id` tradeoff** (adds a nullable `user_id`, set only when the writing session is already authenticated) — MG-approved re-scope of the original H1680 spec after `/next-task` discovered it was unbuildable on H1678's privacy-first `game_events` shape; does not touch `anon_id`/IP/user-agent handling. Executor: Sonnet 5 (`claude-sonnet-5`).

- **HTML-мануал по играм Лила (docs/lila-games-manual.html).** Полное руководство: доступ гость/студент, шесть семейств, каталог ссылок, жесты, FAQ, блок для куратора; метадок lila-games-manual.meta.md; ссылка из student-manual §12. Executor: Grok 4.5 (grok-4.5).

- **Online Sanskrit games, Wave 1 — 5-play gate + CTA→register KPI + three P0 packs (H1678).** `/lila` gate widened from one global free play to **5 free plays per drill family** (`gate.js`, `sgx_plays_v2` localStorage key, family = first path segment under `/lila/`); authenticated users stay ungated. `GameEvent::ctaRegistrationRate()` computes the play→register KPI (locked D6: ≥15% of CTA clickers merge into an authenticated user within 7 days) from the **existing** `anon_id`/`authenticated` columns — deliberately without adding a `user_id` column, since `game_events` is intentionally kept outside the 152-ФЗ perimeter (see `the_table_stores_no_ip_or_user_agent_by_design`); surfaced in `games:funnel` and the Filament «Воронка тренажёров» page. New shared `public/lila/locale.js` (RU/EN string picker + toggle, default RU, falls back to RU when EN copy is missing). Three P0 packs: **G-C01** `sort/vowel-length` (24 curated CV syllables, short vs long vowel, no Devanāgarī), **G-C02** `match/iast-cyrillic` (40 pairs reused from the genders/verb-roots packs + a documented pedagogical Cyrillic transliteration), **G-C03** `match/kochergina-l1` (20 words from the H1431 Kochergina lesson-1 export, `database/seeders/data/memrise_6502608/level_02.csv`). Catalogue cards + free-banner copy updated. Non-goals held: P1 packs (H1679), SRS onboarding (H1680), csl-guides, new engines, audio, multiplayer — `SRS_ENABLED` untouched. Executor: Sonnet 5 (`claude-sonnet-5`).

- **Online Sanskrit games, Wave 1 — three P1 engine-fill packs (H1679).** **G-C04** `roots/ru-faces` (top-25 verbal roots, RU gloss ↔ IAST root match, no Devanāgarī on either face — for learners who don't read it yet); **G-C05** `ligatures/top-10` (existing pack extended with a hand-curated Cyrillic pronunciation hint alongside the IAST answer); **G-C06** `cloze/root-rank` (guess each of the top-25 roots' frequency-rank band, 5-wide bands computed at runtime from `ROOT_BANDS.top25` — no hand-authored blanks). All three reuse the existing `roots/data.js` / `ligatures/data.js` fixtures and the H1678 gate/telemetry/locale shell (`data-drill`/`data-band` ids, RU/EN toggle). Catalogue cards added to `roots/index.html` and `cloze/index.html`. New `tests/Feature/Exercises/P1PackPagesTest.php` (6 tests) covers file presence, gate/telemetry wiring, and the no-Devanagari/no-hand-authored-bands invariants. Non-goals held: no new `engine.js` family, no SRS import (H1680). Executor: Sonnet 5 (`claude-sonnet-5`).

- **Rename free drills path public/exercises/ → public/lila/ (URL /lila/).** Nav, gate/telemetry scripts, tests, manuals, Telegram drafts; nginx 301 /exercises/ → /lila/. Executor: Grok 4.5 (grok-4.5).
- **Nginx: index index.php index.html for /lila/ directory URLs (H1710 follow-up).** Bare paths like /lila/table/ no longer 403; docs/links drop forced index.html. Executor: Grok 4.5 (grok-4.5).

- **H1710 docs: student-manual §12 + README exercises table + Telegram drafts.** Кураторская карта игр-упражнений (docs/student-manual.md §12), расширен блок /lila/ в README, черновики постов канала в marketing/lila-telegram-posts.md. Executor: Grok 4.5 (grok-4.5).

## [1.57.0] - 2026-07-26

### Added
- **Kossovich-заметка: галерея 16 самас в гл. 10 (M12).** По указанию MG («запиши все, лишние потом перенесу в запас») в гл. 10 влит полный шорт-лист FOLLOWUPS K-6 — 16 самас с неочевидными русскими переводами Коссовича, каждая глосса дословно из [kow.jsonl](https://github.com/gasyoun/SanskritLexicography/blob/master/RussianTranslation/src/kow.jsonl) (ключи проверены): gavākṣa «бычий глаз»=окно, anekaja «рождающийся не за один раз»=птица, amṛtasodara «брат амброзии»=конь, budhavāra «день Меркурия»=середа, vitṛṣṇatā «нечувствование жажды»=довольство и др.; рамочный пример kṛtajña помечен в FACTS как общесанскритский (буквы «к» в словаре нет). FACTS +2 строки (K10-6/K10-7), FOLLOWUPS K-6 → done, DECISIONS M12; ~4 000 слов, ~20 мин чтения. Executor: Fable 5 (`claude-fable-5`).

### Added

- **LearningApps to Systema: 7 drills + table engine + decode helper (H1710).** New family public/lila/table/ (TableExercise.mount, LA tool 270) with verb-conjugation grid + masc. -i nominative; five thin drills (sort/verb-person-number, cloze/interrogative-accusative, cloze/demonstrative-pronouns, match/ru-sa-sentences, match/ru-sa-pairs-short). Helper scripts/decode_learningapps.py. Skill /learningapps-port. Executor: Grok 4.5 (grok-4.5).

## [1.56.0] - 2026-07-26

### Added
- **Внешний монитор доступности прода — [`.github/workflows/uptime-samskrte.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/uptime-samskrte.yml).** Заведён после простоя 24–26.07.2026 ([#730](https://github.com/gasyoun/Systema-Sanscriticum/issues/730)): `samskrte.ru` лежал двое суток, и об этом никто не узнал. Проба раз в 10 минут выполняется на раннере GitHub, то есть вне самого сервера — монитор внутри падающей машины о её смерти сообщить не может. Проверяется не только код ответа, но и наличие живого текста на странице (`Общество ревнителей санскрита`), что ловит случай «отвечает 200, а отдаёт заглушку»; три попытки с интервалом 20 с гасят одиночный сетевой сбой раннера. Состояние хранится в issue с меткой `uptime-alert`: упал — заводится issue и уходит одно сообщение в Telegram, лежит — монитор молчит, встал — issue закрывается с длительностью простоя. За пятичасовой простой приходит два сообщения, а не тридцать. Дополнительно: предупреждение за 14 дней до истечения TLS-сертификата (ср. [#658](https://github.com/gasyoun/Systema-Sanscriticum/issues/658)) и ручной прогон `force_alert` для проверки того, что оповещение реально доходит. Секреты `TELEGRAM_BOT_TOKEN` и `TELEGRAM_CHAT_ID` задаются один раз в настройках репозитория; без них монитор продолжает заводить issue. Диагностика простоя и выбор конструкции: Opus 5 (`claude-opus-5[1m]`).

## [1.55.0] - 2026-07-26

### Added
- **Kossovich-заметка: mining-pass M11 — 16 глав, образцы статей словаря, переиздания (H1696 follow-up).** Из escrow-ветки `h1696-kossovich-arzamas-2099` (параллельная попытка H1696) в канонический текст влиты проверенные по первоисточникам пассажи: биография Коссовича по статье Ольденбурга («Этюды» ~1705–1730 — источник, не найденный в первом проходе: Полоцк, «замечательный пример автодидакта», метод «скользил по грамматике»), «решётка кириллицы», латинский «фиговый листок» (мост к гл. 8 первой заметки), четырёхлетняя программа с храмами Ориссы, контраст поглощения 70,9 %/23,6 % (A43), финальные формулы. НОВАЯ гл. 10 «Внутри словаря: „яблоко" из амалаки» — образцы статей из оцифровки kow.jsonl (attā ~ «отец»/«тетя» с пометой «Гильф.» в самой статье, āmalaka ~ «яблоко», aruṇa ~ «румяный», anuśāsitar «на-каз-атель» — против настоящих когнатов aham~азъ, agra~острый, asthi~ость; вторая запись Коссовича и первая запись PWG — одно и то же «восклицаніе состраданія»). По указанию MG добавлены [вступительная лекция 1859 г.](https://samskrtam.ru/kossovich-vstupitelnaya-lekciya-1859) и краудфандинговые переиздания словаря ([planeta.ru/sanskrit](https://planeta.ru/campaigns/sanskrit), 3-е изд. 2017) и «Легенды об охотнике» ([planeta.ru/ohotnik](https://planeta.ru/campaigns/ohotnik), 2018). FACTS +17 строк (K3-9…K16-5, включая kow-ключи, проверенные по файлу), DECISIONS M11, MAJORS-леджер дополнен пост-советной секцией; expected_min_h2 15→16. Тесты: 11 passed (49 assertions). Executor: Fable 5 (`claude-fable-5`).

### Added


## [1.54.0] - 2026-07-26

### Added
- **ВТОРАЯ Arzamas-заметка «Россия и санскритский словарь: Коссович против Бётлингка» (H1696).** Полный материал-пак [docs/materials/kossovich-arzamas/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/kossovich-arzamas) — сюжет, сознательно вырезанный из первой заметки (DECISIONS L9 / FOLLOWUPS W2-5, «их может быть и две» — MG 26-07-2026): русский контекст PWG — империя платит за немецкий семитомник, требование санскрито-РУССКОГО словаря от II Отделения, латынь Уварова «как в добрые старые времена», словарь Коссовича 1854 г. и разгромный отзыв Бётлингка («не делает никакого самостоятельного вклада… за исключением ошибок»), славянофильская санскритомания («шуба» из «шубх», лексикончик Хомякова), фельетон Ламанского 1879 г. о «ста тысячах рублей», защита Булича и Рота, судьба торса по A43 (9 592 уникальных слова = 73 % словника, крупнейший уникальный вкладчик русской семьи) и финал Ольденбурга «до словаря и после словаря». SOURCE.md 15 глав ~3 100 слов; FACTS.md ~80 строк K-*/KA-* (все `verified`/`hedged`; правило «архив ≠ первоисточник» — все архивные цитаты атрибутированы Вигасину прямо в тексте); ASSETS.md — 3 новых PD-портрета (Коссович, Уваров/Голике-1833 как обложка, автопортрет Хомякова-1842) + переиспользованный портрет Бётлингка из пака первой заметки; `build_body.py` (floor ≥12 h2 по goal H1696). Взаимные ссылки: гл. 16 первой заметки теперь ведёт на вторую (re-import первой на проде — после публикации второй, FOLLOWUPS K-2). Импорт: artisan `materials:import-kossovich-arzamas {--publish}` (тонкая команда-сиблинг, идемпотентный upsert по slug `rossiya-i-sanskritskiy-slovar`). Тесты: `KossovichArzamasMaterialTest` (6, включая тест сосуществования и взаимных ссылок обеих заметок). RWS-советы `sanskrit`+`general` (DeepSeek `deepseek-v4-pro`), Majors разобраны в [rws/MAJORS_RESOLUTION.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/kossovich-arzamas/rws/MAJORS_RESOLUTION.md). Публикация в прод — по README-runbook пака (prod-CLI в сессии нет). Executor: Fable 5 (`claude-fable-5`).

## [1.53.0] - 2026-07-26

### Added
- **PWG Arzamas-longread «Петербургский словарь» — wave-1 build (H1620).** Полный материал-пак [docs/materials/pwg-arzamas/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/materials/pwg-arzamas): SOURCE.md (20 глав, ~4 500 слов, Arzamas-регистр), FACTS.md (138 строк claim→source, все `verified`/`hedged`), ASSETS.md (rights-таблица: 9 PD-изображений + 2 авторских SVG в `public/images/materials/pwg/`), детерминированный рендерер `build_body.py` (Markdown→`body.html`, ≥15 h2 gate), DECISIONS_LOG/FOLLOWUPS/bibliography. Данные обогащены по live-ревью MG: статистика csl-atlas (801 790 `<ls>`-цитат, приставочные семьи vi-/ā-/sam-, «худеющие» статьи −14,3 %/декаду, контраст с пунским PD-словарём ≈2280 г.), архивная глава Вигасина («Дело о санскритском словаре»: Уваров и латынь, гонорары, тираж), некролог Ольденбурга, статьи A33/A40/A50/Stache-Weiske; портрет Даля снят (заменят генерируемые инфографики, FOLLOWUPS W2-4); сюжет «Коссович против Бётлингка» вынесен в план второй заметки (W2-5). Импорт: artisan `materials:import-pwg-arzamas {--publish}` (идемпотентный upsert по slug `peterburgskiy-slovar-pwg`, staging обложки на public-диск, reading_time). Тесты: `PwgArzamasMaterialTest` (5, 22 assertions, идемпотентность + 404 черновика + ≥15 h2 + карточка хаба). RWS-советы `sanskrit`+`indology` (DeepSeek `deepseek-v4-pro`; алиас `deepseek-chat` умер — папиркаты поданы), Majors закрыты ([rws/MAJORS_RESOLUTION.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/materials/pwg-arzamas/rws/MAJORS_RESOLUTION.md)). Публикация в прод — по Step 10 (README runbook при отсутствии prod-CLI). Executor: Fable 5 (`claude-fable-5`).

- **Online Sanskrit games multi-wave plan (`/ask`, 26-07-2026).** Layered PLAN + ROADMAP + ARCHITECTURE + IMPLEMENTATION (Wave 1) + VERIFICATION for games built on existing Systema assets (`/lila` engines, frequency roots, Kochergina/SRS fixtures, lead-magnet funnel, Sanskrit-HUB ladder). Invent catalogue **28** game IDs in three sections (asset-pedagogy · viral LM · engine-fill). Wave-1 fence: extend engines only, no audio/multiplayer. Deferred handoffs H1678–H1680 (platform+P0, P1 packs, SRS onboarding). Index: [docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ONLINE_SANSKRIT_GAMES_2026H2.md). Executor: Grok 4.5 (`grok-4.5`).

### Fixed
- **CI на `main` починен: `DeliverSupportReplyTest` получает четвёртую зависимость `TelegramSupportSyncService` ([PR #726](https://github.com/gasyoun/Systema-Sanscriticum/pull/726), [issue #725](https://github.com/gasyoun/Systema-Sanscriticum/issues/725)).** Коммит [`95a61d4`](https://github.com/gasyoun/Systema-Sanscriticum/commit/95a61d4) добавил сервису четвёртый параметр конструктора (`TechnicalIssueRouter`), но анонимный наследник в тесте продолжал строить его с тремя — три кейса падали с `ArgumentCountError`, и job «PHP 8.3 — tests» был красным на `main` с этого коммита (зелёный на [`7b18267`](https://github.com/gasyoun/Systema-Sanscriticum/commit/7b18267)). Коммит ушёл в `main` напрямую, без PR, поэтому его ничто не сгейтило. Все четыре зависимости резолвятся контейнером — правка в две строки, продакшен-код не тронут. Обнаружено при прогоне полного Feature-набора для #724. Executor: Opus 5 (`claude-opus-5[1m]`).
- **Мост оплата→сделка: курс стал различающим признаком и на ветке группы рассрочки (H1690, остатки ревью H1659).** Обе находки adversarial-ревью H1659, оценённые как PLAUSIBLE (а не CONFIRMED, поэтому в [PR #714](https://github.com/gasyoun/Systema-Sanscriticum/pull/714) их сознательно не чинили), закрыты вместе с одним минорным пунктом. **(1)** [`PaymentDealBridgeObserver::closeOrRecordDeal()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Observers/PaymentDealBridgeObserver.php) искал сделку плана ОДНИМ условием `where('installment_group_id', $group)` — без курса, человека и лида, тогда как соседняя `findOpenDealFor()` несёт ровно про это громкий докблок (дефект 1 ревью H1641: «оплата курса B закрывала сделку по курсу A»). Ревью показало в рантайме: сделку плана G на курс A реверсят, человек руками переводит её на курс B — и второй взнос плана G её закрывает. **Развилка решена в пользу прочтения (б) «курс — различающий признак ВЕЗДЕ»**, а не (а) «тождество плана сильнее»: цена ошибки несимметрична — по (а) выигранная сделка пишется на курс, за который в этой транзакции никто не платил, и одновременно настоящая продажа курса A исчезает из воронки (две молчаливых порчи отчётности), по (б) худший исход — вторая, видимая сделка на ту же группу, зато деньги легли на свой курс. Ветка вынесена в `dealOfPlan()` с той же терпимостью к null, что и у `findOpenDealFor()` (сделка плана с ещё не проставленным курсом может быть «той самой»; платёж без курса доверяет группе целиком), чтобы новый гард не начал плодить дубли. Ruling проговорён в докблоке — до H1690 отсутствие проверки курса читалось как недосмотр. **Обязательное adversarial-ревью поймало регрессию в самой этой правке — и, в отличие от H1659, ДО мерджа:** `dealOfPlan()` просматривает ВСЮ группу, поэтому его отказ означал, что ни одна сделка плана по курсу не подошла, а OR-условие `findOpenDealFor()` («группа пуста ИЛИ равна G») после этого способно найти ТОЛЬКО сделку БЕЗ группы — то есть каждый раз, когда срабатывал новый гард, провал управления вёл в заведомо чужую сделку, и `closeDealWith()` навсегда клеймил её штампом чужого плана. Строго хуже, чем до H1690 (там этот случай был немым), и достижимо без единого синтетического состояния: куратор правит курс одного взноса живым `EditAction` обещания. Закрыто гардом `$planOwnsADeal` — если план уже владеет сделкой, а платёж по курсу с ней разошёлся, `findOpenDealFor()` пропускается и заводится своя сделка: ровно та «вторая ВИДИМАЯ сделка на ту же группу», которую обещал ruling и которой в коде до ревью не было. Первый взнос плана (группы в `deals` ещё нет) по-прежнему штатно закрывает менеджерскую открытую сделку. **(2)** Второй переход цепочки (`payment_promises.fulfilled_payment_id`) ревью сочло почти мёртвым кодом: `PromiseAutoFulfiller::handlePaidPayment()` выходит на пустом `linked_promise_id`, значит там, где обратную связь ставит он, первый переход уже вернул ту же группу. **Переход оставлен осознанно** — проверено, что живой случай существует и он единственный: кураторский платёж `PromiseFulfillment::fulfil()` (связывает обещание ПОСЛЕ создания платежа), который откатили и провели заново, — прямой связи у него нет никогда, а третий переход к этому моменту молчит, потому что обещание уже `FULFILLED` и реверс его в `active` не возвращает. Причина записана в докблоке, а тест `instalment_is_recognised_through_the_reverse_promise_link` переписан с синтетической связи через `withoutEvents` + прямой вызов обсервера на РЕАЛЬНЫЙ путь «кураторский взнос → реверс → повторная оплата» (план из одного взноса, иначе группу вернул бы `unmetPlanFor` и тест проверял бы не тот переход). **(3)** Минор: поиск сделки плана упорядочен по `id`, а не `oldest()`/`created_at` — секундная точность на MySQL и SQLite давала неустойчивый тай-брейк при конкурентной доставке двух взносов. Все три пункта ИЗМЕРЕНЫ мутационной проверкой, а не заявлены: `instalment_for_a_repointed_course_never_hijacks_an_unrelated_deal` падает на коде до гарда `$planOwnsADeal`, `instalment_is_recognised_through_the_reverse_promise_link` — с вырезанным вторым переходом, `plan_deal_lookup_breaks_ties_by_id_not_by_timestamp` — при откате сортировки на `oldest()` (первая версия этого теста давала обеим сделкам одинаковый `created_at` и потому проходила при любой сортировке — SQLite при равенстве ключа возвращает строки в порядке rowid; пересобран так, чтобы порядок по `created_at` был обратен порядку по `id`). Тест границы рангов 1–5 по-прежнему проходит по групповой ветке. Денежный путь не тронут (`InstallmentPlanCreator`, `PromiseAutoFulfiller`, `PromiseFulfillment`, `DebtPaymentController` — только чтение), новых миграций и флагов нет, всё по-прежнему за `crm_pipeline_board` (default OFF). Executor: Opus 5 (`claude-opus-5[1m]`).

## [1.52.0] - 2026-07-26

### Added
- **GetCourse-parity F9 — сводная доска продаж как АЛЬТЕРНАТИВНЫЙ третий UI (H1658).** Развилка F9 спеки §7 («что делать с доской заявок теперь, когда есть доска сделок») **закрыта MG 26-07-2026** вариантом (а)+(в) аддитивно: обе существующие доски — «Заявки — доска» ([`LeadKanbanBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/LeadKanbanBoard.php), H451) и «Сделки — доска» ([`DealKanbanBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/DealKanbanBoard.php), H1641) — остаются нетронутыми, а общий слой стадий строится ТРЕТЬИМ представлением рядом. Это НЕ вариант (б) (убрать доску заявок) и НЕ разрушительная форма варианта (в): физического сведения `lead_stages` и `deal_stages` в одну таблицу нет — это развилка **F3**, уже решённая в пользу отдельных таблиц (строковый `key` ↔ числовой `id`, миграция трогала бы живые `leads`). **Ни одной миграции**; `leads.status`, `lead_stages`, `LeadResource`, `Lead::statuses()`, `RemindLeadsForFollowup` не тронуты. Новая страница [`UnifiedSalesBoard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/UnifiedSalesBoard.php) (slug `sales-board`, группа «Продажи», sort 70 — над обеими досками) поверх [`UnifiedSalesStage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/UnifiedSalesStage.php) — словаря из четырёх общих колонок (Новые · В работе · Выиграно · Проиграно), который живёт ТОЛЬКО в слое представления и ни во что не записывается (закреплено тестом `the_common_vocabulary_is_never_persisted_into_either_stage_table`). Value-класс, а не массив в `config/`: у словаря есть поведение (сопоставление в обе стороны + подбор целевой стадии), а часть его ВЫВОДИТСЯ из живых строк и не пережила бы `config:cache`. Асимметрия сторон намеренная: **сделки** раскладываются структурно, из данных (`is_won` → «Выиграно», `is_lost` → «Проиграно», первая по `position` → «Новые», остальные → «В работе»), поэтому своя стадия админа не требует правки кода; **заявки** так вывести нельзя — `lead_stages` несёт только `is_final` и не отличает «Конверсию» от «Отказа», для них явная таблица ключей. Незнакомая стадия с любой стороны падает в «В работе», а не исчезает с доски. Карточки различаются бейджем «Заявка»/«Сделка» и составным DOM-id (`lead-12` / `deal-12`) — числовые ключи двух сущностей пересекаются, и без этого drag-drop переносил бы не ту запись. Перенос пишет в РОДНУЮ сущность: заявка — `leads.status` напрямую (и её `lead_audits`, как на одиночной доске), сделка — через `Deal::moveToStage()`, чтобы журнал `deal_transitions` продолжал наполняться и подписываться менеджером. Оба гарда `blocksRollbackToFirstStage` отклоняют откат ровно как на одиночных досках. Перенос ВНУТРИ одной колонки — сознательный no-op: объединённая колонка не имеет права молча понизить «Квалифицирован» до «В работе» или записать лишнюю строку перехода. За тем же флагом `crm_pipeline_board` (default `false`) плюс тот же `RoleGate::any(ADMIN, MANAGER)` — **своего флага не заводили**, это та же поверхность GC-C1. Тесты: `UnifiedSalesBoardTest` (15). Спека §7 F9 переписана как решение (исходная формулировка сохранена ниже), строка GC-C1 в §1 и метадок обновлены. Executor: Opus 5 (`claude-opus-5[1m]`) — лок хендоффа на Sonnet 5 не соблюдён, запуск человеком напрямую.
- **VK/ORS content calendar — Wave 5: auto-pilot (H1568, PLAN closer).** `content:publish-due` (hourly ticker, `app/Console/Kernel.php`) posts every `scheduled` `ContentCalendarSlot` whose `publish_at` is due to a new n8n webhook (`CalendarPublishService`) — same webhook-forward shape as `PublishSocialPostJob`/`PostMonthlySchedule`, VK-only text `wall.post` per D10 (no TG mirror). n8n workflow JSON: [`docs/n8n/vk-calendar-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/vk-calendar-post.workflow.json). Success marks the slot `published` and mirrors any linked `ContentCandidate`; a non-2xx response leaves the slot `scheduled` for retry on the next hourly tick — no silent drop. Cancel-window enforcement (D15, `ContentCalendarSlot::canCancel()`) was already wired from W1 into the Filament bulk action; this wave adds the first direct unit coverage of its 24h boundary. Flag-gated behind `content_calendar_autopilot` (`CONTENT_CALENDAR_AUTOPILOT`, default OFF) — command no-ops while off; `N8N_CALENDAR_POST_WEBHOOK`/`_SECRET` unset → warn + no-op. No new migration. Tests: `PublishDueContentCommandTest` (5: E1 flag-off no-op, webhook-unset no-op, due-only publish + candidate mirror, failed-response keeps scheduled, never touches `api.vk.com`) + `ContentCalendarSlotCancelTest` (7, `canCancel()` boundary). DEPLOY_QUEUE №60. Executor: Sonnet 5 (`claude-sonnet-5`).
- **H1644 pedagogy hop smoke (Grok 4.5 grok-4.5, 25-07-2026).** Artisan pedagogy:sync-sg-export copies SanskritGrammar data/pedagogy_export (schema major >=1, sha256-checked) into
esources/data/rq4_item_bank.json. Does **not** flip eatures.rq4_study. Smoke: schema_version=1.0.0, items=24, first_item=yat, flag OFF. Tests: SyncPedagogyExportFromSgTest (2) + php artisan test --filter=Rq4 (11) green.
- **GetCourse-parity GC-B1 rescope — одна recurring Zoom-встреча НА КУРС (H1642).** Развилка F1 (per-schedule авто-создание vs. единая-ссылка модель) была **закрыта MG 19-07-2026** на недельном `@DECIDE`-листе → опция (b): рескоуп на «авто-создание ОДНОЙ recurring-встречи на курс», единая-ссылка модель (`eda8059`, 27-06-2026) стоит нетронутой, per-schedule авто-создание НЕ возвращается. Триггер — первая генерация потока занятий курса (`ScheduleGenerator::generate()`): если у курса ещё нет `zoom_meeting_id` и явной ссылки в форме не задано, вызывается `ZoomService::createMeeting()` (реальный вызов Zoom API, `type=8` — recurring без фиксированного времени) и результат сохраняется через уже существующий `Course::setZoomLinkAttribute()` (парсер meeting_id из `join_url` переиспользован, не задублирован). Идемпотентно — маркер уже-созданной встречи — `courses.zoom_meeting_id`; повторная генерация потока для того же курса Zoom API не дёргает. `WebinarProvider`-шов (GC-B3) не тронут — `ZoomService::createMeeting()` остаётся его единственной живой реализацией. Всё за флагом `zoom_auto_create` (default `false`) — пока OFF, `ScheduleGenerator` ведёт себя байт-в-байт как раньше. **Рескоуп-преемник теста-замка:** `WebinarProviderSeamTest::test_zoom_create_meeting_stays_removed_per_gc_b1` заменён на `test_create_meeting_requires_configured_credentials` (тот же гвард кредов, без сети — реальный вызов Zoom API теперь тестируется отдельно, `Http::fake` требует контейнер Laravel). Тесты: `ZoomAutoCreateTest` (5 — флаг выкл/вкл/идемпотентность/ручной путь Filament не тронут/per-schedule-guard — прямое создание `Schedule` в обход генератора Zoom API не дёргает). Полный Feature-набор зелёный, Pint чист. Executor: Sonnet 5 (`claude-sonnet-5`).
- **GetCourse-parity GC-C1 — сделки (`Deal`) + канбан + мост от оплаты (H1641, Wave 2 head).** Развилка F2 (две противоречащих записи решений: «расширить `Lead`» vs «отдельная сущность `Deal`») была **закрыта MG ещё 21-07-2026** на недельном `@DECIDE`-листе в пользу отдельной сущности, но неделю не доезжала до документов — `DECISIONS_roadmap_forks_2026H2.md` §R2 теперь помечен superseded. Аддитивно: миграции `deal_stages` (5 засеянных стадий, ровно одна `is_won`) / `deals` / `deal_transitions` (append-only, СОЗНАТЕЛЬНО без FK — переживает удаление сделки, приём `lead_audits`), модели `Deal`/`DealStage`/`DealTransition` с гардом отката финальной стадии (зеркало `Lead::blocksRollbackToFirstStage`), доска `DealKanbanBoard` (форма скопирована с `LeadKanbanBoard`, `$statusEnum` намеренно опущен — `stage_id` не enum-каст), и `PaymentDealBridgeObserver` — ОТДЕЛЬНЫЙ обсервер по прецеденту `PaymentAuditObserver`/`PaymentTelemetryObserver`, предикат `wasChanged('status')` (развилка F4). Мост повторяет набор исключений `Payment::fireOnPaid` (расход/ЗП/депозит/пробное/марафон/`is_conditional`), идемпотентен по `source_payment_id`, а на реверсе платежа снова ОТКРЫВАЕТ сделку (ранг 1 прав, сделка была устаревшей). Всё за флагом `crm_pipeline_board` (default `false`) — пока OFF, доска недоступна и в `deals` не пишется ни строки. **`LeadKanbanBoard`/`LeadStage` (H451) НЕ тронуты** — их судьба вынесена новой развилкой F9 спеки. Тесты: `DealTest` (25) + `DealFlagDefaultTest` (3, пиннит дефолт флага — дыра §6 закрыта), включая guard денежной границы: мост не пишет НИ В ОДНУ таблицу кроме `deals`/`deal_transitions` и не конвертирует лид обычной покупки (§2.4). **По итогам обязательного adversarial-ревью (свежий контекст, Opus 5 `claude-opus-5`) исправлено ДО мерджа, каждая правка закрыта регрессионным тестом:** (1) ветка сопоставления по лиду игнорировала курс — оплата курса B закрывала сделку по курсу A, т.е. запись не в ту строку; курс теперь различающий признак и по лиду тоже; (2) рассрочка заводила отдельную выигранную сделку на каждый взнос и раздувала воронку — второй платёж по тому же человеку и курсу сделку больше не плодит (цена: повторная покупка того же курса тоже не заведёт вторую — размен вынесен человеку); (3) необработанное исключение моста внутри транзакции вебхука Точки откатило бы ПОДТВЕРЖДЁННЫЙ БАНКОМ платёж (ранг 4 не имеет права вето над рангом 1) — `sync()` целиком в try/catch с логом; (4) реверс перетирал решение человека, воскрешая сделку, уведённую руками в «Проиграна»; (5) `UNIQUE` на `source_payment_id` против гонки check-then-insert вне вебхука; (6) двухшаговое закрытие обёрнуто в транзакцию; (7) `DealStage::first()` → `firstStage()` — перекрывал статический форвардинг Eloquent. Полный Feature-набор **1910 зелёный**.
- **Upgrade-credit refund-link attribution — flag-gated, default OFF (H1405 C2, PR #695).** `Tariff::upgradeRefundsForUser` block branch additionally nets «Расход» rows linked via `refund_of_payment_id` to a paid half of the purchased block when `features.upgrade_credit_refund_link` is ON (`UPGRADE_CREDIT_REFUND_LINK`, default OFF — flag-OFF parity test pins today's behavior). Closes the over-credit where a form-created refund (start/end auto-nulled by PaymentResource) stayed invisible to the netting. Tests: `UpgradeCreditRefundLinkTest` (6). Executor: Fable 5 (`claude-fable-5`).
- **VK/ORS content calendar — Wave 4: forward drafts (H1567).** `ForwardDraftGenerator` fills empty `forward`-type `ContentCalendarSlot` rows with NEW copy, rotating four template kinds — reading-group tease, dictionary tip (grounded in a real `DictionaryWord`, falls back to a generic template when none exists), event promo (grounded in the next upcoming `Schedule`+`Course`, falls back generically), FAQ-style micro-answer (grounded in the "Новичкам" section of `resources/knowledge/faq.md` — deliberately outside the money/policy FAQ sections). CuratorAi polishes the deterministic base when an OpenRouter key is set (`Http::fake` in tests); the base itself covers the no-key/test path. Per the skip-review default (D12: forward is NEW copy), a filled slot only ever moves `empty` → `draft`, never `scheduled` — only a human monthly Keep in Filament schedules it. Cost cap: `content.forward_draft_max_per_run` (default 10, `CONTENT_FORWARD_DRAFT_MAX_PER_RUN`), mirrors `ArticleDraftGenerator::WEEKLY_LESSON_LIMIT`. Artisan `content:fill-forward {YYYY-MM} [--limit=] [--force-flag]`. No new migration; flag-gated behind existing `content_calendar` (OFF); no live VK. Tests: `ForwardDraftFillTest` (11 cases). DEPLOY_QUEUE №57. Executor: Sonnet 5 (`claude-sonnet-5`).
- **VK/ORS content calendar — Wave 3: Systema bridge (H1566).** `SystemaCalendarBridge::bridgeClips()` fills empty `clip_tease`-type `ContentCalendarSlot` rows for `YYYY-MM` with free (`is_free=true`) `LectureClip`s already mirrored into an accepted `ContentCandidate` (H1547 sync) — one clip per slot, deduped against clips already used in another slot, VK permalink in `meta.link` when `vk_owner_id`/`vk_video_id` are already set by n8n. `bridgeScheduleDigest()` creates one `event`-type digest slot for the **current** month (idempotent by `source_kind=schedule_digest`+`source_ref=YYYY-MM`; no-ops for a non-current target month) — **prior-art reuse**: sources courses/schedule/teacher/URL straight from the existing `MonthlyScheduleDigest` service (the live `schedule:post-monthly` poster) instead of re-querying `Schedule`, so the calendar digest can never disagree with the live one about which courses are running (same `is_active`/`is_visible`/course-block-intersects-month filter — a from-scratch `Schedule`-only query was drafted first, then replaced after finding `MonthlyScheduleDigest` mid-build; it would have leaked hidden/inactive courses that still had stray `Schedule` rows). Both bridges go straight to `scheduled` (skip-review default D12: Systema-sourced content is not NEW copy), same pattern as W2's evergreen fill. Only calendar rows pointing at existing artifacts — **no ffmpeg/VK upload from the bridge** (C2; that stays n8n per H1452). Artisan `content:bridge-systema {YYYY-MM}`. Default log (D22): the roadmap's per-class `schedule_note` (change-tracking) and `faq_tease` (FAQ Accept) sources are deferred — neither is required by W3's DoD (C1/C2); `Schedule` carries no diff primitive yet. Tests: `SystemaBridgeTest` (13 cases: clip fill + link, non-free/already-used dedupe, digest reuse/hidden-course-exclusion/idempotency/non-current-month no-op, `Http::assertNothingSent()`, flag-off no-op). DEPLOY_QUEUE №58. Executor: Sonnet 5 (`claude-sonnet-5`).
- **GetCourse-parity GC-A1 segment engine (H1637, Wave 4 head).** `segments` migration (name/description/typed `criteria` JSON/`is_builtin`/`created_by`) + `Segment` model + Filament `SegmentResource`, all behind `marketing_segments` (default `false`). Three built-in segments (`SegmentSeeder`) wrap `ReactivationReport`/`DebtorsReport`/`StuckStudentsReport` query-for-query, no reinterpretation. Custom segments store an AND-combined `criteria` array (group membership, last-activity, completed-lesson, tariff-ownership, attendance, lead-status-by-email, debtor, UTM), all read-only against ranks 1-5 (money/access/lead/stage) per docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2. Tests: `SegmentTest` (14, 33 assertions), including a boundary-rule guard modeled on `test_zoom_create_meeting_stays_removed_per_gc_b1` that fails if evaluation ever issues a non-SELECT statement or changes a rank 1-5 row count.
- **Money/access-core deep systems manual (H1405, Wave 2 of the org deep-manuals programme).** [docs/money-access-core-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md) + metadoc with staleness block: access-key algebra (accessKey/unlockingKeys/isUnlockedBy, verified live), containment upgrade-credit, payment lifecycle, H1359 webhook ledger + three guards, discount/prana/deposit/loyalty stacking order, receivables-vs-conversion loops, money config-gate map, [RU] findir mechanism chapter + [RU] failure/recovery runbook. Claim-verify: C1 amount-field documented-limitation (@DECIDE spike), C2 refund-netting defect CONFIRMED (fix ships separately, flag-gated), C3 audit-trail blind spot documented (schema fix queued per D16). All spot-run commands recorded in the metadoc. Executor: Fable 5 (claude-fable-5).
- **VK/ORS content calendar — Wave 2: evergreen recycle (H1565).** `EvergreenScorer` ranks `top_posts_by_likes.csv` rows (likes DESC) filtered by age ≥12 months, topic ∈ {книга, словарь, pdf, текст} (keyword patterns ported from `IndologyScholars/vk-ors/vk_ors_archive/insights.py` TOPICS), promo exclusion, and de-dupe against any `source_ref` already used within ±6 months of the target month. Artisan `content:fill-evergreen {YYYY-MM}` fills draft `evergreen`-type `ContentCalendarSlot` rows with the verbatim excerpt (D17), sets `source_kind`/`source_ref`/permalink+likes+topic meta, and — per the skip-review default (D12: evergreen is not NEW copy) — calls `markKept()` to go straight to `scheduled` with `publish_at` = the slot's existing seeded date at noon Europe/Moscow. Mirrors the fill onto the slot's linked `ContentCandidate` (type `vk_post`, status `scheduled`). No new migration (reuses W1's `content_calendar_slots` schema). Flag-gated behind the existing `content_calendar` (still OFF); no live VK. Tests: `EvergreenFillTest` (score filters, de-dupe across months, idempotency, flag-off no-op). Executor: Sonnet 5 (`claude-sonnet-5`).
- **n8n lecture content engine — Wave 5 student study artifacts (H1551).** StudyArtifactGenerator builds type=study_artifact drafts (kinds: summary / card_seeds / homework) from transcript spans; body hard-capped (MAX_BODY_CHARS + ratio vs source); QuotePolicy on summary quote; channel staff_study; **never** selected by PublishSocialPostJob / SendContentOneShotMailJob (type guards + observer no-op on Accept). Artisan content:compose-study-artifacts; LessonObserver drafts when CONTENT_FROM_LECTURES=true. Filament «Контент-кандидаты» staff review (filter already had study type). DEPLOY_QUEUE **№55**. Tests: StudyArtifactGeneratorTest (E1/E2). Executor: Grok 4.5 (grok-4.5) Sonnet-lock override.
- **n8n lecture content engine — Wave 4 long-form articles (H1550).** `ArticleDraftGenerator` builds type=`article` drafts (per-lesson outline + optional `--weekly` pack) from transcript spans via ClipSpanPlanner; body hard-capped (`MAX_BODY_CHARS` + ratio vs source); `QuotePolicy` on quote; never a full transcript dump. Artisan `content:compose-article-drafts`; LessonObserver drafts when `CONTENT_FROM_LECTURES=true`. Draft-only Filament (no auto-publish). DEPLOY_QUEUE **№54**. Tests: `ArticleDraftGeneratorTest` (D1/D2). Executor: Grok 4.5 (`grok-4.5`) Sonnet-lock override.
- **n8n lecture content engine — Wave 3 FAQ from lectures (H1549).** `FaqDraftGenerator` mines transcript interrogatives (fallback: ClipSpanPlanner span titles) into `ContentCandidate` type=`faq_draft` (no SupportTopic/CAI3 input, D3). Filament Accept → `KnowledgeFaqPublisher` appends to `resources/knowledge/faq_from_lectures.md` (sibling of ORS-export `faq.md`) and marks published; `BotKnowledgeBase` loads both. Artisan `content:compose-faq-drafts`; LessonObserver drafts when `CONTENT_FROM_LECTURES=true`. DEPLOY_QUEUE **№53**. Tests: `FaqDraftGeneratorTest`, `KnowledgeFaqPublisherTest`, chain C2. Executor: Grok 4.5 (`grok-4.5`) Sonnet-lock override.
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **C3 mobile viewport audit + PWA shell (H1488).** Dated inventory of every student-cabinet route at 320–390 px; fixes header density on <375 px, support-chat short-viewport height, main `overflow-x-hidden`; ships `public/manifest.webmanifest` + `public/sw.js` + `public/offline.html` linked from student layout; Feature smoke `PwaShellAssetsTest`; optional Playwright script `scripts/mobile_viewport_audit.mjs`. Report: [docs/MOBILE_VIEWPORT_CABINET_AUDIT_2026-07-24.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MOBILE_VIEWPORT_CABINET_AUDIT_2026-07-24.md). Executor: Grok 4.5 (`grok-4.5`) override of Sonnet lock.
- **Cabinet hybrid Phase 4 — R20 flag-flip release pack (H1582).** Does **not** enable
  the hybrid in prod. Adds [docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md)
  (GO/NO-GO gates, walkthrough §3, activate/revert, KPI readout),
  `php artisan cabinet:hybrid-readiness`, DEPLOY_QUEUE **№52**, `.env.example`
  `CABINET_HYBRID=false`. Baseline clock from №25 (21-07-2026) → earliest mechanical
  GO ~04-08-2026; human C+D still required. Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **Cabinet hybrid Phase 3 — Прогресс ladder + lighting + course vehi (H1573, R29.6–R29.8).**
  `config/grammar_ladder.php` + `GrammarLadder`: station map on hybrid «Прогресс»
  (письмо → грамматика I/II → тексты), completion lighting, ladder offer only after
  station complete («станция подождёт», no timers), suppressed in recovery; course-home
  landmarks from `CourseBlock` dates (orientation only). Telemetry
  `path.station.view` / `path.station.lit.impression`. Tests: `HybridPhase3Test`.
  Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **VK/ORS content calendar Wave 1 (H1564).** ContentCalendarSlot + import/seed artisan commands + Filament «Календарь контента» behind CONTENT_CALENDAR_ENABLED (OFF). Reuses H1547 ContentCandidate (calendar_slot_id). Fixtures + tests; no live VK. Grok 4.5 (grok-4.5) override of Sonnet lock.
- **SRS Wave 2 authoring UI (H1487).** Filament `SrsDeckResource` +
  `SrsCardResource` (teacher CRUD, course/lesson attach, paste bulk-add,
  "seed from Dictionary" action, CSV import via `SrsCardImporter`) plus
  student private-deck Livewire `SrsDeckEditor` at `/dvaram/srs/decks`.
  Behind existing `srs.enabled` / `SRS_ENABLED` flag (default off). Feature
  tests in `tests/Feature/Srs/SrsAuthoringTest.php`. Grok 4.5 (`grok-4.5`).
- **Cabinet hybrid Phase 2 — Записи shelves + lapse + rail + ownership offer (H1572, R29.3–R29.5).**
  Behind `cabinet_hybrid`: `LapseDetector` (debt gap → first-class lapsed state),
  `RecordingsCatalog` shelves (watching / owned / lapsed / completed), progress rail
  for recording courses without homework, R29.5 ownership offer (suppressed in recovery),
  membership «скоро» slot on Записи. Telemetry `library.shelf.view` + `library.rail.jump`.
  Tests: `HybridPhase2Test` (7). Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **Cabinet hybrid Phase 1 chassis + recovery-mode resolver (H1481, R29.0–R29.2).**
  Flag cabinet_hybrid / CABINET_HYBRID (OFF by default, R20 deploy gate).
  Job-named student nav (Сегодня / Календарь / Записи / Прогресс / Оплата и доступ /
  Помощь); hybrid home with «Сегодня» band (continue + nearest live + homework-rework
  only when returned); course workspace hash-addressable tabs; routes /library,
  /progress, /access (404 while flag off). Server-side
  App\Services\Cabinet\RecoveryStateResolver: declined/canceled payment or expired
  promise → recovery banner, unconditional offer suppression, owned/live access kept;
  bare pending is not recovery (webhook-delay trap). Telemetry cabinet.home.view
  now carries mode: normal|recovery + reason. Feature tests:
  tests/Feature/Cabinet/HybridPhase1Test.php (11 cases). Spec:
  [docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  §6 step 2. **Money-adjacent (offer suppression)** — flag default OFF; enable only
  after review + config:cache. Executor: Grok 4.5 (grok-4.5) via xAI (Opus-lock override).
- **n8n lecture content engine — Wave 2 of 5 (H1548).** `SocialDraftGenerator`
  drafts a `social_post` ContentCandidate for every free clip — quote grounded
  in the actual transcript sentence inside the clip's own span (never
  invented, never the full lecture body), CuratorAi text when an OpenRouter
  key is configured, deterministic template fallback otherwise.
  `PublishSocialPostJob` posts VK wall (with the free clip's video attached)
  + a Telegram mirror in one call, via a new n8n webhook (same
  webhook-forward shape as `clip_extract`/`monthly_schedule`) — QuotePolicy
  hard-fails a too-long quote before it ever posts. `ContentCandidateObserver`
  chains the flow off the existing "accepted" transition: marking a clip
  free auto-drafts the social post; accepting the draft in Filament dispatches
  the publish job (self-gated by `content_auto_publish_pilot`, OFF).
  `EmailBlastComposer` (+ `content:compose-weekly-digest` command) composes a
  weekly `email_blast` digest from clips accepted in the last 7 days;
  `SendContentOneShotMailJob` sends it to the existing `newsletter_subscribed_at`
  segment (H324) once accepted, gated by `content_email_oneshot` (August
  activation, depends on live SMTP per H1449/#504) — no new campaign domain
  model beyond the candidate itself. DEPLOY_QUEUE №51. Waves 3–5 remain
  queued as Uprava H1549–H1551. **Executor:** Sonnet 5 (`claude-sonnet-5`).
- **n8n lecture content engine — Wave 1 of 5 (H1547).** `ContentCandidate`
  model/migration — the unified review/publish unit for everything the
  content engine will generate from lectures (clip now, social/faq/article/
  study in waves 2–5). `SpanRanker` (heuristic top-N, default 5) narrows
  `ClipSpanPlanner`'s output before it reaches n8n. `QuotePolicy` guards
  against a full-transcript leak through any future "public quote" field
  (≤2 sentences, hard-fail). `LessonObserver` dispatches
  `DispatchLectureClipExtractionJob` on the publish transition, idempotent
  (skips if `LectureClip` rows already exist), gated by both
  `content_from_lectures` (new) and `clip_marketing` (H1452, unchanged).
  `LectureClipObserver` + `ContentCandidateSync` mirror every `LectureClip`
  into a `ContentCandidate` row regardless of flags (cheap idempotent
  upsert, not a publish action) — staff marking a clip free flips it to
  `accepted`. Thin `ContentCandidateResource` (Filament, admin-only, gated
  by `content_from_lectures`) for review. DEPLOY_QUEUE №49. Waves 2–5
  tracked as Uprava H1548–H1551. **Executor:** Sonnet 5 (`claude-sonnet-5`).
- **n8n lecture content engine plan (H2 2026).** Layered `/ask` plan for turning weekly
  lecture video + transcript + AI timecodes into five sequenced products (clips → social
  text → FAQ → long-form → student materials) under one `ContentCandidate` backbone,
  reusing H1452 clip plumbing and CuratorAi. Docs:
  [`docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md)
  + ROADMAP / ARCHITECTURE / IMPLEMENTATION / VERIFICATION + metadoc. Cross-linked from
  Content-AI and Anton ops-gaps plans. Execution handoffs H1547–H1551 (Uprava).
  Grok 4.5 (`grok-4.5`).
- **Sanskrit-HUB L5 Workstream-A v0 — `/transliterate` + cascade lemmatizer (H1463).**
  Flag `hub_transliterate` / `HUB_TRANSLITERATE` (OFF by default):
  `GET /transliterate` playground (IAST → Devanāgarī + SLP1 via vendored
  `resources/js/vendor/sanskrit-util.js`, Vite entry `transliterate.js`).
  Internal `App\Services\Nlp\CascadeLemmatizer` (DCS → vidyut → Heritage; stage 1
  reads `resources/data/dcs_form2lemma.json` — 341 DCS-attested forms from the
  Nala-1 reading pack slice; stages 2/3 interface-stubbed). No HTTP route for
  the lemmatizer. Tests: `TransliteratePlaygroundTest`, `CascadeLemmatizerTest`.
  **Executor:** Grok 4.5 (`grok-4.5`) via xAI (user-authorized override of the
  Opus 4.8 handoff lock). **Claude/Opus: verify after** — cascade order, key
  normalizer parity with `SanskritGlossary::normalizeKey`, slice provenance,
  and that the playground never uses `iast_to_devanagari`.
- **Lecture clip marketing pipeline, Wave 4 (H1452).** Flag-gated
  (`clip_marketing` / `CLIP_MARKETING_ENABLED`, OFF by default) n8n orchestration:
  `ClipSpanPlanner` reuses existing AI transcript timecodes (no recompute) →
  `DispatchLectureClipExtractionJob` POSTs spans to n8n → secret-guarded
  `POST /api/webhooks/lecture-clip-callback` writes `LectureClip` rows (idempotent
  on lesson+span) → Filament `LectureClipResource` for staff `is_free` (~3 free
  per lecture) + header «Нарезать лекцию». Importable workflow
  `docs/n8n/lecture-clip-extract.workflow.json` (ffmpeg/VK nodes are operator
  placeholders — no live VK tokens in repo). IMPLEMENTATION:
  `docs/IMPLEMENTATION_SYSTEMA_ANTON_OPS_GAPS_WAVE4.md`. DEPLOY_QUEUE №47.
  Tests: `tests/Feature/LectureClips/*`, `tests/Unit/Lecture/ClipSpanPlannerTest`.
  No money-code; no real VK posts in CI (`Http::fake`).
- **In-video resume — «продолжить с HH:MM» (H1450, Anton ops-gaps W2).** Три
  аддитивные колонки на `lesson_views` (`last_position_seconds`,
  `max_position_seconds` — монотонный прогресс-сигнал, никогда не убывает даже
  при перемотке назад, `video_duration_seconds`) пишутся через уже существующий
  `POST /api/heartbeat` — новый эндпоинт не понадобился, только два
  необязательных поля в теле запроса. Host-agnostic JS-слой
  (`public/js/video-resume.js`) даёт по одному адаптеру на YouTube/RuTube/VK/
  Kinescope/Vimeo (D8) — сегодня в плеере урока реально рендерятся только
  YouTube и RuTube, остальные три деградируют в no-op до появления
  соответствующих плееров (заготовка для W3 Kinescope-пилота, который явно
  переиспользует этот же Kinescope-адаптер). Флаг `video_resume` (config/
  features.php) ВЫКЛЮЧЕН по умолчанию — пока OFF, плеер и heartbeat ведут себя
  байт-в-байт как раньше.
- **Transactional email revival + homegrown campaign engine, Wave 1 (H1449).**
  Closes the first of three genuine Anton-parity gaps (email/resume/clips —
  `docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`). Part A (reuse, don't rebuild):
  a global `App\Listeners\Email\EnforceMailSendingGuards` on every outgoing
  Mailable — a `SuppressedEmail` list (hard-bounce/unsubscribe) that's checked
  before every send, and a `config('mail.throttle_per_minute')` per-minute
  send throttle (mailbox providers rate-limit/suspend on bulk send, unlike a
  dedicated ESP). `mail:scan-bounces` (D11: scheduled IMAP scan, hourly,
  no-op until `mail.bounce_scan.enabled`/host/creds are set) suppresses hard
  bounces it finds. `docs/mail-esp.md` updated with the D6 ruling: mail.ru/
  Yandex 360 mailbox SMTP is the default transport (already covered by the
  existing generic-SMTP `Option A`), transport-agnostic so it can later be
  overridden to Postmark/Mailgun-as-relay (R1) with zero campaign-engine
  changes. Part B, entirely behind the new `email_campaigns` flag (OFF by
  default — `CampaignResource` hidden, `/e/o`/`/e/c` tracking routes 404,
  `CampaignSender`/`SendCampaignRecipient` early-return): `Campaign` +
  `CampaignRecipient` models (additive migrations, indexed
  `(campaign_id, opened_at)`), `CampaignSegmentResolver` (all-subscribers /
  a course's students / a lead-stage, fail-safe empty on an unrecognised
  filter — never all-users), token-scoped open-pixel + click-redirect
  tracking endpoints (no PII in the URL; the click target is an
  app-encrypted opaque token, not attacker-controlled input — no open
  redirect possible), `CampaignHtmlRenderer` (link/pixel rewriter),
  `CampaignSender::send()`/`resend()` (Anton's "догон" — resend to
  `opened_at IS NULL` recipients, linked via `resend_of_id`), and a Filament
  `CampaignResource` modeled on `AnnouncementResource` (compose, pick
  segment, send, open/click stats, "Догнать неоткрывших" action). All new
  mail uses `Mail::fake()`-safe/`array`-transport tests — no live sends.
  `changelog.md`/`DEPLOY_QUEUE.md` carry the activation prerequisites
  (mailbox creds, SPF/DKIM/DMARC, migrations, flag flip).

### Changed
- **GC-C1 — одна продажа определяется группой рассрочки, а не парой «человек + курс» (H1659).** Развилка F9 (второй вопрос) закрыта MG 26-07-2026 в пользу опции **(в) — явный маркер**. Эвристика H1641 «выигранная сделка по этому человеку и курсу уже есть → второй платёж молчит» гасила инфляцию воронки на взносах, но той же ценой прятала НАСТОЯЩУЮ повторную покупку того же курса. Заменена явной цепочкой `Payment` → `linked_promise_id` → `PaymentPromise` → `installment_group_id` (плюс обратная ветвь `payment_promises.fulfilled_payment_id`, которую `PromiseAutoFulfiller` проставляет внутри `fireOnPaid`, то есть ДО обсерверов). Два платежа — одна продажа тогда и только тогда, когда их обещания делят непустую группу; платёж без группы снова заводит собственную сделку. Аддитивная миграция `deals.installment_group_id` (nullable uuid + index, без FK — это uuid-метка на `payment_promises`, а не первичный ключ таблицы): группа материализуется на сделке при её создании/закрытии, чтобы следующий взнос находил свою продажу ОДНИМ индексированным запросом, а не перепрохождением цепочки на каждом платеже. **Проверка группы поднята ВЫШЕ поиска открытой сделки** — взнос по уже учтённому плану обязан быть немым целиком, иначе он закрыл бы собой чужую открытую сделку по тому же курсу (тот же класс «записи не в ту строку», что ревью H1641 уже ловило в `findOpenDealFor()`); свою же сделку группа находит напрямую по `deals.installment_group_id`, поэтому взнос после реверса первого платежа закрывает именно переоткрытую сделку плана, а не заводит вторую. Идемпотентность по `source_payment_id` не ослаблена — это гарантия про ОДИН платёж, ортогональная группировке. Мост остался читателем `payments`/`payment_promises`: список таблиц в тесте денежной границы расширен `payment_promises`. _Поправка по итогам ревью: в той фикстуре обещаний нет, так что групповую ветку тот тест не проходил — реальное покрытие добавлено записью в «Fixed» выше._ **Известное ограничение, оставлено осознанно:** менеджерское «Подтвердить оплату» ([`PromiseFulfillment::fulfil`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php)) создаёт платёж и лишь ПОТОМ связывает обещание, поэтому на момент обсервера ни одной из двух связей ещё нет и такой взнос выглядит самостоятельной продажей. _Поправка по итогам ревью: ограничение оказалось не краем, а основным ручным сценарием (3 сделки вместо 1) и закрыто третьим переходом цепочки — см. «Fixed» выше._ Тесты: `DealTest` 31 метод / 30 кейсов (6 новых; три из них падают на `origin/main` — проверено откатом обсервера: взносы одного плана → одна сделка, повторная покупка без рассрочки → ДВЕ, взнос не закрывает чужую открытую сделку; плюс обратная ветвь связи, обещание без группы, возврат-с-повторной-оплатой). Executor: Opus 5 (`claude-opus-5`).
- **H1623 docs-freshness (Grok 4.5 grok-4.5, 25-07-2026):** metadoc freshness sync for docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.meta.md, docs/deploy.meta.md, docs/support-subsystem-map.meta.md.
- **H1451 true redo (24-07-2026).** Hardened Kinescope pilot after first merge:
  multi-field URL resolve (`video_url` → `youtube_url` → `rutube_url`), reserved
  path-segment reject in `VideoEmbed::kinescopeId`, `.env.example` activation
  knobs, extra tests. Executor: Grok 4.5 (`grok-4.5`) via xAI.
- **VK/ORS content calendar plan (H2).** Layered /ask from
- **Операторский мануал RU: клипы лекций + n8n.** [docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md) — еженедельная эксплуатация; установка: [issue #666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666). Grok 4.5 (grok-4.5).
- **Kinescope pilot on one flagship course — Anton ops-gaps Wave 3 (H1451).**
  Flag `kinescope_pilot` / `KINESCOPE_PILOT` (OFF by default) +
  `config/video.php` `kinescope_pilot_course_id`. `VideoEmbed` recognises
  kinescope.io URLs; lesson player renders a Kinescope iframe + Player SDK
  only when the flag is on, the course matches, and `lesson.video_url` is
  Kinescope — reuses W2 `video-resume.js` kinescope adapter. Comparison memo
  `docs/KINESCOPE_PILOT_COMPARISON_2026.md`; DEPLOY_QUEUE №48.
  **Executor:** Grok 4.5 (`grok-4.5`) via xAI (Sonnet-lock override).

### Fixed
- **GC-C1 — две регрессии H1659, найденные обязательным adversarial-ревью (Opus 5 `claude-opus-5`) уже ПОСЛЕ мерджа [PR #705](https://github.com/gasyoun/Systema-Sanscriticum/pull/705).** Ревью вернулось после мерджа; оба дефекта измерены прогоном, оба регрессировали против до-H1659 поведения. **(1) Кураторское «Подтвердить оплату» заводило по сделке НА КАЖДЫЙ взнос** — измерено 3 сделки против 1: [`PromiseFulfillment::fulfil`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/PromiseFulfillment.php) создаёт платёж и лишь ПОТОМ связывает обещание, поэтому на момент обсервера нет ни прямой, ни обратной связи, а снятая эвристика «человек + курс» была единственным, что прикрывало этот путь; и это не край, а основной ручной сценарий закрытия рассрочки ([`Debtors.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Debtors.php), [`PaymentPromisesRelationManager.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource/RelationManagers/PaymentPromisesRelationManager.php)). Добавлен **третий переход** цепочки — план с НЕПОГАШЕННЫМИ (`active`/`expired`) обещаниями по этому же человеку и курсу. Это НЕ возврат эвристики: план должен существовать и быть ещё не закрыт, поэтому полностью оплаченный план прошлого года повторную покупку больше не глушит (закреплено отдельным тестом `a_fully_paid_plan_no_longer_suppresses_a_later_repurchase`); остаточная цена — покупка курса, по которому прямо сейчас висит неоплаченный план, относится к этому плану. **(2) Платёж мог «угнать» открытую сделку, помеченную ЧУЖИМ планом** — измерено 2 сделки против 1: взнос плана G2 закрывал сделку плана G1 чужими деньгами, штампа G2 не получал, следующий взнос G2 заводил вторую сделку, а будущий взнос G1 упёрся бы в «уже закрыта» и был бы молча съеден. `findOpenDealFor()` теперь получает группу платежа и **никогда не переиспользует сделку с ЧУЖИМ непустым штампом плана**; платёж без группы сделки планов не трогает вовсе. **(3) Тест денежной границы не покрывал групповую ветку** (фикстура строилась без обещаний, `payment_promises` проверялась на пустой таблице) — добавлен `bridge_stays_read_only_while_walking_the_instalment_group`, реально проходящий по коду H1659. Полный вердикт ревью (7 REFUTED, включая рантайм-доказательство отсутствия записей вне `deals`/`deal_transitions` и fault-injection на «ранг 4 не ветирует ранг 1») — [комментарием к PR #705](https://github.com/gasyoun/Systema-Sanscriticum/pull/705#issuecomment-5082410482). Тесты: `DealTest` 34 кейса, из них 4 новых; два (кураторское закрытие взносов, угон чужой сделки) измеренно падают на `main` до этой правки — проверено откатом обсервера на `origin/main` и прогоном. Полный Feature-набор **1932** зелёный, Pint чист. Executor: Opus 5 (`claude-opus-5`).

## [1.51.0] - 2026-07-22

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Kochergina lesson 1 → dedicated SRS deck (H1431).** New
  `srs:import-kochergina-lesson1` artisan command maps the already-sourced
  `database/seeders/data/memrise_6502608/level_02.csv` (Занятие I vocabulary,
  cross-checked against the digitized textbook) onto a dedicated system
  `SrsDeck` (`kochergina-lesson-1`, note type `kochergina_l1`, fields
  `devanagari`/`iast`/`translation_ru`/`translation_en`/`notes` per
  `ROADMAP_MEMRISE_SRS_SANSKRIT_HINDI_2026.md` P1), separate from the generic
  `srs:import-memrise` per-level decks for the same course. Grammar-class tags
  (`(m)`/`(n)`/`(m,n)`) in the Russian gloss are extracted into `notes` without
  dropping them from `translation_ru`. Idempotent; 7 feature tests, full Srs
  suite green. Behind the existing `SRS_ENABLED=false` gate; the import itself
  is a one-time manual deploy step, not auto-seeded.
- **Гейт согласия на рекламную рассылку в мессенджеры (152-ФЗ, H1430).** Путь
  рассылки анонсов `AnnouncementDispatcher` гейтил только email
  (`wants_email_announcements`), а Telegram/VK — ничем, и у `User` не было флага
  согласия на мессенджеры. Добавлен `users.wants_messenger_announcements` (boolean),
  которым теперь гейтится ветка `SendMessengerAlerts` в диспетчере анонсов;
  **транзакционные** уведомления (контент марафона, напоминания о долге, набор
  групп) идут мимо диспетчера и флагом НЕ ограничены. Политика (решение MG
  21-07-2026): новые аккаунты — opt-in из галочки согласия (`TrialController`),
  существующие — грандфазер в `true` разовым `UPDATE` в миграции (opt-out, чтобы
  охват не отвалился). Презумпция согласия для существующих сделала обязательной
  **отписку**: команды бота `/stop`/`отписаться`/`стоп рассылка` в
  `TelegramWebhookController` снимают только рекламное согласие, транзакционные
  уведомления остаются. Админ-колонка + фильтр «Анонсы в мессенджеры» в
  `UserResource`. Тесты: гейт диспетчера (шлёт только согласившимся) + отписка ботом.
  **Follow-up (не в этом PR):** захват согласия на прочих путях создания User
  (newsletter/соц-вход/`Lead→User` при оплате), VK-эквивалент `/stop`, тумблер в
  кабинете студента.
- **Промо-согласие (152-ФЗ) на обеих триал-формах захвата лида (H1429).** Форма
  `promo/blocks/trial_block` и универсальная модалка `components/trial-modal`
  постили в `leads.store` только с обязательной ПДн-галочкой — рекламной
  (`is_promo_agreed`) не было, так что их лиды всегда сохранялись с
  `is_promo_agreed=false` и не могли быть законно включены в отложенную рассылку
  (напр. сентябрьское напоминание). Добавлена вторая, необязательная галочка
  согласия на рассылку по эталону `promo/blocks/form_block`. **Известный
  companion-разрыв (не в этом PR):** путь рассылки в мессенджеры
  (`AnnouncementDispatcher` → `SendMessengerAlerts`) не гейтит Telegram/VK по
  согласию, а у `User` нет флага согласия на мессенджеры — см. `@DECIDE` в GTD.
- **Roadmap: teacher-load report + public schedule widget.** Layered `/ask` plan
  ([PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TEACHER_LOAD_PUBLIC_SCHEDULE_WIDGET_2026H2.md) +
  ROADMAP/ARCHITECTURE/IMPLEMENTATION/VERIFICATION siblings) for an admin
  «преподаватель × группы × направление» analytics page and a reusable public
  iframe-embeddable schedule widget, aimed at replacing the hand-typed
  `samskrtam.ru/raspisanie/` page. No code yet — plan only; wave-1 handoffs
  minted for execution.
- **Public schedule feed + embeddable widget (wave 1b, H1427).** New unauthenticated
  read-only feed `GET /api/public/schedule`
  ([`PublicScheduleController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/PublicScheduleController.php))
  behind a strict field-allowlist Resource
  ([`PublicScheduleResource`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Resources/PublicScheduleResource.php)
  — never emits `link`, `zoom_join_url`/`zoom_start_url`, or numeric ids), throttled 30/min and
  cached 5 min; plus a bare, iframe-embeddable widget page `GET /widgets/schedule`
  ([`PublicWidgetController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicWidgetController.php)
  + [Blade](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/widgets/schedule.blade.php)
  + [vanilla JS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/widgets/schedule.js))
  that renders a direction/teacher-filterable, weekday-grouped schedule and posts its height to the
  parent for iframe auto-resize, with `Content-Security-Policy: frame-ancestors` scoped to
  `samskrtam.ru` on that one response. Copy-paste embed artifact:
  [`docs/copy/public-schedule-widget-embed.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/public-schedule-widget-embed.md).
  Additive, inert-until-visited; pasting onto the live `samskrtam.ru/raspisanie/` page stays a
  separate explicit human go-ahead.

## [1.50.1] - 2026-07-21

### Changed
- **Годовой роадмап сверен с фактом (H1417).** [`docs/ROADMAP_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_2026_2027.md)
  отставал на 300+ коммитов от содержательной редакции 07-07-2026. Добавлен §1a-снимок
  сверки (прогресс ставок года, приехавшие вне тикетов программы, остаток долгов), статусы
  тикетов §5–§6 проставлены по реальным PR/handoff-архиву (R1 SRS ✅ в проде, M1/M2/O3/S5/S6/X1/X4
  и др.), три решения §2 помечены **⟳ ПЕРЕСМОТРЕНО** (CD-пайплайн H1046 построен вопреки «не строим»;
  Laravel 10→**12** вместо 11 H862; X0 из «неразблокируемого гейта» → катящийся прод-шаг Ивана).
  Метадок обновлён (backlog #1 закрыт). `Last updated` → 21-07-2026.

## [1.50.0] - 2026-07-21

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1396 §§2–4: чекаут переживает свою сессию (не-денежная половина).** Три
  дефекта, общий корень с §1 — «страница чекаута живёт дольше собственной сессии»,
  каждый за флагом (по умолчанию OFF), кроме §4-троттла (обычное усиление, которое
  уже есть у всех соседних роутов).
  - **§2 — тупик при истёкшей сессии без remember-me.** Залогиненный студент, чья
    сессия протухла между показом и сабмитом, приходил в
    [`PaymentController::createPayment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php)
    уже гостем; форму ему показывали БЕЗ гостевых полей (они в `@guest`), поэтому
    guest-required валидация сыпала четыре ошибки на невиданных полях, а свой же email
    ловил жёсткий отказ. Теперь скрытая метка `checkout_authed=1` из `@auth`-формы
    распознаёт это состояние и уводит студента на `/login` с intended-возвратом к
    оплате того же тарифа. За флагом `checkout_session_lapse_relogin`.
  - **§3 — идентификация возврата из банка по подписанному id заказа.**
    [`TochkaPaymentService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payments/TochkaPaymentService.php)
    слал неподписанные `redirectUrl`/`failRedirectUrl`, а `success()` опознавал заказ
    по `auth()->id()` + `latest('id')` — что ломалось в in-app WebView (Telegram),
    где редирект из банка уходит в другую cookie jar: реально оплативший студент
    попадал на гостевой экран «Войдите в аккаунт», а при двух pending выбирался НЕ тот
    заказ. Теперь возврат несёт `URL::signedRoute` с payment id, и `success`/`fail`
    опознают точный заказ по валидной подписи, переживая потерю cookie. За флагом
    `checkout_signed_return_url`.
  - **§4 — троттл `/csrf-token`.** Единственный роут web-группы без троттла; с
    `SESSION_DRIVER=file` каждый безкукисный хит писал новый session-файл. Добавлен
    `throttle:30,1` — как у соседних чекаут-роутов
    ([`routes/web.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php)).

  8 тестов (`CheckoutSessionRenewalHardeningTest`), `--filter="Checkout|Promo|Payment"`
  (241) + Pint зелёные. Флаги: `CHECKOUT_SESSION_LAPSE_RELOGIN` /
  `CHECKOUT_SIGNED_RETURN_URL` = true + `config:cache` после ревью.
  ([H1396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1396-Opus_Systema-Sanscriticum_promo-lost-on-session-renewal-full-price-charge_20.07.26.md))

## [1.49.1] - 2026-07-21

### Fixed
- **H1396 §1: промокод переживает обновление сессии в чекауте (денежный баг).**
  Применённый промокод жил ТОЛЬКО в `session('promo_code')`; анти-419 обновление
  CSRF-токена могло выдать новую пустую сессию, а remember-me пере-аутентифицировал
  пользователя — он проскакивал `auth()->check()` в
  [`PaymentController::createPayment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php)
  с потерянным промокодом и уходил в банк на ПОЛНУЮ сумму, хотя кнопка показывала
  скидку (повтор H071 #13 через другую дверь). Теперь код несётся в скрытом поле
  формы и пере-резолвится АВТОРИТЕТНО при сабмите (клиентское значение forgeable →
  те же правила `isCurrentlyActive`/`appliesToCourse`/`redeemedByUser`/`hasCapacity`):
  валиден → скидка списывается заново; протух → НЕ уходим молча в банк на полную и
  НЕ отказываем, а показываем явный экран
  [`confirm-price`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/checkout/confirm-price.blade.php)
  с новой ценой, и заказ создаётся только после явного подтверждения ровно на
  показанную сумму (RULED 20-07-2026 MG). За флагом `checkout_promo_survives_session`
  (по умолчанию OFF) — с флагом OFF `createPayment` байт-в-байт как раньше, что
  закреплено parity-тестом; денежный PR прод-инертен до
  `CHECKOUT_PROMO_SURVIVES_SESSION=true` + `config:cache` после ревью. Сверка
  двойного прогона (dual-run): две независимые сессии Opus 4.8 реализовали §1
  одинаково побайтно; выигравший лейн добавил флаг-гейт и parity-тест.
  4 теста (`CheckoutPromoRenewalTest`), `--filter="Checkout|Promo|Payment"` + Pint
  зелёные в CI. §2/§3/§4 — в отдельном follow-up.
  ([PR #631](https://github.com/gasyoun/Systema-Sanscriticum/pull/631),
  [H1396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1396-Opus_Systema-Sanscriticum_promo-lost-on-session-renewal-full-price-charge_20.07.26.md))

## [1.49.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1291: корпус возражений — микрокопия в точке продажи (ВРЕМЯ + ЦЕНА).**
  Последний лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)):
  готовые формулировки плейбука возражений H474/H482 (537 из 679 возражений —
  ВРЕМЯ и ЦЕНА) перенесены на поверхности, где возражение возникает. Карточка
  каталога объясняет единицу цены («Блок — обычно 4 занятия», только когда
  показанная цена — за целый блок); секция «Расписание» снимает страх пропуска
  («Занятие останется в записи… Пропуск не выбивает из курса»); у тарифной
  сетки — принцип поблочной оплаты (только курсам с целоблочными тарифами и не
  в режиме продажи записей), указатель на запрос «оплата по частям» (та же
  калитка кураторского чата, что у H1290), льготная строка с честной оговоркой
  «на многих курсах»; «Возврат: до начала — 100%» процитирован ссылкой на
  `/vozvrat` (shared string 4). Купившему весь курс микрокопия не показывается.
  Чекаут сознательно не тронут — там оба возражения уже отвечены. Каждая строка
  сверена с продуктом; «вводный разбор Гиты за 2 000 ₽» из плейбука на страницы
  НЕ попал — такого продукта в кодовой базе нет. Строки и решения:
  [`docs/copy/money-objection-corpus-pos-microcopy.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-objection-corpus-pos-microcopy.md);
  13 feature-тестов
  ([`tests/Feature/Shop/ObjectionPosMicrocopyTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Shop/ObjectionPosMicrocopyTest.php)).

## [1.48.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1357: детерминированный `/help` и «мои задания» в боте — до передачи `CuratorAi`.**
  `StudentSelfService` получил `matchesHelpIntent`/`helpMenu()` и
  `matchesHomeworkIntent`/`homeworkSummary()` по образцу уже существующего
  `matchesGroupsIntent`/`groupsSummary` — фиксированный текст и данные из БД,
  без обращения к LLM, так что куратор-ИИ никогда не придумывает статус
  домашнего задания. Подключено во всех трёх каналах, где уже стоит эта
  развилка: `TelegramWebhookController::processStudentQuestion`,
  `ProcessVkBotMessage::handle` (VK) и `StudentChatService::respond`
  (веб-чат). `/help` намеренно не включает «помощь» — это слово уже занято
  триггером передачи живому куратору (`HUMAN_TRIGGERS`) во всех трёх
  контроллерах. 29+112 тестов (`StudentSelfServiceIntentTest`,
  `StudentSelfServiceHomeworkTest`, полный набор `--filter=Bot`) зелёные,
  Pint чист.
  ([PR #610](https://github.com/gasyoun/Systema-Sanscriticum/pull/610),
  [H1357](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1357-Sonnet_Systema-Sanscriticum_bot-deterministic-help-and-homework-status_20.07.26.md)).
- **H1294: рекомендация школы — просьба «пригласите друга» без бонусной рамки.**
  Лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Существовавший блок «Приглашайте друзей» (иконка подарка, «вам начислят
  500 ₽» первым предложением, внутри вкладки «Прана») переписан в рамку
  рекомендации учителя: кредит — «в знак благодарности», механика объяснена
  до конца, нулевые счетчики скрыты; блок перенесен в основную вкладку
  кабинета (раньше исчезал при выключенной пране). Достроены недостающие
  поверхности: тихая просьба на странице успешной оплаты (только
  подтвержденное состояние), готовое личное сообщение для отправки знакомому
  и именная встреча приглашенного на главной («{Имя} рекомендует вам нашу
  школу») по валидному `?ref`-коду. `config/referral.php` и логика начисления
  не тронуты. Строки и решения:
  [`docs/copy/money-referral-invite-ask.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-referral-invite-ask.md);
  6 новых feature-тестов
  ([`tests/Feature/ReferralAskSurfacesTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/ReferralAskSurfacesTest.php)).

## [1.47.0] - 2026-07-20

### Security
- **H1359: Tochka payment-webhook now has an idempotency ledger + resurrection/amount-mismatch grant guards** ([H1359](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1359-Opus_Systema-Sanscriticum_tochka-webhook-delivery-ledger-and-grant-guards_20.07.26.md)). `WebhookController::handleTochkaWebhook` — the single automatic «оплачено → доступ» trigger in prod — granted on the bank's word alone: a re-delivered or replayed success JWT re-ran the entire paid path (`Payment::fireOnPaid` → access re-grant, deposit re-consumption, promo-slot double-burn via `PromoCode::markRedeemed`, referral re-reward) because `!== 'paid'` treated a **reversed** payment like a fresh pending one; the bank-reported amount was never compared to `payments.amount`; and no ledger existed for money webhooks.
  - **New append-only ledger** `payment_webhook_events` (one row per unique signature-valid delivery, keyed by `event_hash` = sha256 of the raw JWT body; `decision` ∈ applied / duplicate / rejected_resurrection / rejected_amount_mismatch / unmatched). Recorded on **every** signature-valid delivery, additively — no behaviour change with the flag off.
  - **Three refusals gated behind `features.tochka_webhook_guard`** (env `TOCHKA_WEBHOOK_GUARD`, default **false** — the money PR stays prod-inert until deliberately enabled): (a) a duplicate `event_hash` short-circuits to a 200 no-op; (b) a success for a payment that was paid and then reversed (detected via the `PaymentAudit` trail, `Payment::hasPriorPaidTransition()`) is refused — no resurrection; (c) a bank amount differing from `payments.amount` beyond `config('checkout.webhook_amount_tolerance')` (default 1.00 ₽) is refused. The amount is extracted defensively (unconfirmed payload key, absent ⇒ null ⇒ no check), keeping the change free of any live-bank dependency.
  - Operators see refusals via a new **«Rejected webhook deliveries»** block in `payments:audit-checkout-integrity` (read-only). **13 webhook tests** (7 existing + 6 new: flag-OFF `paid→failed→replay` parity pinning today's behaviour, flag-ON resurrection refusal, duplicate no-op, amount-mismatch no-grant, matched-amount success, additive ledger) + an audit-command case. Note a subtle bug fixed in passing: the `payment_audits.changes` column collides with Eloquent's protected `Model::$changes`, so the resurrection detector reads it via `getAttribute('changes')` (sibling-class protected access would otherwise return the empty dirty-tracking array).

## [1.46.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Free-drill funnel is now measured — an anonymous `game_events` telemetry rail for `/lila`** ([H1360](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1360-Opus_Systema-Sanscriticum_free-drill-funnel-instrumentation-game-events_20.07.26.md), [PR #622](https://github.com/gasyoun/Systema-Sanscriticum/pull/622)). The whole drill family (ligatures/roots/sort/match/cloze) previously stored **not one row** — `gate.js` kept its one-free-play state in `localStorage` only, and `GET /api/games/auth` was the sole server touchpoint — so the Tier-0 funnel question (how many visitors play → finish → hit the register wall → click «Начать бесплатно») was unanswerable. Added a first-party `POST /api/games/event` ingest (public web-guard, throttled, CSRF-exempt), a new `game_events` table, a `public/lila/telemetry.js` sender (`navigator.sendBeacon`), a `games:funnel --days=N` report command, and a Filament **«Воронка тренажёров»** page (manager/admin).
  - **Anonymous by construction (privacy fence):** the table stores no student id, no IP, and no user-agent — only a short client-minted `anon_id` stripped server-side to `[A-Za-z0-9]{0,32}`. The `authenticated` flag is stamped from the web session on the server, never trusted from the client. This keeps the table out of 152-ФЗ personal-data scope.
  - **`gate.js` untouched:** `telemetry.js` is a passive DOM observer of the wall and completion signals gate.js already produces, so the gate's own gating behaviour stays byte-for-byte unchanged (asserted by test). It uses a distinct `localStorage` key (`sgx_anon_v1`) and never reads or writes the gate's `sgx_played_v1`.

## [1.45.0] - 2026-07-20

### Fixed
- **Level-quiz answer positions — the `deva` cohort's quiz graded "always tap the top option" as 6/6** ([H1387](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1387-Fable_csl-guides_quiz-answer-position-fixed-index-defect_20.07.26.md), [PR #614](https://github.com/gasyoun/Systema-Sanscriticum/pull/614)). All six items in `config('marathon.cohorts.deva.level_quiz')` carried `correct => 0`, inherited verbatim from csl-guides, where every answer was authored first and nothing shuffled. `quiz_level` therefore measured nothing about the student — on the cohort whose first intake is **28-08-2026**. Option order re-ported from the fixed upstream bank ([csl-guides PR #119](https://github.com/sanskrit-lexicon/csl-guides/pull/119)) and verified in sync item by item, so the "ported verbatim" relationship the config comment promises still holds.
  - **The tests encoded the defect as a fixture:** three hardcoded `picks = [0,0,0,0,0,0]` as the perfect run and the class docblock stated it as intended behaviour. They now derive picks from config, so a future re-port cannot silently invalidate them.
  - Two guards added — answers must not all share one option position (**confirmed to fail** against a temporarily restored all-zero config before passing on the fix), and every `correct` index must exist in its own `opts`.
- **Salary period close: percent-scheme accrual ignored a closed month for
  payments created before the closure timestamp, leaving CI red.**
  `TeacherSalaryService::rollForwardEventMonth()` only rolled a `percent`/
  `percent_per_block` accrual event forward out of a closed month when the
  triggering payment's `created_at` was *after* the period's `closed_at`.
  Closing a month is meant to mean "nothing more accrues here," regardless of
  when the underlying payment was recorded — the non-percent schemes already
  enforce that unconditionally via `remapMonths()`/`rollForwardMonth()`. The
  event-level `created_at` comparison had no test coverage of its own and
  directly contradicted `tests/Feature/SalaryPeriodCloseTest.php`'s three
  scenarios (late payment roll-forward, per-teacher isolation, reopening),
  which were failing on `origin/main` at `c81a35d` independent of any other
  in-flight PR. Removed the `created_at` special-case; percent-type events now
  roll forward through `rollForwardMonth()` exactly like the fixed schemes.
- **H1391: checkout on phones (iPhone + Android) — four measured defects.**
  Reported only as "checkout issue on iPhone", with no symptom named, so the
  page was served locally and its geometry measured in a real browser at
  360/375/430/1280 px rather than guessed at.
  (1) **The pay button rendered as a 3-line stack.** Its flex row was
  `nowrap`, so the line could not break *between* items and squeezed the label
  instead — «К безопасной оплате» wrapped *inside words* to three lines and
  the primary CTA stood **116 px** tall against an intended ~60. Reproduced on
  **both** 360 px Android and 375 px iPhone; now one line and 88 px at 360 px
  (56 px at 430 px), with desktop untouched.
  (2) **The cookie bar sat on top of the bottom of every page.** It is
  `fixed bottom-0 z-[200]` and stacks to a column on mobile — **164 px, 24.6 %
  of a 375×667 viewport** — while `body` had no compensating padding. It now
  reserves its own height while open and releases it on dismiss.
  (3) **The pay button could hang forever.** The pre-submit CSRF `fetch` had
  no timeout and the button is disabled *before* the await, so a single
  stalled mobile connection left it permanently dead. Now bounded by a 4 s
  `AbortController`; submission proceeds either way.
  (4) **The prana slider was unusable by touch.** The `input[type=range]` box
  *was* its 8 px visual track — about 18 % of the 44 px iOS minimum — with no
  `touch-action`, so an imprecise drag resolved as a page scroll. Now a 44 px
  hit area with the track still drawn at 8 px.
  Also: the trust row was `hidden sm:flex`, hiding the МИР card list **and the
  page's only link to the refund policy** on every phone — it now wraps
  instead of hiding, and the slider thumb's `:hover` scale moved behind
  `@media (hover: hover)` so it stops sticking after a touch drag.
  No server-side or payment logic changed; the checkout POST path was
  re-verified end to end (guest user + `Payment` row created).

## [1.44.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1358: `payments:expire-stale-checkouts` — abandoned-checkout reaper.**
  Pending `Payment` rows created at checkout provisionally hold resources
  (prana spend, applied referral credit, consumed deposit credit, promo-code
  usage slots) with nothing ever releasing them if the buyer just abandons the
  bank tab — worse, `PaymentController` then hard-blocks a second checkout
  attempt for the same course while the abandoned row sits there. The
  reversal logic already existed (`Payment::booted()`'s `updated` listener
  refunds prana/referral credit and restores deposits/promo slots on any
  `pending → failed/canceled` transition) — it just had no trigger for
  silently-abandoned orders. The new command finds stale `pending` payments
  (timed promo reservations use `PromoCode::WEBHOOK_BUFFER_MINUTES` as
  authoritative; everything else uses the new
  `config('checkout.legacy_pending_days')`, default 30 days — deliberately
  wide, mirroring `AuditCheckoutIntegrity`'s own caution that these rows want
  manual-review-grade care before cancellation) and flips them to `failed`
  under a per-row `lockForUpdate()` transaction — the same idempotency
  pattern `WebhookController` uses — so a bank webhook landing in the same
  instant always wins the race instead of getting reaped. Deposit/trial,
  PayPal-pending, and conditional rows are hard-excluded (not abandoned
  checkouts in the same sense). Scheduled every 15 minutes with `--apply`
  (`Kernel::schedule`); without `--apply` the command only reports what it
  would fail, independent of the gate. Live runs require the new
  deploy-рубильник `features.checkout_stale_order_expiry` (off by default,
  `CHECKOUT_STALE_ORDER_EXPIRY=true` + `config:cache` to enable).

### Fixed
- **H1355: CI green + enforcing — flaky VK deep-link assertion, secretless
  Deploy-production job, 110 hidden Pint violations.** Three CI gaps: (1)
  `VkAuthTokenLinkingTest`'s substring-based `ref` assertion could collide by
  chance against a random 32-char token — now an exact query-param compare;
  (2) `.github/workflows/deploy.yml`'s "Deploy production" job painted `main`
  red on every push because prod SSH secrets aren't set yet (H478 gate) — now
  skips cleanly (success, not failure) until a human wires them; (3)
  `.github/workflows/ci.yml`'s Pint step had `continue-on-error: true` hiding
  96 files of violations — fixed and made enforcing. Pint's own auto-fix
  introduced a real bug (moved `use` imports below their first `::class`
  usage in `routes/api.php`/`scripts/export-analytics-tables.php`, silently
  resolving to the wrong namespace) — caught by the full suite and fixed by
  hand.

## [1.43.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1356: frequency-ranked root drills (top-25/50/100) in `public/lila/roots/`.**
  New match-family exercise pairing each Sanskrit verbal root (deva + IAST hint)
  with its most frequent attested form (RU gloss as hint), banded by DCS corpus
  frequency (top-25 flat, top-50/100 random-10-per-round, mirroring the
  `ligatures/` D6 pattern). Data is generated from the already-committed
  570-root RU fixture (`database/seeders/data/roots_frequency_ru.tsv`, H1280) by
  a newly **committed** generator (`scripts/build_root_drill_data.py`, with a
  `--check` drift mode) — closing the gap the ligatures family left open (its
  equivalent exporter was never committed). Registered as a new family card on
  `public/lila/index.html`; anti-drift coverage in
  `tests/Feature/Exercises/RootDrillPagesTest.php`.

## [1.42.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1290: installments — the no-shame «разбить на части» checkout ask.**
  Лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Механика рассрочки (`InstallmentPlanCreator` + `PaymentPromise`) была
  полностью невидима студенту — прямой ответ на возражение ЦЕНА существовал
  только на стороне куратора. Теперь на чекауте под кнопкой оплаты — тихая
  точка входа «Нужно разбить оплату на части?»
  ([`partials/installments-cta`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/installments-cta.blade.php)):
  форма запроса зовёт куратора через существующий `CuratorNotifier`
  (Telegram-чат) и подтверждает студенту на месте («платить сейчас ничего не
  нужно»). **Запрос не создаёт ни `PaymentPromise`, ни рассрочку, ни
  пользователя** (ruling D6 — условия согласует куратор в рамках лимитов
  финдира); блок скрыт, если кураторский чат не настроен. Регистр: «оплата по
  частям» вместо кредитно-коннотированной «рассрочки», планирование вместо
  уступки, никаких выдуманных условий. Строки и 9 unattended-решений:
  [`docs/copy/money-installments-no-shame-checkout-ask.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-installments-no-shame-checkout-ask.md);
  7 новых feature-тестов
  ([`tests/Feature/InstallmentRequestTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/InstallmentRequestTest.php)).

## [1.41.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1293: лесенка цен с позиционированием на витрине `/online`.** Лейн волны
  revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Витрина не показывала ни одной цены, сравнить блок с целым курсом было
  негде. Теперь секция «Сколько стоит обучение» (`#ceny`) + кнопка-якорь
  «Как устроены цены» на первом экране: три формата с позиционированием —
  кому подходит, что меняется между ступенями, что выбрать, если не уверены;
  честные «от N ₽» только из `ProductLadderAnchors` (хелпер расширен read-only
  якорем `minLiveFullPrice` — живой курс целиком), сравнение «блок против
  целого курса», JSON-LD `OfferCatalog` из `AggregateOffer`. Ни одной
  захардкоженной цены (grep-floor лейна); нет тарифов — числа и schema-нода
  не рендерятся, секция остается. Копи-док:
  [docs/copy/money-price-ladder-narrative-page.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-price-ladder-narrative-page.md).

## [1.40.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1292: диаспорный путь оплаты — PayPal-путь получил тайминги и подтверждение студенту.**
  Лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Для диаспорного покупателя PayPal-заявка — единственный рабочий путь, но CTA
  обещал только «доступ откроем после сверки платежа» (без срока и причины), а
  письмо о заявке уходило только админу — студент не получал ничего (finding F3
  аудита [CHECKOUT_PURCHASE_UX_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md)).
  Теперь CTA и форма-заявка называют срок («обычно в течение одного рабочего
  дня») и причину ручной сверки; страница заявки получила блок «Что будет
  дальше» и снятие страха двойного списания (общая строка 2) наверху; студент
  впервые получает подтверждение — новый
  [`PaypalClaimStudentAckMail`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PaypalClaimStudentAckMail.php)
  (админский `PaypalClaimReceivedMail` не тронут). Фича остается за выключенным
  `PAYPAL_CLAIM_ENABLED` (прод), прод-SMTP сломан (#504) — все инертно до
  включения. Строки и 6 непереданных решений:
  [`docs/copy/money-diaspora-paypal-buyer-path.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-diaspora-paypal-buyer-path.md);
  5 новых feature-тестов в
  [`tests/Feature/PaypalClaimTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PaypalClaimTest.php).

## [1.39.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1289: dunning — одно напоминание должнику стало лестницей из четырёх стадий.**
  Лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  `debts:remind` слал один и тот же шаблон повторно, без эскалации — одна
  температура либо пилит рано, либо шокирует поздно. Теперь стадия выбирается по
  дедлайну оплаты (00:00 МСК дня старта блока): мягкое напоминание → «дедлайн
  близко» (за 3 дня) → «доступ под угрозой» (после дедлайна) → «доступ закрыт»
  (с 14-го дня просрочки); пороги —
  [`config/dunning.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/dunning.php),
  тексты — [`app/Support/DunningStage.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/DunningStage.php)
  с переопределением менеджером в Marketing Settings (аддитивная миграция, 6
  nullable-колонок). Строка «Если оплата уже внесена — просто проигнорируйте…»
  сохранена во всех четырёх стадиях; фрагмент `{deadline}` стал честным по
  времени («срок оплаты истек…» вместо «нужно до <прошедшей даты>»); стадия 4
  честна по механике (материалы блока закрыты автоматически, «это не
  отчисление» верно по построению выборки должников). Win-back после отчисления
  не тронут (территория H219). Строки и решения:
  [`docs/copy/money-dunning-escalation-ladder.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-dunning-escalation-ladder.md);
  7 новых feature-тестов
  ([`tests/Feature/DunningEscalationLadderTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/DunningEscalationLadderTest.php)).

## [1.38.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1286: подтверждение покупки + онбординг первой недели.** Лейн волны
  revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Среди 24 mailable ни один не подтверждал покупку — момент «вы в деле» исчерпывался
  flash-строкой. Теперь: `PurchaseConfirmationMail` — чек-приветствие на каждую реальную
  оплату (что куплено, тариф, сумма, «доступ откроется в течение пары минут» — общая
  строка 1 волны, ссылка на кассовый чек платежной системы); `OnboardingDay1Mail` /
  `OnboardingDay5Mail` — «с чего начать» и мягкий чек-ин без вины. Email-канал инертен
  до починки прод-SMTP ([#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504),
  ESP-гейт H1147) — send-сайта у дней 1/5 сознательно нет (прецедент марафонских писем);
  рабочая доставка дней 1/5 уже сейчас — Telegram/VK через существующий `ScheduledReminder`
  (первая оплата конкретного курса, идемпотентно). `grantAccess()` и путь провайдера не
  тронуты. Строки и 8 решений:
  [`docs/copy/money-purchase-confirmation-onboarding-seq.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-purchase-confirmation-onboarding-seq.md);
  10 новых тестов (диспатч под `Mail::fake()`, рендер с реальными значениями, контракт
  голоса: эмодзи/срочность/ё).

## [1.37.0] - 2026-07-20

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1288: возврат — страница `/vozvrat` поверх оферты.** Лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  Trust row чекаута обещал «Возврат по оферте» и не вел никуда; порядок возврата
  существовал только внутри 8-страничного PDF. Новая страница
  ([`resources/views/docs/vozvrat.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php))
  излагает приложение №1 оферты в точных терминах — 100%-случаи, формула
  частичного возврата, поля заявления, 10 (десяти) рабочих дней — с mailto-кнопкой
  заявления (тема и тело предзаполнены полями §4.2) и ссылками на полный PDF.
  Trust row теперь ссылается на страницу со строкой «Возврат: до начала — 100%»
  (общая строка 4 волны, определена в
  [`docs/copy/_shared_strings.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md));
  подвал всех пяти layout'ов получил ссылку «Условия возврата» через партиал
  `footer-docs`. Term-by-term diff против дословной выдержки из оферты — приемочный
  тест лейна — в
  [`docs/copy/money-refund-policy-student-surface.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-refund-policy-student-surface.md);
  4 новых feature-теста (точные сроки, ссылка из trust row, ссылка на оферту).

## [1.36.0] - 2026-07-19

### Changed
- **H1287: честный дефицит — фальшивые таймеры и «16/20 мест» убиты.** Второй
  Fable-лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md),
  постановление D4). `price_block` и `course_streams_block` без настройки
  рендерили обратный отсчет «Повышение цены через:», сбрасывавшийся на +24 часа
  при каждой загрузке, зашитые «16/20 мест», мигающий бейдж «Акция» и ярлык
  «Осталось мало!». Теперь дефицит рендерится только с реальными данными:
  настроенный дедлайн — датой словами («Текущая цена действует до 26 июля,
  19:00»), а не тикающими цифрами; места — только при явно заполненных числах,
  без давящих ярлыков; пустая конфигурация деградирует до честного прайса.
  Фолбэк на дату вебинара и предзаполненные 16/20 в Filament-схеме удалены,
  истекший дедлайн скрывается. Строки и решения:
  [`docs/copy/money-honest-scarcity-urgency-rewrite.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-honest-scarcity-urgency-rewrite.md);
  machine-checkable floor закреплен новым
  [`tests/Feature/PriceBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/PriceBlockTest.php)
  (6 тестов) + переписанными сценариями
  [`tests/Feature/CourseStreamsBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/CourseStreamsBlockTest.php).

## [1.35.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1285: момент после оплаты — страницы success/fail вместо редиректов.** Первый
  Fable-лейн волны revenue-copy
  ([план](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
  `/payment/success` и `/payment/fail` — две самые эмоциональные точки воронки — не
  рендерили ничего: успех уводил флешем в кабинет, неудача выбрасывала на главную,
  теряя курс и возможность повтора. Теперь оба маршрута рендерят страницы
  ([`resources/views/payment/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views/payment)):
  успех — три состояния (подтверждено вебхуком / банк принял, ждем подтверждения,
  с ограниченным ожиданием «если через 10 минут доступа всё еще нет — напишите…» /
  гость с кнопкой входа), неудача — блок «Если деньги списались — не платите
  повторно…» первым, выше фолда, затем повтор оплаты со ссылкой на курс из
  последнего неоплаченного платежа (только чтение — статус платежа по-прежнему
  меняет исключительно вебхук Точки). Строки и решения:
  [`docs/copy/money-post-payment-moment-copy.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-post-payment-moment-copy.md);
  общие строки волны 1–3 (тайминг доступа, двойное списание, канал поддержки)
  определены в
  [`docs/copy/_shared_strings.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md).
  7 новых feature-тестов: рендер всех состояний, повтор к скрытому курсу уходит в
  каталог, страницы не мутируют статус платежа.

## [1.34.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1345: слежка за ростом файлового хранилища.** Запрос MG: «можно ли поставить слежку
  за этим, чтобы сервер не лег от файлов». До этого рост медиа не измерялся **ничем** —
  `disk_free_space`/`du` не встречались в коде ни разу, то есть узнать о проблеме можно было
  только по факту падения. Новая ежедневная команда `storage:check` (04:20, после
  `archives:cleanup` и `backup:clean`, чтобы мерить освобождённое место, а не временный пик)
  измеряет вес каталогов загрузок и свободное место на диске, светофор 0.8/1.0 как у
  дебиторки, алерт админам через Filament-уведомление. Пороги —
  [`config/storage_watch.php`](config/storage_watch.php) (env-backed), измерение —
  [`StorageUsageService`](app/Services/StorageUsageService.php). Команда **ничего не удаляет**
  (автоматически стирать работы студентов недопустимо) и **громко сообщает о собственной
  слепоте**: если обход упёрся в предохранитель по числу файлов, это отдельная строка алерта,
  а не молча заниженная цифра. Годится и как ручной инструмент — `php artisan storage:check --dry`
  печатает таблицу «занято/потолок/доля».
- **Roadmap хранения медиа** — [`docs/ROADMAP_MEDIA_STORAGE_2026_2028.md`](docs/ROADMAP_MEDIA_STORAGE_2026_2028.md)
  (+ метадок). Этапы привязаны к **триггерам, а не к датам**: на 19-07-2026 всё `storage/app`
  занимает ~20 МБ, переносить нечего, и календарный план был бы выдумкой. Разобрано, почему
  VK как соцсеть не подходит для работ студентов (152-ФЗ; документ VK доступен по ссылке без
  проверки прав, тогда как сейчас скачивание проверяет «владелец/преподаватель/админ»;
  аудио-API закрыт с 2016), включая отдельно рассмотренный вариант «VK-документы для фото» —
  фотография домашней работы остаётся работой студента, послабление по типу файла не помогает.
- **Опции S3 для VK Cloud и Yandex Object Storage** — правильная форма идеи «хранить на
  стороне VK»: закрытый bucket + подписанные ссылки, контроль доступа остаётся у нас. Диск
  один, провайдер выбирается парой `AWS_ENDPOINT`+`AWS_DEFAULT_REGION`; готовые наборы в
  [`.env.example`](.env.example) и `config/filesystems.php`. Пока не включено — включать по
  триггеру этапа 2.

### Changed
- **Закрыты незакрытые лимиты загрузок** (одобрено MG). Лид-магниты подписчиков принимали
  файл **любого размера и любого типа** (включая исполняемый) в публично отдаваемый каталог —
  теперь 20 МБ и только PDF/DOC/DOCX/EPUB. Материалы занятий: 100 МБ на файл при
  `appendFiles()` и **без потолка по количеству** — один урок мог копить видео без предела;
  теперь до 20 файлов. Справочные файлы задания — до 10.

### Fixed
- **Мёртвая тревога в мониторинге бэкапов.** Health-check `MaximumStorageInMegabytes` стоял на
  5000 МБ, тогда как стратегия уборки режет старые бэкапы уже на 1000 МБ — то есть тревога не
  могла сработать никогда. Оба числа выведены в env, порог тревоги опущен до 1200 МБ, где он
  означает осмысленное: «уборка не справляется». Важно, поскольку `storage/app` целиком входит
  в еженедельный бэкап, и рост медиа раздувает его линейно.

## [1.33.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1343: приём видео в домашних заданиях.** MG постановил «видео прежде не было, надо
  сделать» — до этого ни `accept` инпута, ни серверное правило `mimes:` не содержали ни
  одного видеоформата, так что попытка приложить видео падала на валидации, а подпись формы
  видео и не обещала. Добавлены `mp4`, `mov`, `webm`. **Потолок на файл НЕ поднимался**:
  30 МБ — это ~2–3 минуты видео с телефона в 720p, чего для ответа по заданию достаточно, и
  благодаря этому серверные лимиты (`upload_max_filesize`/`post_max_size` в php-fpm,
  `client_max_body_size` в nginx) менять не потребовалось — что важно, поскольку их реальные
  прод-значения нигде в репозитории не записаны.
- **Новый [`config/homework.php`](config/homework.php)** (env-backed, по домовому правилу
  «пороги не хардкодятся в контроллере/странице»): `max_files`, `max_file_kb`,
  `total_max_kb`, `allowed_extensions`. Подпись формы, фильтр выбора файлов и серверная
  валидация теперь читают ОДИН источник и не могут разъехаться — раньше три места правились
  вручную и по отдельности.

### Fixed
- **Превышение размера больше не даёт пустую страницу 413.** Валидация допускала 10 файлов ×
  30 МБ = 300 МБ на отправку против заявленного `post_max_size=100M`, то есть студент с
  четырьмя тяжёлыми файлами уже сегодня попадал в молчаливый отказ: PHP отбрасывает тело
  запроса, Laravel до валидации не доходит, набранный текст ответа теряется. Добавлены две
  защиты: проверка суммарного веса отправки (`total_max_kb`, по умолчанию 90 МБ — с запасом
  ниже `post_max_size`), дающая вежливую ошибку прямо в форме, и обработчик
  `PostTooLargeException` в [`app/Exceptions/Handler.php`](app/Exceptions/Handler.php),
  возвращающий студента в форму с объяснением вместо голого 413. С открытием видео эта
  дорожка стала бы горячей.

## [1.32.0] - 2026-07-19

### Fixed
- **Прикрепление ДЗ: файлы разных форматов больше не вытесняют друг друга.** Студентка
  сообщила, что при отправке домашнего задания нельзя приложить фото и аудио одновременно:
  выбрала jpg, затем добавила аудио — фотографии исчезали, и наоборот. Причина —
  `<input type="file" multiple>` при каждом новом выборе **заменяет** свой `FileList`
  целиком, а форма отправляла его напрямую; ни в JS, ни в Alpine не было накопителя, так
  что на сервер уходила только последняя пачка (потеря была чисто клиентской —
  `HomeworkService::recordSubmission` уже корректно копит файлы между отправками).
  [`resources/views/student/partials/homework.blade.php`](resources/views/student/partials/homework.blade.php)
  теперь держит собственный массив `File`-объектов, при каждом выборе дополняет его,
  дедуплицирует по имени+размеру и переписывает `input.files` через `DataTransfer` —
  формат выбора значения не имеет. Плюс: крестик для удаления отдельного файла,
  предупреждение при превышении лимита в 10 файлов (раньше лишние молча уходили в
  серверную валидацию), и `:key` в `x-for` больше не схлопывает одноимённые файлы.
  Поведение чисто клиентское, PHPUnit его не покрывает — проверять вручную в кабинете.

### Changed
- **H1295: ё-orthography normalisation sweep.** Mechanical prerequisite for the Systema
  revenue-copy wave (ruling D13/D14) — normalises existing user-facing Russian copy to the
  house no-ё rule (е instead of ё, except where dropping it would collide with a different
  word, e.g. «всё»/«все»). A one-off census script scoped to `resources/views/**`,
  `app/Mail/**`, and Russian label values in `config/*.php` classified 297 occurrences
  (MUST_KEEP / REVIEW / SAFE) and applied 275 ё→е replacements across 77 files; full
  rationale, review list, and decisions-taken-unattended in
  [`docs/copy/yo-orthography-normalisation-sweep.md`](docs/copy/yo-orthography-normalisation-sweep.md).
  22 intentional exceptions remain (the «весь»/«он»/«что» pronoun-case minimal pairs, plus
  two individually-reviewed aspectual-verb risks left ё per the ambiguity default).

## [1.31.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H164: Telegram Track C — @zapisi_ORSbot (class-booking bot) integration.**
  Executes the locked D7–D11 rulings
  ([DECISIONS_telegram_harvester.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_telegram_harvester.md#track-c--second-bot-account-zapisi_orsbot)):
  D8 go-forward webhook capture (`POST /api/webhooks/telegram-zapisi`,
  `verify.tg.zapisi` fail-closed secret middleware, `ProcessTelegramZapisiUpdate`
  normalizing into the same corpus schema as Track B, tagged `account_type=bot`)
  with D11 media download (`DownloadTelegramZapisiMedia`, Bot API `getFile` +
  raw download — an override of Track B's D4 metadata-only default, scoped to
  this chat only); D9 full-member-roster snapshot
  (`telegram-harvest:roster {peer}`, `TelegramHarvestSyncService::fetchRoster`,
  `RosterStoreWriter`) and a D11 MadelineProto-backfill media-download path
  gated by the new `services.telegram_harvest.media_download_peers` config
  (peer-scoped, D4 stays metadata-only for every other Track B peer); D10 a new
  independent `zapisi_class_schedules` table + `zapisi:send-reminders`
  (`SendZapisiBotMessageJob`, idempotent via `sent_at`, scheduled every minute,
  gated by the new `features.telegram_zapisi_bot` deploy flag); a new
  admin-only Filament cluster (`ZapisiClassScheduleResource` CRUD +
  `ZapisiBotDashboard` read view over the out-of-git roster/message store) and
  new encrypted `zapisi_bot_token`/`zapisi_webhook_secret`/`zapisi_chat_id`
  fields on `MarketingSetting`. D7 (add the chat as a Track B peer) needs no
  new code — Track B's existing `TELEGRAM_HARVEST_PEERS` mechanism already
  handles it once the chat's numeric id is discovered on a live host via
  `telegram-harvest:peers`; D8b (disable bot privacy mode via @BotFather) is a
  human action, filed as a GTD `@DO`. 17 new feature tests (webhook secret
  verification, normalization/store, media download, roster fetch/command,
  reminder scheduler, D11 peer-scoped backfill download). Sonnet 5
  (`claude-sonnet-5`). [PR #593](https://github.com/gasyoun/Systema-Sanscriticum/pull/593).
  [H164](https://github.com/gasyoun/Uprava/blob/main/handoffs/H164-Sonnet_DO_telegram-sanskrit-corpus_zapisi_orsbot_integration_04.07.26.md).

## [1.30.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1281 (D6): «Лигатуры по частотности» — деванагари-тренажёр конъюнктов.** Новое
  статичное семейство `public/lila/ligatures/` в существующей `public/lila/`
  игротеке (не новый движок — reuse `match/engine.js`+`match/engine.css` as-is, per the
  plan's non-goal). Данные — топ-200 санскритских лигатур (saṃyoga) по
  корпусной частотности из VisualDCS
  [`derived-data/Fonetika/regen-2026/ligature_freq.csv`](https://github.com/gasyoun/VisualDCS/blob/main/derived-data/Fonetika/regen-2026/ligature_freq.csv)
  (Digital Corpus of Sanskrit — Oliver Hellwig, CC BY 4.0; kosha manifest id
  `dcs-grapheme-frequency`), committed as
  [`data.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/lila/ligatures/data.js)
  with the regen command in its header. Three cumulative frequency levels —
  `top-10/` (all 10 shown), `top-50/` and `top-200/` (`perRound: 10`, a fresh random
  ten each "Заново") — each a `MatchExercise.mount()` pairing the Devanāgarī glyph to
  its IAST romanization, hint = corpus rank + % of all ligature tokens. Linked from the
  main `/lila/` catalogue as a fourth family; prior-art fence links out to
  [csl-guides](https://sanskrit-lexicon.github.io/csl-guides/) for the full script
  course rather than duplicating it. Static-only — no migration, no flag, no backend;
  ships with the normal deploy — see
  [DEPLOY_QUEUE №40](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).
  Sonnet 5 (`claude-sonnet-5`).
  [H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md).

## [1.29.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1280 (D4): SRS-колода «Корни санскрита по частотности».** Новая системная колода
  `sanskrit-roots-frequency` в существующем FSRS-тренажёре (H211): 570 санскритских
  корней в порядке корпусной частотности (kosha
  [`roots_frequency.tsv`](https://github.com/gasyoun/kosha/blob/main/data/roots/roots_frequency.tsv),
  H950, Digital Corpus of Sanskrit — Oliver Hellwig, CC BY 4.0), с русскими глоссами
  из WhitneyRoots'
  [`crosswalk/ru_root_glosses.tsv`](https://github.com/gasyoun/WhitneyRoots/blob/main/crosswalk/ru_root_glosses.tsv)
  (H347). Join committed в
  [`database/seeders/data/build_roots_frequency_ru.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/build_roots_frequency_ru.py)
  (readable via `indic_transliteration` for the Devanāgarī root form — `sanskrit-util`'s
  `iast_to_devanagari` is display-only and mangles consonant-final roots, so it was not
  used here); 59 из 629 DCS-ranked corpus roots have no RU gloss match — logged to
  `database/seeders/data/roots_frequency_ru_unmatched.tsv`, not silently dropped. New
  `SrsRootFrequencyDeckSeeder` (idempotent, keyed by `fields->dcs_lemma`) inserts cards
  in rank order so `ReviewService::queueFor()`'s `orderBy('id')` new-card query serves
  the highest-yield roots first with no new sort column needed. Feature test seeds a
  10-root fixture and reviews one card end-to-end. No engine change. Deploy: one-time
  `php artisan db:seed --class=SrsRootFrequencyDeckSeeder` — see
  [DEPLOY_QUEUE №39](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md);
  deck stays invisible to students until the existing `SRS_ENABLED` flag is on (R-6
  baseline protection, default OFF). Sonnet 5 (`claude-sonnet-5`).
  [H1280](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1280-Sonnet_Systema-Sanscriticum_srs-root-frequency-ru-deck_19.07.26.md).

## [1.28.0] - 2026-07-19

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Corpus-frequency learner surfaces staged (queued, docs-only):** new plan
  [`docs/PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md)
  (+ metadoc) staging two Tier-0 integrations via
  [`/ask-batch`](https://github.com/gasyoun/claude-config/blob/main/commands/ask-batch.md):
  a frequency-ranked RU root SRS deck
  ([H1280](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1280-Sonnet_Systema-Sanscriticum_srs-root-frequency-ru-deck_19.07.26.md),
  kosha `roots_frequency.tsv` × WhitneyRoots RU glosses → existing FSRS stack) and a
  conjunct-frequency Devanāgarī drill
  ([H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md),
  `dcs-grapheme-frequency` → `public/lila/` family);
  [`docs/SRS_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md)
  gains the content-deck row. Fable 5 (`claude-fable-5`).

## [1.27.0] - 2026-07-18

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1147: ESP transactional-email transport + `mail:preflight` guard — fixes issue #504's repo-side root cause.** `.env.example` no longer ships `MAIL_HOST=mailpit` as if it were a production value — local dev keeps mailpit, with a commented production shape adjacent pointing at the new [`docs/mail-esp.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mail-esp.md) setup contract (`.env` keys per driver class, SPF+DKIM+DMARC requirement, `mailing`-queue worker requirement). New `php artisan mail:preflight` command ([`app/Console/Commands/MailPreflight.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MailPreflight.php)) rejects a dev mail-catcher host or placeholder sender outside `APP_ENV=local` (non-zero exit, names the reason), warns (non-fatal) when `QUEUE_CONNECTION` isn't `sync`, and supports an opt-in `--send=<addr>` real test send; proven by `tests/Feature/Mail/MailPreflightTest.php` (7/7 green, no network by default). Added `symfony/mailgun-mailer` + `symfony/postmark-mailer` (+ `symfony/http-client`) so the existing `mailgun`/`postmark` blocks in `config/mail.php` are actually usable, alongside the already-generic `smtp` mailer — vendor choice stays a human `@DECIDE` (R-3), no vendor hardcoded. [DEPLOY_QUEUE №37](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) has the exact deploy sequence. **Does not claim mail is delivered** — issue #504 stays open until a human picks an ESP, creates the account, and installs the prod secret. Sonnet 5 (`claude-sonnet-5`). [H1147](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1147-Sonnet_Systema-Sanscriticum_esp-transactional-mail-transport-preflight_17.07.26.md).

## [1.26.0] - 2026-07-18

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1144 (W1-D1): производственная спецификация getcourse-паритета — R29-эквивалент, которого требует R-1.** [docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) + [метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.meta.md). 9 разделов: композиция всех 14 тикетов GC-* с состоянием, **сверенным с деревом** (`9b63861`) — по одному read-only агенту на тикет, каждый вердикт кроме high-confidence `NOT_BUILT` перепроверен вторым агентом с заданием **опровергнуть** его (25 агентов); **лестница приоритетов записи** (§2) — обобщение правила границы денежного ядра («слой `Deal` наблюдает денежное ядро и никогда его не авторизует») на все 14 тикетов, то самое правило, которого нет у роадмапа и которое всегда нужно сборщику; производственная глубина по GC-C1 (`Deal`+канбан, точка подключения моста — `PaymentObserver.php:63`) и GC-C2 (атрибуция по менеджерам); дата-билл, план флагов, 8 названных развилок (ни одна не разрешена — это работа человека) и последовательность «один шаг = один хэндофф».
  Три состояния расходятся с роадмапом H438: **GC-B3 частично сдан** ([PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549)), хотя роадмап числит его в «Later» — при этом привязка контейнера **не имеет ни одного потребителя** (вебхук резолвит конкретный `ZoomService`), а блока `services.bbb` нет, так что абстракция инертна; **GC-A3** понижен PARTIAL → NOT_BUILT (за «частичную сдачу» принимали объявленную самим тикетом базу переиспользования); **GC-C1** частично сдан, но в форме **отвергнутой** архитектуры.
  Главная находка — развилка F2: **два живых управляющих решения противоречат друг другу.** [Uprava DECISIONS_roadmap_forks_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md) §R2 (10-07) рулит «расширять `Lead`», ROADMAP §5 (11-07 00:01) рулит «отдельная сущность `Deal`»; H451 сдал `LeadStage`+`LeadKanbanBoard` 10-07 11:06 — **между** ними, корректно исполнив действовавший тогда рулинг. §R2 никогда не был помечен как superseded. Требуется решение человека. Opus 4.8 (`claude-opus-4-8`). [H1144](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1144-Opus_Systema-Sanscriticum_getcourse-parity-production-spec-r29-equivalent_17.07.26.md).

## [1.25.0] - 2026-07-18

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1146 (W1-D5): Memrise course 6679375 export runner + validator (time-critical, irreversible).** Memrise is sunsetting community courses with no published shutdown date; an agent cannot obtain a Memrise login, so the deliverable shrinks the human's export step to two commands. [`scripts/memrise_export.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/memrise_export.py) (stdlib-only, credential from `MEMRISE_SESSION` env var, never argv; `--dry-run`) emits exactly the `manifest.json` + `level_NN.csv` contract already read by `php artisan srs:import-memrise` ([`ImportMemriseSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportMemriseSrsDeck.php)). [`scripts/memrise_export_validate.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/memrise_export_validate.py) checks that contract with no network and no credentials — manifest parses, every declared level file exists, every CSV header contains every manifest-declared column, no empty levels — proven against [`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) and against both failure modes independently (removed level file, renamed CSV header). Runner is untested against live Memrise (no agent credentials) — see [the destination README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md) for the honest boundary and the CourseDump2022 fallback. Sonnet 5 (`claude-sonnet-5`). [H1146](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1146-Sonnet_Systema-Sanscriticum_memrise-export-runner-validator-6679375_17.07.26.md).

## [1.24.0] - 2026-07-18

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1224: «Жизненные правила для санскритологов» — новый раздел лендинга samskrte.ru.** Новый Filament-блок конструктора `life_rules_block` (17-й в `LandingPageResource`): 45 максим из [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md) (H1215 v2) предзаполнены дефолтом Repeater-поля — куратор просто перетаскивает блок на лендинг, текст редактируется через админку. Рендер — сплошной поток без аккордеона (по образцу шумановских Lebensregeln), свёрнутый до 7 правил с кнопкой разворота (Alpine.js, стиль `faq_block`). Раздел лендинга, не отдельная страница — руление MG 18-07-2026 ([метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md)). 4/4 теста зелёные ([`tests/Feature/LifeRulesBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/LifeRulesBlockTest.php)). Sonnet 5 (`claude-sonnet-5`). [H1224](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1224-Sonnet_Systema-Sanscriticum_lebensregeln-landing-section_18.07.26.md).

## [1.23.0] - 2026-07-18

### Changed
- **H1215/H1224: «Жизненные правила» — операционная модель зафиксирована в метадоке: квартальный цикл ревизий, три недостающих источника v3, руление публикации.** По follow-up-рулениям MG 18-07-2026 (после мерджа v2): (1) документ живой, ревизия каждые ~3 месяца по мере роста корпуса — нынешние ~40 курсов `Uprava/stenogrammy` лишь малая часть всех стенограмм школы; (2) корпус v3: стенограммы выступлений 2019–2022 + интервью с санскритологами **«Ипостаси санскрита»** и **«Санскрит в Венском университете»** (в v2 не задействованы, файлов на диске нет — передает MG); (3) публикация РУЛЕНА: **раздел лендинга** samskrte.ru, не отдельная страница — внедрение вынесено в [H1224](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1224-Sonnet_Systema-Sanscriticum_lebensregeln-landing-section_18.07.26.md) (Sonnet 5, `claude-sonnet-5`), затем DEPLOY_QUEUE. Обновлен [метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md) (назначение, решения, бэклог, ограничения, история). Fable 5 (`claude-fable-5`).

## [1.22.0] - 2026-07-18

### Changed
- **H1215 (v2): «Жизненные правила для санскритологов» — ревизия по стенограммам, 40 → 45 максим.** Первый прогон живого материала школы через манифест: 17 экстракторов Sonnet 5 (`claude-sonnet-5`) прошли ~60 MB стенограмм (`Uprava/stenogrammy` + `lecture-ui/transcription`) — 28 вводных Гасунса, все 16 занятий Парибка «Йога-сутры» 2025 (приоритет MG), Куликов, Клебанов, Бюлероведение, синтаксис, ликбезы, потоки, каллиграфия, чтение (Хитопадеша/Наль/Гита/Упанишады), воспевание, детский интенсив; синтез — Fable 5 (`claude-fable-5`). Все 40 правил v1 сохранены, ~12 существенно переписаны по материалу (вход через наслушивание; «библиотека наизусть» требует переучета; своя рукописная таблица против чужой печатной; лестница словарей с Кнауэром; подстрочник-«протез» Парибка; «из санскрита вышла наука о языке, не языки»; Бетлингк по рукописям до критических изданий; сон как примета погружения), +5 новых из стенограмм (разбор слова с конца, усидчивость 70→4, возвращение после перерыва, «ворох книг», проза прежде стихов). Факт-чек пп. 26/32/33 закрыт («восьмиклассники» → «школьники» по [Arzamas](https://arzamas.academy/mag/142-zaliznyak)/[МГУ](https://msu.ru/press/smiaboutmsu/onlayn-traditsionnaya-lektsiya-akademika-andreya-zaliznyaka-o-berestyanykh-gramotakh.html)). Метадок: провенанс v2, бэклог (следующее — стенограммы выступлений 2019–2022 у MG, затем @DECIDE публикация на samskrte.ru). Текст: [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md); [H1215](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1215-Fable_Systema-Sanscriticum_lebensregeln-sanskritologov_18.07.26.md).

## [1.21.0] - 2026-07-18

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1215 (v1): «Жизненные правила для санскритологов» — 40 максим по образцу Шумана, голосом Зализняка.** Манифест школы samskrte.ru: жанровая рамка — «Musikalische Haus- und Lebensregeln» Шумана (1848), голос — RWS-регистры [zalizniak-method](https://github.com/gasyoun/RuWritingStyles/blob/main/styles/passports/zalizniak-method.yml) + [zalizniak-shkolnikov-1](https://github.com/gasyoun/RuWritingStyles/blob/main/styles/passports/zalizniak-shkolnikov-1.yml); оси: ухо vs. глаз (устная Индия против табличной Европы — опирается на диагноз «NO AUDIO» из [DIGITAL_SANSKRIT_PEDAGOGY_FIELD_2026.md](https://github.com/gasyoun/SanskritGrammar/blob/main/DIGITAL_SANSKRIT_PEDAGOGY_FIELD_2026.md) §3.7) · ежедневное ремесло · инструменты · метод · этос. Спецификация утверждена в интервью MG (3 раунда, 11 вопросов) — зафиксирована в [метадоке](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md). Текст: [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md). Ревизия по стенограммам (выступления MG, записи курсов, интервью санскритологов) = [H1215](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1215-Fable_Systema-Sanscriticum_lebensregeln-sanskritologov_18.07.26.md). [PR #564](https://github.com/gasyoun/Systema-Sanscriticum/pull/564). Fable 5 (`claude-fable-5`).

## [1.20.0] - 2026-07-18

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1197 (Jivo-паритет S2/5, Pillar 2): проактивный монитор посетителей + оператор пишет первым.** Второй уникальный столп Jivo: куратор видит **живой список посетителей на сайте сейчас** (город из S1, текущая страница, время на сайте) — включая тех, кто ещё ничего не написал, — и может **написать первым**; сообщение всплывает в чат-виджете посетителя. Новая эфемерная таблица ([`create_support_visitor_presences_table`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_130000_create_support_visitor_presences_table.php)); presence-beacon [`PublicPresenceController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicPresenceController.php) `POST /support/presence` апсертит строку по `guest_token` (реюз H536), ответ несёт `conversation_id` — так проактив куратора долетает до молчащего посетителя; гео резолвится тем же [`VisitorGeoResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/VisitorGeoResolver.php) (S1) через [`ResolveVisitorPresenceGeoJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ResolveVisitorPresenceGeoJob.php); [`PruneStaleVisitorPresencesJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/PruneStaleVisitorPresencesJob.php) выметает устаревшие (окна — [`config/support_presence.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_presence.php)). Операторская страница [`VisitorsOnline`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/VisitorsOnline.php) «Посетители онлайн» (гейт: флаг + не-преподаватель, как `Helpdesk`): живой список (`wire:poll`) + кнопка «Написать» — тред открывается/переоткрывается (реюз `openForGuest`/`openFor`), curator-сообщение бродкастится `ChatMessageSent`; виджет [`support-chat-widget.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/support-chat-widget.blade.php) шлёт beacon с первого захода и раскрывается на проактив. **Осознанное отступление:** источник правды — beacon → таблица (heartbeat) + `wire:poll`, а не Reverb presence-канал (дешевле по WS, полностью тестируется без Reverb; см. [ROADMAP §3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)). Боты НЕ пишут людям сами (принцип MG) — приглашение шлёт только человек. Всё за флагом `support_visitor_presence` (**OFF** по умолчанию); **@DECIDE MG — 152-ФЗ sign-off** на отслеживание анонимного посетителя (гейт прод-включения, не билда). 20 новых тестов ([`SupportVisitorPresenceTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorPresenceTest.php) 9 · [`VisitorsOnlinePageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/VisitorsOnlinePageTest.php) 9 · +2 render) + full suite 1582 зелёные; деплой — [DEPLOY_QUEUE №32](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). [PR #560](https://github.com/gasyoun/Systema-Sanscriticum/pull/560). Opus 4.8 (`claude-opus-4-8`).

## [1.19.0] - 2026-07-17

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1196 (Jivo-паритет S1/5, Pillar 1): гео/город посетителя веб-чата в панели куратора.** Куратор в [Helpdesk](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php) теперь видит «📍 Город, Страна» и страницу входа гостя — тот самый визитор-слой, ради которого держат Jivo на samskrtam.ru. Аддитивная миграция ([`add_visitor_context_to_support_conversations`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_120000_add_visitor_context_to_support_conversations.php)) добавляет `visitor_ip/city/region/country/geo_resolved_at/entry_url/referrer` на тред; [`PublicChatController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicChatController.php) фиксирует IP+страницу+referrer при первом сообщении (идемпотентно, без внешних вызовов), а `ResolveVisitorGeoJob` → `VisitorGeoResolver` резолвят город асинхронно по драйверу из [`config/support_geo.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_geo.php) (`null`-дефолт / `cloudflare` / `ipapi`). Виджет шлёт `page`. Всё за флагом `support_visitor_geo` (**OFF** по умолчанию); провайдер города — @DECIDE MG (см. [ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) §2). 11 тестов [`SupportVisitorGeoTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorGeoTest.php) + 23 регрессионных чат-теста зелёные; деплой — [DEPLOY_QUEUE №31](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). Новый [`docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) ставит задачи по всем 6 требованиям паритета (S1 сделан; S2–S5 = H1197–H1200). Opus 4.8 (`claude-opus-4-8`).

## [1.18.1] - 2026-07-17
### Fixed
- **H1145: `config/srs.php` default restored to `false` (R-6 baseline protection).** The default had been flipped to `true` by H447 (PR #442, commit `6267d70`) for an August-2026 pilot rationale superseded by R-5/R-6 — three other places (the same file's docblock, `routes/web.php` ~L260, `DEPLOY_QUEUE.md` #24) still asserted OFF-by-default, so an unpatched deploy would have put an SRS nav entry in front of every student and corrupted the R20 baseline. `tests/Feature/Srs/SrsFlagDefaultTest.php` pins `config('srs.enabled') === false` and `GET /dvaram/srs` → 404 with no `SRS_ENABLED` in env; full SRS suite (30 tests) and full `php artisan test` (1549 tests, 4478 assertions) green. Protects the R20 baseline — does not start it (that clock begins only when a human deploys `DEPLOY_QUEUE.md` #25). [PR #553](https://github.com/gasyoun/Systema-Sanscriticum/pull/553).
### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **W1-D4: пять Mailable марафона из рулевого пакета H1067 (H1148).** `MarathonWelcomeMail`/`Day1`/`Day2`/`Day3`/`RecordingMail` + шаблоны `resources/views/emails/marathon/` — текст перенесен ДОСЛОВНО из [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) (обращение «вы», без эмодзи и срочности — анти-urgency дизайн сохранен); плейсхолдеры только рулевые ({link}/{tg_link}/{date}/{host}/{coupon}/{recording_link}); Day3 несет оба трек-варианта (3а/3б). Все на очереди `mailing`, [MarathonMailablesTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Mail/MarathonMailablesTest.php) — рендер/темы/очередь/отсутствие неразрешенных плейсхолдеров и эмодзи. **Отправка сознательно инертна**: send-сайтов вне `app/Mail/` нет — канал ждет ESP-гейта (H1147), Telegram остается основным; DEPLOY_QUEUE №27a. Fable 5 (`claude-fable-5`) по разрешению MG на Sonnet-ряд.

## [1.18.0] - 2026-07-17
### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **GC-B3: шов `WebinarProvider` (страховка от ухода Zoom, руление R1 — BigBlueButton).** Интерфейс с тремя методами (createMeeting / fetchParticipants / normalizeWebhook); `ZoomService` реализует его без изменения поведения (вебхук-контроллер потребляет `normalizeWebhook` — разбор байт-в-байт прежний); скелет `BigBlueButtonService` с формой BBB API (бросает до развертывания Q4); провайдер-нейтральные алиасы `meeting_*` поверх `zoom_*` (реверсивная миграция, бэкфилл копией); биндинг шва на Zoom-драйвер. Авто-создание Zoom-встреч НЕ восстановлено — остается @DECIDE GC-B1. 7 unit-тестов шва; CI зеленый. [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549) + деплой-строка №29 ([PR #550](https://github.com/gasyoun/Systema-Sanscriticum/pull/550), общий `php artisan migrate`). H601, Fable 5 (`claude-fable-5`).

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **GC-B3: шов `WebinarProvider` (страховка от ухода Zoom, руление R1 — BigBlueButton).** Интерфейс с тремя методами (createMeeting / fetchParticipants / normalizeWebhook); `ZoomService` реализует его без изменения поведения (вебхук-контроллер потребляет `normalizeWebhook` — разбор байт-в-байт прежний); скелет `BigBlueButtonService` с формой BBB API (бросает до развертывания Q4); провайдер-нейтральные алиасы `meeting_*` поверх `zoom_*` (реверсивная миграция, бэкфилл копией); биндинг шва на Zoom-драйвер. Авто-создание Zoom-встреч НЕ восстановлено — остается @DECIDE GC-B1. 7 unit-тестов шва; CI зеленый. [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549), H601, Fable 5 (`claude-fable-5`). Деплой: [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) — общий `php artisan migrate`.

## [1.17.1] - 2026-07-17

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1067: marathon 28-08 cohort RU comms pack.** New [marketing/marathon-2026-08/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08) — two landing-copy variants (beginner-fear-focused / outcome-focused) + shared FAQ, a 5-email sequence (drafts only: prod SMTP broken, [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504)), and @samskrte channel posts with a publication-order table. Authoring-only: publish steps are queued as DEPLOY_QUEUE №25 (human-gated); the day 1–3 bot drip in `config/marathon.php` stays canonical and is not duplicated. Testimonial slots publish only with a real quote (`MARATHON_TESTIMONIAL`). Authored by Fable 5 (`claude-fable-5`), [PR #544](https://github.com/gasyoun/Systema-Sanscriticum/pull/544).

## [1.17.0] - 2026-07-16

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1046: CI/CD deploy pipeline (GitHub Actions → SSH → `deploy.sh`), MG-confirm gate.** New `.github/workflows/deploy.yml` — Option A of the [H478 deploy-gate decision](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md): every push to `main` (or manual `workflow_dispatch`) queues a run gated by a GitHub Environment (`production`) approval — MG must click Approve before the runner SSHes to prod and runs the existing `sudo bash deploy.sh` (unchanged). No agent holds prod credentials; the SSH key lives only in the Environment's secrets. **Server-side setup (deploy user, narrow `sudoers`, GitHub Environment + secrets) is a separate one-time human step** — see `docs/deploy.md` §CI/CD and `DEPLOY_QUEUE.md` §D1 — until done, the workflow only accumulates harmless "Waiting" runs.

## [1.16.0] - 2026-07-16

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H1005: RQ4 admin stats page.** New `/admin/rq4-study-dashboard` (admin/super_admin only): enrollment count + arm split, pre/post/retention-test completion counts and percentages, and how many participants are currently due a retention reminder. Built so MG can check enrollment numbers himself — **he doesn't hold SSH credentials to the production server** (only the deploy contractor does, per `docs/deploy.md`), so an artisan-command-only report would still require going through the contractor every time. 3 tests in `tests/Feature/Rq4StudyDashboardTest.php`.

### Changed
- **H987 follow-up: RQ4 consent text approved by MG 15-07-2026.** No wording change — `Rq4StudyController::CONSENT_TEXT` is exactly the draft reviewed in chat, only the "not finalised" doc-comment is removed. Protocol §6.4 is now the last of the 4 `@DECIDE` items ruled — the RQ4 study spec is fully decided; `features.rq4_study` still ships OFF by default (flipping it live is a separate, later call).

## [1.15.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H987: RQ4 study harness (on-ramp-first vs Талмуд-first learning-gain study).** New `/rq4-study` flow behind `features.rq4_study` (OFF by default): consent + intake (self-reported prior exposure), stratified 1:1 arm assignment via a minimisation rule (`Rq4Participant::assignArm`), a 3-phase diagnostic (pre_test/post_test/retention_test) reading the vendored `resources/data/rq4_item_bank.json` (SanskritGrammar's H984 item bank), and a `rq4:send-retention-reminders` command (scheduled daily) that queues one `ScheduledReminder` per participant whose 4-week retention window has arrived — reuses the existing reminder infrastructure (H187) rather than building a new notification channel. New `rq4_participants`/`rq4_responses` tables. Draft consent text included, marked not-finalised pending MG's review (protocol §6.4). 9 tests in `tests/Feature/Rq4StudyTest.php`.

## [1.14.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H962: cabinet remake Phase 0 — instrumentation-first baseline (R20 gate).**
  The current (pre-hybrid) student cabinet now emits the event vocabulary of
  [`docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  §4 through the EXISTING `activity_events`/`ActivityTracker` pipeline (no new
  storage): server-side `cabinet.home.view`, `lesson.mark.mastered`,
  `access.renewal.complete` (new `PaymentTelemetryObserver`, self-service paid
  transition only — money code untouched); client-side via first-party
  `POST /dvaram/telemetry` (whitelist `ActivityEvent::CLIENT_CABINET_EVENTS`,
  inline JS partial, declarative `data-track-*` blade attributes) for
  `cabinet.continue.click`, `course.tab.view` (surface=dashboard),
  `cabinet.homework.rework.click`, `offer.impression`/`offer.click`
  (kind=next-block, locked lessons on the course page) and
  `access.renewal.start` (debt CTAs). `lesson.view.heartbeat` and
  `cabinet.live.zoom.click` are NOT double-written — the readout command
  **`php artisan cabinet:baseline`** aggregates them from their existing tables
  (`lesson_views`, `schedule_join_clicks`) under the §4 names and honestly
  lists the §4 events that have no current surface. No third-party trackers;
  no UX change. Baseline must run ≥2 weeks before the hybrid ships (R20).
  (Fable 5 `claude-fable-5`, [H962](https://github.com/gasyoun/Uprava/blob/main/handoffs/H962-Sonnet_Systema-Sanscriticum_student-cabinet-remake-instrumentation-phase_15.07.26.md))

## [1.13.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H965: kosha last-mile pipeline, Hop C difficulty-score advisory consumption.**
  `/reading/kosha-demo` (same route/flag as H959's Hop A) now also reads the
  vendored `resources/data/kosha_reading_pack_difficulty.json` — kosha's real
  `reading-pack-difficulty` dataset from its H949 scorer, not re-derived here —
  and shows the pack's composite difficulty + four axis scores (vocab, sandhi,
  morphology, compound), plus a ranked list of all 5 scored packs
  (easiest→hardest) with the current page highlighted. Purely advisory per the
  spec's Hop C ruling — nothing here reorders the reader or any course. 2 new
  tests in `tests/Feature/ReadingPackTest.php` (6 total). Closes the last open
  piece of [`docs/LAST_MILE_PIPELINE_SPEC.md`](https://github.com/gasyoun/SanskritGrammar/blob/main/docs/LAST_MILE_PIPELINE_SPEC.md)
  on the Systema side — Hops A, B, and C now all consumed.

## [1.12.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Student-cabinet mockup #5 — direction D «Путь» / Journey & membership hub (H958).** Per
  M.G. ruling R28 (15-07-2026), completing the four-direction set: the cabinet renders the
  school's ladder (письмо → грамматика → тексты) as a station map — done/current/next/horizon
  nodes, milestones as learning-contour landmarks (never payment deadlines), the next station
  «загорается» ONLY after full completion of the current one (no timers, «станция подождёт»),
  membership as path-continuity between paid stations, and an «Вне пути — и это нормально»
  shelf so the zig-zag student is never shamed. 3 pages (incl. the completion state with the
  lit ladder offer) on the shared design system; browser-verified, 6 screenshots
  ([docs/mockups/student-cabinet-remake/journey-membership-hub/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/journey-membership-hub)).
  Non-destructive; the production-direction pick is now the only remaining M.G. `@DECIDE`.

## [1.11.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Hybrid production spec for the cabinet remake (H961, ruling R29).** M.G. closed the
  four-direction exploration: production = hybrid — B «Курс как дом» chassis + A's
  «Сегодня»-band-with-homework and recovery mode + C's ownership shelves, progress rail and
  ownership-expansion offer + D's path-in-«Прогресс», completion-lighting master offer rule
  and вехи. Binding spec with page deltas vs the B v2 reference, unified offer precedence,
  engineering bill, instrumentation-first event schema (R20 gate) and the phased sequence:
  [docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_HYBRID_PRODUCTION_SPEC_2026.md)
  (+ sibling metadoc). Phase 0 (instrumentation baseline) queued as H962.

## [1.10.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **H959: kosha last-mile pipeline, Hop A reader-as-a-service demo.** New
  `/reading/kosha-demo` route (`app/Http/Controllers/ReadingPackController.php`)
  renders the vendored feed `resources/data/kosha_reading_pack_nala_1.json`
  (kosha's `dcs-reading-pack-nala-1`) as a word-by-word reading page: each
  token is a native `<details>`/`<summary>` disclosure (no custom JS) showing
  lemma, morphology, and gloss on tap — no external link or runtime lookup
  needed, every field already lives in the vendored feed. Gated by new
  `features.kosha_reader` flag (`KOSHA_READER` env, OFF by default, mirrors
  `slovar_enrichment`/`kosha_srs`) — with the flag off the route 404s. 4 tests
  in `tests/Feature/ReadingPackTest.php`. Closes the reader half of
  [`docs/LAST_MILE_PIPELINE_SPEC.md`](https://github.com/gasyoun/SanskritGrammar/blob/main/docs/LAST_MILE_PIPELINE_SPEC.md)'s
  Hop A (Systema side); Hop B's SRS-deck import shipped separately (H955).

## [1.9.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Student-cabinet mockup #4 — direction C «Библиотека» / Learning library (H957).** Per M.G.
  ruling R27 (15-07-2026): the cabinet as a personal library of владения — five shelves
  (Идут сейчас / Мои записи / Истёкшие-с-продлением / Завершённые / Материалы), expiry
  ribbons, progress-as-navigation rail (Khan pattern) on the subject page, an
  ownership-expansion offer after progress, and the membership card as a native shelf-level
  slot. 3 pages on the shared design system; browser-verified (console clean, no 390px page
  overflow — shelf scrollers are intentional), 6 screenshots
  ([docs/mockups/student-cabinet-remake/learning-library/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/learning-library)).
  Non-destructive; winner still an M.G. `@DECIDE`.

### Fixed
- **Mobile full-page screenshots of mockups #2/#3 regenerated:** the fixed bottom bar was
  stitched mid-page by the capture method; it now renders at the page end (screenshots only,
  no mockup-code change).

## [1.8.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Student-cabinet mockup #3 — direction A «Сегодня» / Today-first coach (H956).** Per M.G.
  ruling R26 (15-07-2026): the home is a numbered day plan with a fixed honest order
  (unfinished lesson → returned homework → today's live → first steps → ONE next step after a
  real progress event), «Почему такой план?» transparency foldline answers the direction's
  opaque-authority risk, and a recovery state (declined payment) leads with the problem banner
  and suppresses all offers. 4 pages on the shared B-v2 design system so directions compare on
  architecture, not styling; browser-verified (console clean, no 390px overflow), 7 screenshots
  ([docs/mockups/student-cabinet-remake/today-first-coach/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/today-first-coach)).
  Non-destructive; winner still an M.G. `@DECIDE`.
- **H955: kosha last-mile pipeline, Rung B1 demo import.** New
  `php artisan srs:import-kosha-b1-demo` (`app/Console/Commands/ImportKoshaSrsDeckB1Demo.php`)
  imports the vendored feed `resources/data/kosha_srs_deck_b1_demo.json`
  (kosha manifest id `kosha-srs-deck-b1-demo` — content vocabulary of the
  Nala-1 reading pack, `core_rank`-ordered, function words stripped) into one
  system Saraswati SRS deck (`kosha-b1-demo`), mirroring
  `SrsSanskritDeckSeeder`/`ImportMemriseSrsDeck`'s idempotent `firstOrCreate`
  pattern. Card insertion order == feed `rank` order (`srs_cards` has no
  `sort_rank` column yet — a schema migration is deliberately deferred to a
  human-reviewed production follow-up, not built here). Gated by new
  `features.kosha_srs` flag (`KOSHA_SRS` env, OFF by default, mirrors
  `slovar_enrichment`) — with the flag off the command writes nothing.
  5 tests in `tests/Feature/Srs/ImportKoshaSrsDeckB1DemoTest.php`.

## [1.7.0] - 2026-07-15

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Student-cabinet mockup #2 — «Курс как дом» v2 (H954, iterates H822 direction B).** Per
  M.G. rulings R21–R25 (14-07-2026, recorded in
  [docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md)):
  8 pages instead of 4 (+ библиотека записей со слотом членства «Самскрте+» 2000 ₽/мес,
  календарь, прогресс+сертификат, помощь/сообщения), editorial-academism restyle, job-named
  navigation («Сегодня / Календарь / Записи / Прогресс / Оплата и доступ / Помощь»), light JS
  (hash-addressable course tabs, theme toggle, foldlines). Browser-verified: console clean on
  all 8 pages, no 390px overflow, 11 screenshots committed
  ([docs/mockups/student-cabinet-remake/course-workspace-v2/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace-v2)).
  Non-destructive; winner still an M.G. `@DECIDE`.

## [1.6.0] - 2026-07-14

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Student-cabinet remake, first decision artifact (H822).** Evidence-led remake package:
  research ledger ([docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_RESEARCH_2026.md)),
  6-platform EdTech comparison ([docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_EDTECH_COMPARISON_2026.md)),
  20 M.G. rulings + 4 whole-cabinet architecture directions
  ([docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_REMAKE_DIRECTIONS_2026.md)),
  and the first browser-verified static mockup — direction B «Курс как дом» (course
  workspace), 4 linked pages, light/dark, mobile bottom-nav, console-clean, screenshots
  committed ([docs/mockups/student-cabinet-remake/course-workspace/](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/docs/mockups/student-cabinet-remake/course-workspace)).
  Non-destructive: no production Blade/route/controller changes. Winner is an explicit
  M.G. `@DECIDE`; remaining three mockups are decision-gated.

## [1.5.0] - 2026-07-14

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Companion metadocs for the last 16 docs — UX-audits, strategy & one-offs (H891).** Third
  and final metadoc sweep (after H887's 13 roadmaps and H890's 31 manuals/specs): the 8
  `*_UX_AUDIT_2026` audits, 5 strategy/marketing docs (BUSINESS_MODEL_CANVAS,
  GROWTH_STRATEGY_2026_2027, jivo, growth-ideas-2026, zapisi-katalog-strategiya), and 3
  one-offs (deploy-checklist-audit-fixes, lead-magnet-article-first-sentence-ru,
  WIKIDATA_SAMEAS_SPOTCHECK) now each have a sibling `.meta.md`. Point-in-time reports and
  completed checklists carry a `retired`/`superseded` deprecation status; `jivo.meta.md`
  records that the doc's "current state" claims are unreliable (support-subsystem-map.md is
  ground truth). **Every `docs/*.md` in the repo now has a metadoc** (60 across H887/H890/H891).
  Docs only.
- **Companion metadocs for 31 manuals, specs, and reference docs (H890).** Second metadoc
  sweep after the 13 roadmaps (H887): every `docs/` manual (admin/student/debtors/finance/
  accountant, onboarding, cabinet-bot), spec (`*-spec`, ATTRIBUTION_FIELDS_SPEC, TZ_arzamas,
  direct-teacher-receipts, newsletter-subscribe, partner-program, revenue-recognition,
  student-unblock-access-feed), and operational/reference/security doc (deploy,
  php-8.3-upgrade, webhook-security, money-core-adversarial-review, support-subsystem-map,
  support-identity, telegram-userbot-inventory, vitrina, the two SANSKRIT_HUB indices,
  FINANCE_REVIEW_RHYTHM) now has a sibling `.meta.md` per the `/metadoc` contract. Each in its
  subject's language (ru/en). The 8 UX-audits and 5 strategy docs are a separate genre, left
  for a future sweep. Docs only.
- **Companion metadocs for all 13 roadmap docs (H887).** Every `docs/*ROADMAP*.md` /
  `docs/*_ROADMAP.md` / `docs/IMPLEMENTATION_MAP_*.md` now has a sibling `.meta.md` holding
  its purpose, audience, provenance (real git creation date + model), a ranked improvement
  backlog (each row owned by an `H###` or `parked`), limitations, intended-use/misuse,
  maintenance/sunset, deprecation status, and revision history — closing the "13 roadmap docs
  carry zero metadoc coverage" gap flagged in the 13-07-2026 weekly review. Each metadoc is in
  its subject's language (ru/en). Docs only.
- **Optimisation & bottleneck backlog (H881), `docs/OPTIMISATION_BACKLOG_2026H2.md`
  (+ metadoc).** The single leverage-ranked index of what needs unblocking / speeding up /
  paying down, replacing the prior scatter across `.ai_state.md` Dev Notes and ~15 topic
  roadmaps. Every row fact-checked against `origin/main` on 13-07-2026 — which surfaced that
  the Laravel-EOL row and the message-store-unification row were both already resolved (H862
  10→12; the `UnifiedMessage`/`UnifiedInboxReader` read layer from 01-07-2026), and that
  `vendor/` bloat is a non-issue. Documentation only — no product change, so intentionally
  not release-cut.

### Fixed
- **Test suite no longer depends on a built frontend (H884).** `@vite` throws
  `ManifestNotFoundException` (→ 500) when `public/build/manifest.json` is absent,
  which locally turned every view-rendering feature test into a false failure until
  `npm run build` was run. Hoisted `withoutVite()` from two ad-hoc per-test `setUp()`
  overrides into the base `Tests\TestCase::setUp()`, so all 235 feature tests are
  immune to a missing manifest with no build step. CI's manifest-stub is now
  belt-and-suspenders. (Fixes a §2 dev-loop item from `docs/OPTIMISATION_BACKLOG_2026H2.md`.)

### Security
- **Semgrep PHP SAST promoted from advisory to a required/blocking gate (H885).**
  Cleared the 18 advisory findings that were keeping it non-blocking (H081 Part A,
  `docs/SECURITY_ROADMAP.md` Wave 3): pinned all 13 GitHub Actions `uses:` to full
  commit SHAs (supply-chain hardening, Dependabot-maintained), added a 7-day
  Dependabot `cooldown` to all three ecosystems, and removed a stray
  `index.nginx-debian.html` (nginx default page) from the repo root. `semgrep.yml`
  now runs with `--error` and no `continue-on-error`, so a new SAST finding fails
  the PR. Executes a §3 tech-debt item from `docs/OPTIMISATION_BACKLOG_2026H2.md` (H881).

## [1.4.0] - 2026-07-13

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- **Optimisation & bottleneck backlog (H881), `docs/OPTIMISATION_BACKLOG_2026H2.md`
  (+ metadoc).** The single leverage-ranked index of what needs unblocking / speeding up /
  paying down, replacing the prior scatter across `.ai_state.md` Dev Notes and ~15 topic
  roadmaps. Every row fact-checked against `origin/main` on 13-07-2026 (which surfaced that
  the Laravel-EOL row was already resolved by H862's 10→12 upgrade, and that `vendor/` bloat
  is a non-issue). Documentation only — no product change, so intentionally not release-cut.
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
- **Ростер-бот: куратор-команды `/группа` и `/кто` (H816 PR 3, тикет S6).**
  Достраивают заглушку `/группа` до настоящего ростера поверх `Group::activeUsers()`:
  `/группа <название>` — активный состав группы + курс(ы) + долговой маркер ⚠️/✅
  (присутствие в `DebtorsReport`, read-only, БЕЗ подсчёта суммы — денежная логика
  не дублируется); `/группа` без аргумента — список групп; `/кто <имя|@username>` —
  поиск студента по имени/username/email с карточкой (в каких активных группах,
  какие курсы). Та же роль-авторизация, что у `/долги` (S4): admin/manager/super_admin,
  посторонним/студентам — тишина. Новый `App\Services\Bot\RosterBotCommand` (по образцу
  `DebtorsBotCommand`), заглушка `/группа` из `DebtorsBotCommand` убрана. Чистый
  LMS-запрос: без LLM, без новых кред, без миграций — на проде работает сразу после
  выката. Feature-тесты `RosterBotCommandTest` 9/9; полный Bot+Webhooks сьют — 95/95.
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

### Changed
- **Тесты гоняются параллельно — `paratest` (H868).** `brianium/paratest ^7` добавлен в `require-dev`; CI-шаг и локальный прогон переведены на `php artisan test --parallel` (8 процессов локально). Весь набор **1503 теста / 4312 assertions зелёные** параллельно — parallel-safe, гонок по общим файловым путям нет. Сокращает время прогона CI (был ~12.5 мин последовательно) и локали пропорционально числу ядер.

### Security
- **Laravel 10 → 12: закрыты HIGH+MODERATE Dependabot-адвайзори (H862).**
  `laravel/framework` поднят `^10.10` → `^12.63` (плюс `laravel/sanctum` 3→4,
  `phpunit/phpunit` 10→11, `nunomaduro/collision` 7→8, `symfony/css-selector`+`dom-crawler` 6→7,
  `barryvdh/laravel-dompdf` 2→3, `spatie/laravel-backup` 8→9). Закрывает
  [Dependabot #14](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot/14)
  (HIGH, GHSA-5vg9-5847-vvmq — CRLF-инъекция в дефолтном правиле валидации `email`) и
  [#15](https://github.com/gasyoun/Systema-Sanscriticum/security/dependabot/15)
  (MODERATE, GHSA-crmm-hgp2-wgrp — path confusion во временных подписанных URL):
  фикс только в Laravel 11+, бэкпорта под EOL-нутую 10.x нет, поэтому Dependabot не мог
  открыть PR. Классический скелет (`bootstrap/app.php` + `Http/Kernel`) сохранён —
  Filament v3.3.54 уже поддерживает Laravel 12 (прыжок Filament 3→4 не нужен), а
  `jenssegers/agent` не имеет Laravel-констрейнта (замена не потребовалась). Правки под
  нативный SQLite-DDL Laravel 11 (Doctrine DBAL убран): снятие FK/индекса до `DROP COLUMN`
  в [`2026_03_09_..._payments`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_03_09_093322_replace_landing_page_id_with_course_id_in_payments_table.php)
  и [`2026_06_02_..._direct_ad_spends`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_06_02_000001_direct_ad_spends_to_period.php);
  контракт `Authenticatable::getAuthPasswordName()` в
  [`GuestChatUser`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Auth/GuestChatUser.php);
  Carbon 3 `diffInDays()` теперь возвращает float → каст в
  [`DirectAdSpend::periodDays()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/DirectAdSpend.php);
  экранирование JSON-LD `@context` (в Laravel 11 `@context` стала Blade-директивой) в
  [`articles/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/articles/show.blade.php).
  Устаревшие `audit.ignore` для L10-адвайзори убраны из `composer.json`. Весь набор
  **1503/1503 зелёный**, `composer audit` — чисто. Прогон под Opus 4.8 (`claude-opus-4-8`).

## [1.3.0] - 2026-07-13

### Added
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
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
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
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
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
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
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
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
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
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
- **PWG Arzamas-style material plan (H1620 /ask).** Layered PLAN+ROADMAP+ARCHITECTURE+IMPLEMENTATION+VERIFICATION for a 15–20 chapter Russian pop-science longread about PWG on samskrte.ru (Article + Materials hub). Genre contract: [Arzamas 1100](https://arzamas.academy/materials/1100). Index: [docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_PWG_ARZAMAS_MATERIAL_2026.md). Executor plan: Grok 4.5 (`grok-4.5`); wave-1 build: Fable H1620.
- Added this changelog so repository-level changes have a stable home.
- Recorded the current repository purpose: Laravel-приложение: учебный кабинет, магазин курсов, конструктор лендингов, редактор лекций и панель администратора.

### Recent Git History
- 2026-05-29 ai-wip: add .pre-commit-config.yaml (yaml-only)
- 2026-05-29 ai-wip: add CodeQL SAST workflow (php, javascript)
- 2026-05-29 ai-wip: add .github/dependabot.yml for GitHub Actions auto-updates
- 2026-05-29 ai-wip: add CODE_OF_CONDUCT.md (Contributor Covenant 2.1)
- 2026-05-29 fix(ci): proper Vite manifest stub with entry keys

[Unreleased]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.53.0...HEAD
[1.53.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.52.0...v1.53.0
[1.52.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.51.0...v1.52.0
[1.51.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.50.1...v1.51.0
[1.50.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.50.0...v1.50.1
[1.50.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.49.1...v1.50.0
[1.49.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.49.0...v1.49.1
[1.49.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.48.0...v1.49.0
[1.48.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.47.0...v1.48.0
[1.47.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.46.0...v1.47.0
[1.46.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.45.0...v1.46.0
[1.45.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.44.0...v1.45.0
[1.44.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.43.0...v1.44.0
[1.43.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.42.0...v1.43.0
[1.42.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.41.0...v1.42.0
[1.41.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.40.0...v1.41.0
[1.40.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.39.0...v1.40.0
[1.39.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.38.0...v1.39.0
[1.38.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.37.0...v1.38.0
[1.37.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.36.0...v1.37.0
[1.36.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.35.0...v1.36.0
[1.35.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.34.0...v1.35.0
[1.34.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.33.0...v1.34.0
[1.33.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.32.0...v1.33.0
[1.32.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.31.0...v1.32.0
[1.31.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.30.0...v1.31.0
[1.30.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.29.0...v1.30.0
[1.29.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.28.0...v1.29.0
[1.28.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.27.0...v1.28.0
[1.27.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.26.0...v1.27.0
[1.26.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.25.0...v1.26.0
[1.25.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.24.0...v1.25.0
[1.24.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.23.0...v1.24.0
[1.23.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.22.0...v1.23.0
[1.22.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.21.0...v1.22.0
[1.21.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.20.0...v1.21.0
[1.20.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.19.0...v1.20.0
[1.19.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.18.1...v1.19.0
[1.18.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.18.0...v1.18.1
[1.18.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.17.1...v1.18.0
[1.17.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.17.0...v1.17.1
[1.17.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.14.0...v1.15.0
[1.14.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.13.0...v1.14.0
[1.13.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.0
