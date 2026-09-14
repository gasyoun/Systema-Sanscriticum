# MIC taxonomy-of-record: mapping MIC topic ↔ legacy SupportTopicRule ↔ ORS 11 topics

_Created: 12-09-2026 · Last updated: 12-09-2026_

**Handoff:** H4608 (Systema-Sanscriticum). Comparative survey of record: [Uprava COMPARATIVE_INTENT_CLASSIFIERS_MIC_MULTITAXONOMY_12-09-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/COMPARATIVE_INTENT_CLASSIFIERS_MIC_MULTITAXONOMY_12-09-2026.md) (§4.3 prescribes exactly this mapping; this file is the Systema-side copy-of-record).

This is the single committed dictionary between the THREE coexisting vocabularies (G3):

1. **MIC topic plane** — 11 categories, message-level, [taxonomy/v1/topic.yaml](https://github.com/gasyoun/message-intent-classifier/blob/main/taxonomy/v1/topic.yaml) (vendored at `tools/message-intent-classifier/taxonomy/v1/topic.yaml`).
2. **Legacy SupportTopicRule categories** — the live rollup/rule vocabulary of this app: canonical letters A–F from [SupportAnswerSuggestion](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAnswerSuggestion.php) (`CATEGORY_ZOOM`='A' … `CATEGORY_MATERIALS`='F') plus free-form DB rows seeded from legacy sources (support_tech, SelfService, H3526 legacy seed). The vocabulary is DB-driven; `support:mic-null-digest` and the Helpdesk close form read it live.
3. **ORS 11 topics** — the samskrtam.ru FAQ intent register, [INTENT_TAG_OUTCOME_REGISTER_AND_ATTRIBUTION_W0_2026-09-06.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/INTENT_TAG_OUTCOME_REGISTER_AND_ATTRIBUTION_W0_2026-09-06.md) (W0, H4233; dialog counts are that register's own evidence).

## Mapping table

| MIC topic (v1) | Legacy SupportTopicRule | ORS 11 topic | Notes / evidence |
|---|---|---|---|
| `zoom_link` | **A** (zoom / подключение) | `04_ссылка_zoom` (338, serve) | 1:1. Same user question, three vocabularies. |
| `recording_access` | **B** (записи / видео / тайм-коды) | `05_записи_уроков` (549, consultation) | 1:1. MIC negation guards keep «не работает» in `tech_issue`. |
| `schedule` | **C** (расписание / время / переносы) | `06_расписание` (319, course) | 1:1. |
| `payment_billing` | **D** (оплата / цена / тарифы / рассрочка) | `01_оплата` (720) + `02_стоимость` (973) + `09_депозит` (24) | MIC v1 is FLAT here: refund/pause/price_query collapse into one category. v2 hierarchy ticket (comparative §4.1). |
| `access_login` | **E** (доступ / группа / личный кабинет) | — (partial overlap with `10_техпроблемы` for «не могу войти») | ORS has no dedicated access row; serve-only semantics. |
| `materials_content` | **F** (материалы / ДЗ / сертификаты) | `11_материалы` (313, serve) | Legacy F lumps ДЗ+сертификаты into materials; MIC v1 SPLITS them (see below three rows). |
| `tech_issue` | free-form `support_tech` row (H3526 legacy seed; no A–F letter) | `10_техпроблемы` (164, serve) | Legacy A–F has no tech letter — technical issues were handled by TechnicalIssueDetector/router, not the suggester alphabet. |
| `homework_progress` | **F** (частично — домашние задания) | — (contextually near `07_пауза` 114) | MIC split F; ORS pause row is retention-side, not homework-side. |
| `certificate` | **F** (частично — сертификаты) | — | MIC split F; no ORS row. |
| `membership_club` | — (no legacy equivalent) | — | Club/membership is a 2026 product surface; both older vocabularies predate it. |
| `other_support` | — (Helpdesk close form «Другое») | — | Deliberate reserve category, NO auto-rules in v1 (anti-fallback-basket, FINDINGS §125). |

## Deliberate non-correspondences (become v2 tickets, not silent gaps)

- **`03_запись` (enrollment, 654 — the hottest ORS tag)** has NO MIC *topic* equivalent: enrollment intent lives on the MIC **intent** plane (`learn_start`, `trial_request`, `buy_signal`). Cross-plane, not missing.
- **`07_пауза` (pause)** and **`08_возврат` (refund)** have NO MIC v1 category: inside flat `payment_billing` at best. Named v2-hierarchy tickets in comparative §4.1 (`payment_billing → {refund, pause, deposit, price_query, installment}`).
- Legacy **A–F letters are rollup-level** (daily rollups via SupportTopicClassifier), MIC is message-level — the digest command (`support:mic-null-digest`) is the first surface where both vocabularies can be compared on the SAME rows once telemetry is enabled.

## Pointers

- Shadow telemetry table: `mic_shadow_classifications` (flag `features.mic_shadow_classify`, default OFF — no runtime flip; H3529 precision ≥ 0.93 gate stands).
- Weekly digest: `php artisan support:mic-null-digest` (scheduled Mondays 06:55 MSK, writes `storage/app/reports/mic-null-digest/`).
- Rules live upstream: [message-intent-classifier rules/v1](https://github.com/gasyoun/message-intent-classifier/tree/main/rules/v1); re-vendored drift-gated (H4419). Staged into DB read-only by `support:rules-sync`, invisible to runtime (H3529).

_Dr. Mārcis Gasūns_
