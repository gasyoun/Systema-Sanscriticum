# H7803 — Flip топик-классификатора на sync-правила: гейт и доказательства

_Created: 07-11-2026 · Last updated: 07-11-2026_

Handoff: [H7803](https://github.com/gasyoun/Uprava/blob/main/handoffs/H7803-DeepSeek_Systema-Sanscriticum_classifier-synced-rules-flip_07.11.26.md)
(goal G54). План волны: [IMPLEMENTATION_SELF_SERVE_WAVE1_2026H2.md](IMPLEMENTATION_SELF_SERVE_WAVE1_2026H2.md) §Шаг 7.
Контракт приёмки: [VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md](VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md).

## 1. Что изменилось

| Файл | Изменение |
|---|---|
| `app/Services/TelegramSupport/SupportTopicClassifier.php` | Под флагом `features.support_topic_synced_rules` матчит PCRE-паттерны sync-правил (`pattern_hash NOT NULL`) в семантике reference-движка: нормализация (mb_lower, ё→е, whitespace fold), priority asc, `negations` блокируют правило, первый матч выигрывает. Легаси-ключевые слова — фолбэк. Назначения с source `synced_rule`; при переклассификации удаляются оба источника. |
| `config/features.php` | Новый флаг `support_topic_synced_rules` = `env('SUPPORT_TOPIC_SYNCED_RULES', false)`. **Default OFF.** |
| `tests/Feature/Support/SupportSyncedRulesRuntimeGuardTest.php` | OFF-гард сохранён; добавлены тесты ON: regex-матч, negations, first-match-wins, легаси-фолбэк, битый regex не валит день, идемпотентная переклассификация, дефолт флага. |

Не тронуто (фенс): автоответы (`support_dm_llm_*`), платежи, доступы, шаблоны,
`support:rules-sync` (команда уже идемпотентна и покрыта `SupportRulesSyncTest`).

## 2. Гейт precision ≥0.93 — НЕ ПРОГНАН (корпуса не существует)

Требуемый артефакт — `tools/message-intent-classifier/corpora/eval/2026-07-05-masked.jsonl`
(2621 диалог) — в репозитории отсутствует и **не может быть создан**:

- `git log --all` по `tools/message-intent-classifier/corpora/**` знает только `README.md`:
  сам корпус никогда не коммитился;
- H3527 (freeze-половина) **закрыт 05-09-2026 как GATED-FOREVER** голосованием
  [p1_queue_close_14](https://gasyoun.github.io/vote/sheets/p1_queue_close_14.html) 14/14:
  исходные `ors_faq/dialogs/dialog_*.txt` невосстановимы (ни на одной локальной машине,
  ни в git-истории, ни на прод/n8n);
- H3528 (offline-классификация) закрыт тем же голосованием транзитивно.

Поэтому заявленный гейт «per-category precision ≥0.93 при n≥30» в этой поставке
**не вычислим**, и чисел по нему здесь нет. Флаг остаётся OFF; включать его без гейта —
решение MG, не агента.

## 3. Что измерено вместо гейта (coverage, не precision)

Единственный доступный **реальный masked** набор — `tests/fixtures/Support/classifier_corpus_2026_08.json`
(50 входящих rusamskrtam-лейна, PII-маскированы, H3394). Голд в нём размечен под легаси-алфавит
A–F, не под MIC-таксономию, поэтому precision на нём считать нельзя (это была бы подмена
разметки). Coverage — можно и нужно:

```
python tools/message-intent-classifier/harness/precision_report.py \
  --root tools/message-intent-classifier --corpus <fixture.jsonl> --out <report.md>
```

| метрика | значение |
|---|---|
| сообщений | 50 (реальные, masked) |
| topic coverage (MIC v1) | **66.0 %** (33/50) |
| topic-null share | **34.0 %** (17/50) |
| для сравнения: прод 06-11-2026 | 100 % uncategorized (рантайм-набор пуст: все 24 строки — staging `pattern_hash`) |

Распределение topic-категорий на фикстуре: `payment_billing` 8, `access_login` 4,
`zoom_link` 4, `recording_access` 4, `schedule` 4, `refund` 4, `homework_progress` 3,
`materials_content` 1, `certificate` 1. В topic-null — короткие реплики без темы
(«Намо намах!», «Нет», «Заходим», email+phone, «И с чего же начать?»), т.е. значительная
часть 34 % — не недоработка правил, а законно бестемные сообщения.

Это подтверждает направление outcome (uncategorized падает с ~100 %), но **не заменяет**
per-category precision ≥0.93. Синтетический `reports/synthetic-precision-report.md`
(68 генераторных строк, precision 1.000) в гейт не идёт по своему же дисклеймеру.

## 4. Процедура флипа, когда гейт станет возможен

```
php artisan support:rules-sync            # 1-й прогон; повторный → created=updated=disabled=0
SUPPORT_TOPIC_SYNCED_RULES=true           # .env
php artisan config:cache
php artisan support:topic-ranking --days=14   # до/после: uncategorized share
```

Откат: `SUPPORT_TOPIC_SYNCED_RULES=false` + `config:cache`. Назначения пересчитываются
на следующем rollup-е.

## 5. Проверки в этой поставке

| проверка | статус |
|---|---|
| PHP-синтаксис `SupportTopicClassifier.php`, теста, конфига | OK (AST-парсер `ast-grep`, php) |
| `php artisan test --filter=SupportSyncedRulesRuntimeGuardTest` | **НЕ ЗАПУЩЕН** — на этой машине нет `php`, `composer`, `vendor/` (Windows/MSI-бокс, `php: command not found`) |
| `php artisan test --filter=SupportRulesSyncTest` (idempotence) | **НЕ ЗАПУЩЕН** — та же причина; команда не менялась, тест существующий |
| Python harness на фикстуре | OK, отчёт воспроизводим одной командой §3 |

Верификатору/CI: прогон PHP-тестов обязателен на машине с PHP 8.3 + `vendor/`
(или в CI PR). Без него «тесты зелёные» не заявляется.

## 6. Остатки

1. **H7803-a (MG/человек):** решить, включать ли флаг без precision-гейта (корпус
   GATED-FOREVER) или ждать нового masked-снимка из свежего Telegram-экспорта ORS.
2. **H7803-b (ops, после merge):** прод-флип по §4 + живой день: сводка 08:10 показывает
   темы ≠ «uncategorized N».
3. **H7803-c (verifier):** прогон PHP-тестов и `support:rules-sync` idempotence на машине
   с PHP.

_Dr. Mārcis Gasūns_
