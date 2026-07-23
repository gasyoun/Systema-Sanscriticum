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
     | ВЫКЛ по умолчанию — deploy-рубильник; команда без --apply (dry-run отчёт)
     | работает независимо от флага. Включение — CHECKOUT_STALE_ORDER_EXPIRY=true
     | + config:cache после ревью.
     */
    'checkout_stale_order_expiry' => (bool) env('CHECKOUT_STALE_ORDER_EXPIRY', false),

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
];
