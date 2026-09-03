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
     | CRM Wave 1 (H2483): Filament «Карточка 360» — единая лента Lead/Deal/
     | FollowUpTask/Payment/support/ActivityEvent без зеркала фактов.
     | ВЫКЛ по умолчанию. Деньги и доступ только читаются. Включение —
     | CRM_CUSTOMER_360=true + config:cache после ревью.
     */
    'crm_customer_360' => (bool) env('CRM_CUSTOMER_360', false),

    /*
     | CRM Wave 2 (H2484): lifecycle-правила готовят черновики Campaign /
     | FollowUpTask по незавершённому чекауту, отсутствию первого действия
     | в кабинете и следующему курсу. ВЫКЛ по умолчанию. Dry-run работает
     | и при OFF; --apply и Filament-страница — только при ON. Отправка
     | писем по-прежнему только через человеческое «Отправить» и флаг
     | email_campaigns. Включение — CRM_LIFECYCLE_AUTOMATION=true.
     */
    'crm_lifecycle_automation' => (bool) env('CRM_LIFECYCLE_AUTOMATION', false),

    /*
     | CRM Wave 3 (H2485): pipeline-weighted sales forecast + manager dashboard.
     | ВЫКЛ по умолчанию. Сервис и `crm:forecast-report` читают Deal/Payment
     | и ничего не пишут; Filament `/admin/sales-forecast` виден только при ON.
     | Вероятности/окна — config/crm_forecast.php, не в Blade.
     | Включение — CRM_SALES_FORECAST=true + config:clear.
     */
    'crm_sales_forecast' => (bool) env('CRM_SALES_FORECAST', false),

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
     | FAQ RAG for Helpdesk suggester (H2448 Track B): BM25 top-3 chunks from
     | resources/knowledge/faq.md with citations before template/LLM.
     | ВЫКЛ по умолчанию. Requires support_answer_suggester (deploy + admin
     | toggle) to produce suggestions at all; this flag only adds retrieval.
     | Money/policy (category D): refuse draft if best BM25 score < threshold
     | (config support.faq_rag.min_score). Never auto-sends to student.
     | Enable: FAQ_RAG_SUGGESTER=true + config:cache after review. Flag OFF on prod.
     */
    'faq_rag_suggester' => (bool) env('FAQ_RAG_SUGGESTER', false),

    /*
     | H4001 (Wave 3 leverage-плана): dense-нога ретривала — reciprocal rank
     | fusion BM25 ∪ knowledge_chunks поверх bge-m3 с GPU-узла за туннелем.
     | ВЫКЛ по умолчанию: при OFF HybridRetriever байт-в-байт отдаёт ranking
     | BM25 (dense-нога даже не зовётся — туннель не в request-path), и ни
     | один потребитель lane'а не переключён. Включение — решение человека
     | после чтения eval-таблицы H4001 (гибрид ≥ BM25 на обоих наборах).
     | Enable: FAQ_HYBRID_RETRIEVAL=true + config:cache.
     */
    'faq_hybrid_retrieval' => (bool) env('FAQ_HYBRID_RETRIEVAL', false),

    /*
     | H3766 B4 (стадия 1 issue #1633, рулинг R5): вместо того чтобы класть в
     | системный промпт ИИ-куратора ВЕСЬ faq.md (~46 000 символов на каждый
     | вопрос), BotKnowledgeBase кладёт top-K разделов, найденных тем же
     | BM25-ретривером H2448, что уже работает в подсказках куратору. Каталог
     | курсов (CourseCatalogProvider::markdown) по-прежнему идёт ЦЕЛИКОМ —
     | цены берутся только оттуда, и ретривал не имеет права их отфильтровать.
     |
     | Когда вопрос неизвестен (systemPrompt() без $userQuestion) или ретривер
     | ничего не нашёл, поведение байт-в-байт прежнее: весь FAQ.
     |
     | ВЫКЛ по умолчанию. Включение — осознанный шаг: BOT_FAQ_RETRIEVAL=true +
     | config:cache после зелёного смоука на реальных вопросах кабинета
     | (docs/RESULTS_BOT_FAQ_RETRIEVAL_SMOKE_*.md).
     */
    'bot_faq_retrieval' => (bool) env('BOT_FAQ_RETRIEVAL', false),

    /*
     | H3768 (рулинг MG 31-08-2026 «только F»): ЖИВОЙ автоответ студенту из FAQ.
     |
     | До сих пор BM25-ответ доходил только до куратора (подсказка) или до
     | теневого журнала. Здесь он впервые уходит студенту сам — в категориях
     | support.faq_rag.live_categories (сейчас одна: F, материалы/ДЗ/сертификаты)
     | и только выше покатегорийного порога, выведенного H3766 B5.
     |
     | D (деньги) и E (доступы) исключены В КОДЕ, а не конфигом — рулинг R3.
     |
     | Оговорка, которую нельзя терять: порог F (15.7) выведен на 100-вопросном
     | наборе, где выше него оказались 7 вопросов. Это провизорная калибровка;
     | первая же неделя живого трафика может её опрокинуть, и тогда флаг
     | выключается — BOT/SUPPORT_DM_AUTO_REPLY_LIVE_FAQ=false + config:cache.
     |
     | ВЫКЛ по умолчанию. Ответ всегда несёт ссылку на раздел FAQ (faqDraft).
     */
    'support_dm_auto_reply_live_faq' => (bool) env('SUPPORT_DM_AUTO_REPLY_LIVE_FAQ', false),

    /*
     | H3233 B: автоответ простых A/B/C в личке саппорт-аккаунта + подсказка
     | кураторам на сложные. ВЫКЛ по умолчанию = откат на A (кабинетный бот,
     | Helpdesk-черновики, люди печатают в Telegram). Деньги (D) не автоотвечает.
     | Доставка — pending + telegram-support:sync, не Horizon.
     | Включение: SUPPORT_DM_AUTO_REPLY=true + config:cache.
     */
    'support_dm_auto_reply' => (bool) env('SUPPORT_DM_AUTO_REPLY', false),

    /*
     | H3380 (рулинг MG 23-08-2026): шаблонные автоответы D/E/F по привязке S9.
     | Шлётся ТОЛЬКО текст выверенного канреплая (H2339), не LLM, и только на
     | аккаунтах с telegram_support_accounts.auto_reply_enabled=true — проба
     | 2 недели на rusamskrtam. Требует support_dm_auto_reply. Деньги: D2/D3
     | разрешены владельцем на срок испытания; откат — false + config:cache,
     | либо auto_reply_enabled=false на аккаунте.
     */
    'support_auto_reply_templates' => (bool) env('SUPPORT_AUTO_REPLY_TEMPLATES', false),

    /*
     | H3380: ack «приняли, ответим» когда автоответить нечем. Не чаще одного
     | раза в services.telegram_support.auto_ack_cooldown_hours на чат; без
     | linked-пользователя не шлём (некуда ставить очередь). Откат: false.
     */
    'support_auto_ack' => (bool) env('SUPPORT_AUTO_ACK', false),

    /*
     | H3765 A3 (рулинг R9 плана PLAN_SYSTEMA_TELEGRAM_RAG_SUPPORT_2026H2):
     | ТЕНЕВОЙ режим расширенного автоответа. Пока флаг ВКЛ, на каждой
     | подсказке куратору, которую бот МОГ БЫ отправить студенту сам
     | (BM25-скор выше порога, безопасная категория, привязанный студент),
     | пишется событие dm_shadow_would_send с черновиком и скором — и НИЧЕГО
     | больше. Ни одного лишнего исходящего: инвариант §5 контракта плана —
     | поведение, видимое студенту, байт в байт прежнее.
     |
     | Смысл: через неделю support:shadow-report сверит эти «отправил бы» с
     | тем, что куратор ответил на самом деле, и живое включение автоотправки
     | человек решит по числам, а не по надежде. Сам живой флип — решение
     | человека (R9/R10), эта тень его не заменяет.
     */
    'support_dm_auto_reply_shadow' => (bool) env('SUPPORT_DM_AUTO_REPLY_SHADOW', false),

    /*
     | H3542: мост «незалинкованный партнёр → linked user». Когда автоответ
     | заблокирован ТОЛЬКО отсутствием связи (аудитория канала без кабинета),
     | бот один раз за cooldown-окно отправляет приглашение с capability-
     | ссылкой /support/link/{token}: email → find-or-create беспарольного
     | User (паттерн H324) → linked_user_id на контакте и private-чате. После
     | этого ack/шаблоны/факты отвечают сами. Пер-аккаунтный гейт тот же:
     | telegram_support_accounts.auto_reply_enabled. Денominator бэкфилла —
     | support:send-link-invites --dry. ОТКЛ по умолчанию; включать только на
     | пробном аккаунте (rusamskrtam): SUPPORT_DM_LINK_INVITE=true + config:cache.
     */
    'support_dm_link_invite' => (bool) env('SUPPORT_DM_LINK_INVITE', false),

    /*
     | H3242: утренняя сводка вчерашней поддержки в Telegram на ADMIN_TELEGRAM_ID
     | (gasyoun). ВКЛ по умолчанию — админский дайджест по явной просьбе, не
     | студенческий автоответ. Выкл: SUPPORT_DAILY_DIGEST=false + config:cache.
     | Команда support:daily-digest, слот 08:10 Europe/Moscow.
     */
    'support_daily_digest' => (bool) env('SUPPORT_DAILY_DIGEST', true),

    /*
     | H3392: недельный разбор пробы автоответов H3380 («разбираем что пошло
     | не так») на ADMIN_TELEGRAM_ID: счётчики по event_type × kind × категории,
     | подсказки без ответа, объём stale/backlog-пропусков, медиана латентности
     | человеческого ответа после ack/шаблона. ВЫКЛ по умолчанию — новый
     | исходящий контур админам: включение сознательный шаг после первого
     | просмотра (php artisan support:auto-reply-weekly --dry работает и при
     | OFF). Слот расписания — воскресенье 18:00 Europe/Moscow.
     */
    'support_auto_reply_weekly_report' => (bool) env('SUPPORT_AUTO_REPLY_WEEKLY_REPORT', false),

    /*
     | H3462 (рулинг MG 24-08-2026): входящий email как канал поддержки.
     | Ящик zabota@samskrte.ru пересылает почту в проводник (n8n на .91, без
     | нового платного вендора), проводник POSTит payload на
     | /api/webhooks/inbound-email/{secret}; письмо пишется в chat_messages
     | (source='email') с дедупом по Message-ID; нераспознанный отправитель —
     | в видимую очередь (/admin/inbound-emails), не в никуда. ВЫКЛ по
     | умолчанию: маршрут 404. Включение: SUPPORT_INBOUND_EMAIL=true +
     | INBOUND_EMAIL_WEBHOOK_SECRET + config:cache (шаги — DEPLOY_QUEUE H3462;
     | сначала завести ящик и пересылку).
     */
    'support_inbound_email' => (bool) env('SUPPORT_INBOUND_EMAIL', false),

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
     | H2763: публичный /sanskritorium — тот же playground, без флага (indexable).
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
     | Предварительные предупреждения студента о занятии (H2317): «не приду /
     | не уверен / опоздаю / уйду раньше» из личного кабинета (календарь) и
     | self-service бота TG/VK (в т.ч. короткий текст в чате группы). ВЫКЛ по
     | умолчанию — deploy-рубильник. Миграция schedule_attendance_notices
     | аддитивная; пока флаг OFF UI и перехват фраз no-op.
     */
    'attendance_notices' => (bool) env('ATTENDANCE_NOTICES', false),

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
     | Обязательная тема при закрытии диалога в Helpdesk (H2381, Jivo Phase 4).
     | Когда ВКЛ, SupportConversationManager::closeWithTopic / Helpdesk resolve
     | требуют category из SupportTopicRule (+ other/uncategorized). История
     | пишется в support_conversation_topics (append-only). ВЫКЛ по умолчанию —
     | close без темы остаётся допустимым (обратная совместимость).
     */
    'support_required_close_topic' => (bool) env('SUPPORT_REQUIRED_CLOSE_TOPIC', false),

    /*
     | Follow-up задачи из Helpdesk-диалога (H2381): reuse FollowUpTask с
     | support_conversation_id + note, без новой таблицы и без смешения с CRM
     | (crm_follow_up_tasks) или академическими ScheduledReminder.
     | ВЫКЛ по умолчанию — deploy-рубильник.
     */
    'support_follow_up_tasks' => (bool) env('SUPPORT_FOLLOW_UP_TASKS', false),

    /*
     | Жёсткая ёмкость промокодов через timed reservations. Когда ВКЛ, живой
     | pending держит usage slot до TTL ссылки Точки (30 мин) + webhook-буфера
     | (10 мин), null-expiry легаси держится до ручной сверки, а paid-reversal
     | освобождает used_count. ВКЛ по умолчанию (MG 01-08-2026 capacity + reaper):
     | opt-out CHECKOUT_PROMO_RESERVATIONS=false. Требует payment_link_expires_at
     | (миграция 2026_07_18) + reaper для снятия брошенных броней. См.
     | MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.
     */
    'checkout_promo_reservations' => (bool) env('CHECKOUT_PROMO_RESERVATIONS', true),

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
     | ссылки. ВКЛ по умолчанию (MG 01-08-2026 false-economy): opt-out
     | CHECKOUT_INACTIVE_TARIFF_GUARD=false. См. MONEY_FALSE_ECONOMY_DARK_FLAGS_2026.
     */
    'checkout_inactive_tariff_guard' => (bool) env('CHECKOUT_INACTIVE_TARIFF_GUARD', true),

    /*
     | Сериализация реферального кошелька в чекауте. Когда ВКЛ, транзакция первым
     | действием перечитывает и блокирует строку users, а расчёт/списание кредита
     | использует только это DB-значение. ВКЛ по умолчанию (MG 01-08-2026):
     | CHECKOUT_REFERRAL_CREDIT_LOCK=false to opt out.
     */
    'checkout_referral_credit_lock' => (bool) env('CHECKOUT_REFERRAL_CREDIT_LOCK', true),

    /*
     | Обратимость депозитного зачёта. Когда ВКЛ, реальный переход оплаты из
     | paid/success в failed/canceled возвращает ровно deposit_credit_applied в
     | оплаченные deposit/trial строки (LIFO, под row-lock), сохраняя маркер
     | покупки для аудита и повторной оплаты. ВКЛ по умолчанию (MG 01-08-2026):
     | CHECKOUT_DEPOSIT_REVERSAL=false to opt out.
     */
    'checkout_deposit_reversal' => (bool) env('CHECKOUT_DEPOSIT_REVERSAL', true),

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
     | и заказ создаётся ровно на подтверждённую сумму. ВКЛ по умолчанию
     | (MG 01-08-2026 false-economy): OFF = lost promo → full price. Opt-out
     | CHECKOUT_PROMO_SURVIVES_SESSION=false.
     */
    'checkout_promo_survives_session' => (bool) env('CHECKOUT_PROMO_SURVIVES_SESSION', true),

    /*
     | Защита денежного вебхука Точки (H1359). Когда ВКЛ, WebhookController
     | отклоняет три класса опасных доставок: (a) повтор уже виденного тела
     | события (event_hash) — идемпотентный 200-no-op; (b) success для платежа,
     | который был оплачен и затем отменён/возвращён (resurrection) — не даёт
     | воскресить доступ/депозит/промо/реферала; (c) сумму из банка, расходящуюся
     | с payments.amount сверх config('checkout.webhook_amount_tolerance').
     | Журнал payment_webhook_events пишется ВСЕГДА. ВКЛ по умолчанию
     | (MG 01-08-2026): TOCHKA_WEBHOOK_GUARD=false to opt out.
     */
    'tochka_webhook_guard' => (bool) env('TOCHKA_WEBHOOK_GUARD', true),

    /*
     | H3280: live Tochka ClosingAvailable on /admin/teacher-salaries.
     | Read-only Open Banking GET. Default OFF. Enable: TOCHKA_BALANCE_ON_SALARIES=true
     | + config:cache. Teachers on «Моя зарплата» never see it (RoleGate::accounting).
     */
    'tochka_balance_on_salaries' => (bool) env('TOCHKA_BALANCE_ON_SALARIES', false),

    /*
     | H3280: ISO-week teacher payout due calendar.
     | Default OFF. Enable: TEACHER_WEEKLY_PAYOUT_CALENDAR=true + config:cache.
     | Read-only. Never writes teacher_payouts / payments.
     */
    'teacher_weekly_payout_calendar' => (bool) env('TEACHER_WEEKLY_PAYOUT_CALENDAR', false),

    /*
     | H3532: таб «Год» на календаре выплат (52 ISO-недели × все получатели,
     | формулы ×92 %, балансы Точка/PayPal). Default OFF — при OFF страница
     | рендерится байт-в-байт как прежде (H3280), новых запросов нет.
     | Enable: TEACHER_PAYOUT_YEAR_VIEW=true + config:cache. Read-only
     | по money-таблицам; пишет только finance_snapshots (ручной PayPal-баланс).
     */
    'teacher_payout_year_view' => (bool) env('TEACHER_PAYOUT_YEAR_VIEW', false),

    /*
     | «Telegram-вход» в кабинет (CABINET_ADOPTION_ROADMAP P2, 28-08-2026):
     | студент пишет студент-боту /start или /вход — если его Telegram уже
     | привязан (users.telegram_id), бот выдаёт ОДНОРАЗОВУЮ magic-ссылку входа
     | (purpose tg_login, TTL 15 мин, маршрут /tg-login/{token}). Владение
     | Telegram здесь и есть фактор подлинности. ВЫКЛ по умолчанию — это вход
     | в кабинет через чат; включение в проде — отдельный ops-шаг владельца
     | (TELEGRAM_CABINET_LOGIN=true + config:cache).
     */
    'telegram_cabinet_login' => (bool) env('TELEGRAM_CABINET_LOGIN', false),

    /*
     | Под-режим «Telegram-входа» для тех, кто не может даже открыть сайт:
     | непривязанный студент присылает боту email заказа → матч по нормализо-
     | ванному email СРЕДИ ОПЛАЧИВАВШИХ (кабинетная аудитория), staff (admin/
     | manager/super_admin) исключён; привязываем telegram_id и выдаём ссылку
     | входа.
     | Размен «знает email = получит доступ» — тот же, что у разрешённой
     | владельцем «самопроверки входа», но сильнее (тут не перечисление, а
     | доступ), поэтому отдельный флаг + @DECIDE владельца на включение.
     | Работает только вместе с telegram_cabinet_login.
     | Enable: TELEGRAM_CABINET_EMAIL_LINK=true + config:cache.
     */
    'telegram_cabinet_email_link' => (bool) env('TELEGRAM_CABINET_EMAIL_LINK', false),

    /*
     | Самообслуживание «/кабинет <email>» (02-09-2026): непривязанный студент
     | одной командой в личке бота создаёт себе кабинет (Free-tier плейлисты,
     | как при self-register H3643) и получает одноразовую magic-ссылку входа.
     | Щиты: ≤1 создание на telegram_id навсегда; существующий email не
     | перезаписывается и не привязывается по голому знанию email (угон-риск) —
     | мягкий отказ; пароли random и наружу не уходят.
     | Enable: TELEGRAM_CABINET_PROVISION=true + config:cache.
     */
    'telegram_cabinet_provision' => (bool) env('TELEGRAM_CABINET_PROVISION', false),

    /*
     | H2304 spec 2: «у курса нет групп доступа» = throw в Payment::grantAccess()
     | (fail closed на всех платных маршрутах: zero-price checkout, Filament,
     | PayPal, PayPal-claim, conditional, импорт), а не log-and-return «оплачено
     | без доступа». Tochka-вебхук имеет собственную независимую проверку (H2085)
     | и от этого флага не зависит. Money-контур: дефолт OFF, включение в проде —
     | отдельный ops-шаг (GRANT_ACCESS_FAIL_CLOSED=true + php artisan config:cache).
     */
    'grant_access_fail_closed' => (bool) env('GRANT_ACCESS_FAIL_CLOSED', false),

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
     | же тарифа, вместо гостевой формы, которую он не видел. ВКЛ по умолчанию
     | (MG 01-08-2026): CHECKOUT_SESSION_LAPSE_RELOGIN=false to opt out.
     */
    'checkout_session_lapse_relogin' => (bool) env('CHECKOUT_SESSION_LAPSE_RELOGIN', true),

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
     | ВКЛ по умолчанию (MG 01-08-2026): CHECKOUT_SIGNED_RETURN_URL=false to opt out.
     */
    'checkout_signed_return_url' => (bool) env('CHECKOUT_SIGNED_RETURN_URL', true),

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
     | Автопилот канала @rusamskrtam (H3930, Phase 1): stories:publish-due
     | шлёт approved+due текстовые story_posts в канал магнит-ботом
     | (MarketingSetting.tg_bot_token). Default OFF — прод-инертен, ноль HTTP.
     | Photo/video строки скипаются с журналом до Phase 2 (MTProto stories).
     */
    'telegram_story_publisher' => (bool) env('TELEGRAM_STORY_PUBLISHER', false),

    /*
     | Персона @rusamskrtam: user-сториз через MadelineProto (H3964, Phase 2).
     | stories:publish-story шлёт approved+due строки lane=persona СВОИМ
     | профилем (user stories, без админ-прав канала). Default OFF —
     | прод-инертен: ноль HTTP и MadelineProto-сессия не открывается вовсе.
     | Делит ЕДИНУЮ сессию с telegram-support:sync / telegram-harvest:sync
     | через madeline-session-лок (никогда параллельно).
     */
    'telegram_story_stories' => (bool) env('TELEGRAM_STORY_STORIES', false),

    /*
     | Виза MG (ASK_BATCH_CONTENT_FACTORY_TELEGRAM_2026 §2.8): студенческие
     | медиа (story_posts source=homework) издаются сториз-лейном только
     | после утверждения правила анонимизации (blur/crop лиц и имён,
     | подписи без имён и CRM-данных). Default OFF — такие строки скипаются
     | с журналом, никогда не публикуются.
     */
    'telegram_story_student_media_visa' => (bool) env('TELEGRAM_STORY_STUDENT_MEDIA_VISA', false),

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
     | GetCourse-паритет GC-C1 (H1641) + dozhim open-on-pending (H2102):
     | СДЕЛКИ — сущность Deal, канбан DealKanbanBoard, и PaymentDealBridgeObserver:
     |  (1) pending payable intent → open Deal on first stage (H2102);
     |  (2) paid/success sale → close open Deal or record won Deal (H1641).
     | Ранг 4 лестницы полномочий (docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §2.2):
     | наблюдает денежное ядро и НИКОГДА его не авторизует — ни доступа, ни цены,
     | ни отмены платежа. Lead/LeadStage/LeadKanbanBoard (H451) не трогаются.
     | ВЫКЛ по умолчанию — deploy-рубильник: пока флаг OFF, доска не
     | регистрируется, мост ранний return (0 строк в deals). Prod-enable:
     | CRM_PIPELINE_BOARD=true + config:cache (human @DO after review).
     */
    'crm_pipeline_board' => (bool) env('CRM_PIPELINE_BOARD', false),

    /*
     | H3247 cluster 1: пробное занятие как Deal.kind=trial (бесплатный intro +
     | платный SKU tariff=trial). TrialBookingService, Zoom-сверка, черновик
     | FollowUpTask, бейдж «Пробник». ВЫКЛ по умолчанию. Не выдаёт доступ и не
     | пишет payments. Включение — CRM_TRIAL_BOOKING=true + config:cache
     | (human ops после staff smoke). Виджет записи — отдельный ключ ниже.
     */
    'crm_trial_booking' => (bool) env('CRM_TRIAL_BOOKING', false),

    /*
     | H3248 cluster 2: публичная кнопка «Записаться» на /widgets/schedule.
     | POST 404 пока OFF. Требует также crm_trial_booking. Не включать в
     | этом PR — только заготовка ключа, default false.
     */
    'crm_trial_widget_public' => (bool) env('CRM_TRIAL_WIDGET_PUBLIC', false),

    /*
     | Список ожидания (MG 31-08-2026, волна 3): голосование из кабинета.
     | Read-only фид /api/public/waitlist работает всегда; POST /vote 404,
     | пока OFF. Не включать в этом PR — только ключ, default false.
     */
    'waitlist_voting' => (bool) env('WAITLIST_VOTING', false),

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
     | Noboring dozhim Wave 1b (H2119): бакет «недожатые / open Deal старше N ч»
     | в WorkQueueReport + карточка кокпита. Сделки открывает H2102 open-on-pending
     | (тот же crm_pipeline_board); этот флаг только показывает очередь оператору.
     |
     | ВЫКЛ по умолчанию. Не пишет в payments/access. dozhim_drip (шаблоны/drip)
     | — отдельный флаг, не здесь.
     | Включение — DOZHIM_QUEUE=true + config:cache после ревью.
     */
    'dozhim_queue' => (bool) env('DOZHIM_QUEUE', false),

    /*
     | Noboring dozhim Wave 1b (H2059, IMPLEMENTATION H-B п.5): линейный дрип
     | по недожатым open Deal — day0/day3/day7 через уже существующий
     | Messaging (TG/VK/email), шаблоны категории MessageTemplate::CATEGORY_DOZHIM.
     | Независим от dozhim_queue (тот флаг — только видимость бакета оператору,
     | этот — реальная отправка клиенту).
     |
     | ВЫКЛ по умолчанию. Пока OFF — dozhim:drip не отправляет ни одного
     | сообщения (WorkQueueReport::agedOpenDeals() запрашивается, но письма/TG
     | не уходят). Включение — DOZHIM_DRIP=true + config:cache после ревью
     | (тексты 4 заготовок NF-таблицы + порядок day0/day3/day7).
     */
    'dozhim_drip' => (bool) env('DOZHIM_DRIP', false),

    /*
     | NOBORING дожим, операторская сводка (решение MG 24-08-2026, гибрид):
     | будни 10:00 Europe/Moscow — Telegram-сообщение владельцу очереди со
     | списком недожатых open Deal (WorkQueueReport::agedOpenDeals(), без гейта
     | dozhim_queue — видимость и доставка независимы, как у dozhim:drip).
     | Получатель: DOZHIM_OPERATOR_TG_CHAT_ID (числовой chat_id, сырой sendMessage
     | через основной бот) либо первый User роли manager с привязанным telegram_id.
     | Пустая очередь НЕ отправляет ничего (тишина = всё дожато).
     |
     | ВЫКЛ по умолчанию. Включение — DOZHIM_OPERATOR_NOTIFY=true + config:cache.
     */
    'dozhim_operator_notify' => (bool) env('DOZHIM_OPERATOR_NOTIFY', false),

    /*
     | H2186 / NOBORING Rate B: stamp Lead.converted_at on ordinary course paid path.
     | Deposit / trial / marathon already call markConverted always. Course checkout
     | historically did not (GETCOURSE_PARITY_PRODUCTION_SPEC §2.4) — Rate B on prod
     | is ~0% instrumentation gap, not true 0% lead→pay. When ON: processSuccessfulPayment
     | resolves lead (payment.lead_id or email match) and Lead::markConverted(); checkout
     | may also attach lead_id on create. Does not change money/access grant.
     |
     | ВЫКЛ по умолчанию — deploy-рубильник. Prod enable only after human review:
     | LEAD_CONVERTED_AT_ON_COURSE_PAID=true + config:cache. Never enable in this PR.
     */
    'lead_converted_at_on_course_paid' => (bool) env('LEAD_CONVERTED_AT_ON_COURSE_PAID', false),

    /*
     | Noboring dozhim Wave 1 H-C (H2060): student.access ("Оплата и доступ")
     | debt cards gain amount + FAQ payment link + curator contact + installment
     | CTA copy. Pure presentation on top of the already-existing
     | DebtPaymentResolver options and RecoveryStateResolver state — no new
     | payment path, no PaymentObserver/grant change.
     |
     | ВЫКЛ по умолчанию. Пока OFF, student.access рендерится ровно как раньше
     | (без блока amount/FAQ/куратор/рассрочка). Включение —
     | PAYMENT_RECOVERY_CTA=true + config:cache после ревью.
     */
    'payment_recovery_cta' => (bool) env('PAYMENT_RECOVERY_CTA', false),

    /*
     | Access self-service Phase 1 (H2386): "Почему закрыто?" diagnostics on locked
     | lessons + profile access summary + POST materialize paid block keys via
     | BlockAccessMaterializer. Read-only diagnostics; materialize only expands
     | access-only sibling payment rows (amount 0), no new money path.
     |
     | ВЫКЛ по умолчанию. OFF → no diagnostics UI, materialize route 404.
     | Включение — ACCESS_SELF_SERVICE=true + config:cache после ревью.
     */
    'access_self_service' => (bool) env('ACCESS_SELF_SERVICE', false),

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
     | H2166 — Nalopākhyāna Mode-A subscription checkout radio kill-switch.
     | Nala launches on the existing one-shot checkout (ARCHITECTURE_NALOPAKHYANA_
     | SUBHASHITA_COURSES_2026.md §4); this flag ONLY gates whether the (inert,
     | disabled) "auto-pay monthly" radio renders on the Nala checkout page.
     | Deliberately SEPARATE from the master `tochka_recurring` switch above — a
     | human sets this true only after Systema-Sanscriticum#998 (Tochka
     | subscriptions product) is confirmed live AND a sandbox charge has been
     | proven; other courses/modes may enable `tochka_recurring` independently
     | without this flag moving. Even when both this AND `tochka_recurring` are
     | ON, the radio option itself stays disabled in the DOM — H2026 Phase 1
     | (TochkaPaymentService::createSubscriptionWithReceipt) is a separate,
     | not-yet-built handoff. ВЫКЛ по умолчанию — deploy-рубильник.
     | Enable: NALA_SUBSCRIPTIONS_LIVE=true + config:cache (human only, money PR).
     */
    'nala_subscriptions_live' => (bool) env('NALA_SUBSCRIPTIONS_LIVE', false),

    /*
     | Режим просмотра за пользователя — «войти как» (H1947 / H3219). Когда ВКЛ,
     | СУПЕР-АДМИН (и только он) может из карточки пользователя открыть кабинет
     | студента, панель куратора или панель преподавателя, не меняя users.role
     | и не зная чужого пароля: сессия переключается на целевого пользователя,
     | сверху висит несъёмная плашка «выйти из режима», старт и стоп пишутся в
     | impersonation_audits.
     |
     | ВЫКЛ по умолчанию — deploy-рубильник: пока флаг OFF, действия в
     | UserResource скрыты, маршруты /impersonate/* отвечают 404, а уже открытый
     | режим принудительно закрывается на первом же запросе (fail-closed).
     | Включение — STAFF_IMPERSONATION=true + config:cache после ревью.
     |
     | Денежные/выдающие доступ записи в режиме ЗАПРЕЩЕНЫ всегда (403), список
     | закрытых маршрутов — в config/impersonation.php. Никогда не расширять
     | этот флаг на обычного admin: только super_admin.
     */
    'staff_impersonation' => (bool) env('STAFF_IMPERSONATION', false),

    /*
     | PayPal Subscriptions API (H2027) — auto-bill for diaspora, separate from
     | manual claim (PAYPAL_CLAIM_ENABLED). Default OFF: route returns 404 and
     | no Product/Plan/Subscription calls. Prod enable is human @DO only after
     | Business account can sell Subscriptions. See
     | docs/ARCHITECTURE_PAYPAL_SUBSCRIPTIONS_2026.md
     */
    'paypal_subscriptions' => (bool) env('PAYPAL_SUBSCRIPTIONS_ENABLED', false),

    /*
     | «Старт чтения» — Akro-style 5-week paid cohort pilot (H2105, D11: per-cohort
     | entitlement, NEVER a global SRS_ENABLED=true flip). No new money system:
     | the cohort is an ordinary Course+Tariff sold through the existing generic
     | checkout (ShopController/CheckoutController/PaymentController) and unlocked
     | by the existing PaymentObserver::grantAccess() course_group pivot — see
     | App\Support\StartChteniyaCohort. This flag ONLY gates the cohort
     | *entitlement* helper (used by sibling handoffs H2106/H2110/H2111 to unlock
     | multi-pack reader routes / cohort SRS deck): while OFF, a paid student is
     | still enrolled in the course normally, but StartChteniyaCohort::hasEntitlement()
     | returns false — a deploy-inert kill-switch before the pilot launches.
     | Course/Tariff/Group price and dates are human ops (Filament), never invented
     | here — see docs/PLAN_AKRO_START_CHTENIYA_2026.md D6.
     | Включение: START_CHTENIYA_COHORT_ENABLED=true + config:cache after ops has
     | created the Course/Tariff/Group rows.
     */
    'start_chteniya_cohort' => (bool) env('START_CHTENIYA_COHORT_ENABLED', false),

    /*
     | GC-C2 — «Продажи по менеджеру» (H2058 residual /
     | docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §4). F5 RULED 02-08-2026
     | (MG): (a) join = payments.created_by_user_id (кто создал строку платежа —
     | blame-колонка), НЕ leads.assigned_to/deals.assigned_to (near-empty by
     | construction — заполняется только на deposit/trial/marathon, а deposit/
     | trial как раз исключены из знаменателя заказов); (b) видимость —
     | super_admin/admin/accountant видят все строки, manager — только свои
     | (created_by_user_id = auth id). Отдельная страница ManagerSalesReport,
     | СУЩЕСТВУЮЩИЙ OrderPaymentConversion (RoleGate::finance()) не расширяется.
     | Самостоятельные покупки/webhook-заказы остаются created_by_user_id=NULL —
     | это by-design «Без менеджера», не баг; заполнение колонки — дисциплина
     | продаж (менеджер создаёт/фиксирует платёж в админке), а не эта задача.
     | ВЫКЛ по умолчанию — deploy-рубильник: пока флаг OFF, страница не
     | регистрируется и недоступна (canAccess false) даже для admin.
     */
    'manager_sales_report' => (bool) env('MANAGER_SALES_REPORT', false),

    /*
     |--------------------------------------------------------------------------
     | Библиотека курса (фаза 1 — реестр ссылок)
     |--------------------------------------------------------------------------
     |
     | Преподаватель выкладывает ссылку на PDF, она становится строкой в
     | библиотеке курса. Файл НЕ забираем: рост хранилища нулевой — это
     | осознанный выбор, потому что вложения уроков уже основной источник
     | роста storage/app, а он целиком уходит в недельный бэкап.
     |
     | Фаза 2 (опциональное зеркалирование с лимитом размера) добавит
     | disk/path/size/sha256 — до тех пор их нет намеренно.
     |
     | Внешние ссылки гниют, поэтому строки публикуются вручную
     | (is_visible=false по умолчанию), а страница честно предупреждает
     | студента, что файл лежит не у нас.
     |
     | Выключено — маршрут /c/{slug}/library отдаёт 404. Админка при этом
     | работает независимо от флага: куратор может заранее собрать полку.
     */
    'course_library' => (bool) env('COURSE_LIBRARY', false),

    /*
     | Native VisualDCS learner surfaces (H2482). Three independent flags so one
     | surface can roll back without taking the other two down. All default OFF:
     | routes 404 and the cabinet nav entry stays hidden until a human flips
     | VISUALDCS_VERB / VISUALDCS_NOMINAL / VISUALDCS_PASSAGE after the 7-day
     | baseline. Does not change prices, Payment qualification or identity.
     */
    'visualdcs_verb' => (bool) env('VISUALDCS_VERB', false),
    'visualdcs_nominal' => (bool) env('VISUALDCS_NOMINAL', false),
    'visualdcs_passage' => (bool) env('VISUALDCS_PASSAGE', false),

    /*
      |--------------------------------------------------------------------------
      | Мульти-персона ИИ-куратора (H3520). OFF: persona(любой ключ) возвращает
      | дефолтный контракт FAQ-куратора байт-в-байт. Включать только вместе с
      | содержаниями новых персон (config/bot_personas.php) — решение MG.
      |--------------------------------------------------------------------------
      */
    'bot_multi_persona' => (bool) env('BOT_MULTI_PERSONA', false),

    /*
     |--------------------------------------------------------------------------
     | Членство: «Клуб» и бесплатный уровень «Свободный» (H2644, запуск 01-09-2026)
     |--------------------------------------------------------------------------
     |
     | Три НЕЗАВИСИМЫХ флага, все ВЫКЛЮЧЕНЫ: контур денег, поэтому миграция
     | deploy-инертна, а включение — отдельный ops-шаг человека, не деплой.
     | Пока флаги OFF: период членства не заводится на оплате, клубное право
     | не подмешивается ни в один гейт доступа, страница отказа от продления
     | отдаёт 404, а обе команды печатают отчёт и ничего не пишут.
     |
     | Порядок включения на 01-09: club_membership → membership_cancellation →
     | membership_free_tier. Бесплатный уровень последний намеренно: он выдаёт
     | гранты 350 уснувшим плательщикам (D6 = вариант «а»), и включать его стоит,
     | когда клубный контур уже проверен на живых оплатах.
     |
     | ВНИМАНИЕ: club_membership без заведённого в Filament курса-членства
     | (slug из config/membership.php) — тоже no-op, но МОЛЧАЛИВЫЙ. Проверять
     | командой `membership:rehearse` до объявления запуска.
     */
    'club_membership' => (bool) env('CLUB_MEMBERSHIP', false),
    // H2744 staged rollout. Tier interpretation is dark until legacy rows and
    // tariffs have been explicitly classified by membership:classify-tiers.
    'membership_tiered' => (bool) env('MEMBERSHIP_TIERED', false),
    'membership_advanced_features' => (bool) env('MEMBERSHIP_ADVANCED_FEATURES', false),
    // Separate 01-10 go/no-go. OFF caps Top rows at Club capabilities and hides checkout.
    'membership_top' => (bool) env('MEMBERSHIP_TOP', false),
    'membership_recording_shadow' => (bool) env('MEMBERSHIP_RECORDING_SHADOW', false),
    'membership_recording_pilot' => (bool) env('MEMBERSHIP_RECORDING_PILOT', false),
    'membership_recording_enforce' => (bool) env('MEMBERSHIP_RECORDING_ENFORCE', false),
    'membership_free_tier' => (bool) env('MEMBERSHIP_FREE_TIER', false),
    'membership_cancellation' => (bool) env('MEMBERSHIP_CANCELLATION', false),
    // H2745: one canonical commercial feed, two host-specific renderings.
    'membership_public_feed' => (bool) env('MEMBERSHIP_PUBLIC_FEED', false),
    // H2745: authenticated, payment-derived archive offers; all dark by default.
    'membership_private_archives' => (bool) env('MEMBERSHIP_PRIVATE_ARCHIVES', false),
    // H2745: append-only funnel dimensions (tier/term/source/course/feature).
    'membership_funnel_analytics' => (bool) env('MEMBERSHIP_FUNNEL_ANALYTICS', false),
    // H3648: Club/Top grant club-stream / club-efir recordings only; course-lesson
    // recordings stay on the course-purchase path. Default OFF = H2744 D10 predicate
    // unchanged. Enable: MEMBERSHIP_CLUB_STREAMS_ONLY=true + config:cache (human ops).
    'membership_club_streams_only' => (bool) env('MEMBERSHIP_CLUB_STREAMS_ONLY', false),

    /*
     | Guest email+password /register → Free-tier of the club (H3643, self-serve
     | wave B2). Default OFF: GET/POST /register 404. When ON, creates a user,
     | grants MembershipTier::Free via FreeTierLessonGranter::grantSignupFor
     | (ClubMembership, zero payments), seeds the persistent club-free SRS deck.
     | Enable: GUEST_REGISTRATION_ENABLED=true + config:cache. Human ops only —
     | this PR must not flip prod .env on .92.
     */
    'guest_registration' => (bool) env('GUEST_REGISTRATION_ENABLED', false),

    /*
     | Grammar Lab explorer (H2493 / G2). Import and tables are additive;
     | this flag gates every public/cabinet route and payload. Default OFF:
     | /grammar-lab and /dvaram/grammar-lab 404 until a human flips
     | GRAMMAR_LAB=true + config:cache. Does not create payments or flip
     | a subscription. Semantic retrieval is a second flag.
     */
    'grammar_lab' => (bool) env('GRAMMAR_LAB', false),

    /*
     | Offline hybrid (BM25 + local embedding) for Grammar Lab search.
     | Stays OFF until G1 semantic_ready=true AND frozen Recall@5 ≥ 0.85.
     | Flag OFF leaves lexical BM25 live. Never substitutes a paid API.
     */
    'grammar_lab_semantic' => (bool) env('GRAMMAR_LAB_SEMANTIC', false),

    /*
     | Grammar Lab 5–10-student consented pilot (H2495 / G4). Default OFF.
     | Consent routes 404 until a human flips GRAMMAR_LAB_PILOT=true.
     | Does not charge, activate subscriptions, or invite a cohort.
     */
    'grammar_lab_pilot' => (bool) env('GRAMMAR_LAB_PILOT', false),

    /*
     | Hindi programme playlist (H2441): one ordered list of owned Hindi
     | lessons across course shells (category `hindi` + H2333 predecessor).
     | Default OFF — route /dvaram/programme/hindi 404s, nav/card stay hidden.
     | Does not grant access, merge courses, or depend on CABINET_HYBRID.
     | Enable: HINDI_PROGRAMME_PLAYLIST=true + config:cache.
     */
    'hindi_programme_playlist' => (bool) env('HINDI_PROGRAMME_PLAYLIST', false),

    /*
     | Hindi transcript drills (H2443): cloze / translate / vocab pick from
     | Lesson.transcript_file. Default OFF — route /c/{slug}/u/{id}/drills 404s.
     | Does not grant access; reuses H2441 playlist unlock rules. No LLM.
     | Enable: HINDI_TRANSCRIPT_DRILLS=true + config:cache.
     */
    'hindi_transcript_drills' => (bool) env('HINDI_TRANSCRIPT_DRILLS', false),

    /*
     | Student-visible drills from YouTube re-ASR (metadata.source =
     | deepgram-nova-3). Default OFF — Hindi teachers still preview via
     | teachesHindi(); students keep Zoom/n8n transcripts (no source) and
     | attachment drills. Enable only after the Hindi teacher says the
     | YouTube cards are usable: HINDI_YOUTUBE_NOVA3_DRILLS=true + config:cache.
     */
    'hindi_youtube_nova3_drills' => (bool) env('HINDI_YOUTUBE_NOVA3_DRILLS', false),

    /*
     | Hindi attachment drills (H2444): cloze / translate from files already
     | on the lesson (attachments + teacher homework files). txt/md/docx
     | extract; PDF only if a sibling .txt exists. Default OFF.
     | Does not grant access, scrape Telegram, or OCR.
     | Enable: HINDI_ATTACHMENT_DRILLS=true + config:cache.
     */
    'hindi_attachment_drills' => (bool) env('HINDI_ATTACHMENT_DRILLS', false),

    /*
     | Private «Мой хинди» SRS deck (H2445). Default OFF — «в колоду» on
     | playlist/drills 404s. Does not grant access, does not write the public
     | Hindi Core deck, and does not flip srs.enabled. Review stays on
     | /dvaram/koloda/my-hindi when SRS is already on.
     | Enable: HINDI_MY_SRS_DECK=true + config:cache.
     */
    'hindi_my_srs_deck' => (bool) env('HINDI_MY_SRS_DECK', false),

    /*
     | Curated Hindi study-chat practice (H2446). Default OFF — route
     | /dvaram/programme/hindi/chat-practice 404s. Reads committed JSON
     | only; never calls Telegram / Madeline. Privacy gate drops PII rows.
     | Does not grant access. Enable: HINDI_TG_CURATED_PRACTICE=true +
     | config:cache.
     */
    'hindi_tg_curated_practice' => (bool) env('HINDI_TG_CURATED_PRACTICE', false),

    /*
     | Kostina Hindi module dictionaries → student drills (H3206).
     | Default OFF — /dvaram/programme/hindi/vocab 404s. Hindi teachers
     | still preview. Enable: HINDI_DICTIONARY_DRILLS=true + config:cache.
     | Does not grant access. Does not turn transcript/attachment drills on.
     */
    'hindi_dictionary_drills' => (bool) env('HINDI_DICTIONARY_DRILLS', false),

    /*
     | Literal-Jivo Wave 4+ (H2486). All default OFF. Packet:
     | docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md
     | Thresholds: config/telephony.php. No purchase, number, call or
     | recording in this pass. Do not flip without the packet's gates.
     */
    'telephony_callback_request' => (bool) env('TELEPHONY_CALLBACK_REQUEST', false),
    'telephony_pstn' => (bool) env('TELEPHONY_PSTN', false),
    'telephony_recording' => (bool) env('TELEPHONY_RECORDING', false),
    'support_departments' => (bool) env('SUPPORT_DEPARTMENTS', false),
    'support_capacity_routing' => (bool) env('SUPPORT_CAPACITY_ROUTING', false),

    /*
     | H2762 R12 — next-step strip on ONE Kochergina catalog card.
     | «После этого → Бюлер / тексты / рецитация». Default OFF. Isolated:
     | other cards unchanged. Stop: n too small or layout break → hide.
     | Rollback: CATALOG_NEXT_STEP=false. Not a revenue claim.
     */
    'catalog_next_step' => (bool) env('CATALOG_NEXT_STEP', false),

    /*
     | H2762 R15 — CTA label A/B on the same Kochergina course page only.
     | One variable: «Смотреть пробный урок» vs «Записаться». Default OFF.
     | After 30 days, if n is too thin → DEFER, do not ship a winner.
     | Rollback: FLAGSHIP_CTA_AB=false restores the previous string.
     */
    'flagship_cta_ab' => (bool) env('FLAGSHIP_CTA_AB', false),

    /*
     | Подарочные сертификаты (H3334): тариф «в подарок» + одноразовый код
     | активации поверх существующей тарифной модели. Деньги — обычный разовый
     | чекаут (payments.tariff='gift'), доступ покупателю не открывается; после
     | оплаты выпускается сертификат с sha256-хэшем кода, получатель активирует
     | его на /gift/activate — и получает доступ штатным PaymentObserver-контуром.
     |
     | ВЫКЛ по умолчанию — deploy-рубильник money-контура: пока флаг OFF,
     | /gift/* отдают 404, кнопка «Подарить» на чекауте скрыта, параметр
     | gift=1 игнорируется полностью (поведение байт-в-байт прежнее). Включение
     | — осознанный шаг человека: GIFT_CERTIFICATES=true + config:cache ПОСЛЕ
     | репетиции `gift-certificates:rehearse` и ревью цен номиналов (money row MG:
     | цена подарка = цена underlying-тарифа; отдельные подарочные ценники —
     | только через решение MG). Анти-срочность: никаких дедлайн-механик.
     */
    'gift_certificates' => (bool) env('GIFT_CERTIFICATES', false),

    /*
     | H3231 (Wave 3, agent-ops overlay): bounded student agent — ровно три
     | job'а (homework hint / dictionary lookup / cabinet FAQ), жёсткий
     | allow-list инструментов в StudentAgentService, НЕ свободный чат с
     | куратором. Шаблон/БД-поиск/H2448-ретривер побеждают LLM (тот же
     | порядок, что у support_answer_suggester); LLM-фолбэк только для
     | homework_hint и только по названию урока — тело работы, файлы и
     | Telegram DM никогда не уходят в LLM. cabinet_faq требует ВКЛЮЧЁННОГО
     | faq_rag_suggester — второго нерегулируемого пути в него это не
     | открывает. ВЫКЛ по умолчанию — deploy-рубильник: пока OFF,
     | POST /dvaram/agent отдаёт 404 байт-в-байт как раньше (маршрута не
     | существовало). Включение — осознанный шаг человека:
     | STUDENT_AGENT_ENABLED=true + config:cache после ревью.
     */
    'student_agent' => (bool) env('STUDENT_AGENT_ENABLED', false),

    /*
     | H3xxx — near-duplicate email guard at checkout signup (resolveUser()).
     | Exact-email dedup (User::normalizeEmail) already refuses a second
     | account for the SAME email; this catches a near-miss (typo'd domain —
     | .con/.com, gmial/gmail, extra/missing char) that slips through as a
     | genuinely new account, splitting one student's payments/access across
     | two logins. Default OFF: never blocks checkout, only pings curators
     | (NearDuplicateEmailDetector + CuratorNotifier::possibleDuplicateAccount)
     | so a human can merge before it turns into a "why can't I see my paid
     | block" ticket. Incident: Долгополова Анастасия, 2026-08-18 (block_1
     | paid twice under anastasiadolgopolova25@gmail.com vs ...gmail.con).
     */
    'checkout_near_duplicate_email_guard' => (bool) env('CHECKOUT_NEAR_DUPLICATE_EMAIL_GUARD', false),

    /*
     | H3693 — referral CTA at three student-owned loyalty moments:
     | homework accepted, student certificate list, dashboard course-complete.
     | Reuses student/partials/referral.blade.php (H1294). Default OFF:
     | merge is prod-inert; the existing cabinet include stays as it is.
     | Do not put this invite on public /verify. partner.enabled stays OFF.
     | Enable: REFERRAL_LOYALTY_CTA=true + php artisan config:cache.
     */
    'referral_loyalty_cta' => (bool) env('REFERRAL_LOYALTY_CTA', false),

    /*
     | H3764 (O2 + C4) — страница «Активация и завершаемость»: воронка
     | активации по месячным когортам (оплатил → вошёл → открыл урок → сдал
     | домашнюю, плюс медиана TTFL) и завершаемость по курсу/потоку (порог
     | пройденных уроков + выданные сертификаты). Только чтение: страница
     | ничего не пишет и денег не трогает. Доступ — RoleGate::accounting().
     |
     | ВЫКЛ по умолчанию: пока флаг OFF, пункт меню не появляется, а /admin/
     | activation-completion-metrics отдаёт 403 — влитие прод-инертно.
     | Включение — осознанный шаг человека: ACTIVATION_COMPLETION_METRICS=true
     | + php artisan config:cache, ПОСЛЕ сверки знаменателей на проде (числа
     | считаются из живой БД, кэша снимков нет). Пороги — не здесь, а в
     | config/activation_metrics.php.
     */
    'activation_completion_metrics' => (bool) env('ACTIVATION_COMPLETION_METRICS', false),

    /*
     | H3821 — published fixed EUR/USD PayPal price list (PaypalForeignPriceService),
     | replacing the ad hoc per-transaction manual quoting that H3819's reconciliation
     | found varying 0-18% for the identical RUB tariff. ВЫКЛ по умолчанию: пока флаг
     | OFF, PaypalClaimController::show() продолжает читать
     | services.paypal.foreign_block_prices как раньше, и месячный refresh-cron
     | (paypal:refresh-foreign-prices в Kernel) не запускается. Включение —
     | PAYPAL_FIXED_PRICE_LIST_ENABLED=true + php artisan config:cache, ПОСЛЕ
     | ручного прогона `php artisan paypal:refresh-foreign-prices --dry-run` и
     | сверки чисел человеком.
     */
    'paypal_fixed_price_list' => (bool) env('PAYPAL_FIXED_PRICE_LIST_ENABLED', false),

    /*
     | H3915 — Exit-опрос на авто-триггер «завершение курса». Когда курсу
     | выставляют is_completed (.CourseResource → «Курс завершён»), кураторский
     | чат получает задачу с ГОТОВЫМИ черновиками для ЛИЧНОЙ отправки каждому
     | из когорты «спросил цену → оплаты нет» этого курса (правило
     | ACQUISITION_SURVEY_INSTRUMENTS_2026H2: отправляет куратор лично каждому,
     | НЕ рассылкой). Система сама студентам НЕ пишет — только уведомление
     | куратору; включать осознанно: EXIT_SURVEY_AUTO_TRIGGER=true +
     | php artisan config:cache. Дедуп — courses.exit_survey_triggered_at;
     | ручной/догоняющий прогон — surveys:exit-survey-completed.
     */
    'exit_survey_auto_trigger' => (bool) env('EXIT_SURVEY_AUTO_TRIGGER', false),
];
