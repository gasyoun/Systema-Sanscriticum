# Roadmap: полный JIVO-паритет — visitor intelligence + Support Inbox 2026–2027

_Created: 17-07-2026 · Last updated: 19-08-2026_

> **Граница документа после решения MG 08-08-2026:** этот roadmap закрывает именно
> school-operational parity и остаётся обязательным первым гейтом. Следующий план уже коммитит
> CRM, а затем телефонию/отделы/routing как поздние волны:
> [`PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).
> Поэтому старые формулировки «не строить» ниже читаются как «не строить ДО school parity + CRM»,
> а не как вечный отказ.

> Узкий roadmap **паритета с Jivo по «интеллекту посетителя»** — тому пласту, ради
> которого Jivo и держат на [samskrtam.ru](https://samskrtam.ru): куратор видит, **из
> какого города** пишет посетитель и **что он делает на сайте**, и может **написать
> первым**. Наш собственный веб-чат-виджет на [samskrte.ru](https://samskrte.ru)
> (self-hosted, `#scw-root`, H536) уже даёт живой двусторонний чат с оператором, но
> этого визитор-слоя в нём нет — это и есть разрыв.
>
> **Не дублирует** соседние roadmap'ы: автоматизацию ответов (дефлекшн) держит
> [`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md);
> всю Telegram-поверхность (inbound/outbound/reply-out) —
> [`docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md).
> Этот документ — про **новый визитор-слой** (гео + presence + проактив), которого нет
> ни в одном из них. Ground truth по support-коду —
> [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md);
> продуктовый разбор Jivo —
> [`docs/jivo.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md).

**Провенанс:** составлен Opus 4.8 (`claude-opus-4-8`), 17-07-2026, по запросу MG «строить два
недостающих столпа Jivo + поставить задачи по всем 6 требованиям паритета». Живая проверка
обоих сайтов (Jivo на samskrtam.ru работает; наш виджет на samskrte.ru работает, Reverb-push
**подключён в проде** — connState=connected, wsHost=samskrte.ru) выполнена в той же сессии.

## 0. Re-audit 07-08-2026 — visitor parity больше не главный разрыв

Code-grounded source of truth:
[`support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md),
а не исходная колонка «как у нас сейчас» в `jivo.md`. После H536/H1196–H1200/H1837/H221/H223:

| Capability | Статус 07-08 | Остаток |
|---|---|---|
| Web real-time + guest widget | ✅ | production health входит в H2382 |
| Visitor geo/presence + operator-first message | ✅ code, flags | privacy/flag/smoke evidence в H2382 |
| Lead capture + contextual greeting | ✅ code, flags | adoption/readout, не новый build |
| Unified web/TG/VK read + channel badges | ✅ | no table merge; identity remains `social_accounts` |
| Inbox tabs + assignment + canned replies | ✅ | не планировать снова |
| AI suggested reply + summary services | ✅ code, flags | prove operator value; no auto-send |
| Shared topics + per-channel rollups | ✅ | required topic on close still missing |
| EdTech context beside chat | 🟡 | payments/promises/discounts/attendance есть; courses/groups/block access/next lesson/recent tickets incomplete |
| Support follow-up | 🔴 | CRM/academic reminders are not a task-from-dialog workflow |
| Support→payment/access/attendance outcomes | 🔴 | rollups exist, correlation dashboard missing |
| Inbound email | 🟢 built 24-08-2026 (H3462): zabota@samskrte.ru → forwarder → secret-verified webhook ([InboundEmailWebhookTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/InboundEmailWebhookTest.php)); флаг `SUPPORT_INBOUND_EMAIL` default OFF, активация — DEPLOY_QUEUE №82; reply-out остаётся ⛔ ручным шагом человека | spec: [INBOUND_EMAIL_CHANNEL_SPEC_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INBOUND_EMAIL_CHANNEL_SPEC_2026.md) |

**Completion sequence:**

1. [H2381 (Grok 4.5) — Complete JIVO operator workflow with EdTech context, close topics and support follow-ups](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2381-Grok_Systema-Sanscriticum_jivo-operator-workflow-completion_07.08.26.md).
2. Finish the already-active [H1200 (Sonnet 5) — Jivo parity S5/5 email channel, channel badging and reply-out canary](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1200-Sonnet_Systema-Sanscriticum_jivo-parity-s5of5-email-channel-badging_17.07.26.md) residual; do not duplicate it.
3. [H2382 (Grok 4.5) — Prove production support parity across the existing JIVO implementation lanes](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2382-Grok_Systema-Sanscriticum_support-parity-production-acceptance_07.08.26.md): flag matrix, approved canaries, channel health and 14-day KPI evidence.

Full product sequencing and kill criteria:
[`ROADMAP_SYSTEMA_REVENUE_CABINET_EDITORIAL_JIVO_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_REVENUE_CABINET_EDITORIAL_JIVO_2026H2.md).

---

## 1. Требования MG и текущий паритет (6 требований)

Шесть требований — из уточняющего интервью MG (что именно из Jivo должно совпасть):

| # | Требование (как в Jivo) | Есть у нас? | Владелец-задача |
|---|---|---|---|
| 1 | Живой чат с оператором (real-time) | ✅ **Готово** (H536; Reverb-push в проде + фолбэк-опрос) | — (residual reply-out канарейка — S5) |
| 2 | Авто-ответы / сценарии | ⚠️ Частично (AI-куратор + FAQ-суггестер H247 — оператору; веб-виджет без сценариев) | [H1198](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1198-Sonnet_Systema-Sanscriticum_jivo-parity-s3of5-webchat-scenarios_17.07.26.md) (S3) |
| 3 | Сбор контактов / заявки (лиды) | 🟢 **S4 сделано 18-07-2026** (см. §4) | [H1199](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1199-Sonnet_Systema-Sanscriticum_jivo-parity-s4of5-lead-capture_17.07.26.md) (S4) |
| 4 | Каналы TG / VK / почта | ✅ **инбаунд почты построен 24-08-2026 (H3462)**: zabota@samskrte.ru → вебхук, source='email', бейдж Email, дедуп Message-ID, очередь нераспознанных; флаг `SUPPORT_INBOUND_EMAIL` default OFF (активация — DEPLOY_QUEUE №82); reply-out канарейка — по-прежнему ⛔ ручной шаг человека (см. §4) | [H1200](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1200-Sonnet_Systema-Sanscriticum_jivo-parity-s5of5-email-channel-badging_17.07.26.md) (S5), [H3462](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3462-OxAlpha_Systema-Sanscriticum_inbound-email-zabota-forwarder_24.08.26.md) |
| 5 | **Город посетителя** в панели куратора | 🟢 **S1 сделан этой сессией** (см. §2) | [H1196](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1196-Opus_Systema-Sanscriticum_jivo-parity-s1of5-visitor-geo-city_17.07.26.md) (S1, Pillar 1) |
| 6 | **Написать первым** + видеть, что делает на сайте | 🟢 **S2 сделан этой сессией** (см. §3) | [H1197](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1197-Opus_Systema-Sanscriticum_jivo-parity-s2of5-proactive-visitor-monitor_17.07.26.md) (S2, Pillar 2) |

**Два «столпа» (5 и 6)** — это то, чего в подсистеме нет вообще и что уникально для Jivo. Их
и строим в первую очередь; 2/3/4 — доведение уже существующего.

**Жёсткий унаследованный принцип (MG):** *боты НЕ пишут людям сами.* Проактив (Pillar 2) —
всегда **инициатива оператора-человека** (куратор видит, кто на сайте, и решает написать), а не
авто-триггер, рассылающий приглашения. Это совпадает с ручными приглашениями Jivo и с принципом
из [`ROADMAP_SUPPORT_AUTOMATION`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) §7.

---

## 2. S1 — Pillar 1: гео/город посетителя (✅ сделано 17-07-2026, H1196)

**Что сделано** (в этом же PR):

- Миграция [`add_visitor_context_to_support_conversations`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_120000_add_visitor_context_to_support_conversations.php):
  на тред `support_conversations` добавлены `visitor_ip`, `visitor_city`, `visitor_region`,
  `visitor_country`, `visitor_geo_resolved_at`, `entry_url`, `referrer` — аддитивно, nullable.
- [`PublicChatController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicChatController.php)
  при **первом** сообщении треда фиксирует IP + страницу входа (виджет шлёт `page`) + referrer
  (дёшево, без внешних вызовов), идемпотентно.
- `VisitorGeoResolver` + `ResolveVisitorGeoJob` (асинхронно, чтобы внешний вызов не блокировал
  POST) резолвят IP → город/регион/страна; драйвер выбирается в
  [`config/support_geo.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_geo.php):
  `null` (дефолт, без резолва), `cloudflare` (заголовки CF-*, ноль внешних вызовов), `ipapi`
  (ip-api.com).
- Куратор видит «📍 Город, Страна» и страницу входа в [`Helpdesk`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/Helpdesk.php)
  (список гостей + шапка гостевого треда).
- Всё за флагом `support_visitor_geo` (дефолт **OFF**); 11 тестов
  ([`SupportVisitorGeoTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorGeoTest.php)) + 23 регрессионных чат-теста зелёные.

