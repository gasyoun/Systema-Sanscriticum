<?php

/*
|--------------------------------------------------------------------------
| 3-day diagnostic marathon — «Консультация по онлайн-курсам ОРС» (H440)
|--------------------------------------------------------------------------
| MG decisions locked 09-07-2026, see Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md
| §8. Parameterized here per the handoff's explicit instruction — none of
| these are hardcoded in the controller/view.
*/

return [
    // Public landing page slug (LandingPage row created via Filament admin,
    // not seeded here — matches how every other production landing is set up).
    'landing_slug' => env('MARATHON_LANDING_SLUG', 'konsultaciya-po-onlayn-kursam'),

    // Paid track «с проверкой» — tripwire price, ₽.
    'paid_track_price' => (int) env('MARATHON_PAID_TRACK_PRICE', 500),

    // Coupon on the first course purchase after the marathon (MG decision).
    'coupon_amount' => (int) env('MARATHON_COUPON_AMOUNT', 1000),

    // Live consultation host (Day 3 Zoom, single live session — not MG's
    // config/investment.php-style discount rate, this is display copy only).
    'host_name' => env('MARATHON_HOST_NAME', 'к.ф.н. М.Ю. Гасунс'),

    // Delivery channel — Telegram, NOT the internal Systema UI (MG decision).
    'telegram_channel_url' => env('MARATHON_TELEGRAM_CHANNEL_URL', 'https://t.me/samskrte'),

    // H447 §5 — leaderboard A/B. Arm assignment (50/50, fixed at enrolment on
    // marathon_enrollments.ab_arm) ALWAYS runs so the experiment has data.
    // This flag gates only the ACTUAL UN-MASKING for arm B — it un-masks
    // real student names to peers, which needs an explicit enrolment-consent
    // decision (see Uprava/docs/SARASWATI_TRAINERS_2026.md §5 guard, and run
    // /publish-safety-check before flipping this). DEFAULT OFF — do not
    // change without that sign-off. While off, every arm (A and B alike)
    // sees the name-masked leaderboard.
    'leaderboard_unmask_enabled' => (bool) env('MARATHON_LEADERBOARD_UNMASK_ENABLED', false),

    // Day-3 warm-tail length in days — median time-to-purchase from CUSTDEV_2026.md.
    'warm_tail_days' => (int) env('MARATHON_WARM_TAIL_DAYS', 13),

    // H487 Phase 5 — the Day-3 live consultation is ONE shared Schedule row
    // (app/Models/Schedule.php), created/edited by MG through the existing
    // Filament ScheduleResource (no course_id/group_id — a standalone
    // session, same pattern as Course::trialSchedule()). Nothing activates
    // until this is set — see DEPLOY_QUEUE.md.
    'schedule_id' => env('MARATHON_SCHEDULE_ID'),

    // H464 Phase 2 (revised H483 Phase 3b) — Day 1/2 drip content, sent as
    // Telegram messages by `marathon:deliver-due`. `{link}` is replaced by
    // `marathon:deliver-due` with the enrollee's personal tap-choice page
    // URL (magnet_token-keyed, H483) — kept as a template placeholder here,
    // not hardcoded in the command. Cyrillic only, no devanagari (H440 §1a).
    // HTML tags, not Markdown asterisks — TelegramDeliveryChannel::sendMessage
    // sends parse_mode=HTML (app/Services/Messaging/TelegramDeliveryChannel.php).
    'day1_message' => 'День 1. Санскрит роднее, чем кажется 👋'."\n\n"
        .'Пара минут на тап-выбор — без деванагари, без письма, просто узнавание.'."\n\n"
        .'{link}',

    'day2_message' => 'День 2. Как устроено санскритское слово 🧩'."\n\n"
        .'Корень + аффикс — на паре простых примеров. И в конце — оставьте свой '
        .'вопрос к живой консультации Дня 3.'."\n\n"
        .'{link}',

    // H483 Phase 3b — tap-choice recognition content for the Day 1/2 pages
    // (resources/views/marathon/day1.blade.php / day2.blade.php). Linear
    // steps, not the branching results{} shape ShopController::start() uses
    // for its master-quiz (recognition tasks don't route anywhere — each
    // answer just reveals an explanation and advances). 0-indexed `correct`.
    // Day-1 recognition quiz (zero cohort). Optional per-step `link`
    // {url, label} is rendered under the explanation (see day1.blade.php).
    // Zaliznyak: «О языке древней Индии» from the school’s curated list
    // https://samskrtam.ru/mt/ (Муми-тролль talks). Burlak: IE kinship talk
    // already hosted on samskrtam.ru.
    'day1_quiz' => [
        'steps' => [
            [
                'text' => 'Слово veda (существительное или глагол в перфекте) значит...',
                'opts' => ['видеть', 'знать', 'течь'],
                'correct' => 1,
                'explain' => 'Верно! veda — «знание» (и «он узнал» в перфекте). Тот же корень, что в русском «ведать», «весть», «невежда» — санскрит и русский родня, оба из одной индоевропейской семьи.',
                'link' => [
                    'url' => 'https://elementy.ru/nauchno-populyarnaya_biblioteka/431350/O_yazyke_drevney_Indii',
                    'label' => 'А.А. Зализняк: «О языке древней Индии» (из подборки samskrtam.ru/mt)',
                ],
            ],
            [
                'text' => 'А mātar?',
                'opts' => ['отец', 'мать', 'брат'],
                'correct' => 1,
                'explain' => 'Да! mātar — «мать». Слышите родство? bhrātar — «брат» устроено так же.',
            ],
            [
                'text' => 'Bhrātar — это...',
                'opts' => ['сестра', 'друг', 'брат'],
                'correct' => 2,
                'explain' => 'Точно! Родство санскрита и русского — не совпадение, а общий индоевропейский предок.',
                'link' => [
                    'url' => 'https://samskrtam.ru/burlak-sanskrit-2018-tc',
                    'label' => 'С.А. Бурлак: «Какой же русский не понимает санскрита?»',
                ],
            ],
        ],
    ],

    'day2_quiz' => [
        'steps' => [
            [
                'text' => 'gam значит «идти». Что добавляется, чтобы получить «он идет» — gacchati?',
                'opts' => ['Только окончание', 'Корень + показатель времени', 'Совсем другое слово'],
                'correct' => 1,
                'explain' => 'Верно! gacchati = корень gam + показатель настоящего времени. Санскритское слово почти всегда — корень + аффиксы.',
            ],
            [
                'text' => 'āgama значит «приход, писание». Какой в нём корень?',
                'opts' => ['gam', 'ā', 'ma'],
                'correct' => 0,
                'explain' => 'Да, тот же gam! И в saṃgati («встреча») — тоже он. Один корень, разные приставки — разный смысл.',
            ],
        ],
    ],

    // H487 Phase 5 — Day-3 messages, sent once by marathon:deliver-due when
    // currentDay() >= 3. {date}/{link}/{host} are interpolated by the command
    // (date/link from the configured Schedule row, host from host_name above)
    // — kept as template placeholders here, not re-reading env() a second
    // time. Paid track gets the live join link; free track gets told a
    // recording follows (H440 §3 item 6 — не ежедневный эфир, одна консультация).
    'day3_message_paid' => 'День 3 — живая консультация 🎥'."\n\n"
        .'{date}, ведет {host}. Ваш вопрос уже у нас — разберем его лично.'."\n\n"
        .'{link}',

    'day3_message_free' => 'День 3 — живая консультация 🎥'."\n\n"
        .'{date} пройдет живая консультация с {host}. На бесплатном треке — запись '
        .'после эфира, мы пришлем ссылку сюда же.',

    'recording_message' => 'Запись консультации готова 🎬'."\n\n"
        .'{link}',

    // Real testimonial quote, MG-supplied only — MARATHON_DIAGNOSTIC_2026.md
    // §4 wants ONE pointed testimonial from a serious practitioner, never a
    // fabricated one. Null until MG sets MARATHON_TESTIMONIAL in .env; the
    // Day-5 warm-tail message falls back to a no-quote framing while empty
    // (see DeliverMarathonWarmTail::handle()) rather than inventing one.
    'testimonial' => env('MARATHON_TESTIMONIAL'),

    // H440 Phase 6 — 13-day warm-tail, sent to unpaid registrants (paid_at
    // null) on Days 4–16 off their personal clock (median time-to-purchase,
    // CUSTDEV_2026.md/MARATHON_DIAGNOSTIC_2026.md §warm-tail). Evergreen —
    // no deadlines, no "мест осталось" — cycles the four levers the custdev
    // data says move this audience: ВРЕМЯ→own-pace (obj. #1), рассрочка,
    // преподаватель, and ONE pointed testimonial (не навалом — соц.
    // доказательство −6 in this audience per the design doc — see `testimonial`
    // above; никогда не выдумывается). Keyed 1..13 to match
    // MarathonEnrollment::warmTailDay(); `{host}`/`{coupon}` interpolated by
    // marathon:deliver-warm-tail, `{testimonial}` only on Day 5.
    'warm_tail_messages' => [
        1 => 'Как вам марафон? 🙂'."\n\n"
            .'Если разбор устройства слова зашел — курсы идут в том же темпе: '
            .'~15 минут в день, без спешки, каждый в своем ритме.',
        2 => 'Частый вопрос — «а если не успею каждый день?» 📅'."\n\n"
            .'Курс не пропадет: записи открыты бессрочно, занимайтесь своим темпом. '
            .'Ничего не «сгорает».',
        3 => 'Кто ведет курсы 👤'."\n\n"
            .'{host} — санскритолог, автор курсов Общества ревнителей санскрита. '
            .'Разбор в марафоне — из тех же материалов, что и в полном курсе.',
        4 => 'Оплата — не обязательно сразу целиком 💳'."\n\n"
            .'Есть рассрочка по блокам курса — платите по мере прохождения, а не '
            .'всю сумму на старте.',
        5 => '{testimonial}',
        6 => 'Ваш вопрос с Дня 2 никуда не делся 📝'."\n\n"
            .'Если после консультации остались уточнения по курсу — просто напишите '
            .'сюда, ответим.',
        7 => 'Еще раз про темп 🕰️'."\n\n"
            .'Курс рассчитан на занятых людей: короткие уроки, свой график, можно '
            .'начать в любой день — не только с «набора».',
        8 => 'Рассрочка — детали 💳'."\n\n"
            .'Оплата делится на части по блокам курса; следующая часть — когда '
            .'дойдете до следующего блока, не по календарю.',
        9 => '{host} лично ведет разборы 👤'."\n\n"
            .'Не запись «на автомате» — обратная связь и ответы на вопросы от '
            .'преподавателя, который сам переводит и читает санскрит.',
        10 => 'Если пока не время — это нормально ⏳'."\n\n"
            .'Курс никуда не уходит. Когда будет удобно — просто напишите сюда, '
            .'подключим.',
        11 => 'Вопросы по ходу курса — не остаются без ответа 💬'."\n\n"
            .'{host} читает разборы каждого блока лично — если что-то не сложилось, '
            .'можно спросить напрямую, а не искать ответ самому.',
        12 => 'Скидка после марафона всё еще доступна 🎁'."\n\n"
            .'{coupon} ₽ на первый курс — как и обещали на консультации. Просто '
            .'напишите сюда, и мы применим ее к оплате, без ограничения по сроку.',
        13 => 'Если решите начать 🙂'."\n\n"
            .'Напишите сюда в любой момент — подберем курс под ваш вопрос с Дня 2 '
            .'и оформим со скидкой.',
    ],

    // H445 Phase 1 — sparse per-cohort content overlay. Only `zero` (the
    // August all-zero cohort) has real content, and it lives at the
    // top-level keys above (unchanged, as-built by H440) — `zero` needs no
    // entry here. `deva` (January) overrides ONLY day1/day2 content
    // (MarathonEnrollment::content() falls back to the top-level default for
    // any key without an override — price/host/schedule/day3 stay shared).
    // Read via $enrollment->content('day1_message'), never config('marathon.
    // day1_message') directly, once an enrollment might be either cohort.
    'cohorts' => [
        'deva' => [
            // H445 Phase 3 — Day 1: name-in-devanagari, embedding H312's
            // transliterator tool rather than rebuilding it (csl-guides
            // `/tools/name-in-devanagari`, sanskrit-util transcoder). No
            // Systema/RU embed of the tool exists yet (ROADMAP_LEAD_MAGNETS_
            // 2026.md §3 — H312 shipped EN-only, RU-storefront wiring still
            // gated on the LM fleet's own @DECIDE items) — link straight to
            // the live EN page; re-point this string if/when an RU embed
            // lands. `{link}` interpolated by marathon:deliver-due exactly
            // like the `zero` cohort's day1_message.
            'day1_message' => 'День 1. Ваше имя на деванагари 🕉️'."\n\n"
                .'Санскритские тексты записаны деванагари — попробуйте прямо сейчас: '
                .'введите свое имя и увидите, как оно звучит на деванагари, IAST и SLP1.'."\n\n"
                .'https://sanskrit-lexicon.github.io/csl-guides/tools/name-in-devanagari'."\n\n"
                .'Получилась красивая картинка — поделитесь в @samskrte, будет приятно 🙂'."\n\n"
                .'{link}',

            // Recognition tap-choice content for the Day 1 page (same shape
            // as the `zero` cohort's day1_quiz, resources/views/marathon/
            // day1.blade.php — reused unchanged, only the content differs:
            // basic devanagari letter recognition instead of cyrillic
            // root-family recognition). Distinct from the level-quiz
            // (H544, graded once, layered before Day 1) — this is
            // ungraded, explanation-per-step, matching H440's day1_quiz.
            'day1_quiz' => [
                'steps' => [
                    [
                        'text' => 'Деванагари читается...',
                        'opts' => ['Справа налево', 'Слева направо', 'Сверху вниз'],
                        'correct' => 1,
                        'explain' => 'Верно! Деванагари читается слева направо, как кириллица и латиница — привычное направление, разница только в самих буквах.',
                    ],
                    [
                        'text' => 'Буквы деванагари обычно связаны сверху общей чертой. Эта черта — это...',
                        'opts' => ['Разделитель слов', 'Часть каждой буквы (как перекладина)', 'Знак ударения'],
                        'correct' => 1,
                        'explain' => 'Точно! Горизонтальная черта (широ-рекха) — часть написания почти каждой буквы, а не разделитель. Слова разделяются пробелами, как у нас.',
                    ],
                    [
                        'text' => 'В деванагари согласный без явного гласного знака по умолчанию читается с гласным...',
                        'opts' => ['a', 'i', 'u'],
                        'correct' => 0,
                        'explain' => 'Да! Каждый согласный «по умолчанию» несет краткое a — поэтому क читается «ka», а не голое «k». Другие гласные показываются значками вокруг буквы.',
                    ],
                ],
            ],

            // H445 Phase 4 (H546) — Day-2 mantra reading + curator voice
            // review, blocked on one MG @DECIDE (which mantra to use). Own
            // future phase.
            // 'day2_message' => '...',
            // 'day2_quiz' => ['steps' => [...]],

            // H445 Phase 2 — level-quiz, layered ON TOP OF the intent-quiz
            // (quiz_goal), read via MarathonController::levelQuiz(). Content
            // ported verbatim (RU strings) from H313's item bank
            // (csl-guides/src/data/level-quiz.json, generated
            // 09-07-2026 from VisualDCS/DCS frequency data via kosha) —
            // reused, not reinvented; `correct` is the 0-indexed position of
            // the source's `answer` string within its own `options` array.
            // The August `zero` cohort never takes this (H440 §1a — nothing
            // to grade for an all-zero audience).
            //
            // H1387 (20-07-2026): option order re-ported after the upstream
            // fix. Every item here previously had `correct => 0`, inherited
            // from csl-guides, where all six answers were authored first and
            // nothing shuffled — so a student who always tapped the top
            // option scored 6/6 and the grade carried no signal. Upstream now
            // permutes deterministically (scripts/_quiz-options.mjs, seeded on
            // the item id) and guards it with `npm run verify:quiz-positions`;
            // the orders below match that output for the `ru` locale, so the
            // "ported verbatim" relationship above still holds. Keep them in
            // step: re-port if the upstream bank is regenerated.
            'level_quiz' => [
                'steps' => [
                    [
                        'text' => 'Какой звук передает эта буква деванагари: क',
                        'opts' => ['sa', 'ma', 'ta', 'ka'],
                        'correct' => 3,
                    ],
                    [
                        'text' => 'В какой паре показан краткий гласный и его долгая пара?',
                        'opts' => ['k / t', 'm / s', 'ka / ta', 'a / ā'],
                        'correct' => 3,
                    ],
                    [
                        'text' => 'Какое из этого — настоящее санскритское слово (а не набор букв)?',
                        'opts' => ['एव', 'ऴ्ऱि', 'क्ष्ठौ', 'घ्व्रॗ'],
                        'correct' => 0,
                    ],
                    [
                        'text' => 'Как अन्य записывается латиницей (IAST)?',
                        'opts' => ['anya', 'bṛhat', 'durbala', 'vacas'],
                        'correct' => 0,
                    ],
                    [
                        'text' => 'Какое слово чаще встречается в реальных санскритских текстах: «eva» или «durbala»?',
                        'opts' => ['eva', 'durbala'],
                        'correct' => 0,
                    ],
                    [
                        'text' => 'Верно или нет: когда два санскритских слова стоят рядом в предложении, их звуки часто меняются, сливаясь друг с другом (это называется сандхи).',
                        'opts' => ['Неверно', 'Верно'],
                        'correct' => 1,
                    ],
                ],
            ],
        ],
    ],
];
