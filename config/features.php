<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Фича-флаги
    |--------------------------------------------------------------------------
    */

    /*
     | Зачёт уже оплаченных блоков/половин в стоимость ПОЛНОГО курса (тариф full).
     | Сейчас ВЫКЛЮЧЕН: при покупке «весь курс» ранее купленные блоки НЕ вычитаются
     | из цены (показываем только обычную скидку). Включить, когда созреем —
     | true здесь или FULL_COURSE_BLOCK_CREDIT=true в .env.
     |
     | Внимание: зачёт «половина блока → целый блок» работает ВСЕГДА и этим
     | флагом не управляется (см. Tariff::upgradeCreditForUser).
     */
    'full_course_block_credit' => (bool) env('FULL_COURSE_BLOCK_CREDIT', false),

    /*
     | Единый ответ из Helpdesk с маршрутизацией в канал разговора. ВЫКЛЮЧЕН по
     | умолчанию: когда включён, ответ куратора на диалог, живущий в
     | импортированном TG-support (userbot), пишется в TelegramSupportMessage
     | (исходящее) и привязывается к треду, а не в веб-ChatMessage. Реальная
     | доставка через userbot пока НЕ подключена — запись помечается pending и
     | логируется. Веб/бот-каналы работают как прежде. Включать осознанно.
     */
    'support_unified_reply' => (bool) env('SUPPORT_UNIFIED_REPLY', false),

    /*
     | ИИ-ассист в поддержке: черновик ответа (suggested-reply) и краткое
     | резюме диалога поверх существующего SupportAiReplyEvent/ai_state.
     | ВЫКЛЮЧЕН по умолчанию — не шлёт ничего сам, только готовит черновик
     | куратору. Требует OPENROUTER_API_KEY.
     */
    'support_ai_assist' => (bool) env('SUPPORT_AI_ASSIST', false),

    /*
     | Включать ли содержимое ИМПОРТИРОВАННОГО Telegram-support (приватные ЛС) в
     | контекст, уходящий во внешний LLM (OpenRouter) при support_ai_assist.
     | ВЫКЛЮЧЕН по умолчанию: приватные TG-сообщения НЕ покидают систему — ИИ-ассист
     | работает только по веб-чату, пока это явно не разрешено. Включать осознанно
     | (согласие/приватность), см. docs/support-subsystem-map.md.
     */
    'support_ai_include_telegram' => (bool) env('SUPPORT_AI_INCLUDE_TELEGRAM', false),

    /*
     | Дефолтный дневной предел LLM-черновиков FAQ-суггестера v2 (S5, категории
     | D/E/F — цена/доступ/материалы). Ограничивает расход на OpenRouter, если в
     | админке (MarketingSetting.support_ai_daily_cap) значение не задано (NULL).
     | 0 → без предела. Считается по событиям SupportAiReplyEvent.answer_llm_drafted
     | за сутки.
     */
    'support_ai_daily_cap' => (int) env('SUPPORT_AI_DAILY_CAP', 100),

    /*
     | Авто-напоминания CRM: демоны, которые сами ПИШУТ людям по воронке
     | (напоминание менеджеру о лидах с наступившим next_contact_at — команда
     | leads:remind-followup). ВЫКЛЮЧЕН по умолчанию — чтобы отладить каденс и
     | текст прежде, чем система начнёт слать сообщения. Включать осознанно:
     | CRM_REMINDERS=true в .env. Пассивные уведомления (аудит, digest куратору
     | о просрочках) этим флагом НЕ управляются — только исходящие людям пинги.
     */
    'crm_reminders' => (bool) env('CRM_REMINDERS', false),

    /*
     | Операторский «кокпит» CRM (H221): страница «Моя работа сегодня»
     | (лиды на контакт, просроченные обещания, риск оттока, поддержка без
     | ответа), общая библиотека шаблонов (MessageTemplate), действие
     | «Отправить шаблон» в реактивации и полный механизм назначения
     | ответственного в поддержке (селектор в шапке Helpdesk, дополняет
     | «Взять диалог» из H230). ВЫКЛЮЧЕН по умолчанию: пилот для роли `manager`
     | (нетех-ассистент). Пока флаг OFF — интерфейс не меняется.
     */
    'crm_cockpit' => (bool) env('CRM_COCKPIT', false),

    /*
     | Авто-постинг ссылки на занятие в Telegram-чат группы за N минут до старта
     | (команда classes:post-group-link, P0 автоматизации «Отдела заботы»).
     | ВЫКЛЮЧЕН по умолчанию: включается флагом class_link_autopost_enabled в
     | админке (MarketingSetting). Этот env-флаг — аварийный рубильник на уровне
     | деплоя. Требует, чтобы у группы был заполнен telegram_chat_id.
     */
    'class_link_autopost' => (bool) env('CLASS_LINK_AUTOPOST', false),

    /*
     | FAQ-суггестер «Отдела заботы» (H247, тикет S3): факт-черновик ответа на
     | фактологические вопросы категорий A (Zoom/ссылка), B (записи), C (расписание)
     | из данных LMS — БЕЗ единого LLM-вызова. Бот сам НЕ отвечает студенту: готовит
     | pending-черновик, куратор в Helpdesk жмёт Принять/Изменить/Отклонить.
     | ВЫКЛ по умолчанию: это deploy-рубильник. Реальный запуск требует ещё и
     | админ-тумблера support_answer_suggester_enabled (MarketingSetting).
     */
    'support_answer_suggester' => (bool) env('SUPPORT_ANSWER_SUGGESTER', false),

    /*
     | Шаблонные черновики FAQ-суггестера ПЕРЕД LLM (H1838, тикет S9). Когда ВКЛ,
     | категория D/E/F с привязанным шаблоном (MessageTemplate.suggester_category)
     | получает черновик из шаблона с подстановкой плейсхолдеров — LLM для неё
     | не вызывается вовсе (дешевле и консистентнее); непривязанные категории
     | идут прежним LLM-путём (support_ai_assist) без изменений. ВЫКЛ по
     | умолчанию — deploy-рубильник: пока флаг OFF, суггестер ведёт себя
     | байт-в-байт как до H1838, даже если привязки уже расставлены в админке.
     | Включение — SUPPORT_TEMPLATE_DRAFTS=true + config:cache после ревью.
     */
    'support_template_drafts' => (bool) env('SUPPORT_TEMPLATE_DRAFTS', false),

    /*
     | Гео/город посетителя веб-чата в панели куратора (H1196, Jivo-паритет
     | Pillar 1). Когда ВКЛ, при первом сообщении посетителя его IP резолвится
     | асинхронно (ResolveVisitorGeoJob → VisitorGeoResolver, драйвер из
     | config/support_geo.php) в город/регион/страну и показывается в Helpdesk —
     | как «из какого города пишет» в админке Jivo. ВЫКЛ по умолчанию: entry_url/
     | referrer треда пишутся ВСЕГДА (дёшево, без внешних вызовов), но город не
     | запрашивается, пока флаг OFF и/или драйвер support_geo = null. Внешний
     | геопровайдер — сознательный прод-шаг (лицензия/приватность/152-ФЗ), @DECIDE MG.
     */
    'support_visitor_geo' => (bool) env('SUPPORT_VISITOR_GEO', false),

    /*
     | Продажа записей завершённых курсов (H266, тикет M1, #190). Когда ВКЛ, лендинг
     | завершённого курса (Course::is_completed) с активным тарифом-записью
     | (Tariff::is_recording) переключает CTA с «Записаться» на «Купить запись» —
     | см. Course::sellsRecordings(). Доступ/цена не меняются: покупка записи идёт
     | тем же key-based чекаутом (accessKey() → 'full'/'block_N') и открывает уроки
     | через тот же PaymentObserver::grantAccess(). ВЫКЛ по умолчанию — это
     | deploy-рубильник: пока флаг OFF, витрина ведёт себя как раньше.
     */
    'course_recordings_sales' => (bool) env('COURSE_RECORDINGS_SALES', false),

    /*
     | Обогащение словарных entity-страниц /slovar/{slug} корпусной Sa→Ru глоссой
     | (H344, тикет SEO-P2/9). Когда ВКЛ, на странице слова показывается блок
     | «Корпусные значения» из ВЕНДОРНОГО статического фида
     | resources/data/sa_ru_glossary.json (DCS-attested, наши собственные
     | производные данные — НЕ живая зависимость от kosha/сиблинг-репо), а JSON-LD
     | DefinedTerm.description дополняется этими значениями. Никакого LLM: только
     | данные из фида. ВЫКЛ по умолчанию — это deploy-рубильник; снятие noindex и
     | индексация — территория H210/человека, этим флагом не управляется. Пока флаг
     | OFF — /slovar ведёт себя ровно как в Wave 0 (H204).
     */
    'slovar_enrichment' => (bool) env('SLOVAR_ENRICHMENT', false),

    /*
     | Импорт демонстрационной SRS-колоды из kosha (H955, last-mile pipeline
     | Rung B1). Когда ВКЛ, `php artisan srs:import-kosha-b1-demo` читает
     | ВЕНДОРНЫЙ статический фид resources/data/kosha_srs_deck_b1_demo.json
     | (kosha-srs-deck-b1-demo, наши собственные производные данные — НЕ живая
     | зависимость от kosha) и создает одну системную колоду Saraswati SRS.
     | Тот же паттерн, что и slovar_enrichment. ВЫКЛ по умолчанию — команда
     | при выключенном флаге ничего не пишет и завершается с предупреждением.
     */
    'kosha_srs' => (bool) env('KOSHA_SRS', false),

    /*
     | Reader-as-a-service demo (H959, last-mile pipeline Hop A). Когда ВКЛ,
     | /reading/kosha-demo рендерит ВЕНДОРНЫЙ статический фид
     | resources/data/kosha_reading_pack_nala_1.json (kosha's dcs-reading-pack-nala-1,
     | наши собственные производные данные — НЕ живая зависимость от kosha) —
     | текст построчно, каждое слово раскрывается (native <details>) с леммой,
     | морфологией и глоссой. ВЫКЛ по умолчанию — маршрут отвечает 404, ровно
     | как /slovar до включения. Тот же паттерн, что и slovar_enrichment/kosha_srs.
     */
    'kosha_reader' => (bool) env('KOSHA_READER', false),

    /*
     | Sanskrit-HUB L5 Workstream-A v0 (H1463): /transliterate playground +
     | internal CascadeLemmatizer. Когда ВКЛ, /transliterate рендерит клиентский
     | IAST→деванагари+SLP1 playground на vendored sanskrit-util (CDSL).
     | ВЫКЛ по умолчанию — маршрут отвечает 404 (prod-inert, как kosha_reader).
     | Lemmatizer HTTP route нет — только внутренний сервис.
     */
    'hub_transliterate' => (bool) env('HUB_TRANSLITERATE', false),

    /*
     | RQ4 user study (H987, SanskritGrammar docs/RQ4_EVALUATION_PROTOCOL_2026.md):
     | on-ramp-first vs Талмуд-first learning-gain/retention study. Когда ВКЛ,
     | /rq4-study открывает согласие+анкету → распределение по группам
     | (стратифицированная минимизация по уровню подготовки) → диагностика
     | (ВЕНДОРНЫЙ фид resources/data/rq4_item_bank.json, kosha lemma_frequency-
     | ранжированные корни, H984) → +4 недели напоминание через существующую
     | ScheduledReminder-инфраструктуру. Текст согласия — черновик MG на ревью
     | (протокол §6.4 открыт). ВЫКЛ по умолчанию — маршрут отвечает 404, никто
     | не может записаться, пока флаг не включен человеком.
     */
    'rq4_study' => (bool) env('RQ4_STUDY', false),

    /*
     | Подписка на рассылку (H324): GitHub-стиль инлайн-бокс «Подписаться на
     | рассылку». Не-студент вводит ТОЛЬКО email → find-or-create облегчённого
     | кабинетного User (без пароля), письмо с одноразовой magic-ссылкой в личный
     | кабинет + несколько лид-магнитов на бесплатной «полке подписчика». Пишем и
     | Lead-строку (CRM/UTM-атрибуция) — лид→пользователь→оплата не рвётся.
     | Включён по умолчанию с 09-07-2026 (H388: виджет размещён на главной
     | витрине, /{main.blade.php}, тёмный вариант перед footer-CTA) — можно
     | выключить аварийно через NEWSLETTER_SUBSCRIBE_ENABLED=false в .env.
     | Пока флаг OFF, виджет не рендерится, а маршруты /subscribe и
     | /magic/{token} отдают 404. Доступ к платным урокам/тарифам этот флаг НЕ
     | трогает — полка подписчика ортогональна key-based доступу (см.
     | docs/newsletter-subscribe.md).
     */
    'newsletter_subscribe' => (bool) env('NEWSLETTER_SUBSCRIBE_ENABLED', true),

    /*
     | Консолидированный дашборд посещаемости (GetCourse-паритет GC-B2, H553):
     | rate по студенту/группе/курсу, тренд по неделям, список хронических
     | неявок, экспорт CSV — поверх уже существующих ClassAttendanceService /
     | WebinarAttendance, без новой логики подсчёта. ВЫКЛ по умолчанию — это
     | deploy-рубильник; пороги — в config/attendance.php.
     */
    'attendance_dashboard' => (bool) env('ATTENDANCE_DASHBOARD', false),

    /*
     | Дашборд наблюдаемости поддержки (W3.3, H597): здоровье userbot-сессий,
     | лаг синка, доля успешной доставки исходящих, объём обращений к LLM —
     | read-only поверх существующих агрегатов (SupportDailyRollup,
     | TelegramSupportAccount, SupportAiReplyEvent), без новой логики записи.
     | ВЫКЛ по умолчанию — deploy-рубильник по образцу attendance_dashboard.
     */
    'support_observability' => (bool) env('SUPPORT_OBSERVABILITY', false),

    /*
     | Дневные rollup'ы ВЕБ-стороны поддержки (H1837, S10): агрегация
     | `chat_messages` (веб-виджет, VK-бот, TG-student-бот) в те же
     | `support_daily_rollups`, что и TG-support, с тем же KPI
     | «unresolved-after-N-hours» и той же классификацией топиков. Закрывает
     | асимметрию, из-за которой deflection-отчёты (S2/S7) и поиск контент-дыр
     | (CAI3) не видели веб-канал вовсе.
     |
     | ВЫКЛ по умолчанию: это НОВЫЙ always-on путь записи (почасовая команда
     | support:rollup-web) — включать сознательным deploy-шагом. Пока флаг OFF,
     | команда — no-op, веб-строк не появляется, а дашборды показывают ровно те
     | же TG-числа, что и до H1837. Пороги/окна — в config/support.php (rollup).
     */
    'support_web_rollups' => (bool) env('SUPPORT_WEB_ROLLUPS', false),

    /*
     | Проактивный монитор посетителей веб-чата (H1197, Jivo-паритет Pillar 2).
     | Когда ВКЛ, виджет витрины шлёт presence-beacon (POST /support/presence),
     | сервер держит эфемерную строку support_visitor_presences (город из
     | S1-резолвера, текущая страница, время на сайте), а куратор в Filament-
     | странице «Посетители онлайн» видит живой список и может НАПИСАТЬ ПЕРВЫМ —
     | сообщение всплывает в виджете молчащего посетителя. ВЫКЛ по умолчанию: при
     | выключенном флаге beacon-эндпоинт ничего не пишет (enabled:false), страница
     | куратора скрыта, виджет ведёт себя как прежде. Это самый чувствительный по
     | приватности слой (отслеживание анонимного посетителя, 152-ФЗ) — включение
     | сознательный прод-шаг с юридическим согласием, @DECIDE MG. Тонкие окна
     | (интервал beacon, окно «онлайн», TTL) — в config/support_presence.php.
     */
    'support_visitor_presence' => (bool) env('SUPPORT_VISITOR_PRESENCE', false),

    /*
     | Захват лида в веб-виджете поддержки (H1199, Jivo-паритет S4/5, требование
     | 3 из ROADMAP_JIVO_VISITOR_PARITY §1). Когда ВКЛ, форма виджета получает
     | необязательные поля телефон/почта; при первом сообщении с заполненным
     | контактом пишется Lead-строка (реюз паттерна newsletter_subscribe H324:
     | UTM из CaptureAttribution-сессии, dedup по email) — SupportLeadCaptureService,
     | идемпотентно (thread.lead_captured_at). Вне деловых часов
     | (config/support_hours.php) виджет показывает оффлайн-копирайт «оставьте
     | почту — напишем вам», не блокируя отправку (контакты остаются
     | необязательными — см. роадмап). ВЫКЛ по умолчанию — деплой-рубильник.
     */
    'support_lead_capture' => (bool) env('SUPPORT_LEAD_CAPTURE', false),

    /*
     | Жёсткая ёмкость промокодов через timed reservations. Когда ВКЛ, живой
     | pending держит usage slot до TTL ссылки Точки (30 мин) + webhook-буфера
     | (10 мин), null-expiry легаси держится до ручной сверки, а paid-reversal
     | освобождает used_count. ВЫКЛ по умолчанию; ручное включение —
     | CHECKOUT_PROMO_RESERVATIONS=true + config:cache после миграции и ревью.
     */
    'checkout_promo_reservations' => (bool) env('CHECKOUT_PROMO_RESERVATIONS', false),

    /*
     | Разрешает единственную автоматическую запись команды
     | payments:audit-checkout-integrity --apply-safe: пересчёт promo.used_count
     | из paid non-conditional платежей. ВЫКЛ по умолчанию; отрицательные кошельки,
     | исторические депозиты и legacy pending команда не исправляет никогда.
     */
    'checkout_integrity_safe_repairs' => (bool) env('CHECKOUT_INTEGRITY_SAFE_REPAIRS', false),

    /*
     | Авторитетная проверка активности тарифа в POST /payment/create. Когда ВКЛ,
     | выключенный тариф возвращает 404 до создания гостя, Payment и банковской
     | ссылки. ВЫКЛ по умолчанию: денежный PR остаётся прод-инертным до ручного
     | включения CHECKOUT_INACTIVE_TARIFF_GUARD=true и config:cache.
     */
    'checkout_inactive_tariff_guard' => (bool) env('CHECKOUT_INACTIVE_TARIFF_GUARD', false),

    /*
     | Сериализация реферального кошелька в чекауте. Когда ВКЛ, транзакция первым
     | действием перечитывает и блокирует строку users, а расчёт/списание кредита
     | использует только это DB-значение. ВЫКЛ по умолчанию; ручное включение —
     | CHECKOUT_REFERRAL_CREDIT_LOCK=true и config:cache после ревью/деплоя.
     */
    'checkout_referral_credit_lock' => (bool) env('CHECKOUT_REFERRAL_CREDIT_LOCK', false),

    /*
     | Обратимость депозитного зачёта. Когда ВКЛ, реальный переход оплаты из
     | paid/success в failed/canceled возвращает ровно deposit_credit_applied в
     | оплаченные deposit/trial строки (LIFO, под row-lock), сохраняя маркер
     | покупки для аудита и повторной оплаты. ВЫКЛ по умолчанию; включение —
     | CHECKOUT_DEPOSIT_REVERSAL=true + config:cache после ручного ревью.
     */
    'checkout_deposit_reversal' => (bool) env('CHECKOUT_DEPOSIT_REVERSAL', false),

    /*
     | Ридер (reaper) брошенных чекаутов (H1358): payments:expire-stale-checkouts
     | переводит зависшие pending-платежи старше config('checkout.legacy_pending_days')
     | (для чекаутов с timed промо-бронью — старше PromoCode::WEBHOOK_BUFFER_MINUTES)
     | в failed, что через существующий Payment::booted() автоматически возвращает
     | списанную прану, зачтённый реферальный кредит и зачтённый депозит и
     | освобождает промо-слот. Deposit/trial/paypal/conditional строки — вне области:
     | не трогаются никогда. Гонка с банковским вебхуком закрыта построчным
     | lockForUpdate внутри транзакции (тот же паттерн, что и WebhookController).
     |
     | ВКЛ по умолчанию (MG 01-08-2026): держать reaper dark — ложная экономия
     | (stale pending держат резервы; «не трогать деньги cron'ом» = вредительство).
     | Opt-out: CHECKOUT_STALE_ORDER_EXPIRY=false + config:cache. См.
     | docs/MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.md. Dry-run без --apply всегда.
     */
    'checkout_stale_order_expiry' => (bool) env('CHECKOUT_STALE_ORDER_EXPIRY', true),

    /*
     | Промокод переживает обновление сессии в чекауте (H1396 §1). Промокод жил
     | ТОЛЬКО в сессии; анти-419 обновление CSRF-токена могло выдать НОВУЮ пустую
     | сессию, а remember-me пере-аутентифицировал пользователя — он проскакивал
     | auth()->check() с потерянным session('promo_code') и уходил в банк на ПОЛНУЮ
     | сумму, хотя кнопка показывала скидку (money-core, повтор H071 #13 через другую
     | дверь). Когда ВКЛ: промокод несётся в скрытом поле формы и пере-резолвится в
     | createPayment АВТОРИТЕТНО (клиентское значение forgeable → те же правила
     | isValid/appliesToCourse/redeemedByUser/hasCapacity). Валиден → скидка списывается
     | заново; протух между показом и сабмитом → НЕ уходим молча в банк на полную и НЕ
     | отказываем, а показываем явное подтверждение новой цены (RULED 20-07-2026 MG),
     | и заказ создаётся ровно на подтверждённую сумму. ВЫКЛ по умолчанию: с флагом OFF
     | createPayment ведёт себя байт-в-байт как раньше (только сессия) — денежный PR
     | прод-инертен до CHECKOUT_PROMO_SURVIVES_SESSION=true + config:cache после ревью.
     */
    'checkout_promo_survives_session' => (bool) env('CHECKOUT_PROMO_SURVIVES_SESSION', false),

    /*
     | Защита денежного вебхука Точки (H1359). Когда ВКЛ, WebhookController
     | отклоняет три класса опасных доставок: (a) повтор уже виденного тела
     | события (event_hash) — идемпотентный 200-no-op; (b) success для платежа,
     | который был оплачен и затем отменён/возвращён (resurrection) — не даёт
     | воскресить доступ/депозит/промо/реферала; (c) сумму из банка, расходящуюся
     | с payments.amount сверх config('checkout.webhook_amount_tolerance'). ВЫКЛ
     | по умолчанию: журнал payment_webhook_events пишется ВСЕГДА (чисто
     | аддитивно), но отказы — только при включённом флаге. Включение —
     | TOCHKA_WEBHOOK_GUARD=true + config:cache после ревью.
     */
    'tochka_webhook_guard' => (bool) env('TOCHKA_WEBHOOK_GUARD', false),

    /*
     | Telegram Track C (H164, Uprava/docs/DECISIONS_telegram_harvester.md D7-D11):
     | second bot account @zapisi_ORSbot (class-booking chat) — go-forward webhook
     | capture + media download + class-reminder scheduler. ВЫКЛ по умолчанию —
     | deploy-рубильник; включается после заполнения токена/секрета в
     | MarketingSetting (админка) и отключения privacy mode у бота (@BotFather,
     | GTD @DO). Webhook сам fail-closed по секрету независимо от флага; пока флаг
     | OFF, команда zapisi:remind-classes ничего не шлёт (early-return).
     */
    'telegram_zapisi_bot' => (bool) env('TELEGRAM_ZAPISI_BOT_ENABLED', false),

    /*
     | Возврат к входу при истёкшей сессии в чекауте (H1396 §2). Залогиненный
     | студент, чья сессия протухла между показом страницы и сабмитом, приходит в
     | POST /payment/create уже НЕ авторизованным. Форму ему показывали БЕЗ гостевых
     | полей (они рендерятся только в @guest), поэтому дефолтная guest-required
     | валидация выдавала четыре ошибки на полях, которых он не видел, а если он
     | вводил свой же email — жёсткий отказ «у вас уже есть аккаунт». Обычный 419 был
     | строго удобнее. Когда ВКЛ: такой сабмит (скрытая метка checkout_authed=1 без
     | активной сессии) уводит студента на /login с intended-возвратом к оплате того
     | же тарифа, вместо гостевой формы, которую он не видел. ВЫКЛ по умолчанию —
     | deploy-рубильник; включение CHECKOUT_SESSION_LAPSE_RELOGIN=true + config:cache.
     */
    'checkout_session_lapse_relogin' => (bool) env('CHECKOUT_SESSION_LAPSE_RELOGIN', false),

    /*
     | Подписанный URL возврата из банка (H1396 §3). TochkaPaymentService слал банку
     | неподписанные redirectUrl/failRedirectUrl, а PaymentController::success()
     | опознавал заказ по auth()->id() + latest('id') — Точка не передаёт id заказа
     | обратно. В in-app WebView (Telegram) редирект из банка может уйти в
     | SFSafariViewController/Safari — ДРУГУЮ cookie jar: реально оплативший студент
     | попадал на гостевой экран «Войдите в аккаунт» для аккаунта с авто-сгенерённым
     | паролем, которого он никогда не задавал; плюс «последний платёж юзера» показывал
     | НЕ тот заказ при двух pending. Когда ВКЛ: возврат несёт подписанный payment id
     | (URL::signedRoute), и success/fail опознают точный заказ по валидной подписи,
     | переживая потерю cookie; при OFF или без подписи — прежнее поведение по сессии.
     | secure у config/session.php фактически false → SameSite=None/Partitioned здесь
     | НЕ вариант без починки этого (см. §3 брифа). ВЫКЛ по умолчанию — deploy-рубильник;
     | включение CHECKOUT_SIGNED_RETURN_URL=true + config:cache после ревью.
     */
    'checkout_signed_return_url' => (bool) env('CHECKOUT_SIGNED_RETURN_URL', false),

    /*
     | Homegrown email-campaign engine (H1449 W1b, Anton ops-gaps plan): compose
     | a рассылка, segment recipients, per-recipient open/click tracking, и
     | «Догнать неоткрывших» (resend to non-openers). Когда ВЫКЛ (по умолчанию):
     | CampaignResource скрыт из Filament, /e/o и /e/c ничего не трекают (404),
     | CampaignSender/SendCampaignRecipient ничего не отправляют (early-return) —
     | прод-инертен byte-в-byte. Транспорт-агностичен: письма идут через тот же
     | mail-мейлер, что и транзакционные (см. docs/mail-esp.md, D6). Включение —
     | EMAIL_CAMPAIGNS=true + config:cache после реальной сегментации на staging.
     */
    'email_campaigns' => (bool) env('EMAIL_CAMPAIGNS', false),

    /*
     | In-video resume (H1450, Anton ops-gaps W2): «продолжить с HH:MM» в плеере
     | урока + прогресс-сигнал max_position_seconds. Позиция копится в
     | lesson_views ВСЕГДА через существующий POST /api/heartbeat (аддитивные
     | колонки, ничего не меняет для старых запросов без position/duration) —
     | флаг управляет только клиентским JS: пока ВЫКЛ, плеер не шлёт position/
     | duration и не показывает баннер «продолжить», ведёт себя ровно как до
     | H1450. Один адаптер на хост (YouTube/RuTube/VK/Kinescope/Vimeo, D8),
     | деградирует без ошибки, если у хоста нет API текущей позиции.
     */
    'video_resume' => (bool) env('VIDEO_RESUME', false),

    /*
     | Kinescope pilot on ONE flagship course (H1451, Anton ops-gaps W3).
     | Когда ВКЛ + video.kinescope_pilot_course_id совпадает с course.id,
     | плеер урока рендерит Kinescope iframe из lesson.video_url (если URL
     | kinescope.io) и подключает Player SDK (reuse W2 video-resume adapter).
     | Все остальные курсы/хосты — без изменений. ВЫКЛ по умолчанию.
     */
    'kinescope_pilot' => (bool) env('KINESCOPE_PILOT', false),

    /*
     | Клип-маркетинг (H1452, Wave 4 Anton ops-gaps): n8n/ffmpeg нарезает
     | опубликованную лекцию по уже существующим AI-таймкодам (без пересчёта
     | границ) на самостоятельные фрагменты и грузит их в VK Video/Clips;
     | callback пишет LectureClip-строки, куратор в Filament отмечает ~3
     | бесплатных на лекцию. Когда ВЫКЛ: исходящий "нарежь лекцию" вебхук не
     | диспатчится, входящий callback-роут отдаёт 404, LectureClipResource
     | скрыт — прод-инертно. Включение требует VK-приложения с Video/Wall
     | скоупом (человеческий шаг, см. DEPLOY_QUEUE.md) —
     | CLIP_MARKETING_ENABLED=true + config:cache после ревью.
     */
    'clip_marketing' => (bool) env('CLIP_MARKETING_ENABLED', false),

    /*
     | Content engine (n8n lecture content, Wave 1 of 5): мастер-флаг генерации
     | ContentCandidate из лекций — ranked-span dispatch в DispatchLectureClip-
     | ExtractionJob и sync LectureClip → ContentCandidate. Когда ВЫКЛ:
     | LessonObserver не диспатчит нарезку, ContentCandidateSync всё ещё
     | зеркалит существующие LectureClip-строки (дешёвая идемпотентная запись,
     | не публикация), но Filament-ресурс ContentCandidate скрыт. Wave 2–5
     | (social/faq/article/study generators) читают этот же флаг.
     */
    'content_from_lectures' => (bool) env('CONTENT_FROM_LECTURES', false),

    /*
     | VK/ORS content calendar (H1564, plan PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR):
     | import vk-ors derived CSVs, seed monthly slots, Filament «Календарь
     | контента». Когда ВЫКЛ: команды seed/import safe no-op или работают
     | без UI; ресурс календаря скрыт. Авто-публикация в ВК — отдельный
     | флаг content_calendar_autopilot (Wave 5, H1568), не этот.
     */
    'content_calendar' => (bool) env('CONTENT_CALENDAR_ENABLED', false),

    /*
     | Auto-publish due calendar slots via n8n (H1568 Wave 5). Default OFF.
     | W1 does not read this flag.
     */
    'content_calendar_autopilot' => (bool) env('CONTENT_CALENDAR_AUTOPILOT', false),

    /*
     | Гибридный кабинет R29 (H1481, Phase 1 chassis): job-named nav
     | (Сегодня / Календарь / Записи / Прогресс / Оплата и доступ / Помощь),
     | workspace-табы с hash-адресацией, лента «Сегодня» с homework-rework,
     | server-side recovery-state resolver (R29.2). ВЫКЛ по умолчанию —
     | deploy-рубильник R20: прод не меняется, пока CABINET_HYBRID=true
     | + config:cache после ревью. Пока OFF — старый layouts.student и
     | student.dashboard без hybrid-маршрутов (library/progress/access → 404).
     */
    'cabinet_hybrid' => (bool) env('CABINET_HYBRID', false),

    /*
     | Content engine Wave 2 (H1548): пилот авто-публикации социальных постов
     | (ВК-стена с прикреплённым клипом + зеркало в ТГ-канал), n8n-вебхук —
     | тот же паттерн, что monthly_schedule_webhook/clip_extract. Когда ВЫКЛ:
     | PublishSocialPostJob ранний возврат, ни одного реального поста. ВКЛ
     | требует живого n8n-пайплайна + VK wall/video скоупов (см. DEPLOY_QUEUE.md).
     */
    'content_auto_publish_pilot' => (bool) env('CONTENT_AUTO_PUBLISH_PILOT', false),

    /*
     | Content engine Wave 2 (H1548): разовая рассылка `email_blast`-кандидатов
     | подписчикам newsletter_subscribe (H324). Августовская активация —
     | зависит от живого SMTP (см. H1449/#504). Когда ВЫКЛ: SendContentOneShotMailJob
     | ранний возврат, писем не уходит.
     */
    'content_email_oneshot' => (bool) env('CONTENT_EMAIL_ONESHOT', false),

    /*
     | GetCourse-parity GC-A1 segment engine (H1637): saved named data-driven
     | filters (group/behavior/attendance/lead-status/debtor/UTM) over
     | students, foundation for future GC-A2/A3/A4. Rank 6 of the parity
     | ladder (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2) — read-only
     | over ranks 1-5, never writes payments/access/lead/stage. ВЫКЛ по
     | умолчанию — deploy-рубильник: SegmentResource is hidden and
     | Segment::matchingStudentsQuery() returns an unconditionally-empty
     | query while OFF. Включение — MARKETING_SEGMENTS=true + config:cache.
     */
    'marketing_segments' => (bool) env('MARKETING_SEGMENTS', false),

    /*
     | GetCourse-паритет GC-C1 (H1641): СДЕЛКИ — отдельная сущность Deal с
     | настраиваемыми стадиями (deal_stages) и канбан-доской DealKanbanBoard,
     | плюс мост «состоявшаяся оплата → закрытие сделки»
     | (PaymentDealBridgeObserver). Ранг 4 лестницы полномочий
     | (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2): наблюдает денежное
     | ядро и НИКОГДА его не авторизует — ни доступа, ни цены, ни отмены
     | платежа. Lead/LeadStage/LeadKanbanBoard (H451) не трогаются: сделка ≠
     | лид, у одного человека может быть несколько сделок. ВЫКЛ по умолчанию —
     | deploy-рубильник: пока флаг OFF, доска не регистрируется и недоступна,
     | а мост от оплаты делает ранний возврат (ни одной строки в deals).
     | Включение — CRM_PIPELINE_BOARD=true + config:cache.
     */
    'crm_pipeline_board' => (bool) env('CRM_PIPELINE_BOARD', false),

    /*
     | Атрибуция возвратов по ссылке «Возврат за платёж №…» в зачёте докупки
     | (H1405 C2). Ветка целого блока в Tariff::upgradeRefundsForUser видит
     | только Расход-строки с покрывающим диапазоном блоков, а админ-форма
     | обнуляет start/end при выборе «Расход» — реальный возврат за половину
     | блока не уменьшал зачёт при докупке целого блока (школа теряла сумму
     | возврата второй раз). Когда ВКЛ: возврат, привязанный через
     | refund_of_payment_id к оплаченной половине покупаемого блока, тоже
     | уменьшает зачёт. ВЫКЛ по умолчанию — поведение байт-в-байт прежнее;
     | включение UPGRADE_CREDIT_REFUND_LINK=true + config:clear после ревью
     | финдира.
     */
    'upgrade_credit_refund_link' => (bool) env('UPGRADE_CREDIT_REFUND_LINK', false),

    /*
     | GetCourse-паритет GC-B1 rescope (H1642): авто-создание ОДНОЙ recurring
     | Zoom-встречи НА КУРС (никогда не на `Schedule`) — единый-ручной-поток
     | модель (`eda8059`, 27-06-2026) стоит, per-schedule авто-создание НЕ
     | возвращается (см. `docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md` §7 F1,
     | MG-руление 19-07-2026 → опция (b)). Триггер — первая генерация потока
     | занятий курса (`ScheduleGenerator::generate()`) для курса без
     | `zoom_meeting_id`; идемпотентно — повторная генерация встречу не дублирует.
     | ВЫКЛ по умолчанию — deploy-рубильник: пока флаг OFF,
     | `ScheduleGenerator` ведёт себя байт-в-байт как раньше (ссылка занятия
     | берётся из уже сохранённого `Course::zoom_link`, если он есть, иначе
     | пусто — как до этого хэндоффа), `ZoomService::createMeeting()` вызывается
     | только при ВКЛ. Включение — ZOOM_AUTO_CREATE=true + config:cache после
     | ревью (креды OAuth уже настроены, см. `services.zoom`).
     */
    'zoom_auto_create' => (bool) env('ZOOM_AUTO_CREATE', false),

    /*
     | GetCourse-паритет GC-C3 (H1836): ЗАДАЧИ МЕНЕДЖЕРУ по сделке —
     | FollowUpTask (тип/срок/факт закрытия) вместо пары полей
     | `leads.next_contact_at` + `leads.assigned_to`, плюс пятый бакет
     | «задачи на сегодня» в кокпите «Моя работа сегодня» (WorkQueueReport,
     | H221). Висит на Deal (GC-C1, H1641), не на Lead.
     |
     | ОТДЕЛЬНЫЙ флаг, а НЕ crm_reminders — сознательно (спека
     | docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §7 F6): crm_reminders
     | гейтит команду leads:remind-followup, которая САМА ПИШЕТ людям;
     | переиспользование молча расширило бы смысл одного рубильника на две
     | разные по риску поверхности. Поведение leads:remind-followup этим
     | флагом не меняется ни в одну сторону.
     |
     | ВЫКЛ по умолчанию — deploy-рубильник: пока флаг OFF,
     | WorkQueueReport::followUpTasksDue() возвращает пустую коллекцию, а
     | пятая карточка кокпита не рендерится (таблица создаётся миграцией
     | всегда — она аддитивна и ни на что не влияет).
     | Включение — CRM_FOLLOW_UP_TASKS=true + config:cache.
     */
    'crm_follow_up_tasks' => (bool) env('CRM_FOLLOW_UP_TASKS', false),

    /*
     | Cabinet skill-drill strip (H1680, Wave 2 online games): a /dvaram/skill-drills
     | page linking to short /lila drills — DISTINCT from the FSRS review loop
     | (srs.enabled, /dvaram/koloda) and orthogonal to it. Когда ВЫКЛ (по умолчанию):
     | route not registered (404) and the nav entry is hidden. ВЫКЛ по умолчанию —
     | Architecture §5: "any cabinet/SRS surfacing remains OFF by default".
     | Включение — GAMES_SKILL_DRILLS=true + config:cache после ревью.
     */
    'games_skill_drills' => (bool) env('GAMES_SKILL_DRILLS', false),

    /*
     | Tochka multi-mode recurring billing (H2026 Phase 0 scaffold).
     | Master OFF by default: no subscription create/charge/webhook side-effects.
     | Modes allow-list (comma-separated in env): per_course, consolidated, club,
     | installment, multi_month. Phase 1+ only enables paths after human prod enable.
     | Docs: docs/ARCHITECTURE_TOCHKA_RECURRING_BILLING_MODES_2026.md
     | Enable: TOCHKA_RECURRING_ENABLED=true + config:cache (money PR, human only).
     */
    'tochka_recurring' => (bool) env('TOCHKA_RECURRING_ENABLED', false),

    /*
     | Comma-separated mode allow-list when tochka_recurring is ON.
     | Default: per_course,club,installment (A, D, E first-wave; B/C opt-in later).
     */
    'tochka_recurring_modes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TOCHKA_RECURRING_MODES', 'per_course,club,installment'))
    ))),

    /*
     | PayPal Subscriptions API (H2027) — auto-bill for diaspora, separate from
     | manual claim (PAYPAL_CLAIM_ENABLED). Default OFF: route returns 404 and
     | no Product/Plan/Subscription calls. Prod enable is human @DO only after
     | Business account can sell Subscriptions. See
     | docs/ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md
     */
    'paypal_subscriptions' => (bool) env('PAYPAL_SUBSCRIPTIONS_ENABLED', false),
];