**Прод-включение (для Ивана, [DEPLOY_QUEUE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)):**
`php artisan migrate` (аддитивно) → выбрать драйвер (`SUPPORT_GEO_DRIVER=cloudflare|ipapi`) →
`SUPPORT_VISITOR_GEO=true` → `php artisan config:clear`. До включения `entry_url`/`referrer`
всё равно пишутся; город появляется только после включения.

**@DECIDE MG — провайдер города.** Пока по умолчанию `null` (пишем IP, город не запрашиваем).
Варианты, ранжированы по «город + лицензия + приватность + стоимость»:
1. **MaxMind GeoLite2 локально** (рекоменд.): город без внешних вызовов и per-request-стоимости,
   лицензия чистая; цена — ops-шаг (положить `.mmdb`, обновлять) → нужен драйвер `maxmind` (стаб).
2. **Cloudflare** (если сайт за CF): страна бесплатно всегда, город — только на планах с CF-IPCity;
   драйвер `cloudflare` уже готов, ноль внешних вызовов.
3. **ip-api.com** (`ipapi`, готов): город бесплатно, но **НЕкоммерческая** лицензия + HTTP-only —
   для коммерческого сайта юридически не подходит на free-тарифе.
Приватность/152-ФЗ: гео анонимного посетителя — персональные данные; на samskrte.ru cookie-баннер
уже есть, но включение внешнего геопровайдера — сознательный шаг с юридическим согласием.

