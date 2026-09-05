_Created: 25-08-2026 · Last updated: 05-09-2026_

# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0] — 26-08-2026

### Added

- H3526 legacy harvest: config/support_tech.php keywords (белый экран,
  не слышно, микрофон дословно; вебинар/kinescope отказ-композитом; вход в
  access_login), StudentSelfService GROUP_PHRASES + HOMEWORK_PHRASES
  слэш-команды и «мои группы/курсы…», answerer.py _live_intent фразы
  (прайс, тариф, день недели, дни занятий, начало курса, закончится,
  что идет, что сейчас), StudentChatService HUMAN_TRIGGERS («помощь»,
  «менеджер»).
- Новая категория intent/help_menu (StudentSelfService HELP_PHRASES) в
  taxonomy/v1/intent.yaml + правило priority 50.
- reports/rules-seed-census.md — по-источниковая таблица переноса со
  скип-нотами (сайт/приложение/регистрац/доступ-расширения).

### Changed

- vectors/golden.json: intentional golden refresh 143 → 161 (+18 векторов на
  новую покрытие; 0 flips по замороженным). Оба движка зелёные byte-identical.

## [0.1.0] — 25-08-2026

### Added

- Initial scaffold per H3525 / wave-1 step 1 of
  PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.
- `taxonomy/v1/` — four planes (topic / objection / intent / meta), category
  dictionary with descriptions.
- `rules/v1/` — seed rules ported from SupportAnswerSuggester RULES order,
  sufler B1–B11 markers, escalation.py patterns; each rule carries `source:`.
- `engine_py/` — reference engine: normalization (lower + ё→е + whitespace
  fold), priority first-match-wins per plane, negations blocking a whole rule,
  `reason: keyword:<pattern>` traceability; CLI (`classify`, batch JSONL).
- `php/MessageClassifier/` — thin PHP loader (symfony/yaml) + classifier with
  identical semantics; PHPUnit parity test + standalone parity runner reading
  the SAME golden vectors.
- `vectors/golden.json` — ≥60 frozen vectors incl. the «запись»-vs-
  «техподдержка» negation pair passing BOTH engines.
- `harness/precision_report.py` — per-category precision/recall/n + coverage +
  uncategorized sample over a masked JSONL corpus.

_Dr. Mārcis Gasūns_
