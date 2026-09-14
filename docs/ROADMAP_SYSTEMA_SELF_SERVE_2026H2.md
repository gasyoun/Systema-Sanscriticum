# Роадмап: self-serve Systema 2026H2 — три волны

_Created: 25-08-2026 · Last updated: 28-08-2026_

Индекс и решения: [PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md).
Базлайн аудита 25-08-2026: детерминированная классификация уже живёт в проде
([SupportAnswerSuggester](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerSuggester.php),
6 категорий, precision ≥93%, автоответы H3380/H3394) — план её выносит в переиспользуемый пакет,
расширяет таксономию до трёх плоскостей и поднимает покрытие на все каналы.

## Волна В1 — «бесплатная сортировка» (классификатор + корпуса + метрики)

Ноль LLM-токенов, ноль денег. Всё на существующих данных.

1. **Пакет `message-intent-classifier`** (новый репо `gasyoun/message-intent-classifier`):
   YAML-таксономия v1 (topic / objection B1–B11 / intent + мета), референс Python-движок,
   тонкий PHP-лоадер, golden-vectors parity-тесты Py↔PHP, precision-харнесс.
   Разблокирует: всё остальное.
2. **Правила-наследие → YAML seed**: перенос RULES из SupportAnswerSuggester,
   [config/support_tech.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/support_tech.php),
   B1–B11 из [sufler.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/sufler.py),
   эскалаций [escalation.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/escalation.py),
   интентов `_live_intent()`, фраз StudentSelfService и HUMAN_TRIGGERS.
   Разблокирует: прогон корпусов и сидирование прода.
3. **Маскировка + заморозка двух снапшотов** ORS-диалогов (eval 05-07-2026 = 2621; train 22-08-2026 = 2776)
   с PII-валидатором. Разблокирует: харнесс и офлайн-прогон.
4. **Офлайн-прогон корпусов → JSONL+MD-отчёты** в репо пакета: первая бесплатная сортировка
   ~46 тыс. входящих сообщений 2764 диалогов; словарь формулировок из 16 962 классифицированных
   вопросов zabota-export как доп. seed.
5. **Systema-интеграция**: artisan `support:rules-sync` (YAML→`SupportTopicRule`, idempotent),
   Filament-панель coverage%/uncategorized по каналам на
   telegram-support-analytics. Автоответы НЕ включаются.

Критерий закрытия волны: харнесс зелёный (≥93% precision/категория при n≥30), отчёты по корпусам
закоммичены, seeder идемпотентен, полный тест-сьют Systema зелёный.

## Волна В2 — поверхности самообслуживания (конверсия)

1. **Гостевая регистрация `/register`** → авто-Free-tier клуба — **shipped**
   28-08-2026 ([PR #2163](https://github.com/gasyoun/Systema-Sanscriticum/pull/2163),
   H3643). Flag `GUEST_REGISTRATION_ENABLED` still default OFF; prod flip is a
   human ops step. `FreeTierLessonGranter::grantSignupFor` + `ClubFreeTierSrsDeck`.
2. **Пробный виджет ON**: переворот `CRM_TRIAL_BOOKING` + `CRM_TRIAL_WIDGET_PUBLIC`
   ([DEPLOY_QUEUE №80](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)) — human-шаг.
3. **Входящая почта ON**: ящик zabota@, n8n IMAP→POST-forwarder, `INBOUND_EMAIL_WEBHOOK_SECRET`,
   флаг `SUPPORT_INBOUND_EMAIL` ([DEPLOY_QUEUE №82](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md)) — human-шаги;
   письма классифицирует тот же движок после флага.

## Волна В3 — лид-аналитика

1. Первый потребитель [lead_signals.jsonl](https://github.com/gasyoun/telegram-sanskrit-corpus/tree/main/data/derived):
   сегментация авторов TG × crm.jsonl ground truth.
2. Сегментные отчёты по плоскостям objection/intent поверх отсортированных корпусов В1:
   цена/срочность/возражения в разрезе конверсии.
3. Реактивация ghosts.csv под сегменты (рассылки — только ручным пуском MG).

## Не-цели спана

Рекуррентные платежи (#998, PayPal Subscriptions dark), ESP-проводка samskrtam.ru (R11),
донаты, подарочные сертификаты, getcourse-parity, Jivo-паритет телефонии.

_Dr. Mārcis Gasūns_
