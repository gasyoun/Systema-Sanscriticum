_Created: 25-08-2026 · Last updated: 05-09-2026_

# message-intent-classifier

Детерминированный классификатор входящих сообщений школы (Systema-Sanscriticum,
ORS-FAQ): YAML-правила + референсный Python-движок + тонкий PHP-лоадер с той же
семантикой. Без LLM, без сети — только regex-правила и приоритеты.

План: [PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md) ·
Архитектура: [ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md).

## Контракт

Одно сообщение независимо размечается в четырёх плоскостях (до 4 тегов):

| Плоскость | Что размечает | Категории v1 |
|---|---|---|
| `topic` | о чём речь | zoom_link, recording_access, schedule, payment_billing, access_login, materials_content, tech_issue, homework_progress, certificate, membership_club, other_support |
| `objection` | какое сомнение стоит за сообщением (B1–B11) | b1_time … b11_what_next + none |
| `intent` | что человек хочет сделать | price_query, schedule_query, catalog_browse, learn_start, trial_request, buy_signal, complaint, spam_noise |
| `meta` | служебные флаги | human_trigger, urgent, sanskrit_term, new_contact |

Расширение категорий = PR в YAML + прогон харнесса, без кода.

## Семантика движка (едина для Python и PHP)

1. **Нормализация текста**: `lower` → `ё→е` → схлопывание всех пробельных
   последовательностей в один пробел + trim.
2. Внутри плоскости правила сортируются по `priority` (меньше = раньше),
   **первый матч выигрывает**; плоскости независимы.
3. Правило срабатывает, если совпадает хоть один `patterns` И ни одна
   `negations`. Негация блокирует правило целиком (порт урока `(?<!тех)поддержк`
   из escalation.py).
4. Результат несёт трассируемость: `reason: keyword:<pattern>` — какой именно
   паттерн сработал.
5. Ни одно правило не сработало → плоскость = `null` (uncategorized; это же
   метрика coverage в харнессе).

Ограничение на паттерны (проверяется обоими лоадерами, чтобы YAML был строго
переносим между PCRE и `re`): без `\w`, `\b`, `\d`, `\s`, `\uXXXX` — вместо них
явные классы `[а-я]`, `[0-9]`, литеральные диапазоны; разделители `/`, `#`
внутри паттерна запрещены; lookaround допустим только фиксированной ширины.

## Структура

```
taxonomy/v1/*.yaml          # категории, описания — словарь допустимых ярлыков
rules/v1/{topic,objection,intent,meta}.yaml   # правила: plane/category/priority/patterns/negations/source
engine_py/                  # референсный движок (loader, classifier, metrics) + cli
php/MessageClassifier/      # тонкий PHP-лоадер + та же семантика (symfony/yaml)
vectors/golden.json         # общие golden-векторы Py↔PHP parity (>=60)
harness/precision_report.py # per-category precision/recall/n + coverage по корпусу
```

## Быстрый старт

```bash
python -m engine_py.cli classify --rules rules/v1 --text "Где ссылка на запись занятия?"
python -m engine_py.cli batch --rules rules/v1 --in corpus.jsonl --out results.jsonl

python -m pytest engine_py/tests -q          # движок + golden-векторы (Python)
composer install --working-dir=php           # symfony/yaml + phpunit
php php/MessageClassifier/tests/parity_run.php   # PHP parity по тем же векторам
php php/vendor/bin/phpunit --bootstrap php/vendor/autoload.php php/MessageClassifier/tests

python harness/precision_report.py --rules rules/v1 --corpus corpora/eval.jsonl --out reports/baseline.md
```

## Golden-векторы

`vectors/golden.json` — замороженные ожидания полного вывода обоих движков
(`{plane: {category, reason}|null}`); оба обязаны воспроизводить их байт-в-байт.
Включают негативную пару «ссылка на запись» vs «техподдержка» (негация уводит
тему из recording_access в tech_issue) и кейсы нормализации (Ё, регистр, лишние
пробелы). Изменение правил → прогон харнесса → осознанное обновление векторов в
том же PR.

## Источники правил v1 (seed)

Перенос семантики (не дословный): RULES из
[SupportAnswerSuggester.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerSuggester.php)
(порядок «запись раньше zoom», узкие руки раньше широкой `ссылк` — H3394);
маркеры B1–B11 [sufler.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/sufler.py);
9 регулярок [escalation.py](https://github.com/gasyoun/ORS-FAQ/blob/main/ors_faq/escalation.py);
коды возражений [FAQ_FUNNEL_OBJECTION_MAP](https://github.com/gasyoun/ORS-FAQ/blob/main/docs/FAQ_FUNNEL_OBJECTION_MAP.md).
Каждое правило несёт `source:` происхождения. Полный перенос наследия — шаг 2
плана (правила-наследие → YAML seed).

## Фенс и PII

В пакет попадают ТОЛЬКО маскированные корпуса. Не трогать: payments/Tochka/
webhook-код, raw-PII сторы, `.env`, ручные записи в прод-БД.

## Лицензия

Apache-2.0 — как у родственных репозиториев дома.

_Dr. Mārcis Gasūns_