---

## 3. S2 — Pillar 2: проактивный монитор посетителей + оператор пишет первым (✅ сделано 17-07-2026, H1197)

**Цель:** куратор видит **живой список посетителей на сайте прямо сейчас** (город, текущая
страница, время на сайте, источник) — даже тех, кто ещё ничего не написал — и может **написать
первым**; сообщение всплывает в виджете посетителя. Это второй уникальный столп Jivo.

**Что сделано** (в одном PR, за флагом `support_visitor_presence`, **OFF** по умолчанию):

- Миграция [`create_support_visitor_presences_table`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_07_17_130000_create_support_visitor_presences_table.php)
  — эфемерная строка присутствия на посетителя (ключ `guest_token`; `user_id?`,
  `visitor_ip/city/region/country/geo_resolved_at`, `current_url`, `referrer`,
  `first_seen_at`, `last_seen_at`).
- [`PublicPresenceController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PublicPresenceController.php)
  `POST /support/presence` (throttle) — heartbeat-beacon апсертит строку по `guest_token`;
  за флагом OFF ничего не пишет (`enabled:false`); гео резолвится ОДИН раз при создании тем же
  [`VisitorGeoResolver`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/VisitorGeoResolver.php)
  (S1) через [`ResolveVisitorPresenceGeoJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ResolveVisitorPresenceGeoJob.php).
  Ответ несёт `conversation_id` — так проактив куратора долетает до молчащего посетителя.
