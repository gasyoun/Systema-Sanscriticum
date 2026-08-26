# Результаты H3529 — support:rules-sync + Filament coverage panel

_Created: 25-08-2026 · Last updated: 25-08-2026_

Handoff: [H3529-OxAlpha_Systema-Sanscriticum_support-rules-sync-filament-coverage_25.08.26](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3529-OxAlpha_Systema-Sanscriticum_support-rules-sync-filament-coverage_25.08.26.md)
PR: <заполняется при закрытии>

## Транскрипт двойного dry-run (sqlite worktree, 25-08-2026)

```
=== DRY-RUN #1 ===
  create topic/homework_progress 0c4dc93c (3 patterns)
  create topic/certificate c97de34b (1 patterns)
  create topic/membership_club cc15d349 (1 patterns)
  create topic/other_support a507be9c (0 patterns)
summary: created=36 updated=0 disabled=0 unchanged=0 legacy-skipped=0 [DRY RUN]

=== REAL RUN ===
summary: created=36 updated=0 disabled=0 unchanged=0 legacy-skipped=0

=== DRY-RUN #2 (empty diff) ===
support:rules-sync — source tools/message-intent-classifier/rules/v1 (pinned cadf4fcf6e0f5b37a9f2b4c92c93774b90a63813)
summary: created=0 updated=0 disabled=0 unchanged=36 legacy-skipped=0 [DRY RUN]
```

## Локальные тесты (PHP 8.5.9 local / CI 8.3; sqlite :memory:)

```
php artisan test tests/Feature/Support/SupportRulesSyncTest.php
  tests/Feature/Support/SupportSyncedRulesRuntimeGuardTest.php
  tests/Feature/Support/SupportCoveragePanelTest.php
  tests/Unit/VendoredMessageClassifierParityTest.php
→ Tests: 10 deprecated (PHP 8.5 deprecation noise), 0 failures, 121 assertions
php artisan test tests/Feature/TelegramSupportAnalyticsTest.php → 117 assertions, 0 failures
./vendor/bin/pint --test → passed (pint.json исключает vendored дерево пакета)
python3 tools/check_mic_vendor_drift.py --upstream <clone@pin> → matches pin cadf4fcf6e0f
```

## Помеченные дефолты (run-log волны)

1. **Легаси-строки не трогаются.** Sync управляет ТОЛЬКО строками с
   `pattern_hash IS NOT NULL`. Полный переход «YAML = единственный источник»
   отложен до гейта precision ≥93% ([VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md)).
2. **Synced-правила невидимы рантайму** (`whereNull('pattern_hash')` guard в
   SupportTopicClassifier): текущий матчинг — плоский mb_stripos, а 7 YAML-паттернов —
   литералы («техподдержк», «не работает», «кабинет», «нет доступа», «можно попробовать»),
   которые изменили бы живую классификацию до включения движка пакета.
3. **JSON-близнецы вместо runtime-YAML**: symfony/yaml в проде dev-only (sail);
   правила едут прекомпилированными `rules/v1/*.json`, свежесть гейтится drift-check'ом.
4. **CI upstream-parity за токеном**: репо пакета приватное; полный байт-в-байт гейт
   активируется секретом `MIC_UPSTREAM_TOKEN`, до тех пор CI гоняет self-only режим.

## Честные остатки

- ~~Панель-скриншот из проды — после деплоя (нужен MG-approval Environment «production»).~~ — см. остаток ниже.
- ~~Реальный `support:rules-sync` на проде — операторский шаг после деплоя~~ — **выполнено 26-08-2026** (транскрипт ниже).
- Секрет `MIC_UPSTREAM_TOKEN` (fine-grained PAT, contents:read на
  gasyoun/message-intent-classifier) — заводит человек.

## Прод-исполнение 26-08-2026 (MG «go»)

Код на проде уже был свежий (серверный auto-deploy cron успел раньше: HEAD
`e4655a30`, миграция `[176] Ran`). CI-путь деплоя в этот раз заблокировал
зомби-лок concurrency-группы `deploy-production` («waiting for another
serialized run to finish» при нуле активных раннов — известный баг Actions);
использован санкционированный workflow-заголовком ручной операторский путь
(SSH → `artisan`), approval MG в чате.

```
=== PROD DRY-RUN #1 ===
  create topic/materials_content 26951bee (2 patterns)
  ...
summary: created=37 updated=0 disabled=0 unchanged=0 legacy-skipped=7 [DRY RUN]

=== PROD REAL RUN ===
summary: created=37 updated=0 disabled=0 unchanged=0 legacy-skipped=7

=== PROD DRY-RUN #3 (empty diff) ===
summary: created=0 updated=0 disabled=0 unchanged=37 legacy-skipped=7 [DRY RUN]
```

DB-верификация (tinker, read-only): **37 synced rows (36 enabled; 1
yaml-disabled `other_support`)** · **7 legacy rows — все 7 по-прежнему
enabled** (гард сработал: sync легаси не тронул). Смоук: `samskrte.ru` →
HTTP 200 за 0.38 c.

Остатки после прода:

1. Панель-скриншот — открыть /admin/telegram-support-analytics под админом,
   приложить сюда.
2. Секрет `MIC_UPSTREAM_TOKEN` — включит полную байт-в-байт сверку против pin.
3. Зомби-лок группы `deploy-production`: если к следующему деплою не рассосётся —
   cancel+re-dispatch или повторный SSH-путь; GTD-строка заведена.

_Dr. Mārcis Gasūns_
