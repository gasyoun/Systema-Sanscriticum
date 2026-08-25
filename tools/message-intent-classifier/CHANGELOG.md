# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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
