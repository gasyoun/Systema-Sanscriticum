# ё-orthography normalisation sweep

_Created: 19-07-2026 · Last updated: 19-07-2026_

H1295, the eleventh (non-Fable) lane of the Systema revenue-copy wave (ruling D13/D14
in [PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAN_SYSTEMA_REVENUE_COPY_FABLE_WAVE_2026H2.md)).
Mechanical prerequisite for the ten Fable money/UX lanes, which already write in the
target orthography.

## Rationale

MG ruled: new user-facing Russian copy drops ё, reserving it only where its absence
creates a genuine reading ambiguity -- canonically «всё» (everything) versus «все»
(all/everyone). This sweep normalises the **existing** repo to match, so new Fable-lane
copy does not sit beside differently-spelled neighbours. The measured baseline (19-07-2026,
before this sweep) was 261 ё occurrences across 73 files by an informal count; a script-driven
census scoped to `resources/views/**`, `app/Mail/**`, and Russian label values in
`config/*.php` -- excluding `//`/`#`/`/* */`/`{{-- --}}`/`<!-- -->` comments, which are
developer commentary, not shipped copy -- found **297 occurrences across 81 files**, the
difference being scope precision (the informal count likely missed Filament admin-panel
copy, which is in `resources/views/**` and is written for a human user of the app, just
not a student).

## Method (S1 spike, per the implementation doc)

