# Суфлёр: автономная отправка — политика v1 enforced, пилот за гейтами

_Created: 2026-10-03 · H5776 · worktree Systema-Sanscriticum-h5776-drain_

## Что изменилось (policy v0 → v1 enforced)

1. **Гейты перед каждой автоотправкой** (`policy/sufler.policy.yml` v1 →
   `App\Services\Support\Sufler\SendPolicyGate`, вызов в
   `SupportDmAutoReply::sendAuto()` — единственной горловине исходящих бота):
   - `money_amount_matches_system` — сумма в тексте разрешена только kind=`facts`
     (системный расчёт; расхождение заявленной/расчётной гасится выше по
     конвейеру, `dm_balance_dispute` + финансовый follow-up). Шаблон/FAQ/LLM
     с суммой = блокировка + трейс.
   - `no_personal_data_in_corpus` — e-mail / телефон РФ / номер карты в
     исходящем черновике = блокировка + трейс (zoom-ссылки, даты, номера
     уроков гейт не душит — проверено тестом).
   - `citation_present_for_every_fact` — kinds `facts` / `faq_rag` /
     `llm_draft` обязаны нести цитату в мете (`fact_type` / `chunk_id` /
     `faq_chunk_ids`).
   Нарушение = отправки нет, студенту молчание, куратору подсказка, трейс
   событием `dm_policy_blocked` (gates + excerpt). Молчаливых остановок нет.
2. **human_stays → кураторам**: money_disputes (`dm_balance_dispute`),
   сертификаты/возвраты-конфликты (moneyIntent-забор факта + R3-запрет LLM),
   no-facts-hint (`hintComplex`). Были частично; v1 фиксирует их в политике
   как обязательные маршруты.
3. **Потолки machinery** (`App\Services\Support\Sufler\Ceilings`):
   `max_steps_per_ticket=8` (окно 24 ч, конфиг) — нарушение = авто-стоп с
   трейсом `dm_ceiling_stop` и маршрут куратору; `tokens_per_ticket` читается
   из `SUFLER_TOKENS_PER_TICKET`, до monthly_allowance от MG — null =
   потолок disabled, **флаги пилота OFF**.

## RAG-цитаты за score-floor (ветка 2)

- Нога уже живёт в `SupportDmAutoReply` за `features.support_dm_auto_reply_live_faq`
  (OFF по умолчанию) + пер-аккаунтной `auto_reply_enabled` + порогом
  `support.faq_rag.shadow_min_score[_by_category]`; D/E вычеркнуты в коде
  безусловно (R3).
- **Вывод `faq:score-floor` в этом worktree** (копия .env/main, корпус по
  умолчанию worktree):

  | Кат. | N | top-1 без порога | Порог ≥95% | Точность | Покрытие |
  |---|---|---|---|---|---|
  | - | 9 | 78% | 9.8 | 100% | 67% |
  | A | 15 | 67% | недостижимо | 89% | 60% |
  | B | 16 | 31% | недостижимо | 57% | 44% |
  | C | 12 | 50% | 13.1 | 100% | 33% |
  | D | 17 | 29% | недостижимо | 50% | 35% |
  | E | 14 | 86% | недостижимо | 88% | 57% |
  | F | 17 | 71% | недостижимо | 88% | 47% |

  Планку 0.95 при приемлемом покрытии на этой калибровке берёт только
  категория C; A/B/D/E/F — честное «недостижимо» (лучшее 88 %). Планка R3
  на rerank-ноге закрыта week0 (H5771): precision top-1 **95 % (19/20)**,
  при пороге 95 %, top-3 **100 %** — см.
  docs/REPORT_SUFLER_WEEK0_RERANK_LLM_02-10-2026.md. Финальную калибровку
  порогов под F-ногу делать на живом faq.md (killgate K5).

## Пилотное окно (ветка 3) — документированный стоп

**Стоп: живое включение ждёт monthly_allowance от MG** — политики прямо
запрещают флип до заполнения потолка токенов (число даёт только MG, K3
леджера). Поэтому N≥20 на живом аккаунте не запускалось: ноль нарушений
guards гарантируется отсутствием живой ноги (флаги OFF), но метрика
share_resolved_without_human в окне смысла не имеет до армирования.

- База week0 (committed, 02-10-2026): **24/29 (82.76 %)** автономных
  кандидатов; rerank 95 %/95 %/100 %.
- Killgate-леджер армирования: docs/KILLGATE_SUFLER_PILOT_2026-10-03.md
  (K1–K7; K3/K6 — @DO MG).
- После армирования окно считается по событиям `dm_auto_sent` / `dm_hinted` /
  `dm_policy_blocked` / `dm_ceiling_stop` — все трейсы уже пишутся.

## Проверки

- `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Support/SuflerSendPolicyTest.php`
  → **OK (15 tests, 28 assertions)** — по тесту на каждый guard (нарушение
  блокирует отправку и пишет трейс) + ceilings-авто-стопы (steps/tokens).
- Регрессия соседних походов sendAuto:
  `vendor/bin/phpunit SupportDmMoneyIntentFenceTest SupportDmAutoReplyTest
  SupportDmLiveFaqAutoSendTest SupportDmShadowModeTest AutoReplyTrialTest`
  → **OK (41 tests, 130 assertions)**.
- `php artisan faq:score-floor` → таблица выше.

## Риски

- PII-паттерны узкие (e-mail/телефон РФ/карта): экзотические форматы ПДн
  пройдут — компенсируется тем, что leg'и шлют только выверенные тексты и
  факты LMS; расширение списка = правка одного массива + теста.
- Блокировка «сумма вне facts» может зацепить шаблон с ценой, если куратор
  впишет её вручную — это желаемое поведение (статичная цена в шаблоне и есть
  money-риск политики); куратор получит подсказку и ответит сам.

## Инспекция (верификатор открывает первым)

1. `app/Services/Support/Sufler/SendPolicyGate.php` — гейты.
2. `app/Services/Support/SupportDmAutoReply.php::sendAuto` — горловина:
   ceilings → policy → отправка; события-трейсы.
3. `tests/Feature/Support/SuflerSendPolicyTest.php` — по тесту на guard.
4. `policy/sufler.policy.yml` v1 — маппинг пунктов на код.
