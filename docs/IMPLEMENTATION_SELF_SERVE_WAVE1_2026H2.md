# Реализация: волна 1 self-serve (пошагово)

_Created: 25-08-2026 · Last updated: 25-08-2026_

План: [PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md) ·
Архитектура: [ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md).
Шаги идут в порядке исполнения; каждый называет файлы и зависимость от предыдущих.
Шаги 1–5 = пять handoffs волны 1 (все OxAlpha); шаг 6 — приёмка волны.

## Шаг 1 — scaffold пакета (hard, H-minted)

1. Создать репо `gasyoun/message-intent-classifier` по схеме из архитектуры (каркас каталогов,
   README с контрактом, MIT/proprietary header по обычаю дома).
2. `engine_py/classifier.py`: нормализация, плоскости, приоритеты, negations, reason-строки;
   CLI `classify --rules … --text …` и batch-режим JSONL→JSONL.
3. `vectors/golden.json`: ≥60 векторов (по ≥4 на каждую категорию v1 + negation-кейсы
   «ссылка на запись» vs «техподдержка»).
4. `php/MessageClassifier/*` + parity-тест, читающий те же golden-vectors.
   Файлы: только внутри нового репо. Зависимости: нет.

## Шаг 2 — правила-наследие → YAML seed (medium)

1. Перенести в `rules/v1/*.yaml`: RULES SupportAnswerSuggester (соблюдая порядок приоритетов —
   «B раньше A», оплата раньше zoom), ключевые слова support_tech.php, B1–B11 sufler,
   9 регулярок escalation.py, интенты `_live_intent()`, фразы StudentSelfService,
   HUMAN_TRIGGERS StudentChatService.
2. Каждому правилу — `source:` комментарий происхождения (файл+рулинг), чтобы ревью было честным.
3. Прогнать golden-vectors; расхождения семантики чинить в движке, не в векторах.
   Файлы: `rules/v1/*.yaml`. Зависимость: шаг 1.

## Шаг 3 — маскировка + заморозка снапшотов (medium)

1. `tools/mask_corpus.py`: вход — каталог `dialog_*.txt` ORS-FAQ (локальная копия, gitignored);
   выход — JSONL `{dialog_id, msg_id, direction, date, text_masked}`.
2. Валидатор маскировки: regex на телефоны/email/@username/URL + выборочная ручная проба 50
   сообщений до массового прогона (стоп-условие PII).
3. Заморозить eval=05-07-2026 и train=22-08-2026 снапшоты в `corpora/`.
   ⚠️ Исходники лежат untracked в общем дереве ORS-FAQ и могут исчезнуть — локальную копию снять
   первым же действием шага. Зависимость: нет (параллелит с шагами 1–2).

## Шаг 4 — офлайн-прогон → отчёты (medium)

1. `harness/precision_report.py` по eval-корпусу: per-category precision/recall/n,
   coverage%, uncategorized-выборка топ-50 для следующей итерации правил.
2. Батч-прогон train+eval → `reports/2026-08-25-wave1-baseline.md` + `results/*.jsonl`;
   коммит в пакет. Это и есть первая бесплатная сортировка ~46 тыс. сообщений.
3. Seed-дополнение правил из question_phrasing_inventory.json zabota-export (16 962 вопроса)
   — только частотные формулировки, каждое с source-комментарием.
   Зависимость: шаги 2–3. Гейт: ≥93% precision/категория при n≥30 (см. верификацию).

## Шаг 5 — Systema-интеграция (hard)

1. Vendored pinned-копия пакета в `tools/message-intent-classifier@<sha>` + drift-check.
2. Artisan `support:rules-sync {--dry-run}`: YAML → upsert `SupportTopicRule`
   (идемпотентность plane+category+pattern-hash; исчезнувшие правила disable, не delete).
3. Filament-панель на telegram-support-analytics: coverage% за день по каналам
   (TG DM / telegram_bot / web / vk / email-dark), uncategorized-rate, ссылка на последний
   harness-отчёт пакета. Автоответы не включаются; флаги автоответов не трогаются.
4. Тесты: seeder idempotent (второй dry-run diff пуст), classifier unit на vendored PHP,
   полный сьют зелёный. Зависимость: шаги 1–2 (формат правил стабилен).

## Шаг 6 — закрытие волны

Прогнать [VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md)
полностью; CHANGELOG пакета и Systema; handoff-close по каждому H; GTD-остатки (В2 human-шаги).

## Шаг 7 — H7803: рантайм-флип топик-классификатора (за флагом, default OFF)

1. **Что включает флаг.** `features.support_topic_synced_rules`
   (`SUPPORT_TOPIC_SYNCED_RULES=true`) переводит `SupportTopicClassifier` на
   синхронизированные правила пакета (`support_topic_rules.pattern_hash NOT NULL`):
   нормализация текста (mb_lower, ё→е, whitespace fold), порядок priority asc,
   `negations` блокируют правило, первый матч выигрывает — та же семантика, по
   которой меряется precision reference-движка. Синхронизированный набор идёт
   первым; легаси-ключевые слова (`pattern_hash IS NULL`) остаются фолбэком.
   На проде легаси-строк нет, поэтому ON = ровно YAML-истина.
2. **Что флаг НЕ трогает.** Автоответы, шаблоны, платежи, доступы, webhooks —
   классификатор пишет только `support_topic_assignments` (фенс H3529/H7803).
3. **Процедура флипа (после гейта).**
   `php artisan support:rules-sync` (первый прогон; повторный — пустой diff:
   `created=updated=disabled=0`) → `SUPPORT_TOPIC_SYNCED_RULES=true` в `.env` →
   `php artisan config:cache` → `php artisan support:topic-ranking --days=14`
   до/после: uncategorized share должен упасть. Откат — флаг `false` +
   `config:cache` (назначения пересчитываются на следующем rollup-е).
4. **Гейт включения (H3529, не снят).** precision ≥0.93 на категорию при n≥30 на
   masked eval-корпусе. Корпус `corpora/eval/2026-07-05-masked.jsonl` в репозитории
   отсутствует: H3527 закрыт 05-09-2026 как GATED-FOREVER (raw ORS-диалоги
   невосстановимы), поэтому гейт в этой поставке не прогонялся. Доказательство
   поставки — `tools/message-intent-classifier/reports/h7803-synced-rules-gate.md`:
   coverage-прогон на 50 реальных masked-сообщениях (34% topic-null против 100% на
   пустом рантайм-наборе прода) + процедура повторного прогона, когда корпус
   появится. Без живого гейта флаг остаётся OFF; решение о включении — за MG.

_Dr. Mārcis Gasūns_
