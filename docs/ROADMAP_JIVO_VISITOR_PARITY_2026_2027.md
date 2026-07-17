# Roadmap: Jivo-паритет веб-чата — visitor intelligence 2026–2027

_Created: 17-07-2026 · Last updated: 17-07-2026_

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

---

## 1. Требования MG и текущий паритет (6 требований)

Шесть требований — из уточняющего интервью MG (что именно из Jivo должно совпасть):

| # | Требование (как в Jivo) | Есть у нас? | Владелец-задача |
|---|---|---|---|
| 1 | Живой чат с оператором (real-time) | ✅ **Готово** (H536; Reverb-push в проде + фолбэк-опрос) | — (residual reply-out канарейка — S5) |
| 2 | Авто-ответы / сценарии | ⚠️ Частично (AI-куратор + FAQ-суггестер H247 — оператору; веб-виджет без сценариев) | [H1198](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1198-Sonnet_Systema-Sanscriticum_jivo-parity-s3of5-webchat-scenarios_17.07.26.md) (S3) |
| 3 | Сбор контактов / заявки (лиды) | ⚠️ Частично (имя — необязательно; нет телефон/почта, нет оффлайн-формы) | [H1199](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1199-Sonnet_Systema-Sanscriticum_jivo-parity-s4of5-lead-capture_17.07.26.md) (S4) |
| 4 | Каналы TG / VK / почта | ⚠️ Частично (TG+VK есть; почты нет; каналы не разбейджены; reply-out не проверен вживую) | [H1200](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1200-Sonnet_Systema-Sanscriticum_jivo-parity-s5of5-email-channel-badging_17.07.26.md) (S5) |
| 5 | **Город посетителя** в панели куратора | 🟢 **S1 сделан этой сессией** (см. §2) | [H1196](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1196-Opus_Systema-Sanscriticum_jivo-parity-s1of5-visitor-geo-city_17.07.26.md) (S1, Pillar 1) |
| 6 | **Написать первым** + видеть, что делает на сайте | ❌ Нет (нужен presence-слой + проактив) | [H1197](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1197-Opus_Systema-Sanscriticum_jivo-parity-s2of5-proactive-visitor-monitor_17.07.26.md) (S2, Pillar 2) |

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

## 3. S2 — Pillar 2: проактивный монитор посетителей + оператор пишет первым (H1197)

**Цель:** куратор видит **живой список посетителей на сайте прямо сейчас** (город, текущая
страница, время на сайте, источник) — даже тех, кто ещё ничего не написал — и может **написать
первым**; сообщение всплывает в виджете посетителя. Это второй уникальный столп Jivo.

**Дизайн (переиспользует уже готовое):**

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

**Фазировка (own PR):** (P1) таблица + presence-канал + beacon → (P2) страница «Посетители
онлайн» read-only → (P3) кнопка «Написать» (проактив) + виджет слушает с первого захода → тесты
на каждом шаге. Стартовая строка — в [H1197](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1197-Opus_Systema-Sanscriticum_jivo-parity-s2of5-proactive-visitor-monitor_17.07.26.md).

---

## 4. S3–S5 — доведение существующего (2/3/4 требования)

- **S3 — сценарии/приветствие веб-чата + AI-assist для веб-виджета** ([H1198](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1198-Sonnet_Systema-Sanscriticum_jivo-parity-s3of5-webchat-scenarios_17.07.26.md)):
  веб-виджет сейчас — статичное приветствие. Добавить контекстные приветствия по странице входа
  (реюз `entry_url` из S1) + завести черновики FAQ-суггестера (H247, `support_answer_suggester`) на
  веб-сторону, а не только TG. Бот НЕ отвечает сам — черновик куратору. Пересекается с
  [`ROADMAP_SUPPORT_AUTOMATION`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md) S3/S5.
- **S4 — захват лида (контакты)** ([H1199](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1199-Sonnet_Systema-Sanscriticum_jivo-parity-s4of5-lead-capture_17.07.26.md)):
  необязательные телефон/почта в виджете + запись `Lead`-строки (реюз `Lead`/UTM, как
  `newsletter_subscribe` H324), чтобы обращение из чата не терялось как лид. Оффлайн-форма «напишем
  на почту», когда операторов нет онлайн.
- **S5 — email-канал + бейджинг каналов + reply-out канарейка** ([H1200](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1200-Sonnet_Systema-Sanscriticum_jivo-parity-s5of5-email-channel-badging_17.07.26.md)):
  разбейджить VK/TG-student-bot как отдельные каналы в едином инбоксе; контролируемая канарейка
  reply-OUT в TG-support (WS1.3 из [`ROADMAP_TELEGRAM_SCALING`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md));
  inbound email как канал (later-phase и у Jivo).

---

## 5. Провязка

- Хэндоффы: **H1196** (S1 ✅), **H1197** (S2), **H1198** (S3), **H1199** (S4), **H1200** (S5) —
  [реестр](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md).
- GTD: строки в [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) (Tier 0, Systema).
- Индекс roadmap'ов: [`Uprava/ROADMAP_INDEX.md`](https://github.com/gasyoun/Uprava/blob/main/ROADMAP_INDEX.md).
- Deploy: [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) (строка S1).

_Dr. Mārcis Gasūns_
