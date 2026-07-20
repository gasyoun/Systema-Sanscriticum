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
- **H1281 (D6): «Лигатуры по частотности» — деванагари-тренажёр конъюнктов.** Новое
  статичное семейство `public/exercises/ligatures/` в существующей `public/exercises/`
  игротеке (не новый движок — reuse `match/engine.js`+`match/engine.css` as-is, per the
  plan's non-goal). Данные — топ-200 санскритских лигатур (saṃyoga) по
  корпусной частотности из VisualDCS
  [`derived-data/Fonetika/regen-2026/ligature_freq.csv`](https://github.com/gasyoun/VisualDCS/blob/main/derived-data/Fonetika/regen-2026/ligature_freq.csv)
  (Digital Corpus of Sanskrit — Oliver Hellwig, CC BY 4.0; kosha manifest id
  `dcs-grapheme-frequency`), committed as
  [`data.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/exercises/ligatures/data.js)
  with the regen command in its header. Three cumulative frequency levels —
  `top-10/` (all 10 shown), `top-50/` and `top-200/` (`perRound: 10`, a fresh random
  ten each "Заново") — each a `MatchExercise.mount()` pairing the Devanāgarī glyph to
  its IAST romanization, hint = corpus rank + % of all ligature tokens. Linked from the
  main `/exercises/` catalogue as a fourth family; prior-art fence links out to
  [csl-guides](https://sanskrit-lexicon.github.io/csl-guides/) for the full script
  course rather than duplicating it. Static-only — no migration, no flag, no backend;
  ships with the normal deploy — see
  [DEPLOY_QUEUE №40](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).
  Sonnet 5 (`claude-sonnet-5`).
  [H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md).

## [1.29.0] - 2026-07-19

### Added
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
- **Corpus-frequency learner surfaces staged (queued, docs-only):** new plan
  [`docs/PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_CORPUS_FREQUENCY_LEARNER_SURFACES_2026H2.md)
  (+ metadoc) staging two Tier-0 integrations via
  [`/ask-batch`](https://github.com/gasyoun/claude-config/blob/main/commands/ask-batch.md):
  a frequency-ranked RU root SRS deck
  ([H1280](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1280-Sonnet_Systema-Sanscriticum_srs-root-frequency-ru-deck_19.07.26.md),
  kosha `roots_frequency.tsv` × WhitneyRoots RU glosses → existing FSRS stack) and a
  conjunct-frequency Devanāgarī drill
  ([H1281](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1281-Sonnet_Systema-Sanscriticum_marathon-conjunct-frequency-order_19.07.26.md),
  `dcs-grapheme-frequency` → `public/exercises/` family);
  [`docs/SRS_ROADMAP_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SRS_ROADMAP_2026.md)
  gains the content-deck row. Fable 5 (`claude-fable-5`).

## [1.27.0] - 2026-07-18

### Added
- **H1147: ESP transactional-email transport + `mail:preflight` guard — fixes issue #504's repo-side root cause.** `.env.example` no longer ships `MAIL_HOST=mailpit` as if it were a production value — local dev keeps mailpit, with a commented production shape adjacent pointing at the new [`docs/mail-esp.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mail-esp.md) setup contract (`.env` keys per driver class, SPF+DKIM+DMARC requirement, `mailing`-queue worker requirement). New `php artisan mail:preflight` command ([`app/Console/Commands/MailPreflight.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MailPreflight.php)) rejects a dev mail-catcher host or placeholder sender outside `APP_ENV=local` (non-zero exit, names the reason), warns (non-fatal) when `QUEUE_CONNECTION` isn't `sync`, and supports an opt-in `--send=<addr>` real test send; proven by `tests/Feature/Mail/MailPreflightTest.php` (7/7 green, no network by default). Added `symfony/mailgun-mailer` + `symfony/postmark-mailer` (+ `symfony/http-client`) so the existing `mailgun`/`postmark` blocks in `config/mail.php` are actually usable, alongside the already-generic `smtp` mailer — vendor choice stays a human `@DECIDE` (R-3), no vendor hardcoded. [DEPLOY_QUEUE №37](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) has the exact deploy sequence. **Does not claim mail is delivered** — issue #504 stays open until a human picks an ESP, creates the account, and installs the prod secret. Sonnet 5 (`claude-sonnet-5`). [H1147](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1147-Sonnet_Systema-Sanscriticum_esp-transactional-mail-transport-preflight_17.07.26.md).

## [1.26.0] - 2026-07-18

### Added
- **H1144 (W1-D1): производственная спецификация getcourse-паритета — R29-эквивалент, которого требует R-1.** [docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md) + [метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.meta.md). 9 разделов: композиция всех 14 тикетов GC-* с состоянием, **сверенным с деревом** (`9b63861`) — по одному read-only агенту на тикет, каждый вердикт кроме high-confidence `NOT_BUILT` перепроверен вторым агентом с заданием **опровергнуть** его (25 агентов); **лестница приоритетов записи** (§2) — обобщение правила границы денежного ядра («слой `Deal` наблюдает денежное ядро и никогда его не авторизует») на все 14 тикетов, то самое правило, которого нет у роадмапа и которое всегда нужно сборщику; производственная глубина по GC-C1 (`Deal`+канбан, точка подключения моста — `PaymentObserver.php:63`) и GC-C2 (атрибуция по менеджерам); дата-билл, план флагов, 8 названных развилок (ни одна не разрешена — это работа человека) и последовательность «один шаг = один хэндофф».
  Три состояния расходятся с роадмапом H438: **GC-B3 частично сдан** ([PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549)), хотя роадмап числит его в «Later» — при этом привязка контейнера **не имеет ни одного потребителя** (вебхук резолвит конкретный `ZoomService`), а блока `services.bbb` нет, так что абстракция инертна; **GC-A3** понижен PARTIAL → NOT_BUILT (за «частичную сдачу» принимали объявленную самим тикетом базу переиспользования); **GC-C1** частично сдан, но в форме **отвергнутой** архитектуры.
  Главная находка — развилка F2: **два живых управляющих решения противоречат друг другу.** [Uprava DECISIONS_roadmap_forks_2026H2.md](https://github.com/gasyoun/Uprava/blob/main/docs/DECISIONS_roadmap_forks_2026H2.md) §R2 (10-07) рулит «расширять `Lead`», ROADMAP §5 (11-07 00:01) рулит «отдельная сущность `Deal`»; H451 сдал `LeadStage`+`LeadKanbanBoard` 10-07 11:06 — **между** ними, корректно исполнив действовавший тогда рулинг. §R2 никогда не был помечен как superseded. Требуется решение человека. Opus 4.8 (`claude-opus-4-8`). [H1144](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1144-Opus_Systema-Sanscriticum_getcourse-parity-production-spec-r29-equivalent_17.07.26.md).

## [1.25.0] - 2026-07-18

### Added
- **H1146 (W1-D5): Memrise course 6679375 export runner + validator (time-critical, irreversible).** Memrise is sunsetting community courses with no published shutdown date; an agent cannot obtain a Memrise login, so the deliverable shrinks the human's export step to two commands. [`scripts/memrise_export.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/memrise_export.py) (stdlib-only, credential from `MEMRISE_SESSION` env var, never argv; `--dry-run`) emits exactly the `manifest.json` + `level_NN.csv` contract already read by `php artisan srs:import-memrise` ([`ImportMemriseSrsDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportMemriseSrsDeck.php)). [`scripts/memrise_export_validate.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/memrise_export_validate.py) checks that contract with no network and no credentials — manifest parses, every declared level file exists, every CSV header contains every manifest-declared column, no empty levels — proven against [`tests/fixtures/memrise_sample/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/fixtures/memrise_sample) and against both failure modes independently (removed level file, renamed CSV header). Runner is untested against live Memrise (no agent credentials) — see [the destination README](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_6679375/README.md) for the honest boundary and the CourseDump2022 fallback. Sonnet 5 (`claude-sonnet-5`). [H1146](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1146-Sonnet_Systema-Sanscriticum_memrise-export-runner-validator-6679375_17.07.26.md).

## [1.24.0] - 2026-07-18

### Added
- **H1224: «Жизненные правила для санскритологов» — новый раздел лендинга samskrte.ru.** Новый Filament-блок конструктора `life_rules_block` (17-й в `LandingPageResource`): 45 максим из [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md) (H1215 v2) предзаполнены дефолтом Repeater-поля — куратор просто перетаскивает блок на лендинг, текст редактируется через админку. Рендер — сплошной поток без аккордеона (по образцу шумановских Lebensregeln), свёрнутый до 7 правил с кнопкой разворота (Alpine.js, стиль `faq_block`). Раздел лендинга, не отдельная страница — руление MG 18-07-2026 ([метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md)). 4/4 теста зелёные ([`tests/Feature/LifeRulesBlockTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/LifeRulesBlockTest.php)). Sonnet 5 (`claude-sonnet-5`). [H1224](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1224-Sonnet_Systema-Sanscriticum_lebensregeln-landing-section_18.07.26.md).

## [1.23.0] - 2026-07-18

### Changed
- **H1215/H1224: «Жизненные правила» — операционная модель зафиксирована в метадоке: квартальный цикл ревизий, три недостающих источника v3, руление публикации.** По follow-up-рулениям MG 18-07-2026 (после мерджа v2): (1) документ живой, ревизия каждые ~3 месяца по мере роста корпуса — нынешние ~40 курсов `Uprava/stenogrammy` лишь малая часть всех стенограмм школы; (2) корпус v3: стенограммы выступлений 2019–2022 + интервью с санскритологами **«Ипостаси санскрита»** и **«Санскрит в Венском университете»** (в v2 не задействованы, файлов на диске нет — передает MG); (3) публикация РУЛЕНА: **раздел лендинга** samskrte.ru, не отдельная страница — внедрение вынесено в [H1224](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1224-Sonnet_Systema-Sanscriticum_lebensregeln-landing-section_18.07.26.md) (Sonnet 5, `claude-sonnet-5`), затем DEPLOY_QUEUE. Обновлен [метадок](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md) (назначение, решения, бэклог, ограничения, история). Fable 5 (`claude-fable-5`).

## [1.22.0] - 2026-07-18

### Changed
- **H1215 (v2): «Жизненные правила для санскритологов» — ревизия по стенограммам, 40 → 45 максим.** Первый прогон живого материала школы через манифест: 17 экстракторов Sonnet 5 (`claude-sonnet-5`) прошли ~60 MB стенограмм (`Uprava/stenogrammy` + `lecture-ui/transcription`) — 28 вводных Гасунса, все 16 занятий Парибка «Йога-сутры» 2025 (приоритет MG), Куликов, Клебанов, Бюлероведение, синтаксис, ликбезы, потоки, каллиграфия, чтение (Хитопадеша/Наль/Гита/Упанишады), воспевание, детский интенсив; синтез — Fable 5 (`claude-fable-5`). Все 40 правил v1 сохранены, ~12 существенно переписаны по материалу (вход через наслушивание; «библиотека наизусть» требует переучета; своя рукописная таблица против чужой печатной; лестница словарей с Кнауэром; подстрочник-«протез» Парибка; «из санскрита вышла наука о языке, не языки»; Бетлингк по рукописям до критических изданий; сон как примета погружения), +5 новых из стенограмм (разбор слова с конца, усидчивость 70→4, возвращение после перерыва, «ворох книг», проза прежде стихов). Факт-чек пп. 26/32/33 закрыт («восьмиклассники» → «школьники» по [Arzamas](https://arzamas.academy/mag/142-zaliznyak)/[МГУ](https://msu.ru/press/smiaboutmsu/onlayn-traditsionnaya-lektsiya-akademika-andreya-zaliznyaka-o-berestyanykh-gramotakh.html)). Метадок: провенанс v2, бэклог (следующее — стенограммы выступлений 2019–2022 у MG, затем @DECIDE публикация на samskrte.ru). Текст: [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md); [H1215](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1215-Fable_Systema-Sanscriticum_lebensregeln-sanskritologov_18.07.26.md).

## [1.21.0] - 2026-07-18

### Added
- **H1215 (v1): «Жизненные правила для санскритологов» — 40 максим по образцу Шумана, голосом Зализняка.** Манифест школы samskrte.ru: жанровая рамка — «Musikalische Haus- und Lebensregeln» Шумана (1848), голос — RWS-регистры [zalizniak-method](https://github.com/gasyoun/RuWritingStyles/blob/main/styles/passports/zalizniak-method.yml) + [zalizniak-shkolnikov-1](https://github.com/gasyoun/RuWritingStyles/blob/main/styles/passports/zalizniak-shkolnikov-1.yml); оси: ухо vs. глаз (устная Индия против табличной Европы — опирается на диагноз «NO AUDIO» из [DIGITAL_SANSKRIT_PEDAGOGY_FIELD_2026.md](https://github.com/gasyoun/SanskritGrammar/blob/main/DIGITAL_SANSKRIT_PEDAGOGY_FIELD_2026.md) §3.7) · ежедневное ремесло · инструменты · метод · этос. Спецификация утверждена в интервью MG (3 раунда, 11 вопросов) — зафиксирована в [метадоке](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.meta.md). Текст: [docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ZHIZNENNYE_PRAVILA_SANSKRITOLOGOV_2026.md). Ревизия по стенограммам (выступления MG, записи курсов, интервью санскритологов) = [H1215](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1215-Fable_Systema-Sanscriticum_lebensregeln-sanskritologov_18.07.26.md). [PR #564](https://github.com/gasyoun/Systema-Sanscriticum/pull/564). Fable 5 (`claude-fable-5`).

## [1.20.0] - 2026-07-18

### Added
- **H1197 (Jivo-паритет S2/5, Pillar 2): проактивный монитор посетителей + оператор пишет первым.** Второй уникальный столп Jivo: куратор видит **живой список посетителей на сайте сейчас** (город из S1, текущая страница, время на сайте) — включая тех, кто ещё ничего не написал, — и может **написать первым**; сообщение всплывает в чат-виджете посетителя. Новая эфемерная таблица ([`create_support_visitor_presences_table`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_130000_create_support_visitor_presences_table.php)); presence-beacon [`PublicPresenceController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicPresenceController.php) `POST /support/presence` апсертит строку по `guest_token` (реюз H536), ответ несёт `conversation_id` — так проактив куратора долетает до молчащего посетителя; гео резолвится тем же [`VisitorGeoResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/VisitorGeoResolver.php) (S1) через [`ResolveVisitorPresenceGeoJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ResolveVisitorPresenceGeoJob.php); [`PruneStaleVisitorPresencesJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/PruneStaleVisitorPresencesJob.php) выметает устаревшие (окна — [`config/support_presence.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_presence.php)). Операторская страница [`VisitorsOnline`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/VisitorsOnline.php) «Посетители онлайн» (гейт: флаг + не-преподаватель, как `Helpdesk`): живой список (`wire:poll`) + кнопка «Написать» — тред открывается/переоткрывается (реюз `openForGuest`/`openFor`), curator-сообщение бродкастится `ChatMessageSent`; виджет [`support-chat-widget.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/support-chat-widget.blade.php) шлёт beacon с первого захода и раскрывается на проактив. **Осознанное отступление:** источник правды — beacon → таблица (heartbeat) + `wire:poll`, а не Reverb presence-канал (дешевле по WS, полностью тестируется без Reverb; см. [ROADMAP §3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md)). Боты НЕ пишут людям сами (принцип MG) — приглашение шлёт только человек. Всё за флагом `support_visitor_presence` (**OFF** по умолчанию); **@DECIDE MG — 152-ФЗ sign-off** на отслеживание анонимного посетителя (гейт прод-включения, не билда). 20 новых тестов ([`SupportVisitorPresenceTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorPresenceTest.php) 9 · [`VisitorsOnlinePageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/VisitorsOnlinePageTest.php) 9 · +2 render) + full suite 1582 зелёные; деплой — [DEPLOY_QUEUE №32](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). [PR #560](https://github.com/gasyoun/Systema-Sanscriticum/pull/560). Opus 4.8 (`claude-opus-4-8`).

## [1.19.0] - 2026-07-17

### Added
- **H1196 (Jivo-паритет S1/5, Pillar 1): гео/город посетителя веб-чата в панели куратора.** Куратор в [Helpdesk](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php) теперь видит «📍 Город, Страна» и страницу входа гостя — тот самый визитор-слой, ради которого держат Jivo на samskrtam.ru. Аддитивная миграция ([`add_visitor_context_to_support_conversations`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_120000_add_visitor_context_to_support_conversations.php)) добавляет `visitor_ip/city/region/country/geo_resolved_at/entry_url/referrer` на тред; [`PublicChatController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicChatController.php) фиксирует IP+страницу+referrer при первом сообщении (идемпотентно, без внешних вызовов), а `ResolveVisitorGeoJob` → `VisitorGeoResolver` резолвят город асинхронно по драйверу из [`config/support_geo.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_geo.php) (`null`-дефолт / `cloudflare` / `ipapi`). Виджет шлёт `page`. Всё за флагом `support_visitor_geo` (**OFF** по умолчанию); провайдер города — @DECIDE MG (см. [ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) §2). 11 тестов [`SupportVisitorGeoTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorGeoTest.php) + 23 регрессионных чат-теста зелёные; деплой — [DEPLOY_QUEUE №31](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md). Новый [`docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md) ставит задачи по всем 6 требованиям паритета (S1 сделан; S2–S5 = H1197–H1200). Opus 4.8 (`claude-opus-4-8`).

## [1.18.1] - 2026-07-17
### Fixed
- **H1145: `config/srs.php` default restored to `false` (R-6 baseline protection).** The default had been flipped to `true` by H447 (PR #442, commit `6267d70`) for an August-2026 pilot rationale superseded by R-5/R-6 — three other places (the same file's docblock, `routes/web.php` ~L260, `DEPLOY_QUEUE.md` #24) still asserted OFF-by-default, so an unpatched deploy would have put an SRS nav entry in front of every student and corrupted the R20 baseline. `tests/Feature/Srs/SrsFlagDefaultTest.php` pins `config('srs.enabled') === false` and `GET /dvaram/srs` → 404 with no `SRS_ENABLED` in env; full SRS suite (30 tests) and full `php artisan test` (1549 tests, 4478 assertions) green. Protects the R20 baseline — does not start it (that clock begins only when a human deploys `DEPLOY_QUEUE.md` #25). [PR #553](https://github.com/gasyoun/Systema-Sanscriticum/pull/553).
### Added
- **W1-D4: пять Mailable марафона из рулевого пакета H1067 (H1148).** `MarathonWelcomeMail`/`Day1`/`Day2`/`Day3`/`RecordingMail` + шаблоны `resources/views/emails/marathon/` — текст перенесен ДОСЛОВНО из [marathon-email-sequence.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08/marathon-email-sequence.md) (обращение «вы», без эмодзи и срочности — анти-urgency дизайн сохранен); плейсхолдеры только рулевые ({link}/{tg_link}/{date}/{host}/{coupon}/{recording_link}); Day3 несет оба трек-варианта (3а/3б). Все на очереди `mailing`, [MarathonMailablesTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Mail/MarathonMailablesTest.php) — рендер/темы/очередь/отсутствие неразрешенных плейсхолдеров и эмодзи. **Отправка сознательно инертна**: send-сайтов вне `app/Mail/` нет — канал ждет ESP-гейта (H1147), Telegram остается основным; DEPLOY_QUEUE №27a. Fable 5 (`claude-fable-5`) по разрешению MG на Sonnet-ряд.

## [1.18.0] - 2026-07-17
### Added
- **GC-B3: шов `WebinarProvider` (страховка от ухода Zoom, руление R1 — BigBlueButton).** Интерфейс с тремя методами (createMeeting / fetchParticipants / normalizeWebhook); `ZoomService` реализует его без изменения поведения (вебхук-контроллер потребляет `normalizeWebhook` — разбор байт-в-байт прежний); скелет `BigBlueButtonService` с формой BBB API (бросает до развертывания Q4); провайдер-нейтральные алиасы `meeting_*` поверх `zoom_*` (реверсивная миграция, бэкфилл копией); биндинг шва на Zoom-драйвер. Авто-создание Zoom-встреч НЕ восстановлено — остается @DECIDE GC-B1. 7 unit-тестов шва; CI зеленый. [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549) + деплой-строка №29 ([PR #550](https://github.com/gasyoun/Systema-Sanscriticum/pull/550), общий `php artisan migrate`). H601, Fable 5 (`claude-fable-5`).

### Added
- **GC-B3: шов `WebinarProvider` (страховка от ухода Zoom, руление R1 — BigBlueButton).** Интерфейс с тремя методами (createMeeting / fetchParticipants / normalizeWebhook); `ZoomService` реализует его без изменения поведения (вебхук-контроллер потребляет `normalizeWebhook` — разбор байт-в-байт прежний); скелет `BigBlueButtonService` с формой BBB API (бросает до развертывания Q4); провайдер-нейтральные алиасы `meeting_*` поверх `zoom_*` (реверсивная миграция, бэкфилл копией); биндинг шва на Zoom-драйвер. Авто-создание Zoom-встреч НЕ восстановлено — остается @DECIDE GC-B1. 7 unit-тестов шва; CI зеленый. [PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549), H601, Fable 5 (`claude-fable-5`). Деплой: [DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) — общий `php artisan migrate`.

## [1.17.1] - 2026-07-17

### Added
- **H1067: marathon 28-08 cohort RU comms pack.** New [marketing/marathon-2026-08/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/marketing/marathon-2026-08) — two landing-copy variants (beginner-fear-focused / outcome-focused) + shared FAQ, a 5-email sequence (drafts only: prod SMTP broken, [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504)), and @samskrte channel posts with a publication-order table. Authoring-only: publish steps are queued as DEPLOY_QUEUE №25 (human-gated); the day 1–3 bot drip in `config/marathon.php` stays canonical and is not duplicated. Testimonial slots publish only with a real quote (`MARATHON_TESTIMONIAL`). Authored by Fable 5 (`claude-fable-5`), [PR #544](https://github.com/gasyoun/Systema-Sanscriticum/pull/544).

## [1.17.0] - 2026-07-16

### Added
- **H1046: CI/CD deploy pipeline (GitHub Actions → SSH → `deploy.sh`), MG-confirm gate.** New `.github/workflows/deploy.yml` — Option A of the [H478 deploy-gate decision](https://github.com/gasyoun/Uprava/blob/main/SYSTEMA_DEPLOY_GATE_FACTS_OPTIONS_2026H2.md): every push to `main` (or manual `workflow_dispatch`) queues a run gated by a GitHub Environment (`production`) approval — MG must click Approve before the runner SSHes to prod and runs the existing `sudo bash deploy.sh` (unchanged). No agent holds prod credentials; the SSH key lives only in the Environment's secrets. **Server-side setup (deploy user, narrow `sudoers`, GitHub Environment + secrets) is a separate one-time human step** — see `docs/deploy.md` §CI/CD and `DEPLOY_QUEUE.md` §D1 — until done, the workflow only accumulates harmless "Waiting" runs.

## [1.16.0] - 2026-07-16

### Added
- **H1005: RQ4 admin stats page.** New `/admin/rq4-study-dashboard` (admin/super_admin only): enrollment count + arm split, pre/post/retention-test completion counts and percentages, and how many participants are currently due a retention reminder. Built so MG can check enrollment numbers himself — **he doesn't hold SSH credentials to the production server** (only the deploy contractor does, per `docs/deploy.md`), so an artisan-command-only report would still require going through the contractor every time. 3 tests in `tests/Feature/Rq4StudyDashboardTest.php`.

### Changed
- **H987 follow-up: RQ4 consent text approved by MG 15-07-2026.** No wording change — `Rq4StudyController::CONSENT_TEXT` is exactly the draft reviewed in chat, only the "not finalised" doc-comment is removed. Protocol §6.4 is now the last of the 4 `@DECIDE` items ruled — the RQ4 study spec is fully decided; `features.rq4_study` still ships OFF by default (flipping it live is a separate, later call).

## [1.15.0] - 2026-07-15

### Added
- **H987: RQ4 study harness (on-ramp-first vs Талмуд-first learning-gain study).** New `/rq4-study` flow behind `features.rq4_study` (OFF by default): consent + intake (self-reported prior exposure), stratified 1:1 arm assignment via a minimisation rule (`Rq4Participant::assignArm`), a 3-phase diagnostic (pre_test/post_test/retention_test) reading the vendored `resources/data/rq4_item_bank.json` (SanskritGrammar's H984 item bank), and a `rq4:send-retention-reminders` command (scheduled daily) that queues one `ScheduledReminder` per participant whose 4-week retention window has arrived — reuses the existing reminder infrastructure (H187) rather than building a new notification channel. New `rq4_participants`/`rq4_responses` tables. Draft consent text included, marked not-finalised pending MG's review (protocol §6.4). 9 tests in `tests/Feature/Rq4StudyTest.php`.

## [1.14.0] - 2026-07-15

### Added
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

[Unreleased]: https://github.com/gasyoun/Systema-Sanscriticum/compare/v1.12.0...HEAD
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
[1.0.1]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.1
[1.0.0]: https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.0.0