- [`PruneStaleVisitorPresencesJob`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/PruneStaleVisitorPresencesJob.php)
  каждые 5 мин выметает устаревшие (окна — [`config/support_presence.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_presence.php)).
- Операторская страница [`VisitorsOnline`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/VisitorsOnline.php)
  «Посетители онлайн» (гейт: флаг + не-преподаватель, как `Helpdesk`): живой список
  (📍город, страница, время на сайте, `wire:poll`) + кнопка **«Написать»** — куратор пишет
  первым, тред открывается/переоткрывается (реюз `openForGuest`/`openFor`), curator-сообщение
  бродкастится `ChatMessageSent` в виджет.
- Виджет [`support-chat-widget.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/support-chat-widget.blade.php)
  шлёт presence-beacon **с первого захода** и по ответу узнаёт свой тред → подписывается и
  **раскрывается** с сообщением оператора.
- 20 тестов ([`SupportVisitorPresenceTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SupportVisitorPresenceTest.php)
  9 · [`VisitorsOnlinePageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/VisitorsOnlinePageTest.php)
  9 · +2 render-теста виджета) + регрессии зелёные; Pint clean.

**Отступление от первоначального дизайна (осознанное):** источником правды сделан beacon → таблица
`support_visitor_presences` (HTTP-heartbeat), а НЕ Reverb presence-канал `presence-site-visitors`.
Причины: (1) таблица + TTL-выметание были и в исходном дизайне — это артефакты heartbeat-модели, а
не presence-канала; (2) presence-канал держит постоянный WS на каждой странице каждого посетителя
(масштабный риск, отмеченный ниже) — beacon дешевле и полностью тестируется без запущенного Reverb;
(3) «текущая страница» естественно ложится на POST-heartbeat, а не на членство в канале. Живость
списка у куратора даёт `wire:poll`. Реалтайм presence-канал остаётся возможным улучшением.

**Прод-включение (для Ивана, [DEPLOY_QUEUE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)):**
`php artisan migrate` (аддитивно) → `SUPPORT_VISITOR_PRESENCE=true` → `php artisan config:clear`
(город появится, только если ещё и `support_visitor_geo` включён с драйвером ≠ null). Нужен рабочий
воркер очереди (гео-джоба идёт через Horizon). **@DECIDE MG — юридический sign-off 152-ФЗ:** presence
отслеживает анонимного посетителя (город + поведение) — самый чувствительный по приватности слой;
включение — сознательный шаг с согласием (cookie-баннер уже есть), IP наружу оператору не светим.

**Дизайн (переиспользовал уже готовое):**

- **Presence-слой.** Reverb в проде (проверено), Echo подключён на витрине. Заводим Reverb
  **presence-канал** `presence-site-visitors`: виджет (и/или лёгкий beacon на всех страницах
  витрины) присоединяется с эфемерным `guest_token` (тот же, что владеет тредом, H536) и шлёт
  `{page, referrer, since}`; гео берётся тем же `VisitorGeoResolver` (S1).
- **Хранилище присутствия.** Таблица `support_visitor_presences` (эфемерная: `guest_token`,
  `user_id?`, `visitor_city/country` (реюз S1-резолвера), `current_url`, `referrer`,
  `first_seen_at`, `last_seen_at`) + TTL-выметание неактивных (cron, как `CloseStaleSessionsJob`).
- **Оператор: страница «Посетители онлайн»** (Filament, рядом с `Helpdesk`, `RoleGate`): список
  живых посетителей с 📍городом, текущей страницей, временем на сайте. Реюз паттерна левого
  списка `Helpdesk` + presence-подписки оператора.
- **Проактив (инициатива человека).** Кнопка «Написать» у строки посетителя → создаёт/переоткрывает
  гостевой тред по его `guest_token` (реюз `SupportConversationManager::openForGuest`) + curator-
  сообщение (реюз `replyToGuest` из `Helpdesk`) → `ChatMessageSent` в `support.conversation.{id}`;
  виджет, уже слушающий свой канал, **раскрывается** с сообщением оператора.
- **Виджет: слушать свой канал с первого захода** (не только после первого сообщения) — небольшая
  правка `support-chat-widget.blade.php`, чтобы проактив «долетал» до молчащего посетителя.

**Границы / риски.**
- **Никакого авто-мессенджинга:** приглашение шлёт только человек (принцип MG). Никаких
  авто-триггеров «через N секунд напиши сама».
- **Приватность/152-ФЗ:** presence отслеживает анонимного посетителя (город + поведение) — самый
  чувствительный кусок; за флагом `support_visitor_presence` (OFF), с согласием (cookie-баннер уже
  есть), IP наружу оператору не светим (только город). Юридический sign-off — @DECIDE MG.
- **Масштаб:** presence-канал на всей витрине = постоянные WS-соединения; начать можно с
  «только когда виджет открыт», расширять до всех страниц по нагрузке.

**Фазировка (сделана в одном PR):** (P1) таблица + beacon + гео + TTL → (P2) страница «Посетители
онлайн» read-only → (P3) кнопка «Написать» (проактив) + виджет слушает с первого захода — тесты
зелёные на каждом шаге. Исходная стартовая строка — в [H1197](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1197-Opus_Systema-Sanscriticum_jivo-parity-s2of5-proactive-visitor-monitor_17.07.26.md)
(🔴 EXECUTED).

---

## 4. S3–S5 — доведение существующего (2/3/4 требования)

- **S3 — сценарии/приветствие веб-чата + AI-assist для веб-виджета** ([H1198](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1198-Sonnet_Systema-Sanscriticum_jivo-parity-s3of5-webchat-scenarios_17.07.26.md)):
  веб-виджет сейчас — статичное приветствие. Добавить контекстные приветствия по странице входа
  (реюз `entry_url` из S1) + завести черновики FAQ-суггестера (H247, `support_answer_suggester`) на
  веб-сторону, а не только TG. Бот НЕ отвечает сам — черновик куратору. Пересекается с
  [`ROADMAP_SUPPORT_AUTOMATION`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) S3/S5.
- **S4 — захват лида (контакты) — ✅ сделано 18-07-2026** ([H1199](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1199-Sonnet_Systema-Sanscriticum_jivo-parity-s4of5-lead-capture_17.07.26.md),
  [PR #562](https://github.com/gasyoun/Systema-Sanscriticum/pull/562) merged): необязательные
  телефон/почта в виджете + запись `Lead`-строки ([`SupportLeadCaptureService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportLeadCaptureService.php),
  реюз `Lead`/UTM-паттерна `newsletter_subscribe` H324: UTM из `CaptureAttribution`-сессии, dedup
  по email), идемпотентно на тред (`lead_captured_at`) — обращение из чата больше не теряется как
  лид. Оффлайн-копирайт «Операторы сейчас офлайн — оставьте e-mail» вне деловых часов
  ([`config/support_hours.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_hours.php),
  [`App\Support\SupportAvailability`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/SupportAvailability.php));
  отправка НЕ блокируется ни онлайн, ни офлайн (контакты остаются необязательными). Нашёл и
  зафиксировал регрессией реальную коллизию S3↔S4: H1198's `applyContextualGreeting()` затирал
  `#scw-intro` поверх оффлайн-копирайта — исправлено `data-offline`-гардом. Всё за флагом
  `support_lead_capture` (ВЫКЛ по умолчанию). 24 теста / 63 assertions зелёные + 45 регрессионных
  тестов (Helpdesk/CRM/presence/geo) без изменений.
- **S5 — email-канал + бейджинг каналов + reply-out канарейка** ([H1200](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1200-Sonnet_Systema-Sanscriticum_jivo-parity-s5of5-email-channel-badging_17.07.26.md)),
  **бейджинг сделан 18-07-2026, остальное 2 пункта остаются**:
  - ✅ **Бейджинг VK/TG-student-bot** — оба писали в `chat_messages` неотличимо от веб-виджета
    (`UnifiedMessage::CHANNEL_WEB` целиком; support-subsystem-map.md gap #6). Новая колонка
    `chat_messages.source` (nullable, NULL=веб — обратная совместимость) + теги в
    [`ProcessVkBotMessage`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/ProcessVkBotMessage.php)
    (`source=vk`) и [`TelegramWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php)
    (`source=telegram_bot`); `UnifiedMessage` получил `CHANNEL_VK`/`CHANNEL_TELEGRAM_BOT` (отдельно
    от `CHANNEL_TELEGRAM` — импортированный TG-support-аккаунт, другое хранилище) с собственными
    бейджами в Helpdesk. 10 новых тестов / 39 assertions + 112 регрессионных тестов / 316 assertions
    (весь `ChatMessage`-затрагиваемый кластер) без изменений.
  - ⛔ **Reply-OUT канарейка (WS1.3)** — **НЕ выполнено, требует человека.** Контролируемый
    прогон живого userbot-пути ([`TelegramSupportSyncService::deliverMessage()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/TelegramSupportSyncService.php))
    на реальном канарейка-чате — production-действие с реальными получателями, за пределами того,
    что автономный агент может/должен выполнять без прямого разрешения и доступа к прод-учётке
    Ивана. Прод-переключатель и код уже готовы (`support_unified_reply`); канарейка сама остаётся
    ручным шагом человека, как и было задокументировано в [`ROADMAP_TELEGRAM_SCALING`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md) §6 WS1.3.
  - ⏸️ **Inbound email как канал** — later-phase и у самого Jivo (низший приоритет из трёх);
    полноценная почтовая инфраструктура (входящий webhook/polling, маппинг в тред) остаётся
    отдельным по объёму хэндоффом. **19-08-2026 (Sonnet 5 `claude-sonnet-5`):** спека/скелет
    написаны — [`docs/INBOUND_EMAIL_CHANNEL_SPEC_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INBOUND_EMAIL_CHANNEL_SPEC_2026.md) —
    целевая форма (переиспользует `chat_messages.source`/`UnifiedMessage`, без новой схемы),
    три варианта приёма почты с trade-offs, и явные **@DECIDE** человека (какой ящик, какой
    провайдер) — без них ingestion-код писать рано. Код не добавлен намеренно (неиспользуемая
    `UnifiedMessage::CHANNEL_EMAIL`-ветка без потребителя — мёртвый код, см. спеку «Why no code
    yet»).

---

## 5. Провязка

- Хэндоффы: **H1196** (S1 ✅), **H1197** (S2), **H1198** (S3), **H1199** (S4), **H1200** (S5) —
  [реестр](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md).
- GTD: строки в [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) (Tier 0, Systema).
- Индекс roadmap'ов: [`Uprava/ROADMAP_INDEX.md`](https://github.com/gasyoun/Uprava/blob/main/ROADMAP_INDEX.md).
- Deploy: [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) (строка S1).

## 6. Production acceptance matrix (H2382, 07-08-2026) — HOLD

Capstone evidence packet (not full parity GO):

- Report command: `php artisan support:parity-report --days=14`
- Matrix + live probe: [`docs/SUPPORT_PARITY_PRODUCTION_ACCEPTANCE_2026-08-07.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SUPPORT_PARITY_PRODUCTION_ACCEPTANCE_2026-08-07.md)
- Blockers: H2381 (operator close-topic / dialog follow-ups / EdTech sidebar), H1200 residual (email + fresh reply-out canary), geo driver still `null`, zero lead/presence canaries.

_Dr. Mārcis Gasūns_
