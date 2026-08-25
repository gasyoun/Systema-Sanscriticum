# Архитектура: пакет message-intent-classifier

_Created: 25-08-2026 · Last updated: 25-08-2026_

План: [PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md).
Решение MG (интервью #5, #10): единый **data-пакет** — YAML-правила + референс Python-движок +
тонкий PHP-лоадер; Systema и ORS-FAQ оба его потребляют; RPC между системами нет.

## Вердикты build-vs-reuse (prior-art 25-08-2026)

| Кусок | Вердикт | Основание |
|---|---|---|
| Механика regex-классификатора с приоритетами | **Reuse** | [SupportAnswerSuggester](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerSuggester.php) — перенос семантики в YAML, не новая наука |
| DB-правила прод-потока | **Adapt** | [SupportTopicRule](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportTopicRule.php) остаётся рантайм-стором; источником истины становится YAML пакета |
| Метод доказательства точности | **Reuse as-is** | [ClassifierPrecisionTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/ClassifierPrecisionTest.php) + замороженный корпус, пол 93% |
| Возражения B1–B11 | **Reuse as seed rules** | [sufler.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/sufler.py) классы переносятся в YAML дословно |
| Эскалация/живые интенты ORS | **Adapt** | [escalation.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/escalation.py), `_live_intent()` → плоскости meta/intent |
| Транслитерация для мета-тега `sanskrit_term` | **Partial reuse** | [sanskrit-util](https://github.com/gasyoun/sanskrit-util) даёт IAST/Devanagari-нормализацию; кириллическую пракрит-детекцию v1 НЕ строит (см. риски) |
| n8n-маршрутизация по тексту | **Gap — не строим** | Классификация живёт в приложениях; n8n остаётся транспортом |

## Формат правила (v1)

```yaml
plane: topic            # topic | objection | intent | meta
category: recording_access
priority: 20            # меньше = раньше; внутри плоскости первый match выигрывает
patterns:
  - 'ссылк\w* на запис'
negations:
  - 'техподдержк'       # урок escalation.py (?<!тех)поддержк
enabled: true
```

Семантика движка (единая для Py и PHP): нормализация `mb_lower + ё→е + схлопывание пробелов`;
плоскости независимы (одно сообщение получает до 4 тегов); `negations` блокируют pattern;
результат несёт `reason: keyword:<pattern>` — требование трассируемости из H3394.

## Таксономия v1

- **topic**: zoom_link, recording_access, schedule, payment_billing, access_login,
  materials_content, tech_issue, homework_progress, certificate, membership_club, other_support.
- **objection**: B1–B11 коды [FAQ_FUNNEL_OBJECTION_MAP](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/FAQ_FUNNEL_OBJECTION_MAP.md) + `none`.
- **intent**: price_query, schedule_query, catalog_browse, learn_start, trial_request,
  buy_signal, complaint, spam_noise.
- **meta**: human_trigger (список StudentChatService), urgent, sanskrit_term, new_contact.

Расширение категорий = PR в YAML + прогон харнесса, без кода.

## Схема репо пакета

```
message-intent-classifier/
├── taxonomy/v1/*.yaml          # категории, описания, владельцы плоскостей
├── rules/v1/{topic,objection,intent,meta}.yaml
├── corpora/
│   ├── eval/2026-07-05-masked.jsonl      # ЗАМОРОЖЕНО: экспорт 05-07-2026, 2621 диалог
│   └── train/2026-08-22-masked.jsonl     # адаптация: снапшот 22-08-2026, 2776 диалогов
├── engine_py/{classifier,loader,metrics}.py + cli.py
├── php/MessageClassifier/{Loader,Classifier}.php   # symfony/yaml, та же семантика
├── vectors/golden.json         # общие golden-vectors Py↔PHP parity
├── harness/precision_report.py # per-category precision/recall/n + coverage
└── tools/mask_corpus.py        # PII-маскировка + валидатор
```

## Потоки данных

1. **Живой поток**: TG DM / веб-чат / VK / email(dark) → существующие сторы Systema
   (`telegram_support_messages` c `raw_payload`, `chat_messages`) → `SupportTopicClassifier`
   читает сидированные правила → assignments. Автоответы — только существующие ON-полосы.
2. **Исторические корпуса**: локальные txt диалогов ORS → `mask_corpus.py` → JSONL пакета →
   офлайн-прогон → `reports/*.md` + `results/*.jsonl` коммитятся в пакет; прод-БД не трогается.
3. **Деплой правил**: YAML пакета → pinned vendored-снапшот в Systema
   (`tools/message-intent-classifier@<sha>`) → `php artisan support:rules-sync` upsert'ит
   `SupportTopicRule` (ключ идемпотентности: plane+category+pattern-hash), выключает исчезнувшие.
   CI-check: drift vendored-копии против pin.

## PII-политика

В пакет попадают ТОЛЬКО маскированные корпуса: телефоны, email, @username, URL, имена из
разрешённого списка — заменяются плейсхолдерами до коммита (прецедент
[classifier_corpus_2026_08.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/fixtures/Support/classifier_corpus_2026_08.json)).
Немаскированные txt остаются gitignored где лежат; raw telegram-harvest не читается этим слоем.

_Dr. Mārcis Gasūns_
