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
];
