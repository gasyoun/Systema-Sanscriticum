_Created: 25-08-2026 · Last updated: 05-09-2026_

# Changelog

All notable changes to this project are documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.3.1] — 30-08-2026

### Changed

- H3703 (OxAlpha): freeze-protocol step 1 in [corpora/README.md](https://github.com/gasyoun/message-intent-classifier/blob/main/corpora/README.md) corrected — the ORS-FAQ dialogue corpus was described as `gitignored`; it is untracked (H3563), and since H3703 the ORS-FAQ `.gitignore` covers `ors_faq/dialogs/` (plain `clean -fd` spares it, `-x`/`-X` still do not).

## [0.3.0] — 26-08-2026

### Added

- H3527 (частично): `tools/mask_corpus.py` — PII-маскировка исторических
  ORS-диалогов (`dialog_*.txt` → JSONL
  `{dialog_id,msg_id,direction,date,text_masked}`), парсер совместим с
  `ors_faq/custdev_pilot.py` (формат `[YYYY-MM-DD] УЧЕНИК|КУРАТОР:`,
  мультистрочные сворачиваются). Плейсхолдеры URL/EMAIL/PHONE/TG_HANDLE/NUMBER
  + имена из опционального `--names` файла; catch-net валидатор (`\d{7,}`,
  остаточные @/URL) — exit 1 = STOP. Сабкоманды: `mask`, `validate`, `sample`
  (детерминированный чеклист 50 сообщений c sign-off блоком), `census`.
- `engine_py/tests/test_mask_corpus.py` — синтетические фикстуры на все классы
  PII, маска→валидация 0-попаданий, STOP на утечке, форма чеклиста, цензус.
- `corpora/README.md` — протокол заморозки (census → mask → validate PASS →
  подписанная проба → commit) и цензусы 2621/2776; `.gitignore`: raw_corpus/,
  corpora/raw/, tools/pii_names*.txt — raw никогда не коммитится.
- ⚠️ Сама заморозка снапшотов (eval 2621 / train 2776) НЕ выполнена: сырой
  корпус `ors_faq/dialogs/dialog_*.txt` отсутствует на машине исполнения
  (untracked-файлы исчезли из общего дерева до старта; локальных копий,
  Trash/TM/iCloud/удалённых веток нет). Инструмент готов; прогон — по
  восстановлению корпуса.
- H3528 (частично): `harness/run_corpus.py` — batch-классификация именованных
  маскированных корпусов (`--corpus eval=… --corpus train=…`) →
  `results/<name>.jsonl` + Markdown baseline-отчёт (per-plane coverage%,
  per-category n_pred/n_gold/p/r/f1, insufficient-evidence при n_gold < 30,
  uncategorized top-50 по корпусу). Вход совместим с формой mask_corpus.py.
- H3528: zabota-export phrasing seed — «в каком видео» →
  topic/recording_access из
  telegram-zabota-export/question_phrasing_inventory.json (код B, «Скажите
  пожалуйста в каком видео можно найти?» ×2); остальные 7 частотных
  формулировок уже покрыты — разбор в reports/rules-seed-census.md §8.
- H3528: reports/h3528-regression-agreement.{md,json} — 9/9 именованных
  регресс-кейсов ClassifierPrecisionTest маршрутизируются движком пакета
  идентично PHP-канону.

### Changed

- engine_py/metrics.py: n_pred считает каждое срабатывание (с меткой и без),
  чтобы объём по категориям был публикуем на немаркированных снапшотах;
  семантика precision не тронута (tp/(tp+fp) по меченой части).
- harness/precision_report.py: принимает форму mask_corpus.py
  ({dialog_id, msg_id, …, text_masked}) и `--rules` как в верификационном доке.
- vectors/golden.json: +t-rec-06 (zabota seed), 161 → 162; 0 flips по
  замороженным; pytest 19 passed, parity_run.php ALL GREEN 162/162.

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
