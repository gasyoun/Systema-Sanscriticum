# Голосовой контракт денежных поверхностей samskrte.ru (RU)

_Created: 24-08-2026 · Last updated: 24-08-2026_

Сквозное чтение одним голосом десяти линий волны revenue-copy (H1285–H1294,
Fable 5 `claude-fable-5`, июль 2026) по состоянию `main` на 24-08-2026
(коммит `756e7a55`). Волна писалась десятью изолированными сессиями под
предварительным контрактом
[ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md](https://github.com/gasyoun/Uprava/blob/main/docs/ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md)
(Uprava, 19-07-2026) и с четырьмя общими строками в
[docs/copy/_shared_strings.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md);
план назвал этот read самым ценным follow-up'ом волны (риск R1). Здесь —
норма, выведенная из того, что реально на `main`, таблица расхождений с
цитатой файл:строка и что с каждым сделано. Исполнитель — Fable 5
(`claude-fable-5`), handoff
[H3136](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3136-Fable_Systema-Sanscriticum_revenue-copy-cross-lane-voice-read_19.08.26.md).

## 1. Охват: поверхности и их носители

Инвентарь снят по merged PR каждой линии (`git log --grep=H12xx --name-only`),
затем сверен с текущим `main` — копия трех поверхностей уже мутировала после
волны (PayPal-путь: рулинг 22-08-2026 и правки MG 23-08-2026; письма: вставка
MG 23-08-2026).

| Линия | Поверхность на `main` | Носитель |
|---|---|---|
| H1285 | [payment/success.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/payment/success.blade.php) · [payment/fail.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/payment/fail.blade.php) | blade |
| H1286 | [emails/purchase-confirmation.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/purchase-confirmation.blade.php) · [emails/onboarding/day1.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/onboarding/day1.blade.php) · [day5.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/onboarding/day5.blade.php) · темы в [PurchaseConfirmationMail](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PurchaseConfirmationMail.php), [OnboardingDay1Mail](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/OnboardingDay1Mail.php), [OnboardingDay5Mail](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/OnboardingDay5Mail.php) | mailable |
| H1287 | [promo/blocks/price_block.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/price_block.blade.php) · [course_streams_block.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/promo/blocks/course_streams_block.blade.php) | blade (данные из `LandingPage`) |
| H1288 | [docs/vozvrat.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php) · строка «Возврат: до начала — 100%» на [checkout/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/checkout/show.blade.php) · [partials/footer-docs.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/footer-docs.blade.php) | blade |
| H1289 | [app/Support/DunningStage.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/DunningStage.php) (стадии 2–4) · [DebtorReminderDispatcher.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorReminderDispatcher.php) (стадия 1) · [emails/debtor-reminder.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/debtor-reminder.blade.php) | PHP-константы с переопределением в `MarketingSetting` |
| H1290 | [partials/installments-cta.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/installments-cta.blade.php) | blade |
| H1291 | [shop/show.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php) (блоки `objection-time-microcopy`, `objection-price-microcopy`) · [components/shop/course-card.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/components/shop/course-card.blade.php) («Блок — обычно 4 занятия») | blade |
| H1292 | [partials/paypal-cta.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/paypal-cta.blade.php) · [paypal/claim.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php) · [emails/paypal/claim-student-ack.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/paypal/claim-student-ack.blade.php) · [PaypalClaimStudentAckMail](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/PaypalClaimStudentAckMail.php) | blade + mailable |
| H1293 | [shop/partials/price-ladder-narrative.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/partials/price-ladder-narrative.blade.php) | blade (числа из `ProductLadderAnchors`) |
| H1294 | [student/partials/referral.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/referral.blade.php) · [partials/referral-welcome.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/referral-welcome.blade.php) · блок «Порекомендовать школу» на странице успеха | blade |

## 2. Норма — что подтвердилось и что добавилось

Предварительный контракт (Uprava, §1–2) **держится**: во всех двадцати файлах
ни одного эмодзи в копии, ни одной пары восклицательных знаков (единственный
«!» — в «Намасте, {name}!»), ни «спешите/успейте/только сегодня», ни «всего /
даже / всего лишь» перед суммой. Приветствие «Намасте, {name}!» с фолбэком
«друг» — во всех четырех письмах и во всех четырех стадиях дожима. Кнопка
«Написать в Telegram» и адрес `t.me/rusamskrtam` — единственный канал на всех
поверхностях. Строка возврата «Возврат: до начала — 100%» дословно совпадает
на чекауте и на странице курса. Лучший пример переиспользования — фраза
«Платить за весь курс сразу не нужно: … оплачиваете ближайший блок … и решаете
о продолжении после него», почти дословно общая у H1291 (страница курса) и
H1293 (лесенка цен).

Правила ниже — то, чего в предварительном контракте не было, а read показал
нужным. Нумерация продолжает §1–§2 контракта Uprava.

### 2.1 Слово для учащегося — «ученик», не «студент»

На денежных поверхностях «студент» уже занят льготной категорией: «Пенсионерам,
студентам и многодетным … действует льготная цена» ([shop/show.blade.php:988](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php)).
Рядом с этой строкой «вы наш студент» читается как «вы льготник». Наш
учащийся — **ученик** («новым ученикам», «вы наш ученик» в письмах PayPal).
«Студент» допустим только как категория скидки и в служебных экранах
(`/dvaram` называется «кабинет студента» — это навигация, не денежная копия).

### 2.2 «Оплата по частям», не «рассрочка»; «начислено», не «кредит»

H1290 сознательно ушел от «рассрочки» — слово тянет банк и кредитную анкету,
а запрос куратору именно этого и не требует («без банка и кредитных анкет»,
[installments-cta.blade.php:38](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/installments-cta.blade.php)).
Тот же довод запрещает «кредит» для реферальной благодарности: «Кредит за
рекомендации» в кабинете стоял через два экрана от обещания «без кредитных».
Норма: **разбить оплату на части / оплата по частям**; **начислено /
зачтется** для сумм в пользу ученика. «Рассрочка» и «кредит» остаются терминами
финансового контура (`InstallmentPlan`, `PaymentPromise`, `referral_credit`) —
в коде, не в копии.

### 2.3 Два разных обещания времени — не смешивать

- **Ответ поддержки:** «обычно отвечаем в течение рабочего дня» (общая строка 3).
- **Сверка платежа PayPal:** «обычно в течение одного рабочего дня» — это про
  доступ, а не про ответ, и «одного» здесь нагрузочно (обещание срока, а не
  скорости реакции).

Обе формы допустимы рядом, но подлежащее должно совпадать с обещанием:
«куратор свяжется — обычно отвечаем» смешивает третье лицо с первым
множественным.

### 2.4 Общая строка 2 не переставляется

«Если деньги списались — не платите повторно: напишите нам, мы проверим платеж
и либо откроем доступ, либо вернем деньги.» Страх, который она снимает, —
«деньги ушли, а доступа нет», поэтому уточнения «повторно / дважды» в условии
сужают ее до другого, более редкого страха, а перенос «не платите повторно» в
конец ставит успокоение после инструкции. Порядок фиксирован: условие →
запрет повторной оплаты → что мы сделаем (оба исхода).

### 2.5 Дожим: стадия 1 не строже стадии 2

Лестница H1289 задумана с ростом температуры от стадии 1 к 4. Стадия 1 —
«мягкое напоминание» — не должна содержать угрозу потери, которой еще нет в
стадии 2 (та лишь констатирует: «если оплата не придет до старта, доступ … не
откроется»). Норма для стадии 1: факт + ссылка + «просто проигнорируйте», без
мотивирующего придаточного.

### 2.6 Темы писем: «<факт> — <курс>»

Денежные темы держат одну форму: «Оплата получена — «Курс»», «Срок оплаты
близко — {course}», «Оплата не поступила — {course}», «Доступ к материалам
закрыт — {course}», «Напоминание об оплате — {course}», «Заявка получена —
доступ открыт / сверяем ваш платеж PayPal». Онбординговые письма (не деньги)
могут отклоняться: «С чего начать: первый урок уже открыт», «Как идут
занятия?» — допущено, потому что это не транзакционные письма.

### 2.7 Регистр кнопок и юридический островок

- Источник — всегда предложение с прописной первой буквы («В личный кабинет»,
  «Перейти к обучению»). Капитель только через CSS (`text-transform: uppercase`
  в письмах, `uppercase tracking-widest` в промо-блоках) — это верстка, не
  копия, и она не меняет строку в источнике.
- Кнопка в кабинет называет место, а не действие: «В личный кабинет». «Перейти
  к обучению» — только когда доступ уже подтвержден вебхуком.
- [/vozvrat](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php)
  дублирует числа словами («1 (один)», «10 (десяти)») — это оферта, не наш
  голос; юридическая копия выигрывает у стиль-гайда (CLAUDE.md, «Editorial
  style»). Островок не расширять на соседние экраны.

### 2.8 Общая строка 5 — «Кабинет сам умеет»

Вставка MG 23-08-2026 повторяется дословно в трех письмах
(purchase-confirmation, onboarding/day1, paypal/claim-student-ack) и потому
регистрируется в `_shared_strings.md` как строка 5 — менять только во всех
трех сразу. Ее исходная лексика («погасить долг», «взнос по рассрочке»)
расходилась с §2.2 и с посылкой контракта «читатель — ученик, а не должник» —
см. таблицу, строка 10: по решению MG первая строка переписана.

## 3. Таблица расхождений

Каждая строка цитирует `main` `756e7a55` (24-08-2026), не память handoff'а.
Статус: **исправлено** — этим PR; **норма** — оставлено, зафиксировано как
правило; **человек** — требует решения MG.

| № | Поверхность · строка | Отклонение | Норма | Статус |
|---|---|---|---|---|
| 1 | [DebtorReminderDispatcher.php:28](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/DebtorReminderDispatcher.php) | стадия 1: «уже идёт (или скоро начнётся), а оплата ещё не поступила» — три ё, тогда как стадии 2–4 ([DunningStage.php:84–86](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/DunningStage.php)) без ё | D13: без ё | исправлено |
| 2 | там же | «Чтобы не потерять доступ к материалам, оформите оплату.» — угроза потери в самой мягкой стадии; стадия 2 мягче | §2.5 | исправлено (+ два пина в [DunningEscalationLadderTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/DunningEscalationLadderTest.php)) |
| 3 | [paypal/claim.blade.php:202–203](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php) | «Вы наш студент — доступ … после отправки заявки Вами» — прописное «Вами» (контракт §1: «вы» строчное), «студент» против «ученик» в парном письме | §2.1; «вы» строчное | исправлено |
| 4 | [paypal/claim.blade.php:208–211](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php) (ветка своего ученика) | общая строка 2 переставлена: «Если деньги списались повторно или что-то не так — напишите … Не платите повторно — проверим платеж и вернем деньги» | §2.4 | исправлено |
| 5 | [paypal/claim.blade.php:226–227](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php) (ветка гостя) | «Если деньги списались дважды — … проверим платеж и вернем деньги» — сужено до «дважды», потерян исход «откроем доступ» | §2.4 | исправлено |
| 6 | [paypal/claim.blade.php:131](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php) | «(не business-аккаунт)» — латиница там, где есть русское слово | контракт §1 «Never»: без заимствований | исправлено |
| 7 | [student/partials/referral.blade.php:57](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/referral.blade.php) | «Кредит за рекомендации» — читается как банковский кредит рядом с «без кредитных анкет» | §2.2 → «Начислено за рекомендации» | исправлено (+ пин в [ReferralAskSurfacesTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/ReferralAskSurfacesTest.php)) |
| 8 | [student/partials/referral.blade.php:19](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/partials/referral.blade.php) | «рекомендация студента» | §2.1 → «рекомендация ученика» | исправлено |
| 9 | [installments-cta.blade.php:14](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/installments-cta.blade.php) | «Куратор свяжется с вами — обычно отвечаем в течение рабочего дня» — третье лицо + первое множественное в одной фразе | §2.3 → «Запрос у куратора — обычно отвечаем в течение рабочего дня» (общая строка 3 сохранена дословно) | исправлено |
| 10 | [purchase-confirmation.blade.php:50](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/purchase-confirmation.blade.php) · [day1.blade.php:32](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/onboarding/day1.blade.php) · [claim-student-ack.blade.php:67](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/paypal/claim-student-ack.blade.php) | блок MG 23-08-2026 «погасить долг или взнос по рассрочке, без куратора» — «долг» в приветственном письме сразу после оплаты; «рассрочка» против «оплаты по частям» (H1290); строка не зарегистрирована как общая | §2.2, §2.8 → «внести очередную часть оплаты или закрыть просроченный платеж — без куратора» | исправлено (решение MG «B-reword» 24-08-2026, вторым PR во всех трех письмах; строка 5 в `_shared_strings.md` обновлена) |
| 11 | [paypal-cta.blade.php:11–12](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/paypal-cta.blade.php) · [claim.blade.php:20](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/paypal/claim.blade.php) · [claim-student-ack.blade.php:49](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/paypal/claim-student-ack.blade.php) | «в течение одного рабочего дня» (сверка) рядом с «в течение рабочего дня» (ответ) | §2.3: два разных обещания, обе формы законны | норма |
| 12 | [success.blade.php:78, 125, 147](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/payment/success.blade.php) · письма (`В личный кабинет`, капитель CSS) · [day1.blade.php:17](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/onboarding/day1.blade.php) | пять подписей кнопки в кабинет: «Перейти к обучению» / «Перейти в личный кабинет» / «В личный кабинет» / «Войти в аккаунт» / «Открыть первый урок» | §2.7: каждая привязана к состоянию (подтверждено / ждем / гость / первый шаг) | норма |
| 13 | темы писем (см. §2.6) | day1 с двоеточием, day5 вопросом | §2.6: онбординг — не денежная тема | норма |
| 14 | [vozvrat.blade.php:39–44, 120](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/docs/vozvrat.blade.php) | «1 (один)», «7 (семи)», «10 (десяти)» | §2.7: юридический островок | норма |
| 15 | [price-ladder-narrative.blade.php:119–121](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/partials/price-ladder-narrative.blade.php) ↔ [shop/show.blade.php:979](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/shop/show.blade.php) | одна фраза о блоках в двух линиях — почти дословно | образец, не расхождение | норма |

Не найдено (проверено grep'ом по всем двадцати файлам, строки копии без
комментариев): эмодзи; удвоенные «!»; «спешите / успейте / только сегодня /
уникальн-»; «всего / даже / лишь» перед суммой; уменьшительные; ё вне
исключения «всё» — кроме строки 1.

## 4. Что исправлено этим PR (только копия)

Логика и шаблоны не тронуты; изменены строки и два пина в тестах.

1. Стадия 1 дожима — новый дефолт без ё и без угрозы:
   «Намасте, {name}!\n\nБлок №{block} курса «{course}» уже идет или скоро
   начнется, а оплата пока не поступила.{paid_until}{deadline}\n\nОплатить курс:
   {pay_link}\n\nЕсли оплата уже внесена — просто проигнорируйте это сообщение.»
   Менеджерские переопределения в `MarketingSetting` не затронуты (меняется
   только дефолт и плейсхолдер формы).
2. PayPal-страница: «Вы наш ученик — доступ к курсу откроется сразу после
   отправки заявки.»; обе ветки шага 3 — общая строка 2 дословно, затем срок
   ответа; «(личный, не бизнес-аккаунт)».
3. Кабинет: «Начислено за рекомендации»; «рекомендация ученика».
4. Чекаут: «Запрос у куратора — обычно отвечаем в течение рабочего дня.»
5. `_shared_strings.md`: строка 5 зарегистрирована.

## 5. Что ждало человека — решено

Строка 10 таблицы (блок MG «Кабинет сам умеет» в трех письмах) была
единственным расхождением, которое агент не правил сам. MG выбрал «B-reword»
24-08-2026; первая строка блока переписана во всех трех письмах вторым PR,
строка 5 в `_shared_strings.md` обновлена (§2.8). Открытых расхождений нет.

## 6. Как применять следующей линии

1. Прочитать контракт Uprava §1–2 и этот §2, взять общие строки 1–5 дословно.
2. Перед PR прогнать по своим файлам grep из §3 («не найдено»): ё вне «всё»,
   «!» кроме «Намасте», урgency-словарь, «всего/даже/лишь» перед суммой,
   прописное «Вы/Вам/Вами», «студент» вне льготы, «рассрочка/кредит» в копии.
3. Новая фраза, повторенная в двух и более поверхностях, — сразу в
   `_shared_strings.md`, иначе через месяц она разойдется.

_Dr. Mārcis Gasūns_
