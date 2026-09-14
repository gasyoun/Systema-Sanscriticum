# taxonomy/v1/mapping.md — словарь соответствий (dictionary of record)

_Created: 13-09-2026 · Last updated: 13-09-2026_

Единственный источник parent/child иерархии и кросс-системных соответствий
таксономии v2 message-intent-classifier (H4609). Схема категории в загрузчиках
допускает только `key/title/description` — родительство здесь, не в YAML.

Колонки: **MIC topic** — ключ плоскости `topic`; **Parent** — родитель L2-ключа
(«—» = корень плоскости); **Legacy** — категория SupportAnswerSuggester /
SupportTopicRule в Systema-Sanscriticum
([SupportAnswerSuggestion.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAnswerSuggestion.php):
A zoom, B записи, C расписание, D оплата, E доступ, F материалы/ДЗ/сертификаты);
**ORS topic** — тег из регистра W0
([INTENT_TAG_OUTCOME_REGISTER_AND_ATTRIBUTION_W0_2026-09-06.md](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/INTENT_TAG_OUTCOME_REGISTER_AND_ATTRIBUTION_W0_2026-09-06.md));
**funnel_stage** — исход маршрутизации регистра (course 2690 / consultation 719 /
serve_only 815 диалогов).

## Плоскость topic

| MIC topic | Parent | Legacy | ORS topic | funnel_stage | Доказательство |
|---|---|---|---|---|---|
| zoom_link | — | A | 04_ссылка_zoom | serve_only | регистр: «access mechanics; routing adds noise» |
| recording_access | — | B | 05_записи_уроков | consultation | регистр: «отстал от группы» anxiety → human (DROPOFF_TAXONOMY_2026) |
| — access_window | recording_access | B | 05_записи_уроков | consultation | H4609: окно/срок доступа к записям — уточнение того же тега |
| schedule | — | C | 06_расписание | course | регистр: «choosing a group = purchase-adjacent decision» |
| payment_billing | — | D | 01_оплата, 02_стоимость | course | регистр: «price question = active purchase evaluation»; «mid-payment → reduce friction» |
| — refund | payment_billing | D | 08_возврат | consultation | регистр: «risk state — only a human should answer»; R3 (H4404) refuse |
| — pause | payment_billing | D | 07_пауза | consultation | регистр: «pre-churn signal — human retention conversation» |
| — deposit | payment_billing | D | 09_депозит | course | регистр: «money already in motion» |
| — installment | payment_billing | D | 02_стоимость | course | та же покупательская оценка, что 02_стоимость |
| access_login | — | E | — (темы нет) | serve_only | в 11 тегах регистра кабинет/логин отсутствует; «Nothing routes anywhere else» |
| materials_content | — | F | 11_материалы | serve_only | регистр: «access mechanics» |
| tech_issue | — | — (очередь «Техника» config/support_tech.php) | 10_техпроблемы | serve_only | H3526 seed; регистр: «support mechanics» |
| homework_progress | — | F | — (11_материалы частично) | serve_only | легаси-F = «материалы / ДЗ / сертификаты» |
| certificate | — | F | — | serve_only | легаси-F |
| membership_club | — | — | — | serve_only | резерв; регистра тега нет |
| other_support | — | — | — | serve_only | резервная корзина, enabled: false |

## Плоскость intent (справочно)

| MIC intent | ORS topic | funnel_stage | Доказательство |
|---|---|---|---|
| learn_start | 03_запись | course | регистр: «first-contact enrollment intent — the hottest tag» |
| buy_signal | 01_оплата | course | «mid-payment → reduce friction, land on catalogue» |
| price_query | 02_стоимость | course | 973 диалога — крупнейший course-тег |
| schedule_query | 06_расписание | course | 319 диалогов |
| trial_request | — | course | та же воронка записи (правило p10 intent) |
| complaint / spam_noise / help_menu / catalog_browse | — | serve_only | не несут маршрута регистра |

## Плоскости v2

- **funnel_stage** {course, consultation, serve_only} — ключи 1:1 из регистра W0
  («два одобренных исхода» + обслуживание без маршрутизации).
- **escalation** {calm, frustrated, churn_risk} — канал просьбы о человеке из
  [ors_faq/escalation.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/escalation.py);
  churn-лексика — теги 07_пауза/08_возврат (DROPOFF_TAXONOMY_2026).
- **resolution** {auto_answerable, faq_hit, needs_human} — H4404: R3 refuse
  (D/E → needs_human), FAQ live-F порог 15.7 (F → faq_hit), шаблонные руки
  A/B/C → auto_answerable.

## Контракт ZLR-ключей (иерархия)

1. Ключи категорий остаются **L2-scoped** (`refund`, а не `payment_billing.refund`).
2. Родительство **неявное** — только эта таблица; загрузчики его не знают.
3. **Ноль переименований** существующих ключей v1.
4. Новая тема появляется в taxonomy + rules + этой таблице одним PR
   (golden refresh в том же PR — контракт vectors/golden.json).

_Dr. Mārcis Gasūns_