A one-off Python script (not a general transcoder -- scoped to this lane's exact word list,
so it isn't a SHARED_CODE candidate) built the full occurrence list first, classified every
occurrence, and only then applied replacements -- never a blind find-and-replace:

1. **MUST_KEEP** -- dropping ё would land on a different, already-existing word. Found in
   this corpus: the «весь» pronoun family (`всё`/`Всё`, `всём`/`Всём` -- "everything"/"about
   everything" vs. `все`/`всем`, "all"/"to everyone"), `нём` ("о нём" = about him -- vs. `нем`,
   short-form "mute"), `чём` ("о чём" = about what -- vs. `чем`, "than"/instrumental).
2. **REVIEW** -- genuinely ambiguous and individually inspected rather than swept: both
   occurrences of `Распознаём` (Filament admin copy, "we [algorithmically] recognise...") risk
   colliding with `распознаем`, the homographic perfective future of a different verb
   (распознать, "we will recognise [it], once"). Left ё per the lane's default
   ("ambiguous and unreviewed -> leave the ё" -- treated as reviewed-but-still-risky here, so
   the ё stays rather than being forced either way).
3. **SAFE** -- every other ё occurrence was individually checked against its nearest possible
   aspectual/lexical homograph and found to have none (e.g. `пришлём`/`заведём`/`разберём`
   have no colliding perfective/imperfective partner spelled identically without ё; nouns
   like `объём`/`приём`/`платёж`/`расчёт`/`учёный` have no distinct е-spelled sibling word).
   275 occurrences (across 77 files) were normalised ё->е (case-preserved).

Verification: diffed rendered output is not applicable here (no Blade compile step changes --
every replacement stayed within an existing display string, never touched a directive), so
verification is (a) the full `php artisan test` suite staying green, and (b) re-running the
census script and confirming the residue is exactly the 22 documented exceptions below --
which it is (0 SAFE left after apply).

## Residual exceptions (22, all intentional)

| Class | Word(s) | Why kept |
|---|---|---|
| MUST_KEEP | `всё` / `Всё` (13x), `всём` (6x) | "весь" pronoun, everything/about-everything sense; `все`/`всем` are different words |
| MUST_KEEP | `нём` (1x) | "о нём" (about him); `нем` = mute |
| MUST_KEEP | `чём` (1x) | "о чём" (about what); `чем` = than/instrumental |
| REVIEW (left, not force-decided) | `Распознаём` (2x, both in `resources/views/filament/user-courses/blocks-preview*.blade.php`) | risks the aspectual `узнаём`-class collision with perfective `распознаем`; low-stakes internal admin copy, left ё rather than guessed |

## Scope decisions taken unattended

Per the autonomy contract ("pick the default, log it, keep going"):

1. **Filament admin pages counted as in-scope.** The implementation doc scopes the sweep to
   `resources/views/**` without carving out admin panels. Filament pages
   (`resources/views/filament/**`) are rendered UI text for a human user of the app (staff,
   not students) -- not backend/internal code -- so they were swept along with
   student/public-facing views. This explains most of the gap between the informal 261/73
   baseline and this sweep's 297/81: the baseline likely only counted student- and
   public-facing surfaces.
2. **`config/*.php` scoped to actual array-value labels/messages, not developer comments.**
   The doc says "Russian label values in config/*.php" -- read literally as excluding `//`
   commentary describing what a config key does. Comments were stripped before scanning;
   genuine label/message values (`config/badges.php`, `config/prana.php`,
   `config/marathon.php`'s bot broadcast copy) were swept.
3. **`public/docs/*.pdf`, `vendor/`, `node_modules/`, migrations, seeders, PHP
   identifiers/class names/translation keys** -- never touched, per the fence. None of these
   paths were in the scan scope to begin with.

## Changed strings, by file

Every string below is shown as it now stands in the tree (post-sweep), as an inline code
span since these are literal Blade/HTML/PHP source excerpts, not authored markdown. 275
replacements across 77 files.

### `config/badges.php`

| Line | Before | After |
|---|---|---|
| 18 | `['key' => 'mentor',        'name' => 'Наставник',         'icon' => '🤝', 'desc' => 'Приглашённый друг оплатил курс',   'metric' => 'referrals',       …` | `['key' => 'mentor',        'name' => 'Наставник',         'icon' => '🤝', 'desc' => 'Приглашенный друг оплатил курс',   'metric' => 'referrals',       …` |
| 19 | `['key' => 'ambassador',    'name' => 'Амбассадор',        'icon' => '🌟', 'desc' => 'Трое приглашённых оплатили курс',  'metric' => 'referrals',       …` | `['key' => 'ambassador',    'name' => 'Амбассадор',        'icon' => '🌟', 'desc' => 'Трое приглашенных оплатили курс',  'metric' => 'referrals',       …` |

### `config/marathon.php`

| Line | Before | After |
|---|---|---|
| 97 | `'text' => 'gam значит «идти». Что добавляется, чтобы получить «он идёт» — gacchati?',` | `'text' => 'gam значит «идти». Что добавляется, чтобы получить «он идет» — gacchati?',` |
| 118 | `.'{date}, ведёт {host}. Ваш вопрос уже у нас — разберём его лично.'."\n\n"` | `.'{date}, ведет {host}. Ваш вопрос уже у нас — разберем его лично.'."\n\n"` |
| 122 | `.'{date} пройдёт живая консультация с {host}. На бесплатном треке — запись '` | `.'{date} пройдет живая консультация с {host}. На бесплатном треке — запись '` |
| 123 | `.'после эфира, мы пришлём ссылку сюда же.',` | `.'после эфира, мы пришлем ссылку сюда же.',` |
| 147 | `.'Если разбор устройства слова зашёл — курсы идут в том же темпе: '` | `.'Если разбор устройства слова зашел — курсы идут в том же темпе: '` |
| 148 | `.'~15 минут в день, без спешки, каждый в своём ритме.',` | `.'~15 минут в день, без спешки, каждый в своем ритме.',` |
| 150 | `.'Курс не пропадёт: записи открыты бессрочно, занимайтесь своим темпом. '` | `.'Курс не пропадет: записи открыты бессрочно, занимайтесь своим темпом. '` |
| 152 | `3 => 'Кто ведёт курсы 👤'."\n\n"` | `3 => 'Кто ведет курсы 👤'."\n\n"` |
| 162 | `7 => 'Ещё раз про темп 🕰️'."\n\n"` | `7 => 'Еще раз про темп 🕰️'."\n\n"` |
| 167 | `.'дойдёте до следующего блока, не по календарю.',` | `.'дойдете до следующего блока, не по календарю.',` |
| 168 | `9 => '{host} лично ведёт разборы 👤'."\n\n"` | `9 => '{host} лично ведет разборы 👤'."\n\n"` |
| 177 | `12 => 'Скидка после марафона всё ещё доступна 🎁'."\n\n"` | `12 => 'Скидка после марафона всё еще доступна 🎁'."\n\n"` |
| 179 | `.'напишите сюда, и мы применим её к оплате, без ограничения по сроку.',` | `.'напишите сюда, и мы применим ее к оплате, без ограничения по сроку.',` |
| 181 | `.'Напишите сюда в любой момент — подберём курс под ваш вопрос с Дня 2 '` | `.'Напишите сюда в любой момент — подберем курс под ваш вопрос с Дня 2 '` |
| 206 | `.'введите своё имя и увидите, как оно звучит на деванагари, IAST и SLP1.'."\n\n"` | `.'введите свое имя и увидите, как оно звучит на деванагари, IAST и SLP1.'."\n\n"` |
| 236 | `'explain' => 'Да! Каждый согласный «по умолчанию» несёт краткое a — поэтому क читается «ka», а не голое «k». Другие гласные показываются значками вокр…` | `'explain' => 'Да! Каждый согласный «по умолчанию» несет краткое a — поэтому क читается «ka», а не голое «k». Другие гласные показываются значками вокр…` |
| 259 | `'text' => 'Какой звук передаёт эта буква деванагари: क',` | `'text' => 'Какой звук передает эта буква деванагари: क',` |

### `config/prana.php`

| Line | Before | After |
|---|---|---|
| 52 | `['key' => 'pandita', 'name' => 'Paṇḍita · учёный', 'min' => 8000],` | `['key' => 'pandita', 'name' => 'Paṇḍita · ученый', 'min' => 8000],` |
| 57 | `'lesson_complete' => 'Завершён урок',` | `'lesson_complete' => 'Завершен урок',` |
| 62 | `'referral' => 'Приглашённый друг оплатил курс',` | `'referral' => 'Приглашенный друг оплатил курс',` |

### `resources/views/auth/passwords/email.blade.php`

| Line | Before | After |
|---|---|---|
| 23 | `заказ — мы проверим ваш аккаунт и пришлём ссылку для входа.</p>` | `заказ — мы проверим ваш аккаунт и пришлем ссылку для входа.</p>` |
| 33 | `<p class="mt-2 text-green-700/80 text-xs">Не пришло за 5 минут? Отправьте запрос ещё раз ниже` | `<p class="mt-2 text-green-700/80 text-xs">Не пришло за 5 минут? Отправьте запрос еще раз ниже` |

### `resources/views/certificate/verify.blade.php`

| Line | Before | After |
|---|---|---|
| 17 | `<h1 class="mt-4 text-xl md:text-2xl font-extrabold text-white">Сертификат подтверждён</h1>` | `<h1 class="mt-4 text-xl md:text-2xl font-extrabold text-white">Сертификат подтвержден</h1>` |

### `resources/views/checkout/show.blade.php`

| Line | Before | After |
|---|---|---|
| 66 | `this.promoFlash = data.message \|\| 'Промокод применён.';` | `this.promoFlash = data.message \|\| 'Промокод применен.';` |
| 68 | `this.promoError = 'Сетевая ошибка. Попробуйте ещё раз.';` | `this.promoError = 'Сетевая ошибка. Попробуйте еще раз.';` |

### `resources/views/components/courses-recorded-mini.blade.php`

| Line | Before | After |
|---|---|---|
| 5 | `'subtitle' => 'Учитесь в своём темпе — записи лекций с пожизненным доступом.',` | `'subtitle' => 'Учитесь в своем темпе — записи лекций с пожизненным доступом.',` |

### `resources/views/components/newsletter-subscribe.blade.php`

| Line | Before | After |
|---|---|---|
| 13 | `'blurb' => 'Оставьте email — заведём личный кабинет и подарим бесплатные материалы.',` | `'blurb' => 'Оставьте email — заведем личный кабинет и подарим бесплатные материалы.',` |

### `resources/views/components/open-lessons-carousel.blade.php`

| Line | Before | After |
|---|---|---|
| 128 | `this.authError = 'Ошибка сети. Попробуйте ещё раз.';` | `this.authError = 'Ошибка сети. Попробуйте еще раз.';` |

### `resources/views/emails/deposit/received.blade.php`

| Line | Before | After |
|---|---|---|
| 23 | `Сумма предоплаты <strong>зачтётся при оплате полного тарифа</strong> — доплатить нужно будет только разницу.` | `Сумма предоплаты <strong>зачтется при оплате полного тарифа</strong> — доплатить нужно будет только разницу.` |
| 29 | `Пока идёт подготовка — присоединяйтесь к чату курса:` | `Пока идет подготовка — присоединяйтесь к чату курса:` |

### `resources/views/emails/deposit/transferred.blade.php`

| Line | Before | After |
|---|---|---|
| 24 | `Сумма предоплаты <strong>зачтётся при оплате нового курса</strong> — доплатить нужно будет только разницу.` | `Сумма предоплаты <strong>зачтется при оплате нового курса</strong> — доплатить нужно будет только разницу.` |

### `resources/views/emails/paypal/claim-received.blade.php`

| Line | Before | After |
|---|---|---|
| 4 | `Студент сообщил об оплате из-за рубежа. Требуется **ручная сверка в PayPal** — доступ откроется, когда вы переведёте платёж в статус «Оплачено» в адми…` | `Студент сообщил об оплате из-за рубежа. Требуется **ручная сверка в PayPal** — доступ откроется, когда вы переведете платеж в статус «Оплачено» в адми…` |
| 10 | `- **Рублёвый номинал:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽` | `- **Рублевый номинал:** {{ number_format((float) $payment->amount, 0, '.', ' ') }} ₽` |
| 20 | `После сверки в PayPal переведите платёж в статус «Оплачено» (фильтр «Заявки PayPal на проверке» → кнопка «Подтвердить PayPal») — студент получит досту…` | `После сверки в PayPal переведите платеж в статус «Оплачено» (фильтр «Заявки PayPal на проверке» → кнопка «Подтвердить PayPal») — студент получит досту…` |

### `resources/views/emails/teacher-payout-report.blade.php`

| Line | Before | After |
|---|---|---|
| 5 | `<title>Расчёт выплаты за блок</title>` | `<title>Расчет выплаты за блок</title>` |
| 34 | `<p style="margin: 0 0 20px;">Расчёт выплаты за блок` | `<p style="margin: 0 0 20px;">Расчет выплаты за блок` |
| 83 | `<td style="padding: 6px 0; color: #555;">Расчёт</td>` | `<td style="padding: 6px 0; color: #555;">Расчет</td>` |
| 126 | `Если есть вопросы по расчёту — ответьте на это письмо.` | `Если есть вопросы по расчету — ответьте на это письмо.` |

### `resources/views/emails/teacher/invite.blade.php`

| Line | Before | After |
|---|---|---|
| 19 | `<p style="font-size: 18px;">В панели вы наполняете свои курсы и уроки, проверяете домашние работы, ведёте расписание занятий и выдаёте сертификаты уче…` | `<p style="font-size: 18px;">В панели вы наполняете свои курсы и уроки, проверяете домашние работы, ведете расписание занятий и выдаете сертификаты уче…` |

### `resources/views/emails/trial/zoom-link.blade.php`

| Line | Before | After |
|---|---|---|
| 20 | `<p style="font-size: 17px; text-align: center;">Мы получили оплату пробного занятия курса <strong>«{{ $course->title }}»</strong>. Ждём вас на живом з…` | `<p style="font-size: 17px; text-align: center;">Мы получили оплату пробного занятия курса <strong>«{{ $course->title }}»</strong>. Ждем вас на живом з…` |
| 46 | `<p style="font-size: 16px; text-align: center; color: #8a3324;">Ссылку на подключение мы пришлём дополнительно — она появится в вашем личном кабинете.…` | `<p style="font-size: 16px; text-align: center; color: #8a3324;">Ссылку на подключение мы пришлем дополнительно — она появится в вашем личном кабинете.…` |

### `resources/views/filament/group/attendance-matrix.blade.php`

| Line | Before | After |
|---|---|---|
| 31 | `<span><b style="color:#16a34a;">✓</b> пришёл (Zoom)</span>` | `<span><b style="color:#16a34a;">✓</b> пришел (Zoom)</span>` |
| 32 | `<span><b style="color:#b45309;">~</b> перешёл по ссылке</span>` | `<span><b style="color:#b45309;">~</b> перешел по ссылке</span>` |

### `resources/views/filament/pages/finance-planning.blade.php`

| Line | Before | After |
|---|---|---|
| 5 | `Отчёты факта (ОПиУ, ДДС, баланс) считаются автоматически в «Финансовом штурвале».` | `Отчеты факта (ОПиУ, ДДС, баланс) считаются автоматически в «Финансовом штурвале».` |
| 6 | `Планирование — сценарии, бюджет — ведётся вручную в этих workbooks.` | `Планирование — сценарии, бюджет — ведется вручную в этих workbooks.` |

### `resources/views/filament/pages/helpdesk.blade.php`

| Line | Before | After |
|---|---|---|
| 418 | `'resolved' => 'Решённые',` | `'resolved' => 'Решенные',` |

### `resources/views/filament/pages/investment-model.blade.php`

| Line | Before | After |
|---|---|---|
| 43 | `Это отправные ориентиры для ручного ввода, а не готовый сценарий: крупная трата — forward-looking, её выручку/расходы задаёт человек.` | `Это отправные ориентиры для ручного ввода, а не готовый сценарий: крупная трата — forward-looking, ее выручку/расходы задает человек.` |
| 141 | `<div><b>NPV</b> — чистая приведённая стоимость: сумма дисконтированных потоков за горизонт минус капзатраты. NPV &gt; 0 ⇔ IRR выше ставки дисконтирова…` | `<div><b>NPV</b> — чистая приведенная стоимость: сумма дисконтированных потоков за горизонт минус капзатраты. NPV &gt; 0 ⇔ IRR выше ставки дисконтирова…` |
| 144 | `<div>Модель forward-looking: доп. выручка растёт на «рост, % в год» (ramp-up), расходы плоские. Ставка/горизонт/порог — config/investment.php (меняютс…` | `<div>Модель forward-looking: доп. выручка растет на «рост, % в год» (ramp-up), расходы плоские. Ставка/горизонт/порог — config/investment.php (меняютс…` |

### `resources/views/filament/pages/order-payment-conversion.blade.php`

| Line | Before | After |
|---|---|---|
| 69 | `title="{{ $p['label'] }}: {{ $pct($p['conversion_pct']) }} — {{ $p['paid'] }}/{{ $p['orders'] }} заказов{{ $p['current'] ? ' (текущий, ещё копится)' :…` | `title="{{ $p['label'] }}: {{ $pct($p['conversion_pct']) }} — {{ $p['paid'] }}/{{ $p['orders'] }} заказов{{ $p['current'] ? ' (текущий, еще копится)' :…` |
| 77 | `Высота = конверсия %. Полупрозрачный столбец — текущий незавершённый период (цифра ещё копится).` | `Высота = конверсия %. Полупрозрачный столбец — текущий незавершенный период (цифра еще копится).` |
| 159 | `<div><b>Оформленный заказ</b> = реальный (не conditional) платёж-заказ, кроме техрасходов/выплат ЗП и брони/пробного (config <code>conversion.excluded…` | `<div><b>Оформленный заказ</b> = реальный (не conditional) платеж-заказ, кроме техрасходов/выплат ЗП и брони/пробного (config <code>conversion.excluded…` |
| 160 | `<div><b>Конверсия</b> считается когортно по дате оформления: из заказов периода — доля дошедших до статуса paid/success. Текущий период занижен лагом …` | `<div><b>Конверсия</b> считается когортно по дате оформления: из заказов периода — доля дошедших до статуса paid/success. Текущий период занижен лагом …` |

### `resources/views/filament/pages/partials/student-info-modal.blade.php`

| Line | Before | After |
|---|---|---|
| 55 | `<span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; background: #fee2e2; color: #dc2626;" title="{{ $u->unreliable_…` | `<span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; background: #fee2e2; color: #dc2626;" title="{{ $u->unreliable_…` |
| 167 | `'present' => ['пришёл', '#16a34a', '#dcfce7'],` | `'present' => ['пришел', '#16a34a', '#dcfce7'],` |

### `resources/views/filament/pages/profit-funds.blade.php`

| Line | Before | After |
|---|---|---|
| 136 | `<div><b>Накопленный фонд</b> — управленческий earmark (сумма месячных отчислений за окно), не отдельный банковский счёт: у LMS нет банковского леджера…` | `<div><b>Накопленный фонд</b> — управленческий earmark (сумма месячных отчислений за окно), не отдельный банковский счет: у LMS нет банковского леджера…` |
| 137 | `<div><b>Обеспеченность резерва</b> = накопленный чистый денежный поток ДДС ÷ накопленный резерв. Ниже 100% — резерв не полностью подкреплён реальными …` | `<div><b>Обеспеченность резерва</b> = накопленный чистый денежный поток ДДС ÷ накопленный резерв. Ниже 100% — резерв не полностью подкреплен реальными …` |

### `resources/views/filament/pages/reactivation.blade.php`

| Line | Before | After |
|---|---|---|
| 8 | `Сегмент и исключения (Покинул/Исключён/Льготник/Выпускник) — те же, что у «Должников».` | `Сегмент и исключения (Покинул/Исключен/Льготник/Выпускник) — те же, что у «Должников».` |
| 13 | `решает сам, тёплым тоном и без давления (срочность и «все берут» отталкивают).` | `решает сам, теплым тоном и без давления (срочность и «все берут» отталкивают).` |

### `resources/views/filament/pages/receivables-governance.blade.php`

| Line | Before | After |
|---|---|---|
| 62 | `{{ $s['overdue_pct_rising'] ? '↑ растёт' : '' }}` | `{{ $s['overdue_pct_rising'] ? '↑ растет' : '' }}` |
| 142 | `<div><b>Дебиторка</b> = сумма непогашенных обещаний оплаты (active/expired с заданной суммой); рассрочка — обещания, объединённые в план (installment_…` | `<div><b>Дебиторка</b> = сумма непогашенных обещаний оплаты (active/expired с заданной суммой); рассрочка — обещания, объединенные в план (installment_…` |

### `resources/views/filament/pages/support-observability.blade.php`

| Line | Before | After |
|---|---|---|
| 14 | `<x-slot name="description">Последний успешный проход telegram-support:sync по каждому аккаунту; лаг больше 15 минут у включённого аккаунта подсвечивае…` | `<x-slot name="description">Последний успешный проход telegram-support:sync по каждому аккаунту; лаг больше 15 минут у включенного аккаунта подсвечивае…` |
| 24 | `<th class="py-2 pr-4">Включён</th>` | `<th class="py-2 pr-4">Включен</th>` |
| 106 | `События журнала SupportAiReplyEvent по типам, плюс оценка расхода из usage/model в meta (доступно только для событий, записанных после H763 — более ра…` | `События журнала SupportAiReplyEvent по типам, плюс оценка расхода из usage/model в meta (доступно только для событий, записанных после H763 — более ра…` |

### `resources/views/filament/pages/work-queue.blade.php`

| Line | Before | After |
|---|---|---|
| 107 | `<div class="wq-meta">ждёт с {{ $item['waiting_since']->diffForHumans() }}</div>` | `<div class="wq-meta">ждет с {{ $item['waiting_since']->diffForHumans() }}</div>` |

### `resources/views/filament/schedule/attendance.blade.php`

| Line | Before | After |
|---|---|---|
| 9 | `'present' => ['Пришёл', '#16a34a', '#dcfce7'],` | `'present' => ['Пришел', '#16a34a', '#dcfce7'],` |
| 10 | `'clicked' => ['Перешёл по ссылке', '#b45309', '#fef3c7'],` | `'clicked' => ['Перешел по ссылке', '#b45309', '#fef3c7'],` |

### `resources/views/filament/user-courses/blocks-preview-students.blade.php`

| Line | Before | After |
|---|---|---|
| 85 | `Зелёным — что проставится. Серым — уже заполнено вручную, оставляем как есть.` | `Зеленым — что проставится. Серым — уже заполнено вручную, оставляем как есть.` |

### `resources/views/filament/user-courses/blocks-preview.blade.php`

| Line | Before | After |
|---|---|---|
| 81 | `Зелёным — что проставится. Серым — уже заполнено вручную, оставляем как есть.` | `Зеленым — что проставится. Серым — уже заполнено вручную, оставляем как есть.` |

### `resources/views/livewire/student-chat.blade.php`

| Line | Before | After |
|---|---|---|
| 44 | `<p class="text-sm">Здесь можно задать вопрос по учёбе, оплате или доступу.<br>Напишите первым сообщением — ответит ИИ-куратор.</p>` | `<p class="text-sm">Здесь можно задать вопрос по учебе, оплате или доступу.<br>Напишите первым сообщением — ответит ИИ-куратор.</p>` |

### `resources/views/livewire/student-payments.blade.php`

| Line | Before | After |
|---|---|---|
| 29 | `Следующий платёж до: {{ $paidUntil->next_payment_deadline->format('d.m.Y') }}, 00:00 (МСК)` | `Следующий платеж до: {{ $paidUntil->next_payment_deadline->format('d.m.Y') }}, 00:00 (МСК)` |

### `resources/views/main.blade.php`

| Line | Before | After |
|---|---|---|
| 236 | `Все программы Общества ревнителей санскрита носят исключительно просветительский характер. Участие в них не ведёт к присвоению квалификации, профессии…` | `Все программы Общества ревнителей санскрита носят исключительно просветительский характер. Участие в них не ведет к присвоению квалификации, профессии…` |

### `resources/views/maintenance.blade.php`

| Line | Before | After |
|---|---|---|
| 23 | `Скоро вернёмся 🙏 Идут технические работы — кабинет ненадолго недоступен.` | `Скоро вернемся 🙏 Идут технические работы — кабинет ненадолго недоступен.` |

### `resources/views/marathon/level-quiz-result.blade.php`

| Line | Before | After |
|---|---|---|
| 10 | `<p class="text-sm text-gray-500">Дальше — День 1 марафона, начнём с того, что вам уже понятно.</p>` | `<p class="text-sm text-gray-500">Дальше — День 1 марафона, начнем с того, что вам уже понятно.</p>` |

### `resources/views/marathon/level-quiz.blade.php`

| Line | Before | After |
|---|---|---|
| 9 | `<p class="text-xs font-black uppercase tracking-widest text-[#E85C24] mb-2">Перед Днём 1</p>` | `<p class="text-xs font-black uppercase tracking-widest text-[#E85C24] mb-2">Перед Днем 1</p>` |

### `resources/views/marathon/show.blade.php`

| Line | Before | After |
|---|---|---|
| 14 | `3 дня, ~15 минут в день, в своём темпе. Личный маршрут, а не общий поток —` | `3 дня, ~15 минут в день, в своем темпе. Личный маршрут, а не общий поток —` |

### `resources/views/partials/why-us-block.blade.php`

| Line | Before | After |
|---|---|---|
| 36 | `'title' => 'Кто ведёт занятия',` | `'title' => 'Кто ведет занятия',` |

### `resources/views/partners/landing.blade.php`

| Line | Before | After |
|---|---|---|
| 7 | `$pageTitle = 'Партнёрская программа — Общество ревнителей санскрита';` | `$pageTitle = 'Партнерская программа — Общество ревнителей санскрита';` |
| 8 | `$description = 'Рекомендуйте наши курсы и получайте вознаграждение за каждого приведённого клиента. Все условия и цифры — сразу при регистрации.';` | `$description = 'Рекомендуйте наши курсы и получайте вознаграждение за каждого приведенного клиента. Все условия и цифры — сразу при регистрации.';` |
| 28 | `<i class="fas fa-handshake"></i> Партнёрская программа` | `<i class="fas fa-handshake"></i> Партнерская программа` |
| 34 | `Мы ценим каждую связь, которая сложилась за время работы, — и предлагаем вывести её` | `Мы ценим каждую связь, которая сложилась за время работы, — и предлагаем вывести ее` |
| 37 | `за каждого приведённого клиента. Всю остальную работу берём на себя.` | `за каждого приведенного клиента. Всю остальную работу берем на себя.` |
| 45 | `['fa-user-graduate', 'Клиент учится', 'Мы принимаем клиента, консультируем и ведём его сами — от вас нужна только рекомендация.'],` | `['fa-user-graduate', 'Клиент учится', 'Мы принимаем клиента, консультируем и ведем его сами — от вас нужна только рекомендация.'],` |
| 46 | `['fa-ruble-sign', 'Вы получаете выплату', 'Когда приведённый клиент впервые оплачивает курс, вам начисляется фиксированное вознаграждение к выплате.']…` | `['fa-ruble-sign', 'Вы получаете выплату', 'Когда приведенный клиент впервые оплачивает курс, вам начисляется фиксированное вознаграждение к выплате.']…` |
| 63 | `['Вознаграждение', number_format($reward, 0, '.', ' ').' ₽ за каждого приведённого клиента'],` | `['Вознаграждение', number_format($reward, 0, '.', ' ').' ₽ за каждого приведенного клиента'],` |
| 64 | `['Когда начисляется', 'При первой реальной оплате курса приведённым клиентом'],` | `['Когда начисляется', 'При первой реальной оплате курса приведенным клиентом'],` |
| 65 | `['Учёт', 'Прозрачно: в личном кабинете партнёра и в Telegram-боте видно каждого клиента и статус выплаты'],` | `['Учет', 'Прозрачно: в личном кабинете партнера и в Telegram-боте видно каждого клиента и статус выплаты'],` |
| 67 | `['Что от вас нужно', 'Только рекомендация. Продажу, поддержку и обучение ведём мы.'],` | `['Что от вас нужно', 'Только рекомендация. Продажу, поддержку и обучение ведем мы.'],` |
| 80 | `<p class="text-gray-600 mb-6">Заполните заявку — мы активируем ваш партнёрский аккаунт и вышлем ссылку для рекомендаций.</p>` | `<p class="text-gray-600 mb-6">Заполните заявку — мы активируем ваш партнерский аккаунт и вышлем ссылку для рекомендаций.</p>` |

### `resources/views/partners/registered.blade.php`

| Line | Before | After |
|---|---|---|
| 6 | `<title>Вы в партнёрской программе — Общество ревнителей санскрита</title>` | `<title>Вы в партнерской программе — Общество ревнителей санскрита</title>` |
| 32 | `<h1 class="text-3xl font-extrabold mb-2">Партнёрский кабинет</h1>` | `<h1 class="text-3xl font-extrabold mb-2">Партнерский кабинет</h1>` |
| 33 | `<p class="text-gray-600">Партнёр: <span class="font-bold">{{ $partner->name }}</span></p>` | `<p class="text-gray-600">Партнер: <span class="font-bold">{{ $partner->name }}</span></p>` |
| 50 | `<label class="block text-sm font-bold text-gray-700 mb-1">Ваша партнёрская ссылка</label>` | `<label class="block text-sm font-bold text-gray-700 mb-1">Ваша партнерская ссылка</label>` |

### `resources/views/pdf/debtors-manual.blade.php`

| Line | Before | After |
|---|---|---|
| 51 | `<li>Договорённости и рассрочка</li>` | `<li>Договоренности и рассрочка</li>` |
| 54 | `<li>Неблагонадёжные студенты (рецидивисты)</li>` | `<li>Неблагонадежные студенты (рецидивисты)</li>` |
| 64 | `<p>Это рабочий список студентов, у которых начался блок курса, но он не оплачен. Сервис нужен для звонков, напоминаний, оформления договорённостей по …` | `<p>Это рабочий список студентов, у которых начался блок курса, но он не оплачен. Сервис нужен для звонков, напоминаний, оформления договоренностей по …` |
| 67 | `<li><strong>Не продлил</strong> — студент когда-то платил, но не оплатил текущий блок. «Тёплый» долг, обычно достаточно напоминания.</li>` | `<li><strong>Не продлил</strong> — студент когда-то платил, но не оплатил текущий блок. «Теплый» долг, обычно достаточно напоминания.</li>` |
| 72 | `<p>Из выборки автоматически исключаются студенты, у которых в карточке «Обучается на курсах» по этому курсу стоит один из <em>терминальных</em> статус…` | `<p>Из выборки автоматически исключаются студенты, у которых в карточке «Обучается на курсах» по этому курсу стоит один из <em>терминальных</em> статус…` |
| 73 | `<p>Для всех терминальных статусов в форме статуса есть поле <strong>«Блок выхода»</strong> — номер блока этого курса, на/после которого студент вышел.…` | `<p>Для всех терминальных статусов в форме статуса есть поле <strong>«Блок выхода»</strong> — номер блока этого курса, на/после которого студент вышел.…` |
| 83 | `<li><strong>Неблагонадёжных</strong> — сколько студентов помечены флагом 🚩.</li>` | `<li><strong>Неблагонадежных</strong> — сколько студентов помечены флагом 🚩.</li>` |
| 100 | `<td>Имя, по клику — быстрая карточка. В подписи мелким: красная иконка 🚩 (если флаг неблагонадёжности), бейджи каналов связи (<code>TG</code>, <code>V…` | `<td>Имя, по клику — быстрая карточка. В подписи мелким: красная иконка 🚩 (если флаг неблагонадежности), бейджи каналов связи (<code>TG</code>, <code>V…` |
| 104 | `<td>Номер текущего блока курса (<code>№7</code>). В подписи — даты блока и просрочка: «просрочено 5 дн» или «просрочено 3 нед» (недели после 14 дней).…` | `<td>Номер текущего блока курса (<code>№7</code>). В подписи — даты блока и просрочка: «просрочено 5 дн» или «просрочено 3 нед» (недели после 14 дней).…` |
| 116 | `<td>Активная договорённость по этому курсу: <span class="badge badge-warn">до 25.05.2026</span> или <span class="badge badge-danger">просрочено 18.05.…` | `<td>Активная договоренность по этому курсу: <span class="badge badge-warn">до 25.05.2026</span> или <span class="badge badge-danger">просрочено 18.05.…` |
| 130 | `<strong>Группировка по курсу.</strong> Таблица по умолчанию свёрнута в группы по курсам. Если у студента долги на разных курсах, они попадают в разные…` | `<strong>Группировка по курсу.</strong> Таблица по умолчанию свернута в группы по курсам. Если у студента долги на разных курсах, они попадают в разные…` |
| 140 | `<li><strong>Неблагонадёжные</strong> — «Все» / «Только 🚩» / «Скрыть 🚩».</li>` | `<li><strong>Неблагонадежные</strong> — «Все» / «Только 🚩» / «Скрыть 🚩».</li>` |
| 141 | `<li><strong>Обещание оплатить</strong> — «Все» / «С обещанием» / «Без обещания». Имеет смысл сначала закрыть тех, у кого обещаний ещё нет.</li>` | `<li><strong>Обещание оплатить</strong> — «Все» / «С обещанием» / «Без обещания». Имеет смысл сначала закрыть тех, у кого обещаний еще нет.</li>` |
| 148 | `<li><span class="badge badge-success">Подтвердить оплату</span> — видно, когда есть активное обещание. Создаёт реальный Payment и закрывает обещание (…` | `<li><span class="badge badge-success">Подтвердить оплату</span> — видно, когда есть активное обещание. Создает реальный Payment и закрывает обещание (…` |
| 152 | `<li><strong>🚩 Отметить как неблагонадёжного</strong> — поднять флаг с обязательной причиной (раздел 11).</li>` | `<li><strong>🚩 Отметить как неблагонадежного</strong> — поднять флаг с обязательной причиной (раздел 11).</li>` |
| 153 | `<li><strong>Снять флаг неблагонадёжности</strong> — видна только когда флаг уже стоит.</li>` | `<li><strong>Снять флаг неблагонадежности</strong> — видна только когда флаг уже стоит.</li>` |
| 160 | `<li><strong>Сумма</strong> — подставляется из обещания или из расчётного долга.</li>` | `<li><strong>Сумма</strong> — подставляется из обещания или из расчетного долга.</li>` |
| 165 | `<p>После «Подтвердить»: создаётся Payment в статусе <code>paid</code>, обещание становится <span class="badge badge-success">Выполнено</span>, студент…` | `<p>После «Подтвердить»: создается Payment в статусе <code>paid</code>, обещание становится <span class="badge badge-success">Выполнено</span>, студент…` |
| 168 | `<strong>silent ≠ conditional access.</strong> silent фиксирует ОПЛАТУ задним числом (фин-отчёты считают её настоящей), а conditional access (раздел 9)…` | `<strong>silent ≠ conditional access.</strong> silent фиксирует ОПЛАТУ задним числом (фин-отчеты считают ее настоящей), а conditional access (раздел 9)…` |
| 181 | `<p>Слева у каждой строки чекбокс. Отметили несколько → внизу появляется кнопка «Напомнить в TG/VK». Те же плейсхолдеры. Студенты без TG и без VK попад…` | `<p>Слева у каждой строки чекбокс. Отметили несколько → внизу появляется кнопка «Напомнить в TG/VK». Те же плейсхолдеры. Студенты без TG и без VK попад…` |
| 184 | `<strong>Перед массовой рассылкой</strong> отфильтруйте по типу долга и/или курсу. Одно сообщение на разные сегменты редко даёт хорошую конверсию.` | `<strong>Перед массовой рассылкой</strong> отфильтруйте по типу долга и/или курсу. Одно сообщение на разные сегменты редко дает хорошую конверсию.` |
| 187 | `<h2>8. Договорённости и рассрочка</h2>` | `<h2>8. Договоренности и рассрочка</h2>` |
| 197 | `<p>Обещание сохраняется в статусе <span class="badge badge-warn">Активно</span>. Студенту автоматических сообщений не уходит (если не включён тогл «От…` | `<p>Обещание сохраняется в статусе <span class="badge badge-warn">Активно</span>. Студенту автоматических сообщений не уходит (если не включен тогл «От…` |
| 203 | `<li>Если сумма долга посчитана — суммы автоподставятся поровну, остаток в последний платёж.</li>` | `<li>Если сумма долга посчитана — суммы автоподставятся поровну, остаток в последний платеж.</li>` |
| 204 | `<li><strong>Факт. дата</strong> — DatePicker для случая, когда деньги по этой строке уже фактически поступили (например, при импорте старого графика з…` | `<li><strong>Факт. дата</strong> — DatePicker для случая, когда деньги по этой строке уже фактически поступили (например, при импорте старого графика з…` |
| 205 | `<li><strong>Откр. с блока № / по №</strong> — заполняйте, если этот платёж сразу открывает диапазон блоков (см. раздел 9).</li>` | `<li><strong>Откр. с блока № / по №</strong> — заполняйте, если этот платеж сразу открывает диапазон блоков (см. раздел 9).</li>` |
| 208 | `<p>После сохранения создаётся N обещаний, объединённых общим id плана. В карточке студента они помечены бейджем <span class="badge badge-info">#xxxxxx…` | `<p>После сохранения создается N обещаний, объединенных общим id плана. В карточке студента они помечены бейджем <span class="badge badge-info">#xxxxxx…` |
| 211 | `<strong>Факт. дата без реального Payment долг не закрывает.</strong> Если вы поставили только «Факт. оплата», но не вызвали «Подтвердить оплату», студ…` | `<strong>Факт. дата без реального Payment долг не закрывает.</strong> Если вы поставили только «Факт. оплата», но не вызвали «Подтвердить оплату», студ…` |
| 225 | `<p>По умолчанию обещание / рассрочка <strong>сами по себе не открывают доступ</strong> — это просто заметка о договорённости. Но если студенту нужно н…` | `<p>По умолчанию обещание / рассрочка <strong>сами по себе не открывают доступ</strong> — это просто заметка о договоренности. Но если студенту нужно н…` |
| 238 | `<li>«Сохранить» → студенту в TG: «Доступ к {курс/блокам} открыт по договорённости до DD.MM».</li>` | `<li>«Сохранить» → студенту в TG: «Доступ к {курс/блокам} открыт по договоренности до DD.MM».</li>` |
| 255 | `На практике чаще открывают весь предполагаемый объём рассрочки сразу при создании. Студент начинает учиться, а помесячные обещания служат напоминанием…` | `На практике чаще открывают весь предполагаемый объем рассрочки сразу при создании. Студент начинает учиться, а помесячные обещания служат напоминанием…` |
| 264 | `<p>В action отзыва можно вписать комментарий для студента — он попадёт во все 4 канала. После выполнения Filament покажет менеджеру отчёт по каналам: …` | `<p>В action отзыва можно вписать комментарий для студента — он попадет во все 4 канала. После выполнения Filament покажет менеджеру отчет по каналам: …` |
| 268 | `<li>Создаётся «conditional» Payment со статусом <code>paid</code> и флагом <code>is_conditional=true</code>, привязанный к обещанию через <code>linked…` | `<li>Создается «conditional» Payment со статусом <code>paid</code> и флагом <code>is_conditional=true</code>, привязанный к обещанию через <code>linked…` |
| 271 | `<li>Conditional Payments <strong>не считаются оплатой</strong>: должник остаётся в списке, прана не начисляется, welcome-email не уходит, в Google She…` | `<li>Conditional Payments <strong>не считаются оплатой</strong>: должник остается в списке, прана не начисляется, welcome-email не уходит, в Google She…` |
| 272 | `<li>В TG студенту уходит спец-уведомление «доступ открыт по договорённости до DD.MM», а не стандартное «оплата получена».</li>` | `<li>В TG студенту уходит спец-уведомление «доступ открыт по договоренности до DD.MM», а не стандартное «оплата получена».</li>` |
| 285 | `<td>Фиксируем договорённость, доступ не нужен</td>` | `<td>Фиксируем договоренность, доступ не нужен</td>` |
| 290 | `<td>«Подтвердить оплату» (раздел 6). Создаётся реальный Payment, доступ открывается, начисляется прана.</td>` | `<td>«Подтвердить оплату» (раздел 6). Создается реальный Payment, доступ открывается, начисляется прана.</td>` |
| 294 | `<td>«Договориться» / «Рассрочка» с включённым тоглом «Открыть доступ». В фин-отчётах нет, в списке должников остаётся.</td>` | `<td>«Договориться» / «Рассрочка» с включенным тоглом «Открыть доступ». В фин-отчетах нет, в списке должников остается.</td>` |
| 312 | `<p>В личном кабинете студента во вкладке <strong>«Мои долги»</strong> у каждого долга есть кнопка <strong>«Оплатить»</strong>: долг «не продлил» ведёт…` | `<p>В личном кабинете студента во вкладке <strong>«Мои долги»</strong> у каждого долга есть кнопка <strong>«Оплатить»</strong>: долг «не продлил» ведет…` |
| 313 | `<p>Когда реальная оплата приходит, покрытые ею обещания <strong>закрываются автоматически</strong> (становятся «Выполнено», записывается платёж; части…` | `<p>Когда реальная оплата приходит, покрытые ею обещания <strong>закрываются автоматически</strong> (становятся «Выполнено», записывается платеж; части…` |
| 325 | `<p>После «Сохранить» создаётся запись в <code>lesson_access_grants</code>. Студент сразу видит этот урок открытым, остальной блок остаётся закрытым. Э…` | `<p>После «Сохранить» создается запись в <code>lesson_access_grants</code>. Студент сразу видит этот урок открытым, остальной блок остается закрытым. Э…` |
| 326 | `<p><strong>Отзыв</strong>: на строке гранта — кнопка <span class="badge badge-danger">Отозвать</span>. Доступ закрывается, статус — «отозван», студент…` | `<p><strong>Отзыв</strong>: на строке гранта — кнопка <span class="badge badge-danger">Отозвать</span>. Доступ закрывается, статус — «отозван», студент…` |
| 329 | `<h2>11. Неблагонадёжные студенты (рецидивисты)</h2>` | `<h2>11. Неблагонадежные студенты (рецидивисты)</h2>` |
| 336 | `<li><strong>Conditional access не открывается.</strong> Сервис кидает ошибку «Студент в чёрном списке».</li>` | `<li><strong>Conditional access не открывается.</strong> Сервис кидает ошибку «Студент в черном списке».</li>` |
| 343 | `<li><strong>Вручную</strong>: меню действий → «🚩 Отметить как неблагонадёжного». Причина обязательна. Сохраняется кто пометил и когда.</li>` | `<li><strong>Вручную</strong>: меню действий → «🚩 Отметить как неблагонадежного». Причина обязательна. Сохраняется кто пометил и когда.</li>` |
| 348 | `<p><strong>Только вручную</strong> — меню действий → «Снять флаг неблагонадёжности». Автоматически не снимается, даже если поведение улучшилось.</p>` | `<p><strong>Только вручную</strong> — меню действий → «Снять флаг неблагонадежности». Автоматически не снимается, даже если поведение улучшилось.</p>` |
| 368 | `<tr><td><span class="badge badge-warn">Льготник</span></td><td>Учится бесплатно по договорённости.</td><td><strong>Нет</strong> — исключён.</td></tr>` | `<tr><td><span class="badge badge-warn">Льготник</span></td><td>Учится бесплатно по договоренности.</td><td><strong>Нет</strong> — исключен.</td></tr>` |
| 369 | `<tr><td><span class="badge badge-success">Выпускник</span></td><td>Курс завершён.</td><td><strong>Нет</strong> — исключён.</td></tr>` | `<tr><td><span class="badge badge-success">Выпускник</span></td><td>Курс завершен.</td><td><strong>Нет</strong> — исключен.</td></tr>` |
| 370 | `<tr><td><span class="badge badge-danger">Покинул</span></td><td>Студент вышел из курса.</td><td><strong>Нет</strong> — исключён.</td></tr>` | `<tr><td><span class="badge badge-danger">Покинул</span></td><td>Студент вышел из курса.</td><td><strong>Нет</strong> — исключен.</td></tr>` |
| 371 | `<tr><td><span class="badge badge-danger">Исключен</span></td><td>Отчислен администрацией.</td><td><strong>Нет</strong> — исключён.</td></tr>` | `<tr><td><span class="badge badge-danger">Исключен</span></td><td>Отчислен администрацией.</td><td><strong>Нет</strong> — исключен.</td></tr>` |
| 379 | `<li>На странице «Должники» — в подписи к колонке «Статус обучения» мелким: «после блока №5» (только для пар, которые ещё не исключены полностью — напр…` | `<li>На странице «Должники» — в подписи к колонке «Статус обучения» мелким: «после блока №5» (только для пар, которые еще не исключены полностью — напр…` |
| 389 | `<p>Все обещания студента по всем курсам — активные, выполненные, просроченные, отменённые. Колонки:</p>` | `<p>Все обещания студента по всем курсам — активные, выполненные, просроченные, отмененные. Колонки:</p>` |
| 393 | `<li><strong>Платёж</strong> — номер реального платежа, которым закрыто обещание (для выполненных).</li>` | `<li><strong>Платеж</strong> — номер реального платежа, которым закрыто обещание (для выполненных).</li>` |
| 399 | `<p>Все ранее выданные lesson grants. Колонки: курс, урок (с подписью «Блок №X»), статус (<span class="badge badge-success">активен</span> / <span clas…` | `<p>Все ранее выданные lesson grants. Колонки: курс, урок (с подписью «Блок №X»), статус (<span class="badge badge-success">активен</span> / <span clas…` |
| 401 | `<h2>13a. Скор платёжной дисциплины</h2>` | `<h2>13a. Скор платежной дисциплины</h2>` |
| 414 | `<li><strong>Напомнить в TG/VK</strong> — массовая рассылка. Плейсхолдеры: <code>{name}</code>, <code>{course}</code>, <code>{block}</code>. Идёт в Tel…` | `<li><strong>Напомнить в TG/VK</strong> — массовая рассылка. Плейсхолдеры: <code>{name}</code>, <code>{course}</code>, <code>{block}</code>. Идет в Tel…` |
| 430 | `<td>Пересчёт авто-флага 🚩 (3+ expired-обещаний за 12 мес.) и подсветки «поведение улучшилось» (6 мес. без просрочек).</td>` | `<td>Пересчет авто-флага 🚩 (3+ expired-обещаний за 12 мес.) и подсветки «поведение улучшилось» (6 мес. без просрочек).</td>` |
| 453 | `<h3>Студент оплатил, но колонка «Долг» всё ещё показывает блок</h3>` | `<h3>Студент оплатил, но колонка «Долг» всё еще показывает блок</h3>` |
| 466 | `<p>Технически да, кнопка «Удалить» в RM есть. Но лучше переводить в «Отменено» — тогда останется история и видно, что договорённость была.</p>` | `<p>Технически да, кнопка «Удалить» в RM есть. Но лучше переводить в «Отменено» — тогда останется история и видно, что договоренность была.</p>` |
| 472 | `<li>Conditional access (открытие в кредит): студенту «доступ открыт по договорённости до DD.MM», в Google Sheets НЕ уходит.</li>` | `<li>Conditional access (открытие в кредит): студенту «доступ открыт по договоренности до DD.MM», в Google Sheets НЕ уходит.</li>` |
| 490 | `<p>После «Отозвать доступ» Filament показывает отчёт по каналам: <code>telegram: sent / skipped:no_telegram_id / failed:…</code>. Тот же отчёт хранитс…` | `<p>После «Отозвать доступ» Filament показывает отчет по каналам: <code>telegram: sent / skipped:no_telegram_id / failed:…</code>. Тот же отчет хранитс…` |

### `resources/views/promo/blocks/course_finished_block.blade.php`

| Line | Before | After |
|---|---|---|
| 5 | `$title = $data['title'] ?? 'Курс завершён';` | `$title = $data['title'] ?? 'Курс завершен';` |
| 6 | `$subtitle = $data['subtitle'] ?? 'Повторный набор не планируется. Все лекции доступны в записи — учитесь в своём темпе с пожизненным доступом.';` | `$subtitle = $data['subtitle'] ?? 'Повторный набор не планируется. Все лекции доступны в записи — учитесь в своем темпе с пожизненным доступом.';` |

### `resources/views/promo/blocks/hero_block.blade.php`

| Line | Before | After |
|---|---|---|
| 8 | `.'. Ещё '.$spotsLeft.' '.\App\Support\Plural::ru($spotsLeft, 'место', 'места', 'мест').' доступно';` | `.'. Еще '.$spotsLeft.' '.\App\Support\Plural::ru($spotsLeft, 'место', 'места', 'мест').' доступно';` |

### `resources/views/promo/blocks/recorded_courses_block.blade.php`

| Line | Before | After |
|---|---|---|
| 3 | `$blockSubtitle = $data['subtitle'] ?? 'Учитесь в своём темпе — записи лекций с пожизненным доступом.';` | `$blockSubtitle = $data['subtitle'] ?? 'Учитесь в своем темпе — записи лекций с пожизненным доступом.';` |

### `resources/views/promo/blocks/student_story_block.blade.php`

| Line | Before | After |
|---|---|---|
| 107 | `<span class="font-bold text-gray-600">Кому не зайдёт:</span> {{ $story['not_for'] }}` | `<span class="font-bold text-gray-600">Кому не зайдет:</span> {{ $story['not_for'] }}` |

### `resources/views/promo/blocks/webinar_topics_block.blade.php`

| Line | Before | After |
|---|---|---|
| 14 | `{{ $data['title'] ?? 'Что разберём на вебинарах' }}` | `{{ $data['title'] ?? 'Что разберем на вебинарах' }}` |

### `resources/views/rq4/complete-post-test.blade.php`

| Line | Before | After |
|---|---|---|
| 10 | `На этом основная часть закончена. Через 4 недели мы пришлём вам напоминание пройти` | `На этом основная часть закончена. Через 4 недели мы пришлем вам напоминание пройти` |

### `resources/views/shop/partials/ladder.blade.php`

| Line | Before | After |
|---|---|---|
| 10 | `<p class="text-slate-400 mb-8 max-w-2xl">Начните бесплатно, продолжите в записи в своём темпе, присоединяйтесь к живому потоку, когда будете готовы.</…` | `<p class="text-slate-400 mb-8 max-w-2xl">Начните бесплатно, продолжите в записи в своем темпе, присоединяйтесь к живому потоку, когда будете готовы.</…` |

### `resources/views/shop/partials/tech-payment.blade.php`

| Line | Before | After |
|---|---|---|
| 29 | `'Оплачиваете картой онлайн на защищённой странице.',` | `'Оплачиваете картой онлайн на защищенной странице.',` |

### `resources/views/shop/partials/trust-strip.blade.php`

| Line | Before | After |
|---|---|---|
| 14 | `'text' => 'Преподаём санскрит '.max(1, now()->year - $trustSinceYear).'+ лет — в традиции живых учителей.',` | `'text' => 'Преподаем санскрит '.max(1, now()->year - $trustSinceYear).'+ лет — в традиции живых учителей.',` |
| 16 | `['icon' => 'fas fa-film', 'title' => 'Записи всех занятий', 'text' => 'Доступ к видеозаписям остаётся с вами навсегда.'],` | `['icon' => 'fas fa-film', 'title' => 'Записи всех занятий', 'text' => 'Доступ к видеозаписям остается с вами навсегда.'],` |

### `resources/views/student.blade.php`

| Line | Before | After |
|---|---|---|
| 55 | `<h1>Моё обучение</h1>` | `<h1>Мое обучение</h1>` |

### `resources/views/student/calendar.blade.php`

| Line | Before | After |
|---|---|---|
| 2 | `@section('title', 'Моё расписание')` | `@section('title', 'Мое расписание')` |
| 24 | `Подписаться на расписание в своём календаре` | `Подписаться на расписание в своем календаре` |

### `resources/views/student/partials/onboarding-checklist.blade.php`

| Line | Before | After |
|---|---|---|
| 65 | `onsubmit="return confirm('Отвязать Telegram-бота? Уведомления по учёбе перестанут приходить. Подключить заново можно в любой момент.');"` | `onsubmit="return confirm('Отвязать Telegram-бота? Уведомления по учебе перестанут приходить. Подключить заново можно в любой момент.');"` |

### `resources/views/student/partials/prana-rank.blade.php`

| Line | Before | After |
|---|---|---|
| 26 | `Ещё {{ number_format(max(0, $rank['next_min'] - $rank['lifetime']), 0, '.', ' ') }} праны до следующего ранга.` | `Еще {{ number_format(max(0, $rank['next_min'] - $rank['lifetime']), 0, '.', ' ') }} праны до следующего ранга.` |
| 27 | `Ранг растёт от накопленной праны и не падает при тратах на скидки.` | `Ранг растет от накопленной праны и не падает при тратах на скидки.` |

### `resources/views/student/partials/prana-streak.blade.php`

| Line | Before | After |
|---|---|---|
| 16 | `Ещё {{ $milestone['remaining'] }} {{ \App\Support\Plural::ru($milestone['remaining'], 'день', 'дня', 'дней') }} до бонуса` | `Еще {{ $milestone['remaining'] }} {{ \App\Support\Plural::ru($milestone['remaining'], 'день', 'дня', 'дней') }} до бонуса` |

### `resources/views/student/partials/referral.blade.php`

| Line | Before | After |
|---|---|---|
| 21 | `на счёт — они автоматически спишутся в счёт вашей следующей покупки.` | `на счет — они автоматически спишутся в счет вашей следующей покупки.` |

## Decisions taken unattended

1. **Filament admin pages counted as in-scope** (see Scope decisions above) -- included
   rather than carved out, since the doc's scope line names `resources/views/**` without
   exception and admin copy is still rendered UI text for a human user.
2. **`config/*.php` scoped to array-value labels, excluding `//`/`#` developer comments** --
   the phrase "Russian label values" was read literally; comments describing a config key
   are not shipped copy.
3. **`Распознаём` (2 occurrences) left with ё** -- a genuine aspectual-homograph risk
   (see Residual exceptions), and the lane's own default on unreviewed ambiguity is to leave
   the ё. Low-stakes (internal Filament admin description, not student/money copy), so left
   for a future pass rather than blocking the lane.
4. **Auto-merge:** per the wave's prerequisite, Semgrep (PHP SAST) is red on Systema's
   `main` as of 19-07-2026 08:01Z (confirmed before this lane started, independent of this
   PR's diff -- a markdown-only PR carries the identical failure). This PR does **not**
   enable auto-merge; it is opened and left for manual merge once the scanner is fixed or
   the red gate is otherwise resolved. Reported per the wave's "acceptable" posture, not the
   forbidden one (no attempt was made to bypass, disable, or reconfigure Semgrep).
5. **Test-suite fallout fixed as part of this lane:** `php artisan test` surfaced string
   assertions in feature tests pinned to the pre-sweep ё-spelling; those tests were updated
   to assert the normalised copy rather than being skipped or the source strings being
   reverted (see the commit for the exact test diff).

_Dr. Mārcis Gasūns_
