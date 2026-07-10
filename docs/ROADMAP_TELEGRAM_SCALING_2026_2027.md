# Roadmap: масштабирование Telegram-интеграции 2026–2027

_Created: 11-07-2026 · Last updated: 11-07-2026_

> **Зонтичный** roadmap по всей Telegram-поверхности Академии — «с чего начать
> масштабирование». Отвечает на прямой вопрос MG (11-07-2026): «насколько хороша
> наша Telegram-интеграция, куда расти, с чего начать». Не отменяет, а надстраивает
> три существующих узких плана:
> - inbound-поддержка (детализация WS1) — [`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md);
> - ground truth по support-коду — [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md);
> - кабинет-бот (студенческий ИИ-куратор) — [`docs/cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md).
>
> Хэндофф-происхождение: [H565](https://github.com/gasyoun/Uprava/blob/main/handoffs/H565-Opus_Systema-Sanscriticum_telegram_scaling_roadmap_11.07.26.md),
> Opus 4.8 (`claude-opus-4-8`), 11-07-2026. Составлен после чтения кода на `main`
> и четырёх решений MG (см. §4).

---

## 1. Резюме

**Диагноз: архитектура сильная, активация низкая.** Код Telegram-поверхности хорошо
разложен, приватность-сознателен, за фича-флагами, переиспользует инфраструктуру. Проблема
не в качестве, а в том, что **большая часть автоматизации выключена**, а тяжёлый путь
(reply-out через userbot) до недавнего в репо-доках числился «не проверенным вживую».

**Ключевая реконсиляция (11-07-2026):** MG подтвердил — **Иван операционализировал userbot
на сервере**: MTProto-сессия залогинена, очередь (Horizon) работает, import-путь живой. Это
именно тот host-side интерактивный вход (QR/2FA), который агент выполнить не мог (см.
`.ai_state.md` «Now-A», пункт про интерактивный `telegram-support:sync`). Значит **репо-доки
устарели** в части «reply-out never exercised live» — правится в этом же PR (§9).

**Куда расти (решение MG):** все 4 направления, с приоритетной последовательностью ниже.
**Автономность бота (решение MG):** правило «бот только черновик, отправляет человек»
**ослабляется точечно** — авто-ответ разрешён для безопасных фактологических категорий
(ссылка на Zoom, расписание, ссылка на запись) с полным логированием и рубильником; всё
остальное остаётся draft-only. Это меняет многолетний жёсткий принцип — см. §4.2.

---

## 2. Что уже есть — scorecard Telegram-поверхности

Шесть+ различных Telegram-поверхностей, очень разной зрелости:

| # | Поверхность | Что делает | Состояние |
|---|---|---|---|
| 1 | **Кабинет-бот** ([`TelegramWebhookController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/TelegramWebhookController.php)) | Привязка (`/start <token>`), ИИ-куратор (DeepSeek/OpenRouter), передача человеку по триггер-словам, личные уведомления | ✅ **Живой, крепкий** |
| 2 | **Основной бот** | Алерты кураторам на `ADMIN_TELEGRAM_ID`, служебные чаты | ✅ Живой |
| 3 | **VK-бот** ([`VkBotController`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Api/VkBotController.php)) | Тот же ИИ-куратор для ВК | ✅ Живой |
| 4 | **Лид-магнит-боты** (TG/VK/MAX) | Отдельные вебхуки, доставка магнита | ✅ Живые |
| 5 | **Команда `/долги`** (S4/[H250](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md)) | Сводка должников поверх `DebtorsReport` в вебхуке | ✅ Смержено |
| 6 | **MTProto userbot — support** ([`TelegramSupportSyncService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/TelegramSupportSyncService.php), MadelineProto) | Импорт чата «Отдел заботы» для аналитики; reply-out подключён | ✅ **Import живой (Иван)** / ⚠️ reply-out за флагом |
| 7 | **MTProto userbot — harvester** ([`TelegramHarvestSyncService`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/app/Services/TelegramHarvest)) | Персональный харвест санскрит-групп в корпус (Track B) | ⚠️ Драйвер смержен; host-запуск за MG (PHP 8.3) |
| 8 | **Автоматизация поддержки S1–S12** | Deflection-метрики, факт-черновики, LLM-черновики, self-service | ⚠️ В основном **за флагами / только спроектировано** |

**Две вещи, важные ровно в момент масштабирования:**

1. **`/api/telegram/webhook` без проверки секрета.** Нет `X-Telegram-Bot-Api-Secret-Token`
   ([`cabinet-bot.md` §3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md) прямо предупреждает).
   Кто узнал URL — может слать поддельные апдейты (привязать аккаунт, гонять ИИ). Закрыть
   **до** роста трафика — это prerequisite, не afterthought. (NB: лид-магнит-вебхуки
   `/api/webhooks/*-magnet` секрет **имеют** — дыра только в основном кабинетном вебхуке.)
2. **Один MTProto-аккаунт — одна сессия.** support-sync и harvester **делят одну
   MadelineProto-сессию** (`MadelineClientFactory`): запуск двух команд одновременно →
   `AUTH_RESTART` (`.ai_state.md` «Now-A2», D1). С добавлением демона Ивана в контур
   консументов сессии становится **три** — их нужно строго сериализовать (§5, P0.3).

---

## 3. Уровни зрелости (что переключить, что построить)

| Уровень | Поверхности | Что значит для масштабирования |
|---|---|---|
| **Живое** | Кабинет-бот, основной бот, VK, лид-магниты, `/долги`, userbot-import | Работает; масштаб = нагрузка/охват/надёжность, не постройка |
| **Построено, за флагом** | Автопост ссылки (S1, [PR #333](https://github.com/gasyoun/Systema-Sanscriticum/pull/333)), `SupportAnswerSuggester` v1 (S3, `support_answer_suggester`), `support_ai_assist`, `support_unified_reply`, reply-out userbot ([`DeliverSupportReply`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/DeliverSupportReply.php)) | Осознанный прод-переключатель + предпосылки (заполнить `telegram_chat_id`, canary) |
| **Спроектировано** | S5–S12 (LLM-черновики, ростер, self-service доступа, RAG-пилот, ретро-анализ) | PR-размерные расширения, каждое — сессия агента |

Список фича-флагов — [`config/features.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/features.php);
все дефолты ВЫКЛ, кроме `newsletter_subscribe`.

---

## 4. Управляющие решения (закреплено 11-07-2026)

### 4.1. Направление роста — все 4, в последовательности
MG выбрал все четыре направления. Приоритетная последовательность — §6: сперва Phase 0
(разблокировка + измеримость), затем WS1 (inbound, максимум готового кода) параллельно с
WS3 (надёжность), потом WS2 (outbound, нужна рамка согласия), WS4 (student-AI, непрерывно).

### 4.2. Автономность бота — **ослабить точечно** (меняет жёсткий принцип)
Действующий многолетний принцип кодовой базы: **«боты НЕ отвечают студентам сами — только
pending-черновик куратору»** ([`ROADMAP_SUPPORT_AUTOMATION` §2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)).
MG (11-07-2026) разрешил **точечное ослабление**: бот **может отвечать сам** на безопасные
**фактологические** категории — A «Zoom/ссылка», C «расписание», B «ссылка на запись» — при
условиях: (1) факт берётся из LMS, не из LLM (ноль галлюцинаций реквизитов); (2) полное
логирование каждого авто-ответа в `SupportAiReplyEvent`; (3) рубильник-флаг и мгновенный
откат в draft-only; (4) всё остальное (цена/оплата/индивидуальные условия/эксперт-контент)
остаётся **draft-only** с человеком в петле. Формально это оформляется через `/decision-record`
(D##) перед включением в проде — см. §8, @DECIDE-D1.

### 4.3. Приватность LLM — уже разрешено, дефолт ВЫКЛ
MG снял @DECIDE 02-07-2026: в проде разрешено включать `support_ai_assist` **и**
`support_ai_include_telegram` (импортированные приватные TG-ЛС уходят в OpenRouter). Дефолты
в коде ВЫКЛ; включение — осознанный env-шаг активации (`.ai_state.md` «Now-A′»).

---

## 5. С чего начать — Phase 0 (разблокировка + измеримость)

**Это ответ на «с чего начать».** Ни одно из четырёх направлений нельзя масштабировать
безопасно, пока не закрыты четыре предпосылки. Оценка: 2–4 недели, почти всё — конфигурация
и аудит, не большой код.

| # | Тикет | Почему первым | Усилие |
|---|---|---|---|
| **P0.1** | **Реконсиляция скриптов Ивана в репо/наблюдаемость** | Иван операционализировал userbot **вне git** (`telegram-userbot/` в `.gitignore`, см. [`AUDIT_PLAN.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AUDIT_PLAN.md)). До масштабирования нужен **инвентарь**: какие демоны крутятся, systemd/supervisor-юниты, какая(-ие) MTProto-сессия(-и), каденс cron, и **кто единый раннер** sync (демон Ивана vs Laravel-команда `telegram-support:sync` — **не оба**). Это #1 разблокировщик: без него любой WS рискует «двумя системами». | M |
| **P0.2** | **Секрет вебхука `/api/telegram/webhook`** | Закрыть auth-дыру до роста трафика. Добавить проверку `X-Telegram-Bot-Api-Secret-Token` (шаблон уже есть у лид-магнит-вебхуков) + перевыпустить `setWebhook` с `secret_token`. Заодно — открытые находки [`SECURITY_AUDIT_money_2026-07-02.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/SECURITY_AUDIT_money_2026-07-02.md) гейтят self-service доступа (WS1/WS3). | S |
| **P0.3** | **Сериализация одного MTProto-аккаунта** | С демоном Ивана консументов одной сессии стало три (support-sync, harvester, Ивановы скрипты). Формализовать session-lock ([`LocksMadelineSession`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/app/Console) — расширить на раннер Ивана) или единый планировщик, чтобы исключить `AUTH_RESTART`-контенцию. | S–M |
| **P0.4** | **Deflection-инструментовка (S2) на проде** | Без метрик масштаб непроверяем. Прогнать на проде `support:deflection-report` / [`support:topic-ranking --months=6`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SupportTopicRanking.php) (`.ai_state.md` «Now-A» #3), зафиксировать реальный порядок категорий. Это измерительный хребет для всех WS. | M |

**Выход Phase 0:** известно, что крутится у Ивана и кто единый раннер; вебхук защищён;
сессии сериализованы; на проде есть базлайн deflection по категориям. Только после этого —
включать автоматизацию.

---

## 6. Четыре рабочих потока (workstreams)

### WS1 — Inbound: автоматизация поддержки (максимум готового кода)
Детальный план — [`ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)
(тикеты S1–S12). Здесь — дельта под решения §4:

- **W1.1 Автопост ссылки на занятие (S1).** Включить `class_link_autopost` + заполнить
  `telegram_chat_id` по группам (ручной шаг-человек, он же prerequisite S3/S5). Снимает
  категорию A «где ссылка» до возникновения вопроса.
- **W1.2 `SupportAnswerSuggester` v1 (S3, [H247](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md)) → авто-ответ на безопасных категориях.**
  Кэш-аут решения §4.2: для A (Zoom) / C (расписание) / B (запись) черновик **промотируется
  в авто-ответ** при выполнении условий §4.2, с логом в `SupportAiReplyEvent` и рубильником.
  Требует D## (`/decision-record`, @DECIDE-D1) до прод-включения.
- **W1.3 Live-fire reply-out.** Userbot Ивана живой → впервые **контролируемо прогнать
  ранее непроверенный** путь доставки ([`TelegramSupportSyncService::deliverMessage()`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/TelegramSupportSyncService.php) →
  [`DeliverSupportReply`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/DeliverSupportReply.php))
  на одном канарейка-чате: `support_unified_reply=true` + очередь. Черновики работают и без
  него (куратор шлёт как сейчас) — риск изолирован.
- **W1.4 → S5–S12** по мере данных deflection: LLM-черновики D/E/F, ростер, self-service
  доступа, RAG-пилот.

### WS2 — Outbound: рост (нужна рамка согласия)
- **W2.1 Рамка согласия и rate-limit.** До любых рассылок — модель согласия (явный opt-in
  vs законный интерес для действующих студентов) и жёсткие лимиты частоты. @DECIDE-D3.
- **W2.2 Сегментные рассылки.** Поверх [`SendMessengerAlerts`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Jobs/SendMessengerAlerts.php)
  (мультиканал TG+VK, уже есть) — сегменты (должники, холодные лиды, когорта), честный
  opt-out в каждом сообщении.
- **W2.3 Drip / реактивация.** Переиспользовать движок дрипа марафона
  ([`marathon:deliver-due`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/DeliverDueMarathonContent.php),
  H464) и `crm_reminders` (демоны, что сами пишут по воронке — сейчас за флагом).
- **W2.4 Инструмент рассылок: строить в Systema vs использовать скрипты Ивана.** @DECIDE-D4.

### WS3 — Consolidation & reliability (параллельно WS1)
- **W3.1 Скрипты Ивана → супервизируемый деплой.** Из P0.1: systemd/supervisor-юниты в
  репо (или задокументированы), healthcheck, алертинг на падение сессии/лаг синка.
- **W3.2 Живой unified reply.** `support_unified_reply` on после W1.3-канарейки; единый
  ответ куратора маршрутизируется в правильный канал ([`SupportReplyService`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportReplyService.php)).
- **W3.3 Наблюдаемость.** Дашборд: здоровье сессии, лаг синка, доля успешной доставки,
  расход LLM. Закрыть веб-чат-аналитику (S10 — `SupportDailyRollup` покрывает только TG-сторону).
- **W3.4 Единая идентичность — добить на проде.** `social_accounts` уже канон
  ([`support-identity.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md));
  прогнать `identity:backfill-social-accounts --apply` на проде (сейчас — только сухой прогон).

### WS4 — Autonomous student-AI (непрерывно)
- **W4.1 Расширить авто-ответ кабинет-бота** на безопасные категории (та же рамка §4.2),
  с эскалацией к человеку по цене/оплате/эксперт-контенту.
- **W4.2 Качество KB.** Категории из ранжирования (P0.4) → [ORS-FAQ](https://github.com/gasyoun/ORS-FAQ)
  → derived FAQ в Systema + KB кабинет-бота (двойная отдача, `.ai_state.md` «Now-A′»).
- **W4.3 Качество эскалации.** Мерить приемлемость авто-ответов; порог промоушена категории
  из draft-only в auto — по данным deflection (§4.2, условие 2 кварталов ≥ порога).

---

## 7. Последовательность и вехи

```
Phase 0 (разблокировка)      ── недели 1–4 ── P0.1 P0.2 P0.3 P0.4
   │
   ├─► WS1 inbound            ── Q3 2026 ── W1.1 → W1.2 (D1) → W1.3 canary → S5+
   ├─► WS3 reliability        ── Q3 2026 ── W3.1 W3.2 W3.4  (параллельно WS1)
   │
   ├─► WS2 outbound           ── Q4 2026 ── W2.1 (D3) → W2.2 W2.3  (после рамки согласия)
   └─► WS4 student-AI         ── непрерывно ── W4.1 (D1) W4.2 W4.3
```

Сезонный дедлайн наследуется от support-roadmap: пики вопросов — сентябрь–октябрь; всё, что
снимает нагрузку (WS1, WS3), должно быть на проде к **01-09-2026**.

---

## 8. Открытые развилки (@DECIDE — решает человек)

- **@DECIDE-D1 — авто-ответ: категории и порог.** Какие фактологические категории включить
  первыми (рекомендация: A Zoom → C расписание → B запись) и при какой доле приемлемости
  черновиков категория промотируется из draft-only в auto. Оформить через `/decision-record`
  **до** прод-включения (это изменение жёсткого принципа §4.2).
- **@DECIDE-D2 — единый раннер MTProto-синка.** Демон Ивана или Laravel-команда
  `telegram-support:sync` — **один**. Кто владелец сессии, кто держит session-lock. Без
  этого решения P0.3 не закрыть.
- **@DECIDE-D3 — модель согласия для outbound.** Явный opt-in vs законный интерес для
  действующих студентов; лимиты частоты; текст opt-out. Гейт для всего WS2.
- **@DECIDE-D4 — инструмент рассылок.** Строить сегментные рассылки в Systema (поверх
  `SendMessengerAlerts`) или использовать/обернуть уже существующие broadcast-скрипты Ивана.

---

## 9. Правки устаревших доков (в этом же PR)

Реконсиляция §1 требует поправить репо-доки, писавшиеся до операционализации Ивана:

- [`docs/support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md)
  «Actually open», Phase 6: строку «Reply-out path untested in production» уточнить —
  **import-путь живой в проде** (демон Ивана); untested остаётся именно delivery/reply-out,
  до W1.3-канарейки.
- [`docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md)
  §9 «Reply-out путь (userbot) не проверен живьём» — уточнить аналогично + добавить перекрёстную
  ссылку на этот зонтичный roadmap.
- `.ai_state.md` «Now-A» — отметить, что интерактивный host-side вход **выполнен Иваном**
  (import живой), и перевести пункт в измерение (P0.4) вместо «после появления credentials».

---

## 10. Провязка

- Хэндофф: [H565](https://github.com/gasyoun/Uprava/blob/main/handoffs/H565-Opus_Systema-Sanscriticum_telegram_scaling_roadmap_11.07.26.md)
  (Opus 4.8, `claude-opus-4-8`, 11-07-2026).
- Детализация WS1: [`ROADMAP_SUPPORT_AUTOMATION_2026_2027.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SUPPORT_AUTOMATION_2026_2027.md).
- Ground truth support-кода: [`support-subsystem-map.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) · кабинет-бот: [`cabinet-bot.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/cabinet-bot.md).
- Четыре @DECIDE (§8) зеркалятся в [`Uprava/GTD_NEXT_ACTIONS.md`](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) (Tier 0, Systema).
- Статус-журнал: [`.ai_state.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md).

_Dr. Mārcis Gasūns_
